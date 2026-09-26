<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 最後に通った TOTP のステップ（30秒ごとの通し番号）を保存する。
 *
 * TOTP の検証は時刻ずれを許すため前後1ステップを受け入れ、同じコードが最大90秒間
 * 通る。通ったステップを覚えておき、それ以前のステップのコードを拒否することで
 * 「同じコードは二度通らない」を実現する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('two_factor_last_used_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_used_step');
        });
    }
};
