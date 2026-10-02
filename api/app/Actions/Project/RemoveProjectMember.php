<?php

namespace App\Actions\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * メンバーを外す。owner が外す場合と、本人が抜ける場合の両方で使う。
 * 最後の owner は外せない（抜けられない）。プロジェクトごと消すなら削除を使う。
 *
 * 人数の確認はプロジェクトの行をロックしてから行う（AddProjectMember / ChangeProjectMemberRole を参照）。
 *
 * 外れた人が担当していたタスクは、担当を外して未割り当てに戻す（Trello も、ボードから
 * 外した人を全カードの担当から外す）。担当者は必ずそのプロジェクトのメンバー、という
 * 決まり（タスクの作成・更新で検証している）を、外した後も崩さない。同じトランザクション
 * の中で行うので、「外れたのに担当のまま」の状態が途中で見えることもない。
 */
final class RemoveProjectMember
{
    /**
     * @throws ConflictHttpException 最後の owner を外そうとしたとき（409）
     */
    public function __invoke(ProjectMember $member): void
    {
        DB::transaction(function () use ($member): void {
            Project::whereKey($member->project_id)->lockForUpdate()->first();

            $member->refresh();

            if ($member->isLastOwner()) {
                throw new ConflictHttpException(__('The last owner cannot leave the project.'));
            }

            //   UPDATE tasks SET assignee_id = NULL, updated_at = ?
            //   WHERE project_id = ? AND assignee_id = ?
            $member->project->tasks()
                ->where('assignee_id', $member->user_id)
                ->update(['assignee_id' => null]);

            $member->delete();
        });
    }
}
