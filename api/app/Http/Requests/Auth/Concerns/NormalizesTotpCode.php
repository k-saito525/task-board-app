<?php

namespace App\Http\Requests\Auth\Concerns;

/**
 * 6桁コードの空白を取り除いてから検証する。
 *
 * 認証アプリはコードを「751 790」のように3桁ずつ区切って表示するので、そのまま
 * 書き写した入力が実際に届く。起こり得ない入力へのガードではなく、来る入力の正規化。
 * 全角空白も含めるため u 修飾子を付ける（\s は u 無しだと ASCII の空白しか拾わない）。
 *
 * code フィールドだけが対象。リカバリコードはハイフンを含めて1つの値なので手を付けない。
 */
trait NormalizesTotpCode
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => preg_replace('/\s+/u', '', $this->input('code'))]);
        }
    }
}
