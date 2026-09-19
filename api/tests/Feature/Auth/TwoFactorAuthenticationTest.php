<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\RecoveryCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * MFA（TOTP）の登録・確認・解除を固定する。
 *
 * 見るべきは「有効化できること」よりも、
 *   - シークレットが平文で DB に残らないこと
 *   - 確認が済むまで MFA が有効にならないこと
 *   - 現在のパスワード無しで設定を変えられないこと
 * の3点。ここが緩むと MFA を足した意味がなくなる。
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
    }

    /** 認証アプリが今表示しているであろうコードを作る */
    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
    }

    public function test_enabling_requires_authentication(): void
    {
        $this->postJson('/api/auth/two-factor', ['password' => self::PASSWORD])
            ->assertUnauthorized();
    }

    public function test_enabling_requires_the_current_password(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor', ['password' => 'wrong-password-here'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_enabling_returns_a_secret_and_an_otpauth_uri(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor', ['password' => self::PASSWORD])
            ->assertCreated()
            ->assertJsonStructure(['secret', 'otpauth_uri']);

        $secret = $response->json('secret');

        // 認証アプリが読めるのは otpauth:// 形式の URI。同じ鍵が入っていること。
        $this->assertStringStartsWith('otpauth://totp/', $response->json('otpauth_uri'));
        $this->assertStringContainsString('secret='.$secret, $response->json('otpauth_uri'));

        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    /**
     * 登録を開始しただけでは MFA は有効にならない。
     * ここが逆（保存＝有効化）だと、認証アプリへの登録に失敗したユーザーが締め出される。
     */
    public function test_enabling_does_not_activate_two_factor_until_confirmed(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor', ['password' => self::PASSWORD])
            ->assertCreated();

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    /**
     * DB に入っている値が平文でないことを、カラムを直接読んで確かめる。
     * Eloquent 経由では encrypted キャストが復号するので違いが見えない。
     */
    public function test_the_secret_is_stored_encrypted(): void
    {
        $user = $this->user();

        $secret = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor', ['password' => self::PASSWORD])
            ->json('secret');

        $stored = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));
    }

    public function test_confirming_with_a_valid_code_enables_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->twoFactorPending()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => $this->currentCode($user)])
            ->assertOk();

        $codes = $response->json('recovery_codes');

        $this->assertCount(RecoveryCode::COUNT, $codes);
        $this->assertSame($codes, array_unique($codes));

        $user->refresh();

        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertSame($codes, $user->two_factor_recovery_codes);
    }

    public function test_confirming_with_an_invalid_code_does_not_enable_two_factor(): void
    {
        $user = User::factory()->twoFactorPending()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $user->refresh();

        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_confirming_rejects_a_code_that_is_not_six_digits(): void
    {
        $user = User::factory()->twoFactorPending()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    /**
     * 手順を飛ばした呼び出しは 409。入力の誤りではないので 422 とは区別する。
     */
    public function test_confirming_without_starting_the_setup_conflicts(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => '123456'])
            ->assertConflict();
    }

    /**
     * 有効な MFA を黙って別の鍵に差し替えさせない。
     * 差し替えられると、手元の認証アプリが使えないことに気づくのは次のログイン時になる。
     */
    public function test_enabling_again_while_already_enabled_conflicts(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create(['password' => Hash::make(self::PASSWORD)]);
        $secret = $user->two_factor_secret;

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor', ['password' => self::PASSWORD])
            ->assertConflict();

        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    /**
     * 確認待ちのまま放置した登録はやり直せる。QR を読み込む前に画面を閉じたユーザーが
     * 先へ進めなくなるのを避けるため、こちらは 409 にしない。
     */
    public function test_enabling_again_while_pending_replaces_the_secret(): void
    {
        $user = User::factory()->twoFactorPending()->create(['password' => Hash::make(self::PASSWORD)]);
        $before = $user->two_factor_secret;

        $after = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor', ['password' => self::PASSWORD])
            ->assertCreated()
            ->json('secret');

        $this->assertNotSame($before, $after);
        $this->assertSame($after, $user->fresh()->two_factor_secret);
    }

    public function test_regenerating_recovery_codes_replaces_the_previous_set(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create(['password' => Hash::make(self::PASSWORD)]);
        $before = $user->two_factor_recovery_codes;

        $after = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/recovery-codes', ['password' => self::PASSWORD])
            ->assertOk()
            ->json('recovery_codes');

        $this->assertCount(RecoveryCode::COUNT, $after);
        $this->assertEmpty(array_intersect($before, $after));
        $this->assertSame($after, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_regenerating_recovery_codes_requires_the_current_password(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create(['password' => Hash::make(self::PASSWORD)]);
        $before = $user->two_factor_recovery_codes;

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/recovery-codes', ['password' => 'wrong-password-here'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame($before, $user->fresh()->two_factor_recovery_codes);
    }

    /**
     * トークンを盗んだ相手が MFA を解除できてはならない。所持要素を外す操作なので、
     * 知識要素（現在のパスワード）で再確認する。
     */
    public function test_disabling_requires_the_current_password(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create(['password' => Hash::make(self::PASSWORD)]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/auth/two-factor', ['password' => 'wrong-password-here'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_disabling_clears_the_secret_and_the_recovery_codes(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create(['password' => Hash::make(self::PASSWORD)]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/auth/two-factor', ['password' => self::PASSWORD])
            ->assertNoContent();

        $user->refresh();

        // 3カラムすべてを消す。シークレットが残ると、次の登録で同じ鍵が使い回される。
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    /**
     * /me が MFA の状態を返すこと。シークレットは出さない。
     */
    public function test_me_reports_whether_two_factor_is_enabled(): void
    {
        $enabled = User::factory()->twoFactorConfirmed()->create();
        $pending = User::factory()->twoFactorPending()->create();

        $this->actingAs($enabled, 'sanctum')->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', true);

        $this->app['auth']->forgetGuards();

        // 確認待ちは「無効」として返る
        $this->actingAs($pending, 'sanctum')->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', false);
    }
}
