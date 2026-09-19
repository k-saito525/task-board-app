<?php

namespace Tests\Feature\Models;

use App\Enums\ProjectRole;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * モデルのリレーション・enum キャスト・DB 制約が意図どおり働くかを検証する。
 *
 * RefreshDatabase は各テストの前に taskboard_test をマイグレーションし、
 * テストをトランザクションで包んで終了時にロールバックする。
 * そのためテスト間でデータが混ざらず、開発用の taskboard にも影響しない。
 */
class ProjectRelationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DB には文字列 'owner' が入るが、モデル経由では ProjectRole 列挙型で返る。
     * Policy でロールを判定する際に文字列比較を避けられるのはこのキャストのおかげ。
     */
    public function test_role_is_cast_to_enum(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->ownedBy($user)->create();

        // sole() は「ちょうど1件」を期待する。2件以上あれば例外になるので、
        // owner が重複登録されていないことも同時に検証できる。
        $this->assertSame(ProjectRole::Owner, $project->members()->sole()->role);
    }

    /**
     * status を指定せずに作ると、マイグレーションで設定した DEFAULT 'todo' が入る。
     */
    public function test_status_is_cast_to_enum_and_defaults_to_todo(): void
    {
        $task = Task::factory()->create();

        $this->assertSame(TaskStatus::Todo, $task->status);
    }

    /**
     * 認可の土台。$user->projects() は JOIN の条件で参加プロジェクトに限定されるため、
     * 部外者には他人のプロジェクトが「存在しない」ものとして見える。
     *
     *   SELECT exists(
     *     SELECT projects.* FROM projects
     *     INNER JOIN project_members ON projects.id = project_members.project_id
     *     WHERE project_members.user_id = ? AND projects.id = ?
     *   )
     */
    public function test_projects_relation_only_returns_projects_the_user_belongs_to(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $project = Project::factory()->ownedBy($member)->create();

        $this->assertTrue($member->projects()->whereKey($project->id)->exists());
        $this->assertFalse($outsider->projects()->whereKey($project->id)->exists());
    }

    /**
     * 外部キーの ON DELETE CASCADE の検証。
     * プロジェクトを消したとき、参加者行とタスクが DB 側で連鎖削除される。
     * アプリ側で消して回る必要がないことを担保する。
     */
    public function test_deleting_a_project_removes_its_members_and_tasks(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->ownedBy($user)->create();

        // for() は belongsTo 側の紐づけ。project_id に $project->id が入る
        Task::factory()->for($project)->create();

        $project->delete();

        $this->assertDatabaseEmpty('project_members');
        $this->assertDatabaseEmpty('tasks');
    }

    /**
     * assignee_id だけは ON DELETE SET NULL にしてある。
     * 担当者が退会してもタスク自体は履歴として残す、という意図の検証。
     */
    public function test_deleting_an_assignee_keeps_the_task_and_clears_the_assignee(): void
    {
        $assignee = User::factory()->create();
        $task = Task::factory()->assignedTo($assignee)->create();

        $assignee->delete();

        // fresh() は DB から読み直す。$task はメモリ上の古い値を持っているため
        $this->assertNotNull($task->fresh());
        $this->assertNull($task->fresh()->assignee_id);
    }
}
