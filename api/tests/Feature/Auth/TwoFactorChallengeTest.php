<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\TwoFactorChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * MFA が有効なユーザーのログイン（2段階）を固定する。
 *
 * 見るべきは「コードを入れればログインできること」よりも、
 *   - パスワードだけでは本トークンが出ないこと（引換券では API を使えないこと）
 *   - 券もコードも1回しか使えないこと
 * の2点。ここが緩むと、パスワードさえ知っていれば MFA を飛ばせる。
 *
 * google2fa は PHP の time() でステップを決めるので、$this->travel() ではコードの
 * 計算は動かない（券の有効期限はキャッシュ経由で Carbon を見るので動く）。
 * 次のステップのコードが必要なときは oathTotp() でステップを指定して作る。
 */
class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function user(): User
    {
        return User::factory()->twoFactorConfirmed()->create(['password' => Hash::make(self::PASSWORD)]);
    }

    /** 1段階目を通して引換券を受け取る */
    private function challengeFor(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('challenge');
    }

    /** 認証アプリが今表示しているであろうコードを作る */
    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
    }

    /** 次のステップ（30秒後）のコード。前後1ステップの許容に入るので今でも通る */
    private function nextCode(User $user): string
    {
        $google2fa = app(Google2FA::class);

        return $google2fa->oathTotp($user->two_factor_secret, $google2fa->getTimestamp() + 1);
    }

    public function test_login_with_two_factor_enabled_returns_a_challenge_instead_of_a_token(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('two_factor', true)
            ->assertJsonStructure(['challenge'])
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * 確認待ち（登録を始めただけ）のユーザーは MFA 無効として扱う。
     * ここでチャレンジを返すと、認証アプリへの登録に失敗したユーザーが締め出される。
     */
    public function test_login_while_two_factor_is_pending_returns_a_token(): void
    {
        $user = User::factory()->twoFactorPending()->create(['password' => Hash::make(self::PASSWORD)]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonMissingPath('challenge');
    }

    public function test_the_challenge_cannot_be_used_as_a_token(): void
    {
        $challenge = $this->challengeFor($this->user());

        $this->withToken($challenge)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_valid_code_completes_the_login(): void
    {
        $user = $this->user();
        $challenge = $this->challengeFor($user);

        $token = $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $challenge,
            'code' => $this->currentCode($user),
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    /** 認証アプリは「751 790」のように区切って表示する。書き写したままで通ること */
    public function test_a_code_with_spaces_is_accepted(): void
    {
        $user = $this->user();
        $code = $this->currentCode($user);

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => substr($code, 0, 3).' '.substr($code, 3),
        ])->assertOk();
    }

    public function test_an_invalid_code_is_rejected_without_issuing_a_token(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_unknown_challenge_is_rejected(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => 'not-a-challenge-that-was-issued',
            'code' => $this->currentCode($user),
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }

    public function test_a_challenge_cannot_be_used_twice(): void
    {
        $user = $this->user();
        $challenge = $this->challengeFor($user);

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $challenge,
            'code' => $this->currentCode($user),
        ])->assertOk();

        // コードは新しいもの（それ単体なら通る）でも、券が使用済みなので通らない
        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $challenge,
            'code' => $this->nextCode($user),
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_an_expired_challenge_is_rejected(): void
    {
        $user = $this->user();
        $challenge = $this->challengeFor($user);

        $this->travel(TwoFactorChallenge::TTL_MINUTES + 1)->minutes();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $challenge,
            'code' => $this->currentCode($user),
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }

    /** 券を受け取った後に別の端末で MFA を解除された場合、その券ではログインさせない */
    public function test_a_challenge_is_rejected_once_two_factor_is_disabled(): void
    {
        $user = $this->user();
        $challenge = $this->challengeFor($user);
        $code = $this->currentCode($user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $challenge,
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }

    /**
     * 同じコードは前後1ステップの許容で最大90秒間通る。券を取り直しても、
     * 一度通ったコードはもう通らないこと。
     */
    public function test_the_same_code_cannot_be_used_twice(): void
    {
        $user = $this->user();
        $code = $this->currentCode($user);

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => $code,
        ])->assertOk();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    /** 使い回しを塞いだ結果、次のステップのコードまで拒否していないこと */
    public function test_a_newer_code_is_accepted_after_one_is_used(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => $this->currentCode($user),
        ])->assertOk();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => $this->nextCode($user),
        ])->assertOk();
    }

    /** 有効化の確認に使ったコードも使用済みになる。直後のログインに流用させない */
    public function test_the_code_used_to_confirm_cannot_be_reused_to_log_in(): void
    {
        $user = User::factory()->twoFactorPending()->create(['password' => Hash::make(self::PASSWORD)]);
        $code = $this->currentCode($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/two-factor/confirm', ['code' => $code])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_recovery_code_completes_the_login_only_once(): void
    {
        $user = $this->user();
        $recoveryCode = $user->two_factor_recovery_codes[0];

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'recovery_code' => $recoveryCode,
        ])->assertOk()->assertJsonStructure(['token']);

        $remaining = $user->fresh()->two_factor_recovery_codes;
        $this->assertCount(7, $remaining);
        $this->assertNotContains($recoveryCode, $remaining);

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'recovery_code' => $recoveryCode,
        ])->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
    }

    public function test_code_and_recovery_code_cannot_be_sent_together(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($user),
            'code' => $this->currentCode($user),
            'recovery_code' => $user->two_factor_recovery_codes[0],
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_either_code_or_recovery_code_is_required(): void
    {
        $this->postJson('/api/auth/two-factor-challenge', [
            'challenge' => $this->challengeFor($this->user()),
        ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
    }
}
