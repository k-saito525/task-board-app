<?php

namespace App\Actions\TwoFactor;

use App\Models\User;

/**
 * リカバリコードを1つ消し込む。1コードにつき1回しか使えない。
 *
 * 保存値は暗号化されているので SQL で探せない。復号した配列を PHP 側で照合する。
 * 比較は hash_equals で行い、何文字目まで一致したかが応答時間に出ないようにする。
 */
final class ConsumeRecoveryCode
{
    public function __invoke(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes;

        $match = collect($codes)->first(fn (string $stored): bool => hash_equals($stored, $code));

        if ($match === null) {
            return false;
        }

        $user->two_factor_recovery_codes = array_values(array_diff($codes, [$match]));
        $user->save();

        return true;
    }
}
