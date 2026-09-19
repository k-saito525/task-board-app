<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            // ここでは形式だけ見る。Password::defaults() は登録時のポリシーであり、
            // ログインに適用すると「ポリシー強化前に登録した既存ユーザーが
            // 正しいパスワードを入力しても弾かれる」ことになる。
            'password' => ['required', 'string'],
        ];
    }
}
