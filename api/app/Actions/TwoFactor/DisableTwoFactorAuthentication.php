<?php

namespace App\Actions\TwoFactor;

use App\Models\User;

/**
 * MFA を解除する。シークレット・リカバリコード・確認時刻をまとめて消す。
 *
 * confirmed_at だけを null にする実装にはしない。シークレットが残っていると、次に
 * 登録を開始したときに以前と同じ鍵が使い回され、解除の前後で認証アプリの登録が
 * そのまま通ってしまう。解除は「鍵を捨てる」操作として扱う。
 */
final class DisableTwoFactorAuthentication
{
    public function __invoke(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_used_step = null;
        $user->save();
    }
}
