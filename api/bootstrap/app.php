<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // API 専用のため web ルートは持たない
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API 全体にレート制限をかける。Laravel 11 以降、これを呼ばない限り
        // throttle ミドルウェアは api グループに入らない（= 制限が一切かからない）。
        // 上限の内容は AppServiceProvider の名前付きリミッター 'api' 側で定義する。
        // 対の定義が無いと MissingRateLimiterException で全リクエストが落ちるので、
        // この呼び出しとリミッターの定義は必ずセットで置く。
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
