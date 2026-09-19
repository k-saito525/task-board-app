<?php

namespace App\Actions\TwoFactor;

use App\Models\User;
use App\Support\RecoveryCode;

/**
 * リカバリコードを作り直す。古いコードはすべて無効になる。
 *
 * 平文を渡せるのは発行直後の応答だけなので、コードを紛失したユーザーには再発行しか
 * 手段がない。使い切ったとき・控えを漏らしたときの復旧手段でもある。
 */
final class RegenerateRecoveryCodes
{
    /**
     * @return list<string>
     */
    public function __invoke(User $user): array
    {
        $user->two_factor_recovery_codes = RecoveryCode::generateSet();
        $user->save();

        return $user->two_factor_recovery_codes;
    }
}
