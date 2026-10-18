<?php

/**
 * NOTE: This migration documents a table that was created directly via
 * SQL against the production database. Do NOT run this migration.
 * It exists for schema version-control and local/testing environments only.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_violation_types', function (Blueprint $table): void {
            $table->id('violation_type_id');
            $table->string('violation_code', 50);
            $table->string('violation_name_ar');
            $table->string('bylaw_article_reference', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_violation_types');
    }
};
