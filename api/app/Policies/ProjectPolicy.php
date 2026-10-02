<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
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

    /*
    | メンバー管理。Jetstream の TeamPolicy（addTeamMember / updateTeamMember /
    | removeTeamMember）と同じく、親のプロジェクトの Policy に置く。判定は親の
    | ロールで決まり、ProjectMember 単体では決まらないため。
    */

    public function addMember(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    public function updateMember(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    /**
     * owner は誰でも外せる。owner でなくても自分自身なら外せる（プロジェクトから抜ける）。
     * 最後の owner を外せないことは RemoveProjectMember が守る（ロールではなく人数の
     * 問題で、同時に届いたリクエストと合わせて数える必要があるため）。
     */
    public function removeMember(User $user, Project $project, ProjectMember $member): bool
    {
        return $member->user_id === $user->id || $this->isOwner($user, $project);
    }

    /*
    | タスク。作成・編集・ステータスの移動はメンバー全員ができる（{project} のバインディングが
    | メンバーであることを保証するので、ここにはメソッドを置かない）。
    |
    | 削除だけは owner に限る。物理削除で取り消せないため。不要になったタスクは member でも
    | done にできる。GitHub の Issue も、閉じるのは書き込み権限で足りるが、削除は管理者に
    | 限っている。可否が親のプロジェクトのロールで決まるので、メンバー管理と同じくここに置く。
    */

    public function deleteTask(User $user, Project $project): bool
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
