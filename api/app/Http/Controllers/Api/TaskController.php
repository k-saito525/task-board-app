<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\ListTasksRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * プロジェクトのタスク。
 *
 * {project} は参加しているプロジェクトしか解決されない（非メンバーは 404）。
 * {task} は scoped() によりそのプロジェクトのタスクの中からだけ探される。別の
 * プロジェクトのタスクの id を混ぜても 404 になる（IDOR 対策）。
 *
 *   SELECT * FROM tasks
 *   WHERE tasks.project_id = ?   -- 親のプロジェクトに属すること
 *     AND id = ?
 *   LIMIT 1
 *
 * 作成・表示・編集・ステータスの移動はメンバー全員。削除だけ owner（ProjectPolicy::deleteTask）。
 */
class TaskController extends Controller
{
    /**
     * プロジェクトのタスクを全件。ボードは3列すべてのカードを一度に描くので分割しない
     * （Trello もボードを開くと全カードを読み込む）。?status= で1列分に絞れる。
     *
     *   SELECT * FROM tasks WHERE project_id = ? [AND status = ?] ORDER BY id
     *   → index(project_id, status) がそのまま効く
     *   SELECT * FROM users WHERE id IN (?, ...)   ← with('assignee')。1件ずつ引かない
     */
    public function index(ListTasksRequest $request, Project $project): JsonResponse
    {
        $tasks = $project->tasks()
            ->with('assignee')
            ->when($request->validated('status'), fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('id')
            ->get();

        // 一覧は常に data の中（withoutWrapping() のため手で包む）
        return response()->json(['data' => TaskResource::collection($tasks)]);
    }

    /**
     * project_id は fillable に含めず、リレーション経由の create() で親から入れる。
     * リクエストの値でタスクの所属先を変えられる余地を作らない。
     */
    public function store(StoreTaskRequest $request, Project $project): JsonResponse
    {
        $task = $project->tasks()->create($request->validated());

        // status を省いた場合の値は DB の既定値（todo）で決まり、create() が返すモデルには
        // 入っていない。読み直して、保存された値で応答する。
        return (new TaskResource($task->refresh()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Project $project, Task $task): TaskResource
    {
        return new TaskResource($task);
    }

    public function update(UpdateTaskRequest $request, Project $project, Task $task): TaskResource
    {
        $task->update($request->validated());

        return new TaskResource($task);
    }

    public function destroy(Project $project, Task $task): Response
    {
        Gate::authorize('deleteTask', $project);

        $task->delete();

        return response()->noContent();
    }
}
