<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API が返すユーザーの形を1か所で定義する。
 *
 * モデルを直接返すと DB のカラム構成がそのまま API の仕様になり、カラムを追加した
 * 瞬間にそれが応答へ現れる。モデルの #[Hidden] は「隠すものを挙げる」拒否リストで、
 * 追加のたびに書き足す必要があるのに対し、Resource は「出すものを挙げる」許可リスト。
 * 書いていない項目は出ないため、two_factor_secret のような値を取りこぼさない。
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,

            // シークレットではなく「有効かどうか」だけを出す。設定画面の表示と、
            // 解除ボタンを出すかどうかの判断に必要な情報はこれで足りる。
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),

            'created_at' => $this->created_at,
        ];
    }
}
