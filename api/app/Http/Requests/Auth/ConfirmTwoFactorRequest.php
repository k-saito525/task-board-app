<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // TOTP は6桁の数字。digits は「数字以外を含まない」かつ「桁数が一致」を
            // 文字列として見るので、先頭が 0 のコード（"012345"）も通る。
            // integer や numeric を付けると先頭の 0 が落ちるため付けない。
            'code' => ['required', 'digits:6'],
        ];
    }
}
