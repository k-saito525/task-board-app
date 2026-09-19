<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description'])]
class Project extends Model
{
    use HasFactory;

    /**
     * 参加者。ロールもこの行が持つ。ユーザー情報は members()->with('user') で辿る。
     *
     * $project->members
     *   SELECT * FROM project_members WHERE project_id = ?
     *
     * $project->members()->where('user_id', $id)->first()   ← Policy でのロール判定
     *   SELECT * FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1
     *   → UNIQUE(project_id, user_id) のインデックスがそのまま効く
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * $project->tasks
     *   SELECT * FROM tasks WHERE project_id = ?
     *
     * $project->tasks()->where('status', 'todo')->get()   ← ボードのカラム取得
     *   SELECT * FROM tasks WHERE project_id = ? AND status = ?
     *   → index(project_id, status) がそのまま効く
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
