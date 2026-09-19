<?php

namespace App\Http\Controllers\Api;

use App\Actions\TwoFactor\ConfirmTwoFactorAuthentication;
use App\Actions\TwoFactor\DisableTwoFactorAuthentication;
use App\Actions\TwoFactor\EnableTwoFactorAuthentication;
use App\Actions\TwoFactor\RegenerateRecoveryCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\PasswordConfirmationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PragmaRX\Google2FA\Google2FA;

/**
 * MFA（TOTP）の登録・確認・解除。すべて自分自身に対する操作なので Policy は使わない。
 *
 * 状態は two_factor_secret と two_factor_confirmed_at の組み合わせで3つある。
 *
 *   未登録   secret = null                       → store で登録を開始できる
 *   確認待ち secret あり / confirmed_at = null   → MFA は無効。confirm で有効化する
 *   有効     secret あり / confirmed_at あり     → destroy で解除する
 *
 * 手順を飛ばした呼び出しには 409 を返す。入力の誤り（422）ではなく状態の不一致であり、
 * 同じリクエストを送り直しても解決しないため。
 */
class TwoFactorAuthenticationController extends Controller
{
    /**
     * 登録を開始する。シークレットを発行するが、この時点では MFA はまだ無効。
     */
    public function store(
        PasswordConfirmationRequest $request,
        EnableTwoFactorAuthentication $enable,
        Google2FA $google2fa,
    ): JsonResponse {
        $user = $request->user();

        // 有効な MFA を上書きさせない。黙って新しい鍵に差し替えると、手元の認証アプリが
        // 使えなくなったことにユーザーが気づくのは次のログイン時になる。作り直すなら
        // 一度解除させる。
        abort_if(
            $user->hasTwoFactorEnabled(),
            Response::HTTP_CONFLICT,
            __('Two factor authentication is already enabled.'),
        );

        $secret = $enable($user);

        return response()->json([
            // QR 画像は作らず otpauth:// の URI だけを返す。画像化はフロントの責務
            // （表示の都合で解像度や配色が変わる一方、URI は変わらない）。
            // 手入力したいユーザー向けに secret も返す。どちらも同じ鍵を運ぶ。
            'secret' => $secret,
            'otpauth_uri' => $google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret,
            ),
        ], Response::HTTP_CREATED);
    }

    /**
     * 認証アプリのコードを確認して有効化する。リカバリコードはここで初めて発行する。
     */
    public function confirm(
        ConfirmTwoFactorRequest $request,
        ConfirmTwoFactorAuthentication $confirm,
    ): JsonResponse {
        $user = $request->user();

        abort_if(
            $user->two_factor_secret === null,
            Response::HTTP_CONFLICT,
            __('Two factor authentication has not been started.'),
        );

        return response()->json([
            'recovery_codes' => $confirm($user, $request->validated('code')),
        ]);
    }

    /**
     * リカバリコードを作り直す。古いコードは無効になる。
     */
    public function recoveryCodes(
        PasswordConfirmationRequest $request,
        RegenerateRecoveryCodes $regenerate,
    ): JsonResponse {
        $user = $request->user();

        // MFA が無効なユーザーにリカバリコードを持たせる意味はない。
        abort_unless(
            $user->hasTwoFactorEnabled(),
            Response::HTTP_CONFLICT,
            __('Two factor authentication is not enabled.'),
        );

        return response()->json(['recovery_codes' => $regenerate($user)]);
    }

    /**
     * MFA を解除する。確認待ちの登録を取り消す用途も兼ねる。
     */
    public function destroy(
        PasswordConfirmationRequest $request,
        DisableTwoFactorAuthentication $disable,
    ): Response {
        // 未登録のユーザーが叩いても 204 を返す。結果の状態（MFA 無効）は同じで、
        // 409 にしても呼び出し側にできることがない。
        $disable($request->user());

        return response()->noContent();
    }
}
