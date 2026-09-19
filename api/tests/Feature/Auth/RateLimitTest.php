<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * レート制限が意図した上限でかかっていることを固定する。
 *
 * Laravel 11 以降、bootstrap/app.php で throttleApi() を呼ばないと制限は一切
 * かからない。「設定したつもりで何もかかっていない」という状態は外から見えないため、
 * 実際の応答ヘッダと 429 で確かめる。
 *
 * テストの CACHE_STORE は array（phpunit.xml）。カウンターはテストメソッドごとに
 * 作り直されるアプリのメモリ上にあり、メソッド間に漏れない。
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    /**
     * ルートごとに効いている上限は X-RateLimit-Limit に出る。
     * ここが 60 のままなら、認証の枠が重なっていない（= 制限がゆるすぎる）。
     */
    public function test_each_route_is_throttled_at_its_own_limit(): void
    {
        $user = User::factory()->twoFactorPending()->create(['password' => Hash::make(self::PASSWORD)]);

        // 認証以外は API 全体の枠だけ
        $this->getJson('/api/health')->assertOk()->assertHeader('X-RateLimit-Limit', 60);

        // パスワードを検証する入口
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 10);

        // コードを検証する場所。重ねた2つの上限のうち厳しい側が出る
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', 5);
    }

    public function test_login_is_blocked_after_ten_attempts_in_a_minute(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password-here',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password-here',
        ])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    /**
     * 6桁 = 100万通りのコードを無制限に試せないこと。ここが MFA の総当たり耐性を
     * 決めるので、上限を緩めたら落ちるようにしておく。
     */
    public function test_two_factor_code_is_blocked_after_five_attempts_in_a_minute(): void
    {
        $user = User::factory()->twoFactorPending()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
                ->assertUnprocessable();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertTooManyRequests();
    }

    /**
     * 毎分の上限だけでは1日に7,200回試せてしまう。日単位の上限を重ねてあること。
     *
     * 分の枠は時間を進めれば回復するが、日の枠は回復しない。2つの上限が同じ by() を
     * 持っていてもカウンターが混ざらないこと（RateLimiter がキーを差し替える）も
     * ここで一緒に確かめている。
     */
    public function test_two_factor_code_has_a_daily_cap_beyond_the_minute_window(): void
    {
        $user = User::factory()->twoFactorPending()->create();

        // 毎分5回 × 6分 = 30回まではどれも通る（コードが誤りなので 422）
        for ($minute = 1; $minute <= 6; $minute++) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->actingAs($user, 'sanctum')
                    ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
                    ->assertUnprocessable();
            }

            $this->travel(1)->minutes();
        }

        // 31回目。分の枠は空いているのに通らない
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertTooManyRequests();
    }

    /**
     * 制限はユーザー単位で数える。IP だけで数えると、同じ IP を共有する利用者
     * （NAT・社内網）が互いの枠を食い合う。
     */
    public function test_the_limit_is_counted_per_user_not_per_address(): void
    {
        $blocked = User::factory()->twoFactorPending()->create();
        $other = User::factory()->twoFactorPending()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($blocked, 'sanctum')
                ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
                ->assertUnprocessable();
        }

        $this->actingAs($blocked, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertTooManyRequests();

        // 同じ IP からの別ユーザーは影響を受けない
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable();
    }

    /**
     * 未認証のリクエストは 401 で止まり、制限の枠を消費しない。
     * ミドルウェアの優先順位で auth が throttle より先に走るため。
     * 逆だと、トークン無しの連打で正規の利用者の枠を潰せてしまう。
     */
    public function test_unauthenticated_requests_do_not_consume_the_limit(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
                ->assertUnauthorized();
        }

        $user = User::factory()->twoFactorPending()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertHeader('X-RateLimit-Remaining', 4);
    }
}
