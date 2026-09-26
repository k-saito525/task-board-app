<?php

namespace Tests\Feature\Project;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * プロジェクトの CRUD と認可を固定する。
 *
 * 見るべきは「owner が操作できること」よりも、
 *   - 部外者には存在自体が見えないこと（404。存在しない id と区別がつかない）
 *   - メンバーでも owner でなければ変更できないこと（403）
 * の2点。ここが緩むと他のチームのデータに触れられる。
 *
 * 登場人物は3人。owner（作成者）/ member（参加者）/ outsider（部外者）。
 */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $outsider;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->project = Project::factory()
            ->ownedBy($this->owner)
            ->hasMember($this->member)
            ->create(['name' => 'Original name', 'description' => 'Original description']);
    }

    private function url(): string
    {
        return "/api/projects/{$this->project->id}";
    }

    public function test_projects_require_authentication(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
    }

    // ---------------------------------------------------------------- 一覧

    public function test_index_lists_only_projects_the_user_belongs_to_with_their_role(): void
    {
        $ownedByMember = Project::factory()->ownedBy($this->member)->create();

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // 新しい順
            ->assertJsonPath('data.0.id', $ownedByMember->id)
            ->assertJsonPath('data.0.role', 'owner')
            ->assertJsonPath('data.1.id', $this->project->id)
            ->assertJsonPath('data.1.role', 'member');

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_is_paginated_by_twenty(): void
    {
        Project::factory()->count(20)->ownedBy($this->owner)->create();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/projects?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // 最も古い（最初に作った）ものが2ページ目に来る
            ->assertJsonPath('data.0.id', $this->project->id);
    }

    // ---------------------------------------------------------------- 作成

    public function test_store_creates_a_project_owned_by_the_creator(): void
    {
        $id = $this->actingAs($this->outsider, 'sanctum')
            ->postJson('/api/projects', ['name' => 'New project', 'description' => null])
            ->assertCreated()
            ->assertJsonPath('name', 'New project')
            ->assertJsonPath('role', 'owner')
            ->assertExactJsonStructure(['id', 'name', 'description', 'role', 'created_at', 'updated_at'])
            ->json('id');

        // sole() は「ちょうど1件」を期待する。作成者だけが owner として参加していること
        $membership = Project::findOrFail($id)->members()->sole();
        $this->assertSame($this->outsider->id, $membership->user_id);
        $this->assertSame(ProjectRole::Owner, $membership->role);
    }

    public function test_store_validates_the_input(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/projects', ['name' => '', 'description' => str_repeat('a', 5001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'description']);
    }

    // ---------------------------------------------------------------- 表示

    public function test_members_can_view_the_project(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('id', $this->project->id)
            ->assertJsonPath('role', 'member');
    }

    /**
     * 部外者には、存在しない id と同じ応答を返す。403 を返すと「その番号の
     * プロジェクトは存在する」ことが伝わる。
     */
    public function test_outsiders_get_the_same_404_as_for_a_missing_project(): void
    {
        $missingId = $this->project->id + 1000;

        $forOutsider = $this->actingAs($this->outsider, 'sanctum')->getJson($this->url())->assertNotFound();
        $forMissing = $this->actingAs($this->outsider, 'sanctum')->getJson("/api/projects/{$missingId}")->assertNotFound();

        // メッセージは id だけが違う
        $this->assertSame(
            str_replace((string) $this->project->id, '{id}', $forOutsider->json('message')),
            str_replace((string) $missingId, '{id}', $forMissing->json('message')),
        );
    }

    /** 数字以外の id は PostgreSQL の型変換で 500 にならず、404 になる */
    public function test_a_non_numeric_id_is_not_found(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/projects/not-a-number')
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- 更新

    public function test_owner_can_update_only_the_given_fields(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url(), ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed')
            ->assertJsonPath('description', 'Original description')
            ->assertJsonPath('role', 'owner');

        $this->assertSame('Renamed', $this->project->fresh()->name);
    }

    /** 名前を送らずに説明だけを変えられる（name は必須だが、送ったときだけ検証する） */
    public function test_owner_can_update_only_the_description(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url(), ['description' => null])
            ->assertOk()
            ->assertJsonPath('name', 'Original name')
            ->assertJsonPath('description', null);
    }

    public function test_members_cannot_update_the_project(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->patchJson($this->url(), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Original name', $this->project->fresh()->name);
    }

    public function test_outsiders_cannot_update_the_project(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->patchJson($this->url(), ['name' => 'Renamed'])
            ->assertNotFound();

        $this->assertSame('Original name', $this->project->fresh()->name);
    }

    // ---------------------------------------------------------------- 削除

    public function test_owner_can_delete_the_project_with_its_members_and_tasks(): void
    {
        Task::factory()->for($this->project)->create();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url())
            ->assertNoContent();

        $this->assertModelMissing($this->project);
        $this->assertDatabaseMissing('project_members', ['project_id' => $this->project->id]);
        $this->assertDatabaseMissing('tasks', ['project_id' => $this->project->id]);
    }

    public function test_members_cannot_delete_the_project(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->deleteJson($this->url())
            ->assertForbidden();

        $this->assertModelExists($this->project);
    }

    public function test_outsiders_cannot_delete_the_project(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->deleteJson($this->url())
            ->assertNotFound();

        $this->assertModelExists($this->project);
    }
}
