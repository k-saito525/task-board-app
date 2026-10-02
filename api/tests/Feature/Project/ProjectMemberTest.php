<?php

namespace Tests\Feature\Project;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * プロジェクトのメンバー管理と認可を固定する。
 *
 * 見るべきは次の3点。
 *   - 変更できるのは owner だけ（本人が抜ける場合を除く）。権限の無い人には入力の
 *     中身を見る前に 403 を返す
 *   - owner が0人になる操作（最後の owner の降格・脱退）は 409
 *   - {member} はそのプロジェクトの参加者の中からだけ探す（他は 404）
 *
 * 登場人物: owner / member（参加者）/ invitee（登録済みだが未参加）/ outsider（部外者）。
 */
class ProjectMemberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $invitee;

    private User $outsider;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->invitee = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->project = Project::factory()
            ->ownedBy($this->owner)
            ->hasMember($this->member)
            ->create();
    }

    private function url(?User $user = null): string
    {
        return "/api/projects/{$this->project->id}/members".($user ? "/{$user->id}" : '');
    }

    private function roleOf(User $user): ?ProjectRole
    {
        return $this->project->members()->where('user_id', $user->id)->first()?->role;
    }

    // ---------------------------------------------------------------- 一覧

    /**
     * UserResource（/me 用）を流用していないこと。流用すると two_factor_enabled
     * などの本人向けの項目が他のメンバーにも見える。
     */
    public function test_members_can_list_members_with_only_the_listed_fields(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // '*' は「配列の各要素」
            ->assertExactJsonStructure(['data' => ['*' => ['user_id', 'name', 'email', 'role', 'joined_at']]])
            // 参加した順
            ->assertJsonPath('data.0.user_id', $this->owner->id)
            ->assertJsonPath('data.0.role', 'owner')
            ->assertJsonPath('data.1.user_id', $this->member->id)
            ->assertJsonPath('data.1.role', 'member');
    }

    public function test_outsiders_cannot_list_members(): void
    {
        $this->actingAs($this->outsider, 'sanctum')->getJson($this->url())->assertNotFound();
    }

    // ---------------------------------------------------------------- 招待

    public function test_owner_can_add_a_registered_user_as_a_member_by_default(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->url(), ['email' => $this->invitee->email])
            ->assertCreated()
            ->assertJsonPath('user_id', $this->invitee->id)
            ->assertJsonPath('role', 'member');

        $this->assertSame(ProjectRole::Member, $this->roleOf($this->invitee));
    }

    public function test_owner_can_add_a_user_as_an_owner(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->url(), ['email' => $this->invitee->email, 'role' => 'owner'])
            ->assertCreated()
            ->assertJsonPath('role', 'owner');
    }

    public function test_adding_an_unregistered_email_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->url(), ['email' => 'nobody@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_adding_an_existing_member_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->url(), ['email' => $this->member->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, $this->project->members()->where('user_id', $this->member->id)->count());
    }

    /**
     * owner でない人には、メールアドレスが登録済みかどうかに関係なく 403。
     * 検証が権限の確認より先に走ると、未登録なら 422・登録済みなら 403 と分かれ、
     * 登録状況を調べる手段になる。
     */
    public function test_members_cannot_add_members_nor_probe_whether_an_email_is_registered(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->url(), ['email' => 'nobody@example.com'])
            ->assertForbidden();

        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->url(), ['email' => $this->invitee->email])
            ->assertForbidden();

        $this->assertNull($this->roleOf($this->invitee));
    }

    public function test_outsiders_cannot_add_members(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->postJson($this->url(), ['email' => $this->outsider->email])
            ->assertNotFound();

        $this->assertNull($this->roleOf($this->outsider));
    }

    // ---------------------------------------------------------------- ロールの変更

    public function test_owner_can_promote_a_member(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url($this->member), ['role' => 'owner'])
            ->assertOk()
            ->assertJsonPath('role', 'owner');

        $this->assertSame(ProjectRole::Owner, $this->roleOf($this->member));
    }

    public function test_an_owner_can_be_demoted_while_another_owner_remains(): void
    {
        $this->project->members()->where('user_id', $this->member->id)->update(['role' => ProjectRole::Owner]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url($this->owner), ['role' => 'member'])
            ->assertOk();

        $this->assertSame(ProjectRole::Member, $this->roleOf($this->owner));
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url($this->owner), ['role' => 'member'])
            ->assertConflict();

        $this->assertSame(ProjectRole::Owner, $this->roleOf($this->owner));
    }

    /** 自分を owner に上げることもできない。不正な値でも 422 より先に 403 */
    public function test_members_cannot_change_roles(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->patchJson($this->url($this->member), ['role' => 'owner'])
            ->assertForbidden();

        $this->actingAs($this->member, 'sanctum')
            ->patchJson($this->url($this->member), ['role' => 'not-a-role'])
            ->assertForbidden();

        $this->assertSame(ProjectRole::Member, $this->roleOf($this->member));
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url($this->member), ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    /**
     * {member} はこのプロジェクトの参加者の中からだけ探す。別のプロジェクトにしか
     * 参加していないユーザーの id を入れても、そちらの参加記録には届かない。
     */
    public function test_a_user_who_is_not_a_member_of_this_project_is_not_found(): void
    {
        $otherProject = Project::factory()->ownedBy($this->outsider)->create();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson($this->url($this->outsider), ['role' => 'member'])
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url($this->outsider))
            ->assertNotFound();

        $this->assertSame(ProjectRole::Owner, $otherProject->members()->sole()->role);
    }

    // ---------------------------------------------------------------- 外す・抜ける

    public function test_owner_can_remove_a_member(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url($this->member))
            ->assertNoContent();

        $this->assertNull($this->roleOf($this->member));
    }

    public function test_a_member_can_leave_the_project(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->deleteJson($this->url($this->member))
            ->assertNoContent();

        $this->assertNull($this->roleOf($this->member));

        // 抜けた後はプロジェクトが見えない
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}")
            ->assertNotFound();
    }

    /**
     * 外した人の担当は外す。「担当者は必ずメンバー」を崩さない。
     * 同じ人が担当している、別のプロジェクトのタスクには触れない。
     */
    public function test_removing_a_member_unassigns_their_tasks_in_this_project_only(): void
    {
        $assigned = Task::factory()->for($this->project)->assignedTo($this->member)->create();
        $elsewhere = Task::factory()
            ->for(Project::factory()->ownedBy($this->member))
            ->assignedTo($this->member)
            ->create();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url($this->member))
            ->assertNoContent();

        $this->assertNull($assigned->fresh()->assignee_id);
        $this->assertSame($this->member->id, $elsewhere->fresh()->assignee_id);
    }

    public function test_members_cannot_remove_other_members(): void
    {
        $this->project->members()->create(['user_id' => $this->invitee->id, 'role' => ProjectRole::Member]);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson($this->url($this->invitee))
            ->assertForbidden();

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson($this->url($this->owner))
            ->assertForbidden();

        $this->assertSame(ProjectRole::Member, $this->roleOf($this->invitee));
        $this->assertSame(ProjectRole::Owner, $this->roleOf($this->owner));
    }

    public function test_the_last_owner_cannot_leave(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url($this->owner))
            ->assertConflict();

        $this->assertSame(ProjectRole::Owner, $this->roleOf($this->owner));
    }

    public function test_an_owner_can_leave_while_another_owner_remains(): void
    {
        $this->project->members()->where('user_id', $this->member->id)->update(['role' => ProjectRole::Owner]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson($this->url($this->owner))
            ->assertNoContent();

        $this->assertNull($this->roleOf($this->owner));
    }

    public function test_outsiders_cannot_remove_members(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->deleteJson($this->url($this->member))
            ->assertNotFound();

        $this->assertSame(ProjectRole::Member, $this->roleOf($this->member));
    }
}
