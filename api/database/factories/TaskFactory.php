<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * 既定値は固定する。ランダムにすると「todo だけ絞り込めるか」のような
     * テストが実行ごとに結果が変わる（フレーキーテスト）になるため。
     * 変えたい値は state か create() の引数で明示する。
     */
    public function definition(): array
    {
        return [
            // project_id を渡さなかった場合は、親のプロジェクトごと作られる
            'project_id' => Project::factory(),
            'assignee_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TaskStatus::Todo,
            'due_date' => null,
        ];
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(['assignee_id' => $user->id]);
    }
}
