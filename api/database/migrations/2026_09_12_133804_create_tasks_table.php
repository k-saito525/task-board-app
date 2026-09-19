<?php

use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // 担当者は未割り当てを許す。担当者が退会してもタスクは残す。
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default(TaskStatus::Todo->value);
            $table->date('due_date')->nullable();
            $table->timestamps();

            // ボードは「プロジェクト内をステータスで絞る」形でしか引かないため、この複合順が効く
            $table->index(['project_id', 'status']);
            $table->index('assignee_id');
        });

        $statuses = collect(TaskStatus::cases())
            ->map(fn (TaskStatus $status) => "'{$status->value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ({$statuses}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
