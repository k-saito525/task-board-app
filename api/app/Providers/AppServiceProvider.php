<?php

namespace App\Providers;

use App\Support\TwoFactorChallenge;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();

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

    /**
     * レート制限を定義する。ここと bootstrap/app.php の throttleApi() は対で、
     * どちらか片方だけでは機能しない（片方だけだと全リクエストが 500 になる）。
     *
     * 3段構えにする。守る対象が違うため上限値も分ける。
     *
     *   api             … 全体の保護。素朴な連打や暴走したクライアントを止める
     *   auth            … パスワードを検証する場所（登録・ログイン・MFA の設定変更）
     *   two-factor-code … 6桁のコードを検証する場所
     *
     * 認証済みのルートではユーザー単位、未認証のルートでは IP 単位で数える。
     * ミドルウェアの優先順位は auth が throttle より先なので、認証が必要なルートでは
     * リミッターが呼ばれる時点でユーザーが解決済み（未認証は 401 で、制限の枠を
     * 消費しない）。IP 単位だけにすると、同じ IP を共有する利用者（NAT・社内網）が
     * 互いの枠を食い合う。
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($this->rateLimitKey($request)));

        // パスワードを当てにくる場所。通常の利用で毎分10回に達することはない。
        //
        // ただし毎分の制限だけで総当たりを止められるわけではない（10/分 =
        // 14,400/日）。パスワードを守っているのは主に12文字以上と漏洩リストとの
        // 照合であって、ここは速度を落として「気づく時間」を稼ぐ層にすぎない。
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)
            ->by($this->rateLimitKey($request)));

        // コードは6桁 = 100万通りしかなく、パスワードのような鍵空間の広さに頼れない。
        // 毎分の上限だけでは1日に7,200回試せてしまうため、日単位の上限を重ねる。
        // 30回/日なら当たる確率は1日あたり約10万分の1（前後1ステップの許容を含む）。
        //
        // 2つの上限が同じ by() を持ってもカウンターは混ざらない。RateLimiter::limiter()
        // がキーの重複を検出し、上限値と期間を含むキー（Limit::fallbackKey()）に
        // 差し替える。手で接頭辞を付ける必要はない。
        RateLimiter::for('two-factor-code', fn (Request $request) => [
            Limit::perMinute(5)->by($this->twoFactorCodeKey($request)),
            Limit::perDay(30)->by($this->twoFactorCodeKey($request)),
        ]);
    }

    /**
     * 6桁コードの試行を数える単位。ログインの2段階目はまだトークンが無く未認証だが、
     * 引換券から持ち主が分かるので、そのユーザーで数える。
     *
     * IP で数えると、IP を次々に変えられる相手には IP ごとに30回/日の枠が生まれ、
     * 日の上限が意味を失う。2段階目に来ている時点で相手はパスワードを知っているので、
     * 守るべきはユーザーごとの試行回数。confirm（認証済み）と同じキーになるので、
     * 両方を合わせて1ユーザー30回/日になる。
     *
     * 代償として、パスワードを知る相手はわざと間違え続けて本人のコード入力を1日
     * 止められる。その時点でパスワードは漏れており、変更が必要な状況ではある。
     *
     * 券が無効ならユーザーが分からないので IP で数える。無効な券ではコードの検証に
     * 進めない（CompleteTwoFactorChallenge が先に弾く）。
     */
    private function twoFactorCodeKey(Request $request): string
    {
        $challenge = $request->input('challenge');

        $userId = $request->user()?->id
            ?? (is_string($challenge) ? TwoFactorChallenge::userId($challenge) : null);

        return (string) ($userId ?? $request->ip());
    }

    /**
     * 制限を数える単位。認証済みならユーザー、未認証なら IP。
     */
    private function rateLimitKey(Request $request): string
    {
        return (string) ($request->user()?->id ?? $request->ip());
    }
}
