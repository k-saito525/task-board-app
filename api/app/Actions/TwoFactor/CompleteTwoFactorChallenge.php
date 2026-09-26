<?php

namespace App\Actions\TwoFactor;

use App\Models\User;
use App\Support\TwoFactorChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ログインの2段階目。券とコード（TOTP かリカバリコード）を検証し、通ったら券を消す。
 * 本トークンの発行は呼び出し側（AuthController）が行う。
 *
 * ユーザーの行をロックしてから検証する。ロックが無いと、同じコードを載せた2つの
 * リクエストが同時に届いたとき、両方が「まだ使われていない」を読んでから保存し、
 * 2本ともトークンを受け取れてしまう（使い回し防止の穴になる）。ロックでその2本を
 * 順番に並べれば、後の1本は先の1本が保存した結果を読む。
 *
 *   SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE
 */
final class CompleteTwoFactorChallenge
{
    public function __construct(
        private readonly ConsumeTotpCode $consumeTotpCode,
        private readonly ConsumeRecoveryCode $consumeRecoveryCode,
    ) {}

    /**
     * code と recoveryCode はどちらか片方だけが来る（TwoFactorChallengeRequest で保証）。
     *
     * @throws ValidationException 券かコードが無効なとき
     */
    public function __invoke(string $challenge, ?string $code, ?string $recoveryCode): User
    {
        // ロックする行を決めるために、まず券の持ち主を引く
        $userId = TwoFactorChallenge::userId($challenge) ?? throw $this->invalidChallenge();

        return DB::transaction(function () use ($userId, $challenge, $code, $recoveryCode): User {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();

            // ロックを待つ間に、同じ券が別のリクエストで使われたかもしれない。
            // また、券の発行後に別の端末から MFA を解除された場合も券は無効にする。
            if (TwoFactorChallenge::userId($challenge) === null || ! $user->hasTwoFactorEnabled()) {
                throw $this->invalidChallenge();
            }

            $passed = $code !== null
                ? ($this->consumeTotpCode)($user, $code)
                : ($this->consumeRecoveryCode)($user, $recoveryCode);

            if (! $passed) {
                throw ValidationException::withMessages([
                    $code !== null ? 'code' : 'recovery_code' => __('The provided two factor authentication code was invalid.'),
                ]);
            }

            TwoFactorChallenge::forget($challenge);

            return $user;
        });
    }

    private function invalidChallenge(): ValidationException
    {
        return ValidationException::withMessages([
            'challenge' => __('The two factor challenge has expired. Please log in again.'),
        ]);
    }
}
