<?php

namespace App\Actions\TwoFactor;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * 認証アプリのコードを検証し、通ったらそのステップを使用済みにする。
 *
 * verifyKey は時刻ずれを許すため前後1ステップ（既定の window = 1）を受け入れ、同じ
 * コードが最大90秒間通る。盗み見たコードや、中継型のフィッシングで横取りしたコードを
 * その間に使い回されないよう、最後に通ったステップより新しいものだけを受け入れる。
 * 比較は hash_equals なので総当たりの手がかりになる時間差は出ない。
 */
final class ConsumeTotpCode
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function __invoke(User $user, string $code): bool
    {
        // 第3引数が null だと、一致したときにステップではなく true を返す
        // （Google2FA::findValidOTP）。未使用のユーザーでもステップを受け取れるよう 0 を渡す。
        $step = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            $user->two_factor_last_used_step ?? 0,
        );

        if ($step === false) {
            return false;
        }

        $user->two_factor_last_used_step = $step;
        $user->save();

        return true;
    }
}
