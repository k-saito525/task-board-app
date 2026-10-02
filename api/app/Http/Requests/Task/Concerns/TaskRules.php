<?php

namespace App\Http\Requests\Task\Concerns;

use App\Enums\TaskStatus;
use App\Models\Project;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * 作成と更新で共通の項目ルール。更新側は各項目の先頭に sometimes を足して使う。
 */
trait TaskRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function taskRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => [Rule::enum(TaskStatus::class)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            // 担当者はこのプロジェクトのメンバーに限る。users に存在するだけでは足りない。
            // 部外者を担当にできると、そのカードに部外者の名前が出続ける。
            //
            //   SELECT count(*) FROM project_members WHERE user_id = ? AND project_id = ?
            //
            // プロジェクトの条件はクロージャに包み、検証の時点で初めて読む。Scramble は
            // OpenAPI を作るときにリクエストの外で rules() を呼ぶので、ここで
            // $this->route('project') を直接読むと（ルートが無く null）失敗する。
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('project_members', 'user_id')->where(
                    fn (Builder $query) => $query->where('project_id', $this->project()->id),
                ),
            ],
        ];
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
