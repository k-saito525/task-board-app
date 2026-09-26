<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;

/**
 * プロジェクトに対する操作のうち、ロールで可否が分かれるものを判定する。
 *
 * 「メンバーかどうか」はここでは見ない。{project} のバインディング
 * （AppServiceProvider::configureRouteBindings）が参加しているプロジェクトしか
 * 取得しないため、ここに来た時点でメンバーであることは SQL で保証されている。
 * そのため view / create のように「メンバーなら誰でも」「ログインしていれば誰でも」の
 * 操作にはメソッドを置かない。
 *
 *   非メンバー         → バインディングで 404（存在を伝えない）
 *   メンバー・非 owner → ここで false → 403（存在は既に知っている）
 */
class ProjectPolicy
{
    public function update(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    /**
     * ロールは毎回 DB に問い合わせる。バインディングで取れた pivot の値を使えば1回
     * 減らせるが、それだとプロジェクトをどう取得したかに判定が左右される。
     *
     *   SELECT exists(
     *     SELECT * FROM project_members
     *     WHERE project_id = ? AND user_id = ? AND role = 'owner'
     *   )
     *   → UNIQUE(project_id, user_id) のインデックスがそのまま効く
     */
    private function isOwner(User $user, Project $project): bool
    {
        return $project->members()
            ->where('user_id', $user->id)
            ->where('role', ProjectRole::Owner)
            ->exists();
    }
}
