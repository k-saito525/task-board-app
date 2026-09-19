<?php

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * 既定値は固定する。optional() のようにランダムで null になる値を混ぜると、
     * テストが実行ごとに結果の変わるフレーキーテストになるため。
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * 指定したユーザーを owner として参加させた状態で作る。
     * プロジェクトは owner を必ず1人持つため、テストではこの形が既定になる。
     *
     *   Project::factory()->ownedBy($user)->create()
     */
    public function ownedBy(User $user): static
    {
        return $this->hasMember($user, ProjectRole::Owner);
    }

    /**
     * 参加者を1人追加した状態で作る。
     *
     *   INSERT INTO project_members (project_id, user_id, role, ...) VALUES (?, ?, ?, ...)
     */
    public function hasMember(User $user, ProjectRole $role = ProjectRole::Member): static
    {
        return $this->afterCreating(function (Project $project) use ($user, $role) {
            $project->members()->create([
                'user_id' => $user->id,
                'role' => $role,
            ]);
        });
    }
}
