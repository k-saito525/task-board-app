<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * タスクの CRUD と認可を固定する。
 *
 * 見るべきは次の3点。
 *   - 別のプロジェクトのタスクの id を URL に混ぜても届かない（404。IDOR 対策）
 *   - 担当者はこのプロジェクトのメンバーに限る
 *   - 削除だけは owner に限る（member は 403）
 *
 * 登場人物: owner / member（参加者）/ outsider（部外者。別のプロジェクトの owner）。
 */
class TaskTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $outsider;

    private Project $project;

    /** outsider のプロジェクトのタスク。こちらの URL に混ぜて使う */
    private Task $foreignTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->project = Project::factory()
            ->ownedBy($this->owner)
            ->hasMember($this->member)
            ->create();

        $this->foreignTask = Task::factory()
            ->for(Project::factory()->ownedBy($this->outsider))
            ->create(['title' => 'Foreign task']);
    }

    private function url(?Task $task = null): string
    {
        return "/api/projects/{$this->project->id}/tasks".($task ? "/{$task->id}" : '');
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes);
    }

    // ---------------------------------------------------------------- 一覧

    public function test_members_can_list_all_tasks_of_the_project(): void
    {
        $first = $this->task(['title' => 'First']);
        $second = Task::factory()->for($this->project)->assignedTo($this->member)->create(['due_date' => '2026-10-15']);

        $this->actingAs($this->member, 'sanctum')
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertExactJsonStructure(['data' => ['*' => [
                'id', 'title', 'description', 'status', 'due_date', 'assignee', 'created_at', 'updated_at',
            ]]])
            // 作成順。別のプロジェクトのタスクは含まれない
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.assignee', null)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.assignee', ['user_id' => $this->member->id, 'name' => $this->member->name])
            // 日付だけの値として返す（時刻・タイムゾーンを付けない）
            ->assertJsonPath('data.1.due_date', '2026-10-15');
    }

    public function test_tasks_can_be_filtered_by_status(): void
    {
        $this->task(['status' => TaskStatus::Todo]);
        $done = $this->task(['status' => TaskStatus::Done]);

        $this->actingAs($this->member, 'sanctum')
            ->getJson($this->url().'?status=done')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $done->id);
    }

    /** 不正な値を黙って無視すると、0件の一覧が返って書き間違いに気づけない */
    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson($this->url().'?status=archived')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_outsiders_cannot_list_tasks(): void
    {
        $this->actingAs($this->outsider, 'sanctum')->getJson($this->url())->assertNotFound();
    }

    // ---------------------------------------------------------------- 作成

    public function test_members_can_create_a_task_that_starts_as_todo(): void
    {
        $id = $this->actingAs($this->member, 'sanctum')
            ->postJson($this->url(), [
                'title' => 'Write the README',
                'assignee_id' => $this->member->id,
                'due_date' => '2026-10-31',
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Write the README')
            ->assertJsonPath('status', 'todo')
            ->assertJsonPath('assignee.user_id', $this->member->id)
            ->assertJsonPath('due_date', '2026-10-31')
            ->json('id');

        $this->assertSame($this->project->id, Task::findOrFail($id)->project_id);
    }

    public function test_create_validates_the_input(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->url(), [
                'title' => '',
                'status' => 'archived',
                'due_date' => '2026/10/31',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'status', 'due_date']);
    }

    /**
     * 担当者はこのプロジェクトのメンバーだけ。users に存在するだけでは足りない。
     */
    public function test_the_assignee_must_be_a_member_of_the_project(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->url(), ['title' => 'Task', 'assignee_id' => $this->outsider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id');

        $this->assertSame(0, $this->project->tasks()->count());
    }

    public function test_outsiders_cannot_create_tasks(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->postJson($this->url(), ['title' => 'Task'])
            ->assertNotFound();

        $this->assertSame(0, $this->project->tasks()->count());
    }

    // ---------------------------------------------------------------- 表示

    public function test_members_can_view_a_task(): void
    {
        $task = $this->task();

        $this->actingAs($this->member, 'sanctum')
            ->getJson($this->url($task))
            ->assertOk()
            ->assertJsonPath('id', $task->id);
    }

    public function test_outsiders_cannot_view_a_task(): void
    {
        $task = $this->task();

        $this->actingAs($this->outsider, 'sanctum')->getJson($this->url($task))->assertNotFound();
    }

    /**
     * 完了条件。自分のプロジェクトの URL に、別のプロジェクトのタスクの id を混ぜても、
     * そのタスクには届かない。表示・更新・削除のどれでも 404 で、元のタスクは変わらない。
     */
    public function test_a_task_of_another_project_is_not_found_through_this_project(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->url($this->foreignTask))
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url($this->foreignTask), ['title' => 'Hijacked'])
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url($this->foreignTask))
            ->assertNotFound();

        $this->assertSame('Foreign task', $this->foreignTask->fresh()->title);
    }

    public function test_a_non_numeric_task_id_is_not_found(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->url().'/not-a-number')
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- 更新

    /**
     * ボードのボタンは status だけを送る。他の項目は変わらない。
     */
    public function test_members_can_move_a_task_by_sending_only_the_status(): void
    {
        $task = Task::factory()->for($this->project)->assignedTo($this->member)->create(['title' => 'Original']);

        $this->actingAs($this->member, 'sanctum')
            ->patchJson($this->url($task), ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('title', 'Original')
            ->assertJsonPath('assignee.user_id', $this->member->id);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_members_can_edit_and_unassign_a_task(): void
    {
        $task = Task::factory()->for($this->project)->assignedTo($this->member)->create();

        $this->actingAs($this->member, 'sanctum')
            ->patchJson($this->url($task), ['title' => 'Renamed', 'assignee_id' => null])
            ->assertOk()
            ->assertJsonPath('title', 'Renamed')
            ->assertJsonPath('assignee', null);
    }

    public function test_update_rejects_an_assignee_outside_the_project(): void
    {
        $task = $this->task();

        $this->actingAs($this->member, 'sanctum')
            ->patchJson($this->url($task), ['assignee_id' => $this->outsider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id');

        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_outsiders_cannot_update_a_task(): void
    {
        $task = $this->task(['title' => 'Original']);

        $this->actingAs($this->outsider, 'sanctum')
            ->patchJson($this->url($task), ['title' => 'Renamed'])
            ->assertNotFound();

        $this->assertSame('Original', $task->fresh()->title);
    }

    // ---------------------------------------------------------------- 削除

    public function test_owner_can_delete_a_task(): void
    {
        $task = $this->task();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url($task))
            ->assertNoContent();

        $this->assertModelMissing($task);
    }

    /** 削除は取り消せないので owner だけ。member は done にはできる */
    public function test_members_cannot_delete_a_task(): void
    {
        $task = $this->task();

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson($this->url($task))
            ->assertForbidden();

        $this->assertModelExists($task);
    }

    public function test_outsiders_cannot_delete_a_task(): void
    {
        $task = $this->task();

        $this->actingAs($this->outsider, 'sanctum')
            ->deleteJson($this->url($task))
            ->assertNotFound();

        $this->assertModelExists($task);
    }
}
