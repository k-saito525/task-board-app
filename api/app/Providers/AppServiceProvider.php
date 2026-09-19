<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configurePasswordPolicy();

        // Resource の "data" ラッパーを外す。
        //
        // ラッパーは Resource がレスポンスの最上位にあるときだけ付き、配列に入れ子に
        // すると付かない。有効のままだと同じ UserResource でも形がずれる。
        //
        //   GET  /api/auth/me       → {"data": {"id": 1, ...}}
        //   POST /api/auth/register → {"token": "...", "user": {"id": 1, ...}}
        //
        // 外すことで、ユーザーオブジェクトはどこに現れても同じ形になる。
        // 一覧のページネーションは別の仕組みなので data / links / meta を返し続ける。
        JsonResource::withoutWrapping();
    }

    /**
     * パスワードポリシーをここに集約する。登録・リセット・変更で参照がずれないよう、
     * 各 FormRequest は min:12 などを直書きせず Password::defaults() を使う。
     *
     * 文字種は強制しない。NIST SP 800-63B は文字種の強制がかえって予測可能なパターン
     * （Password1! など）を生むとして推奨を取り下げており、長さと漏洩リストとの照合を
     * 推奨している。uncompromised() は SHA-1 ハッシュの先頭5文字だけを
     * api.pwnedpasswords.com へ送り、照合はローカルで行うため平文は外部に出ない。
     *
     * テスト時は外部通信を避けて無効にする。ネットワーク障害で CI が落ちるのを防ぐ。
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(fn () => $this->app->runningUnitTests()
            ? Password::min(12)
            : Password::min(12)->uncompromised());
    }
}
