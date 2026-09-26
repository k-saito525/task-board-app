<?php

namespace App\Http\Controllers\Api;

use App\Actions\Project\CreateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * プロジェクトの CRUD。
 *
 * {project} を受け取るメソッドには、参加しているプロジェクトしか届かない
 * （AppServiceProvider::configureRouteBindings）。ロールで可否が分かれる更新・削除だけ
 * Gate::authorize() で ProjectPolicy を呼ぶ。拒否されると AuthorizationException が
 * 投げられ、Laravel が 403 に変換する。
 */
class ProjectController extends Controller
{
    /**
     * 自分が参加しているプロジェクトの一覧。新しい順に20件ずつ。
     *
     *   SELECT projects.*, project_members.role AS pivot_role, ...
     *   FROM projects
     *   INNER JOIN project_members ON projects.id = project_members.project_id
     *   WHERE project_members.user_id = ?
     *   ORDER BY projects.id DESC
     *   LIMIT 20 OFFSET ?
     *
     * 並び順を id にしているのは、ページの境目で行が重複・欠落しないため。created_at は
     * 同じ時刻の行があり得るので、それだけでは順序が一意に決まらない。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = $request->user()->projects()
            ->orderByDesc('projects.id')
            ->paginate(20);

        return ProjectResource::collection($projects);
    }

    /**
     * 作成者は owner として参加する。
     */
    public function store(StoreProjectRequest $request, CreateProject $create): JsonResponse
    {
        $project = $create($request->user(), $request->validated());

        // 応答に自分のロールを載せるため、参加プロジェクトとして取り直す。
        // create() が返したモデルには project_members の情報（pivot）が付いていない。
        $project = $request->user()->projects()->findOrFail($project->id);

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return new ProjectResource($project);
    }

    /**
     * メンバーとタスクは外部キーの ON DELETE CASCADE で一緒に消える。
     */
    public function destroy(Project $project): Response
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return response()->noContent();
    }
}
