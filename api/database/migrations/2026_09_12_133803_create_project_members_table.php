<?php

use App\Enums\ProjectRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            // 同じユーザーを同じプロジェクトに二重登録させない。
            // このインデックスは「自分が参加しているか」の判定にも使われる。
            $table->unique(['project_id', 'user_id']);
        });

        // PHP 側の enum と対になる DB 側の防壁。
        // アプリを経由しない直接の INSERT でも不正な値を弾く。
        $roles = collect(ProjectRole::cases())
            ->map(fn (ProjectRole $role) => "'{$role->value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE project_members ADD CONSTRAINT project_members_role_check CHECK (role IN ({$roles}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
