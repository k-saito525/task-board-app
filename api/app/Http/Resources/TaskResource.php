<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            // date キャストの値をそのまま出すと "2026-10-05T00:00:00.000000Z" になり、
            // 受け取った側のタイムゾーンで前日にずれうる。日付だけの値として出す。
            'due_date' => $this->due_date?->toDateString(),
            // カードに担当者の名前を出すため埋め込む（GitHub の Issue も assignee を埋め込む）。
            // メールアドレスはメンバー一覧にあるので、ここには出さない。
            'assignee' => $this->assignee === null ? null : [
                'user_id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
