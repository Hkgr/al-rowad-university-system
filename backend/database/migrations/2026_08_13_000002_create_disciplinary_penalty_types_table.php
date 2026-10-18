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
        Schema::create('disciplinary_penalty_types', function (Blueprint $table): void {
            $table->id('penalty_type_id');
            $table->string('penalty_code', 50);
            $table->string('penalty_name_ar');
            $table->unsignedInteger('severity_order');
            $table->boolean('requires_investigation')->default(false);
            $table->boolean('cascades_to_subsequent_courses')->default(false);
            $table->string('min_authority_level', 80)->nullable();
            $table->string('bylaw_article_reference', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_penalty_types');
    }
};
