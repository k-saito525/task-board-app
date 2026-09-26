<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesTotpCode;
use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    use NormalizesTotpCode;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string'],
            // 認証アプリのコードとリカバリコードは、どちらか片方だけを受け取る。
            // 両方来たときにどちらを優先するかを決めずに済むよう、組み合わせごと弾く。
            'code' => ['nullable', 'required_without:recovery_code', 'prohibits:recovery_code', 'digits:6'],
            'recovery_code' => ['nullable', 'required_without:code', 'string'],
        ];
    }
}
