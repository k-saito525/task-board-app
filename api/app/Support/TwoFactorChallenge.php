<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ログインの2段階目で使う引換券（チャレンジ）。
 *
 * パスワードは通ったがコードはまだ、という途中の状態を表す。推測できない ID を
 * クライアントに渡し、「この ID = このユーザー」という控えをキャッシュに置く。
 * Fortify がセッションに login.id を置くのと同じ考え方を、Cookie を使わない API 向けに
 * したもの。控えがサーバーにあるので、使い終わった券はその場で無効にできる。
 *
 * 券そのものは本トークンではない。これで API は使えず、使えるのはチャレンジの
 * エンドポイントだけ。
 */
final class TwoFactorChallenge
{
    /** 有効期間（分）。認証アプリを開いてコードを打つのに足りる長さにとどめる */
    public const TTL_MINUTES = 5;

    /**
     * @return string クライアントに渡す ID。40文字の英数（約238ビット）
     */
    public static function issue(User $user): string
    {
        $challenge = Str::random(40);

        Cache::put(self::key($challenge), $user->id, now()->addMinutes(self::TTL_MINUTES));

        return $challenge;
    }

    /**
     * 券の持ち主。期限切れ・使用済み・存在しない券なら null。
     */
    public static function userId(string $challenge): ?int
    {
        return Cache::get(self::key($challenge));
    }

    public static function forget(string $challenge): void
    {
        Cache::forget(self::key($challenge));
    }

    /**
     * キャッシュのキーには ID そのものではなくハッシュを使う。この環境のキャッシュは
     * cache テーブルでキーが平文のまま残るため、DB を読めるだけの相手に使える券を
     * 渡さない。Sanctum がトークンをハッシュで保存するのと同じ理由。
     */
    private static function key(string $challenge): string
    {
        return 'two-factor-challenge:'.hash('sha256', $challenge);
    }
}
