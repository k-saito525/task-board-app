<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ヘルスチェック
|--------------------------------------------------------------------------
| Laravel 標準の /up は「アプリがブートできたか」しか見ないため、DB が落ちて
| いても 200 を返す。ここでは実際に接続を1回試して、DB まで含めた生死を返す。
| デプロイ時はオーケストレーター（ECS / k8s / Fly.io 等）がこの URL を叩く。
*/
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        return response()->json(['status' => 'error', 'database' => 'down'], 503);
    }

    return ['status' => 'ok', 'database' => 'up'];
});

/*
|--------------------------------------------------------------------------
| 認証
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        /*
        | MFA（TOTP）
        |
        | 登録開始 → コード確認で有効化 → 解除。apiResource にしないのは、ユーザーごとに
        | 1つしかなく id で指すものがないため（コレクションではない）。
        | 有効化・解除・リカバリコードの再発行は現在のパスワードを要求する。
        */
        Route::prefix('two-factor')->group(function () {
            Route::post('/', [TwoFactorAuthenticationController::class, 'store']);
            Route::post('confirm', [TwoFactorAuthenticationController::class, 'confirm']);
            Route::post('recovery-codes', [TwoFactorAuthenticationController::class, 'recoveryCodes']);
            Route::delete('/', [TwoFactorAuthenticationController::class, 'destroy']);
        });
    });
});
