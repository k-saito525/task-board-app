<?php

namespace App\Actions\Project;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * メンバーのロールを変える。最後の owner を member に下げることはできない。
 *
 * owner が0人になると、誰もプロジェクトを編集・削除できず、メンバーも管理できない。
 * 人数の確認はプロジェクトの行をロックしてから行う（AddProjectMember を参照）。
 * ロックが無いと、owner が2人いて互いを同時に下げたとき、どちらも「owner はまだ
 * 2人いる」と読んでから保存し、0人になる。
 */
final class ChangeProjectMemberRole
{
    /**
     * @throws ConflictHttpException 最後の owner を下げようとしたとき（409）
     */
    public function __invoke(ProjectMember $member, ProjectRole $role): ProjectMember
    {
        return DB::transaction(function () use ($member, $role): ProjectMember {
            Project::whereKey($member->project_id)->lockForUpdate()->first();

            // ロックを待つ間に、別のリクエストがこの人のロールを変えたかもしれない
            $member->refresh();

            if ($role !== ProjectRole::Owner && $member->isLastOwner()) {
                throw new ConflictHttpException(__('The last owner cannot be demoted.'));
            }

            $member->update(['role' => $role]);

            return $member;
        });
    }
}
