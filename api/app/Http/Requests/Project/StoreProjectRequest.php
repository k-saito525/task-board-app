<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // カラムは text で長さの上限が無い。上限を置かないと、1件で巨大な本文を
            // 送りつけて DB と応答を膨らませられる。
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
