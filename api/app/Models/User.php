<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * 参加しているプロジェクト。
     *
     * 認可の起点。プロジェクトは必ずこの関連を経由して取得し、Project::find() のように
     * 直接引かない。JOIN の条件が所有権のチェックそのものになるため、非メンバーには
     * 行が返らず、条件の書き漏れによる情報漏洩が起こらない。
     *
     * $user->projects
     *   SELECT projects.* FROM projects
     *   INNER JOIN project_members ON projects.id = project_members.project_id
     *   WHERE project_members.user_id = ?
     *
     * $user->projects()->findOrFail($id)   ← 単体取得は必ずこの形で書く
     *   SELECT projects.* FROM projects
     *   INNER JOIN project_members ON projects.id = project_members.project_id
     *   WHERE project_members.user_id = ?   -- 自分が参加していること
     *     AND projects.id = ?
     *   LIMIT 1
     *   → 非メンバーなら 0 件が返り findOrFail が 404 を投げる
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members');
    }

    /**
     * 自分が担当しているタスク。外部キーが規約外（user_id ではなく assignee_id）なので明示する。
     *
     * $user->assignedTasks
     *   SELECT * FROM tasks WHERE assignee_id = ?
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }
}
