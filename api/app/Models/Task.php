<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'description', 'status', 'due_date', 'assignee_id'])]
class Task extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_date' => 'date',
        ];
    }

    /**
     * $task->project
     *   SELECT * FROM projects WHERE id = ? LIMIT 1
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * 担当者。外部キーが規約外（user_id ではなく assignee_id）なので明示する。
     * 未割り当てなら null。
     *
     * $task->assignee
     *   SELECT * FROM users WHERE id = ? LIMIT 1
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
