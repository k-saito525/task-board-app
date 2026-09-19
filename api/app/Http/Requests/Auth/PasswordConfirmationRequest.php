<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * MFA の設定変更（有効化・解除・リカバリコードの再発行）で現在のパスワードを要求する。
 *
 * トークンだけで通せてしまうと、トークンを盗んだ相手が MFA を解除して所持要素を無効化
 * できる。MFA を足す目的そのものが崩れるため、設定変更は知識要素で再確認する。
 * 追加のパスワード入力を求める操作は Laravel も password.confirm ミドルウェアとして
 * 持っており、トークン認証の API ではその判定を各リクエストに置き換える形になる。
 */
class PasswordConfirmationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // current_password は認証中のユーザーのハッシュと照合する（Hash::check 相当）。
            // ガードを明示するのは、既定ガードに依存させないため。auth:sanctum は認証が
            // 通ったときに shouldUse('sanctum') を呼ぶが、それに頼ると認証の設定を
            // 変えたときに照合先が静かにずれる。
            'password' => ['required', 'string', 'current_password:sanctum'],

            // Password::defaults() は適用しない。ログインと同じ理由で、既存ユーザーの
            // 正しいパスワードを形式で弾いてはならない。
        ];
    }
}
