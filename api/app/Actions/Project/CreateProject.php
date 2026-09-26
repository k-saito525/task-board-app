<?php

namespace App\Actions\Project;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プロジェクトを作り、作成者を owner として参加させる。
 *
 * projects に owner_id は無く、所有者は project_members.role で表す。2つの INSERT を
 * 1つのトランザクションにまとめるのは、途中で失敗したときに「owner がいない
 * プロジェクト」を残さないため。そうなると誰もメンバーではないので、作成者自身にも
 * 見えず、消すこともできない。
 *
 *   BEGIN
 *   INSERT INTO projects (name, description, ...) VALUES (?, ?, ...)
 *   INSERT INTO project_members (project_id, user_id, role, ...) VALUES (?, ?, 'owner', ...)
 *   COMMIT
 */
final class CreateProject
{
    /**
     * @param  array{name: string, description?: string|null}  $attributes
     */
    public function __invoke(User $creator, array $attributes): Project
    {
        return DB::transaction(function () use ($creator, $attributes): Project {
            $project = Project::create($attributes);

            $project->members()->create([
                'user_id' => $creator->id,
                'role' => ProjectRole::Owner,
            ]);

            return $project;
        });
    }
}
