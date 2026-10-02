<?php

namespace App\Http\Requests\Task;

use App\Http\Requests\Task\Concerns\TaskRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 権限の確認（authorize）は置かない。タスクの編集・ステータスの移動はメンバー全員が
 * できる。
 */
class UpdateTaskRequest extends FormRequest
{
    use TaskRules;

    /**
     * PATCH は送った項目だけを変える。ボードのボタンでステータスを移すときは
     * {"status": "in_progress"} だけを送る（GitHub の Issue 更新、Trello のカード更新と
     * 同じく、専用の入口は作らない）。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_map(
            fn (array $rules): array => ['sometimes', ...$rules],
            $this->taskRules(),
        );
    }
}
