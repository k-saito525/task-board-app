<?php

namespace App\Actions\TwoFactor;

use App\Models\User;
use App\Support\RecoveryCode;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * 認証アプリが出したコードを1回検証し、通ったら MFA を有効化する。
 *
 * 有効化とリカバリコードの発行を同じ1回の保存にまとめている。「有効だがリカバリコードが
 * 無い」状態を作らないため。認証アプリを失えばその状態は復旧不能になる。
 */
final class ConfirmTwoFactorAuthentication
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * @return list<string> 発行したリカバリコード。平文を渡す機会はこの応答だけ
     *
     * @throws ValidationException コードが一致しないとき
     */
    public function __invoke(User $user, string $code): array
    {
        // verifyKey は現在のステップと前後1ステップ（既定の window = 1、計±30秒）を
        // 試す。端末の時刻ずれで正しいコードが弾かれるのを防ぐための窓で、広げるほど
        // 同時に有効なコードが増える。比較は hash_equals なので総当たりの手がかりに
        // なる時間差は出ない。
        if (! $this->google2fa->verifyKey($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = RecoveryCode::generateSet();
        $user->save();

        return $user->two_factor_recovery_codes;
    }
}
