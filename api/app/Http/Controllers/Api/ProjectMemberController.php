<?php

namespace App\Http\Controllers\Api;

use App\Actions\Project\AddProjectMember;
use App\Actions\Project\ChangeProjectMemberRole;
use App\Actions\Project\RemoveProjectMember;
use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AddProjectMemberRequest;
use App\Http\Requests\Project\UpdateProjectMemberRequest;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * プロジェクトのメンバー管理。
 *
 * {project} は参加しているプロジェクトしか解決されない（非メンバーは 404）。
 * {member} はユーザーの id で、scoped() によりそのプロジェクトの参加者の中からだけ
 * 探される。別のプロジェクトの参加者や、参加していないユーザーの id は 404 になる。
 *
 *   SELECT * FROM project_members
 *   WHERE project_members.project_id = ?   -- 親のプロジェクトに属すること
 *     AND user_id = ?
 *   LIMIT 1
 */
class ProjectMemberController extends Controller
{
    /**
     * 参加者の全員。人数は限られており、担当者の選択（Step 10）でも全員が要るので
     * 分割しない。参加した順に並べる。
     *
     *   SELECT * FROM project_members WHERE project_id = ? ORDER BY id
     *   SELECT * FROM users WHERE id IN (?, ?, ...)   ← with('user')。1人ずつ引かない
     *
     * data で包むのは手で行う。JsonResource::withoutWrapping()（AppServiceProvider）で
     * ラッパーを外しているため、ページ分けしないコレクションは最上位が裸の配列になる。
     * ページ分けした一覧（プロジェクト一覧）は別の仕組みで data / links / meta を返すので、
     * 「一覧は常に data の中」に揃える。
     */
    public function index(Project $project): JsonResponse
    {
        return response()->json([
            'data' => ProjectMemberResource::collection(
                $project->members()->with('user')->orderBy('id')->get(),
            ),
        ]);
    }

    public function store(AddProjectMemberRequest $request, Project $project, AddProjectMember $add): JsonResponse
    {
        $member = $add(
            $project,
            User::where('email', $request->validated('email'))->firstOrFail(),
            ProjectRole::from($request->validated('role', ProjectRole::Member->value)),
        );

        return (new ProjectMemberResource($member))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * @throws ConflictHttpException 最後の owner を member に下げようとしたとき（409）
     */
    public function update(
        UpdateProjectMemberRequest $request,
        Project $project,
        ProjectMember $member,
        ChangeProjectMemberRole $change,
    ): ProjectMemberResource {
        return new ProjectMemberResource($change($member, ProjectRole::from($request->validated('role'))));
    }

    /**
     * owner が外す場合と、本人が抜ける場合の両方をここで受ける。
     *
     * @throws ConflictHttpException 最後の owner を外そうとしたとき（409）
     */
    public function destroy(Project $project, ProjectMember $member, RemoveProjectMember $remove): Response
    {
        Gate::authorize('removeMember', [$project, $member]);

        $remove($member);

        return response()->noContent();
    }
}
