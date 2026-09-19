<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // unique:users,email は SELECT 1 FROM users WHERE email = ? を発行する。
            // users.email の UNIQUE 制約と二重になるが、こちらは 422 で理由を返すためのもの。
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // confirmed は password_confirmation との一致を検証する
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
