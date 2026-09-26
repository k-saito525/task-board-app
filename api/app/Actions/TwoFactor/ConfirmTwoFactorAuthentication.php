<?php

namespace App\Actions\TwoFactor;

use App\Models\User;
use App\Support\RecoveryCode;
use Illuminate\Validation\ValidationException;

/**
 * 認証アプリが出したコードを1回検証し、通ったら MFA を有効化する。
 *
 * 有効化とリカバリコードの発行を同じ1回の保存にまとめている。「有効だがリカバリコードが
 * 無い」状態を作らないため。認証アプリを失えばその状態は復旧不能になる。
 */
final class ConfirmTwoFactorAuthentication
{
    public function __construct(private readonly ConsumeTotpCode $consumeTotpCode) {}

    /**
     * @return list<string> 発行したリカバリコード。平文を渡す機会はこの応答だけ
     *
     * @throws ValidationException コードが一致しないとき
     */
    public function __invoke(User $user, string $code): array
    {
        // ログインと同じく使用済みとして記録する。確認に使ったコードを、有効化の直後に
        // ログインのチャレンジでもう一度通させないため。
        if (! ($this->consumeTotpCode)($user, $code)) {
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
