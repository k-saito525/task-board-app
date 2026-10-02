<?php

namespace App\Http\Requests\Task;

use App\Http\Requests\Task\Concerns\TaskRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 権限の確認（authorize）は置かない。タスクの作成はメンバー全員ができ、メンバーで
 * あることは {project} のバインディングが保証している。
 */
class StoreTaskRequest extends FormRequest
{
    use TaskRules;

    /**
     * status を省くと DB の既定値（todo）になる。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->taskRules();
    }
}
