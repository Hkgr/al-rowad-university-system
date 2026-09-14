<?php

namespace Tests\Support;

use App\Support\ScientificProgramAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Isolated SQLite behavior only. MariaDB protection is tested separately. */
final class AcademicPlanFixture
{
    public static function initialize(): void
    {
        ScientificCatalogFixture::initialize(true);
        Schema::table('academic_programs', function (Blueprint $t) {
            $t->string('plan_state')->default('legacy');
            $t->integer('default_academic_plan_version_id')->nullable();
            $t->timestamp('archived_at')->nullable();
        });
        Schema::create('academic_plan_control', function (Blueprint $t) {
            $t->integer('control_id')->primary(); $t->integer('schema_version'); $t->boolean('is_ready');
        });
        DB::table('academic_plan_control')->insert(['control_id' => 1, 'schema_version' => 1, 'is_ready' => 1]);
        Schema::create('academic_plan_versions', function (Blueprint $t) {
            $t->increments('academic_plan_version_id'); $t->integer('academic_program_id');
            $t->integer('version_number'); $t->string('label'); $t->string('status'); $t->string('calculation_policy');
            $t->integer('source_version_id')->nullable(); $t->integer('total_credit_hours')->nullable();
            $t->integer('created_by_user_id'); $t->integer('approved_by_user_id')->nullable();
            $t->timestamp('approved_at')->nullable(); $t->timestamp('fixed_at')->nullable(); $t->timestamps();
            $t->unique(['academic_program_id', 'version_number']);
            $t->foreign('academic_program_id')->references('academic_program_id')->on('academic_programs')->restrictOnDelete();
        });
        Schema::create('student_academic_plan_assignments', function (Blueprint $t) {
            $t->increments('student_academic_plan_assignment_id'); $t->integer('student_id'); $t->integer('academic_program_id');
            $t->integer('academic_plan_version_id'); $t->tinyInteger('current_slot')->nullable();
            $t->integer('assigned_by_user_id')->nullable(); $t->string('reason'); $t->timestamp('assigned_at'); $t->timestamp('ended_at')->nullable();
            $t->unique(['student_id', 'current_slot']);
            $t->foreign('student_id')->references('student_id')->on('students')->restrictOnDelete();
            $t->foreign('academic_plan_version_id')->references('academic_plan_version_id')->on('academic_plan_versions')->restrictOnDelete();
        });
        Schema::create('academic_plan_events', function (Blueprint $t) {
            $t->bigIncrements('academic_plan_event_id'); $t->integer('academic_program_id'); $t->integer('academic_plan_version_id')->nullable();
            $t->string('action'); $t->integer('actor_user_id'); $t->text('context'); $t->timestamp('created_at');
        });
        foreach (['academic_plan_versions', 'student_academic_plan_assignments', 'academic_plan_events'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) DB::unprepared("CREATE TRIGGER plan_epoch_{$table}_{$event} AFTER {$event} ON {$table} BEGIN UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1; END");
        }
        foreach ([ScientificProgramAccess::VIEW, ScientificProgramAccess::PLANS, ScientificProgramAccess::APPROVE, ScientificProgramAccess::ASSIGN, ScientificProgramAccess::ARCHIVE, ScientificProgramAccess::DELETE] as $code) {
            $id = DB::table('permissions')->insertGetId(['permission_code' => $code], 'permission_id');
            DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $id]);
        }
        foreach (['university', 'college', 'department'] as $scope) foreach (['mandatory', 'elective'] as $type) {
            $group = DB::table('academic_requirement_groups')->insertGetId(['academic_program_id' => 1, 'group_code' => $scope.'-'.$type,
                'group_name' => $scope.' '.$type, 'requirement_scope' => $scope, 'requirement_type' => $type,
                'required_credit_hours' => $scope === 'university' && $type === 'mandatory' ? 3 : 0], 'requirement_group_id');
            if ($scope === 'university' && $type === 'mandatory') {
                $pc = DB::table('program_courses')->insertGetId(['academic_program_id' => 1, 'course_id' => 1, 'course_type' => 'mandatory'], 'program_course_id');
                DB::table('program_course_requirement_groups')->insert(['program_course_id' => $pc, 'requirement_group_id' => $group]);
            }
        }
        DB::table('students')->insert(['student_id' => 1, 'academic_program_id' => 1]);
        Schema::create('student_course_registrations', function (Blueprint $t) {
            $t->increments('student_course_registration_id'); $t->integer('student_id'); $t->integer('course_offering_id');
            $t->integer('academic_plan_version_id')->nullable(); $t->integer('plan_program_course_id')->nullable(); $t->timestamps();
            $t->integer('registration_status_id')->nullable(); $t->integer('result_status_id')->nullable();
            $t->date('registration_date')->nullable(); $t->unique(['student_id', 'course_offering_id']);
        });
        foreach (['student_registration_requests' => 'student_registration_request_id', 'student_registration_modification_requests' => 'student_registration_modification_request_id', 'student_registration_replacement_requests' => 'student_registration_replacement_request_id'] as $table => $id) {
            Schema::create($table, function (Blueprint $t) use ($id) {
                $t->increments($id); $t->integer('student_id'); $t->integer('academic_plan_version_id')->nullable(); $t->timestamps();
                $t->string('status')->default('draft'); $t->integer('current_slot')->nullable();
                $t->integer('academic_year_id')->nullable(); $t->integer('semester_id')->nullable();
            });
        }
        Schema::create('student_registration_request_items', function (Blueprint $t) {
            $t->increments('student_registration_request_item_id'); $t->integer('student_registration_request_id'); $t->integer('course_offering_id');
        });
        Schema::table('students', function (Blueprint $t) {
            $t->integer('current_academic_level_id')->nullable(); $t->integer('student_status_id')->nullable();
            $t->string('student_number')->nullable(); $t->string('first_name')->nullable(); $t->string('last_name')->nullable();
        });
        Schema::table('course_offerings', function (Blueprint $t) {
            $t->integer('academic_year_id')->nullable(); $t->integer('semester_id')->nullable(); $t->integer('department_id')->nullable();
            $t->string('status')->default('CLOSED');
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->increments('academic_year_id'); $t->string('year_name'); $t->date('start_date'); $t->date('end_date'); $t->boolean('is_current')->default(true);
        });
        foreach (['registration_statuses' => 'registration_status_id', 'approval_statuses' => 'approval_status_id', 'student_statuses' => 'student_status_id'] as $table => $id) {
            Schema::create($table, function (Blueprint $t) use ($id) { $t->increments($id); $t->string('status_code'); $t->string('status_name')->nullable(); });
        }
        Schema::create('grade_approvals', function (Blueprint $t) {
            $t->increments('grade_approval_id'); $t->integer('course_offering_id'); $t->integer('approval_status_id'); $t->timestamps();
        });
        Schema::create('grade_components', function (Blueprint $t) {
            $t->increments('grade_component_id'); $t->integer('course_offering_id'); $t->string('component_type');
            $t->boolean('is_required')->default(true); $t->decimal('max_mark', 6, 3)->nullable();
        });
        Schema::create('student_course_results', function (Blueprint $t) {
            $t->increments('student_course_result_id'); $t->integer('student_course_registration_id'); $t->integer('result_status_id');
            $t->decimal('final_mark', 6, 3)->nullable(); $t->decimal('theoretical_total', 6, 3)->nullable(); $t->decimal('practical_total', 6, 3)->nullable(); $t->timestamps();
        });
        foreach (['student_progression_decisions', 'student_graduation_decisions'] as $table) Schema::table($table, function (Blueprint $t) {
            $t->integer('academic_plan_version_id')->nullable();
            $t->integer('student_id')->nullable(); $t->string('status')->nullable(); $t->timestamp('materialized_at')->nullable();
            $t->integer('current_slot')->nullable();
        });
        Schema::create('student_registration_withdrawal_requests', function (Blueprint $t) {
            $t->increments('student_registration_withdrawal_request_id'); $t->integer('student_id'); $t->integer('student_course_registration_id'); $t->string('status'); $t->integer('current_slot')->nullable();
        });
        Schema::create('appeal_statuses', function (Blueprint $t) { $t->increments('appeal_status_id'); $t->string('status_code'); });
        Schema::create('grade_appeals', function (Blueprint $t) { $t->increments('grade_appeal_id'); $t->integer('student_id'); $t->integer('appeal_status_id')->nullable(); });
        Schema::create('supplementary_exam_registrations', function (Blueprint $t) { $t->increments('supplementary_exam_registration_id'); $t->integer('student_id'); $t->string('status'); });
        Schema::create('supplementary_exam_materializations', function (Blueprint $t) { $t->increments('supplementary_exam_materialization_id'); $t->integer('supplementary_exam_registration_id'); });
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => 'Test', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        DB::table('registration_statuses')->insert([['registration_status_id' => 1, 'status_code' => 'completed'], ['registration_status_id' => 2, 'status_code' => 'registered']]);
        DB::table('approval_statuses')->insert(['approval_status_id' => 1, 'status_code' => 'approved']);
        foreach (['student_course_registrations', 'student_course_results', 'grade_approvals', 'student_registration_requests',
            'student_registration_modification_requests', 'student_registration_replacement_requests', 'student_registration_withdrawal_requests',
            'grade_appeals', 'appeal_statuses', 'supplementary_exam_registrations', 'supplementary_exam_materializations'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) DB::unprepared("CREATE TRIGGER plan_context_epoch_{$table}_{$event} AFTER {$event} ON {$table} BEGIN UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1; END");
        }
    }
}
