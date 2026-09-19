<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * リカバリコード（認証アプリを失ったときの代替手段）の生成。
 *
 * 1コードは英数20文字。Str::random() は random_bytes()（CSPRNG）由来なので
 * 予測できない。62種類から20文字なので約119ビットで、総当たりは現実的でない。
 *
 * ハイフンで2つに割るのは人間が読み上げ・書き写しをしやすくするため。検証時は
 * 入力をそのまま比較するので、区切りも含めて1つのコードとして扱う。
 */
final class RecoveryCode
{
    /** 1回の生成で発行する本数 */
    public const COUNT = 8;

    /**
     * @return list<string>
     */
    public static function generateSet(): array
    {
        return array_map(
            fn (): string => Str::random(10).'-'.Str::random(10),
            range(1, self::COUNT),
        );
    }
}
