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
// API が返す項目は UserResource（許可リスト）が正。ここに併記するのは、API を通らない
// 経路（ログ出力、dd、キューのペイロードなど）でモデルがそのまま配列化されるときに
// シークレットを載せないため。二重化の意図は「応答の仕様」と「不注意な直列化」で
// 守る対象が違うことにある。
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
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

            // encrypted は APP_KEY による可逆な暗号化（hashed とは別物）。TOTP の検証には
            // 鍵の平文が必要なのでハッシュ化はできない。DB だけが漏れた場合に読めない
            // ことを狙う。APP_KEY ごと漏れれば読める。
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
        ];
    }

    /**
     * MFA が有効か。
     *
     * シークレットの有無ではなく confirmed_at で判定する。登録を開始しただけ
     * （シークレットはあるが確認が済んでいない）の状態を有効と見なすと、認証アプリへの
     * 登録に失敗したユーザーがログインできなくなる。
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
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
     *
     * withPivot('role') で、JOIN した project_members.role も一緒に取る。取得した
     * プロジェクトの $project->pivot->role が「このユーザーのロール」になる（表示用。
     * 認可の判定は ProjectPolicy が行う）。
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->withPivot('role');
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
