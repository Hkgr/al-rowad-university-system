<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_course_results', function (Blueprint $table) {
            $table->decimal('theoretical_total', 5, 2)->nullable()->change();
            $table->decimal('practical_total', 5, 2)->nullable()->change();
            $table->decimal('final_mark', 5, 2)->nullable()->change();
            $table->string('incomplete_reason')->nullable()->after('is_deprived');
            $table->text('incomplete_requirements')->nullable()->after('incomplete_reason');
            $table->timestamp('incomplete_granted_at')->nullable()->after('incomplete_requirements');
            $table->date('incomplete_deadline')->nullable()->after('incomplete_granted_at');
            $table->timestamp('incomplete_resolved_at')->nullable()->after('incomplete_deadline');
        });

        Schema::table('grading_policies', function (Blueprint $table) {
            $table->string('incomplete_expiry_status_code', 20)->nullable()->after('absence_deprivation_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('grading_policies', function (Blueprint $table) {
            $table->dropColumn('incomplete_expiry_status_code');
        });

        Schema::table('student_course_results', function (Blueprint $table) {
            $table->dropColumn([
                'incomplete_reason',
                'incomplete_requirements',
                'incomplete_granted_at',
                'incomplete_deadline',
                'incomplete_resolved_at',
            ]);
            $table->decimal('theoretical_total', 5, 2)->nullable(false)->default(0)->change();
            $table->decimal('practical_total', 5, 2)->nullable(false)->default(0)->change();
            $table->decimal('final_mark', 5, 2)->nullable(false)->default(0)->change();
        });
    }
};
