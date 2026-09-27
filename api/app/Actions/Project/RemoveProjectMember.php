<?php

namespace App\Actions\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * メンバーを外す。owner が外す場合と、本人が抜ける場合の両方で使う。
 * 最後の owner は外せない（抜けられない）。プロジェクトごと消すなら削除を使う。
 *
 * 人数の確認はプロジェクトの行をロックしてから行う（AddProjectMember / ChangeProjectMemberRole を参照）。
 *
 * 外れた人が担当していたタスクは、tasks.assignee_id がそのまま残る。Step 6 で
 * 担当者をメンバーに限るときに扱いを決める。
 */
final class RemoveProjectMember
{
    /**
     * @throws HttpException 409 最後の owner を外そうとしたとき
     */
    public function __invoke(ProjectMember $member): void
    {
        DB::transaction(function () use ($member): void {
            Project::whereKey($member->project_id)->lockForUpdate()->first();

            $member->refresh();

            abort_if(
                $member->isLastOwner(),
                Response::HTTP_CONFLICT,
                __('The last owner cannot leave the project.'),
            );

            $member->delete();
        });
    }
}
