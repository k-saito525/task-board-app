<?php

namespace App\Actions\Project;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 登録済みのユーザーをプロジェクトに参加させる。
 *
 * メンバーを変える処理（追加・ロール変更・削除）は、どれも最初にプロジェクトの行を
 * ロックする。同じプロジェクトへの変更が1本ずつ順番に処理されるので、「すでに
 * 参加しているか」「owner が何人いるか」の確認が、同時に届いた別のリクエストと
 * ずれない。ここでは owner がボタンを二度押したときに、2本目が確認をすり抜けて
 * UNIQUE(project_id, user_id) 違反の 500 になるのを防いでいる。
 *
 *   SELECT * FROM projects WHERE id = ? LIMIT 1 FOR UPDATE
 */
final class AddProjectMember
{
    /**
     * @throws ValidationException すでに参加しているとき
     */
    public function __invoke(Project $project, User $invitee, ProjectRole $role): ProjectMember
    {
        return DB::transaction(function () use ($project, $invitee, $role): ProjectMember {
            Project::whereKey($project->id)->lockForUpdate()->first();

            if ($project->members()->where('user_id', $invitee->id)->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('This user already belongs to the project.'),
                ]);
            }

            return $project->members()->create([
                'user_id' => $invitee->id,
                'role' => $role,
            ]);
        });
    }
}
