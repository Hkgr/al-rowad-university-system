<?php

/**
 * NOTE: This migration documents a table that was created directly via
 * SQL against the production database. Do NOT run this migration.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ministry_placement_batches', function (Blueprint $table): void {
            $table->id('batch_id');
            $table->string('batch_name');
            $table->string('source_file_name')->nullable();
            $table->foreignId('academic_year_id')->constrained('academic_years', 'academic_year_id');
            $table->date('import_date')->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users', 'user_id');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ministry_placement_batches');
    }
};
