<?php

namespace App\Actions\TwoFactor;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * MFA の登録を開始する。シークレットを発行して保存するだけで、まだ有効化はしない。
 *
 * 有効化は ConfirmTwoFactorAuthentication（認証アプリが出したコードを1回通せたことの確認）
 * まで待つ。
 */
final class EnableTwoFactorAuthentication
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * @return string 認証アプリに渡すシークレット（Base32）
     */
    public function __invoke(User $user): string
    {
        // 32文字の Base32 = 160ビット。RFC 6238 が HMAC-SHA1 の鍵長として推奨する値。
        $secret = $this->google2fa->generateSecretKey(32);

        // 属性への直接代入。#[Fillable] に two_factor_* を足して fill() を通すのは、
        // リクエストの入力がそのままシークレットに入る余地を作るので避ける。
        $user->two_factor_secret = $secret;
        $user->save();

        return $secret;
    }
}
