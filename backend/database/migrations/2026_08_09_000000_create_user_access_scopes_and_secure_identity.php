<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_access_scopes')) {
            return;
        }

        Schema::create('user_access_scopes', function (Blueprint $table): void {
            $table->bigIncrements('user_access_scope_id');
            $table->integer('user_id');
            // university scope_id references the existing PRES organizational root;
            // the remaining types reference their namesake academic tables.
            $table->enum('scope_type', ['university', 'college', 'department', 'program', 'section']);
            $table->integer('scope_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'scope_type', 'scope_id'], 'user_scope_unique');
            $table->index(['scope_type', 'scope_id', 'is_active'], 'active_scope_lookup');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });

        // Existing production schema already has nullable, indexed student_id and
        // employee_id foreign keys. No historical identity is rewritten here.
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_access_scopes')) {
            return;
        }

        Schema::dropIfExists('user_access_scopes');
    }
};
