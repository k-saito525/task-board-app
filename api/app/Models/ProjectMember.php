<?php

namespace App\Models;

use App\Enums\ProjectRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'role'])]
class ProjectMember extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * このプロジェクトの owner がこの人だけか。
     *
     * ChangeProjectMemberRole / RemoveProjectMember が、プロジェクトの行をロックした
     * うえで呼ぶ。ロック無しで呼ぶと、同時に届いた別の変更と数え方がずれる。
     *
     *   SELECT count(*) FROM project_members WHERE project_id = ? AND role = 'owner'
     */
    public function isLastOwner(): bool
    {
        return $this->role === ProjectRole::Owner
            && $this->project->members()->where('role', ProjectRole::Owner)->count() === 1;
    }
}
