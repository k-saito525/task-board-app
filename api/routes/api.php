<?php

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
