<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // 閲覧しているユーザーのロール。フロントが編集・削除ボタンを出すかの判断に
            // 使う。ボタンを隠すのは表示の都合で、可否は ProjectPolicy が判定する。
            //
            // pivot は $user->projects() 経由で取得したときにだけ付く。プロジェクトは
            // 必ずこの関連から取る決まりなので、pivot が無ければその決まりが破られている。
            'role' => $this->pivot->role,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
