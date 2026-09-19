<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA（TOTP）用のカラムを users に追加する。
 *
 *   two_factor_secret          … 認証アプリと共有する鍵。encrypted キャストで暗号化して保存する
 *   two_factor_recovery_codes  … 認証アプリを失ったときに使う使い捨てコード
 *   two_factor_confirmed_at    … 実際にコードを1回通せた時刻。null の間は MFA は無効
 *
 * confirmed_at を別に持つのは、シークレットを保存した時点ではまだ有効化しないため。
 * 保存＝有効化にすると、認証アプリへの登録に失敗したユーザーが次のログインで
 * 自分のアカウントから締め出される。
 *
 * 型が string ではなく text なのは暗号化のため。encrypted キャストの保存値は
 * iv / value / mac を持つ JSON を Base64 化したもので、元の長さの数倍になる。
 * リカバリコード8件の配列は 255 文字に収まらない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
