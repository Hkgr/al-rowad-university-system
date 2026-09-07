<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/** Extends the real manual-grade fixture and retains its full canonical grade-cycle tests. */
class ExamManualGradeGridBehaviorTest extends ExamManualGradeEntryBehaviorTest
{
    private const GRID = '/api/v1/exams/manual-grade-entry/students/1';

    protected function setUp(): void
    {
        parent::setUp(); // Asserts SQLite before creating this test-only schema.
        Schema::table('grade_components', function (Blueprint $t) { $t->string('status')->default('active'); $t->decimal('weight_percentage', 8, 2)->nullable(); });
        Schema::table('program_courses', function (Blueprint $t) { $t->string('course_type')->default('mandatory'); $t->integer('academic_level_id')->nullable(); $t->integer('recommended_semester_id')->nullable(); });
        Schema::table('academic_years', function (Blueprint $t) { $t->boolean('is_current')->default(true); $t->boolean('is_active')->default(true); $t->string('calendar_lifecycle_status')->default('active'); });
        Schema::table('semesters', fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        Schema::table('student_course_registrations', function (Blueprint $t) {
            $t->date('registration_date')->nullable(); $t->integer('registered_by_user_id')->nullable();
            $t->integer('advisor_user_id')->nullable(); $t->text('notes')->nullable(); $t->unique(['student_id', 'course_offering_id']);
        });
        $tables = [
            'course_departments' => ['course_department_id', [], ['course_id', 'department_id']],
            'academic_requirement_groups' => ['requirement_group_id', ['group_code', 'group_name', 'requirement_scope', 'requirement_type'], ['academic_program_id', 'required_credit_hours']],
            'program_course_requirement_groups' => ['program_course_requirement_group_id', [], ['program_course_id', 'requirement_group_id']],
            'course_prerequisites' => ['course_prerequisite_id', [], ['course_id', 'prerequisite_course_id', 'minimum_result_status_id']],
            'student_registration_requests' => ['student_registration_request_id', ['status', 'expired_at'], ['student_id', 'academic_year_id', 'semester_id']],
            'student_registration_request_items' => ['student_registration_request_item_id', [], ['student_registration_request_id', 'course_offering_id']],
            'course_offering_schedule_slots' => ['course_offering_schedule_slot_id', ['component_type', 'start_time', 'end_time', 'location_label'], ['course_offering_id', 'day_of_week', 'created_by_user_id']],
            'academic_calendar_event_types' => ['academic_calendar_event_type_id', ['event_type_code'], []],
            'academic_calendar_events' => ['academic_calendar_event_id', ['cancelled_at'], ['academic_year_id', 'semester_id', 'academic_calendar_event_type_id']],
            'academic_calendar_event_versions' => ['academic_calendar_event_version_id', ['starts_at', 'ends_at', 'student_registration_ends_at', 'advisor_approval_ends_at', 'publication_status', 'published_at', 'superseded_at'], ['academic_calendar_event_id']],
            'user_activity_logs' => ['activity_log_id', ['module_code', 'action_code', 'description'], ['user_id']],
        ];
        foreach ($tables as $name => [$key, $texts, $ints]) Schema::create($name, function (Blueprint $t) use ($key, $texts, $ints, $name) {
            $t->increments($key);
            foreach ($texts as $column) $t->text($column)->nullable();
            foreach ($ints as $column) $t->integer($column)->nullable();
            $t->timestamps();
            if (in_array($name, ['academic_requirement_groups', 'academic_calendar_event_types'])) $t->boolean('is_active')->default(true);
            if ($name === 'academic_calendar_event_versions') $t->boolean('is_enforcement')->default(true);
        });
        DB::table('course_departments')->insert(['course_id' => 1, 'department_id' => 1]);
        DB::table('program_courses')->insert(['program_course_id' => 1, 'academic_program_id' => 1, 'course_id' => 1, 'is_active' => 1]);
        DB::table('academic_requirement_groups')->insert(['requirement_group_id' => 1, 'academic_program_id' => 1, 'group_code' => 'M', 'group_name' => 'Mandatory', 'requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'required_credit_hours' => 3]);
        DB::table('program_course_requirement_groups')->insert(['program_course_id' => 1, 'requirement_group_id' => 1]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id' => 1, 'event_type_code' => 'course_registration']);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id' => 1, 'academic_calendar_event_type_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1]);
        DB::table('academic_calendar_event_versions')->insert(['academic_calendar_event_id' => 1, 'publication_status' => 'published',
            'starts_at' => now()->subDay(), 'student_registration_ends_at' => now()->addDay(), 'advisor_approval_ends_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)]);
        foreach (['theoretical', 'practical'] as $i => $part) DB::table('course_offering_schedule_slots')->insert([
            'course_offering_id' => 1, 'component_type' => $part, 'day_of_week' => $i + 1, 'start_time' => '08:00:00', 'end_time' => '09:00:00']);
    }

    public function test_catalog_without_registrations_includes_college_and_shared_courses_once_in_any_period(): void
    {
        DB::table('student_course_registrations')->delete();
        DB::table('courses')->insert(['course_id' => 2, 'course_code' => 'C2', 'course_name' => 'Other program', 'credit_hours' => 3]);
        DB::table('course_departments')->insert([['course_id' => 2, 'department_id' => 1], ['course_id' => 1, 'department_id' => 1]]);
        $this->getJson(self::GRID.'/catalog?academic_year_id=999')->assertOk()->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.courses.0.own_program', true)->assertJsonPath('data.courses.1.own_program', false)
            ->assertJsonPath('data.courses.0.offerings', []);
        self::assertSame(0, DB::table('student_course_registrations')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_missing_components_preview_and_idempotent_preparation_do_not_create_marks_or_registrations(): void
    {
        DB::table('grade_components')->delete();
        $preview = $this->getJson(self::GRID.'/offerings/1/component-preview')->assertOk()->json('data');
        self::assertCount(2, $preview['components']);
        self::assertSame(0, DB::table('grade_components')->count());
        $payload = ['confirmed' => true, 'revision' => $preview['revision']];
        $this->postJson(self::GRID.'/offerings/1/components', $payload)->assertOk();
        $this->postJson(self::GRID.'/offerings/1/components', $payload)->assertOk();
        self::assertSame(2, DB::table('grade_components')->count());
        self::assertSame(1, DB::table('user_activity_logs')->count());
        self::assertSame(0, DB::table('student_grade_components')->count());
        self::assertSame(2, DB::table('student_course_registrations')->count());
    }

    public function test_initial_catalog_overflow_can_recover_using_independent_authorized_periods(): void
    {
        DB::table('academic_years')->insert(['academic_year_id' => 2, 'year_name' => 'Narrow year', 'is_current' => false]);
        $offering = (array) DB::table('course_offerings')->where('course_offering_id', 1)->first();
        for ($id = 2; $id <= 501; $id++) {
            DB::table('course_offerings')->insert(array_replace($offering, [
                'course_offering_id' => $id, 'academic_year_id' => $id === 501 ? 2 : 1,
            ]));
        }
        $this->getJson(self::GRID.'/catalog')->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
        $terms = $this->getJson(self::GRID.'/periods')->assertOk()->assertJsonCount(2, 'data.terms')->json('data.terms');
        self::assertSame([2, 1], array_column($terms, 'academic_year_id'));
        $this->getJson(self::GRID.'/catalog?academic_year_id=2&semester_id=1')->assertOk()
            ->assertJsonPath('data.terms', $terms)->assertJsonCount(1, 'data.courses.0.offerings')
            ->assertJsonPath('data.courses.0.offerings.0.course_offering_id', 501);
        self::assertSame(2, DB::table('student_course_registrations')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_period_lookup_enforces_student_access_and_independent_offering_scope(): void
    {
        DB::table('academic_years')->insert(['academic_year_id' => 2, 'year_name' => 'Outside scope', 'is_current' => false]);
        DB::table('colleges')->insert(['college_id' => 2, 'college_name' => 'Other']);
        DB::table('departments')->insert(['department_id' => 2, 'college_id' => 2]);
        DB::table('academic_programs')->insert(['academic_program_id' => 2, 'department_id' => 2]);
        DB::table('course_offerings')->insert(['course_offering_id' => 2, 'course_id' => 1, 'academic_program_id' => 2,
            'department_id' => 2, 'academic_year_id' => 2, 'semester_id' => 1, 'status' => 'open']);
        DB::table('user_access_scopes')->update(['scope_type' => 'college', 'scope_id' => 1]);
        $this->getJson(self::GRID.'/periods')->assertOk()->assertJsonCount(1, 'data.terms')->assertJsonPath('data.terms.0.academic_year_id', 1);
        $this->getJson(self::GRID.'/periods?student_id=2')->assertUnprocessable();
        DB::table('students')->where('student_id', 1)->update(['academic_program_id' => 2]);
        $this->getJson(self::GRID.'/periods')->assertForbidden();
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'super_admin']);
        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson(self::GRID.'/periods')->assertForbidden();
    }

    public function test_partial_optional_and_inactive_configuration_is_not_replaced(): void
    {
        foreach ([['is_required' => 0], ['status' => 'inactive'], ['max_mark' => 59], ['weight_percentage' => 100]] as $mutation) {
            DB::table('grade_components')->where('grade_component_id', 1)->update($mutation);
            $before = DB::table('grade_components')->get()->toJson();
            $this->getJson(self::GRID.'/offerings/1/component-preview')->assertConflict()->assertJsonPath('error_code', 'manual_components_incompatible');
            self::assertSame($before, DB::table('grade_components')->get()->toJson());
            DB::table('grade_components')->where('grade_component_id', 1)->update(['is_required' => 1, 'status' => 'active', 'max_mark' => 60, 'weight_percentage' => null]);
        }
        DB::table('grade_components')->where('grade_component_id', 2)->delete();
        $this->getJson(self::GRID.'/offerings/1/component-preview')->assertConflict();
    }

    public function test_single_part_uses_policy_limits_and_undefined_delivery_fails_closed(): void
    {
        DB::table('grade_components')->delete();
        foreach (['theoretical', 'practical'] as $part) {
            DB::table('courses')->where('course_id', 1)->update(['theoretical_hours' => $part === 'theoretical' ? 2 : 0, 'practical_hours' => $part === 'practical' ? 1 : 0]);
            DB::table('grading_policies')->update(['minimum_final_mark' => 30]);
            $this->app->forgetInstance(\App\Services\GradeService::class);
            $this->getJson(self::GRID.'/offerings/1/component-preview')->assertOk()->assertJsonCount(1, 'data.components')
                ->assertJsonPath('data.components.0.component_type', $part);
        }
        DB::table('courses')->update(['theoretical_hours' => 0, 'practical_hours' => 0]);
        $this->getJson(self::GRID.'/offerings/1/component-preview')->assertConflict()->assertJsonPath('error_code', 'manual_components_undefined');
    }

    public function test_exception_without_student_request_creates_normal_registrations_and_completes_canonical_grade_cycle(): void
    {
        DB::table('student_course_registrations')->delete();
        // SQLite-only fixture identity reset; production code never changes an identifier.
        DB::table('sqlite_sequence')->where('name', 'student_course_registrations')->delete();
        foreach ([1, 2] as $student) $this->postJson('/api/v1/exams/manual-grade-entry/students/'.$student.'/offerings/1/registration', $this->confirmation())->assertOk()->assertJsonPath('data.registration_id', $student);
        self::assertSame(0, DB::table('student_registration_requests')->count());
        self::assertSame(0, DB::table('student_registration_request_items')->count());
        self::assertSame(0, DB::table('grade_part_approvals')->count());
        self::assertSame(0, DB::table('student_grade_components')->count());
        self::assertSame(0, DB::table('student_course_results')->count());
        self::assertNull(DB::table('student_course_registrations')->value('advisor_user_id'));
        self::assertSame(2, DB::table('user_activity_logs')->where('action_code', 'manual_grade_entry.registration')->count());
        // Real save -> incomplete refusal -> submit -> return/correct/resubmit -> approvals -> official transcript/GPA.
        $this->test_complete_offering_submission_return_correction_and_official_finalization();
    }

    public function test_exception_waives_student_window_but_preserves_scope_identity_and_official_locks(): void
    {
        $path = self::GRID.'/offerings/1/registration';
        $this->postJson($path, $this->confirmation() + ['advisor_user_id' => 1])->assertUnprocessable();
        $this->postJson($path, array_replace($this->confirmation(), ['semester_id' => 2]))->assertConflict();
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1]);
        $this->postJson($path, $this->confirmation())->assertConflict();
        $this->getJson(self::GRID.'/offerings/1/component-preview')->assertConflict();
        DB::table('grade_approvals')->delete();
        DB::table('student_course_registrations')->where('student_id', 1)->delete();
        DB::table('academic_calendar_event_versions')->update(['starts_at' => now()->subDays(5), 'student_registration_ends_at' => now()->subDays(3), 'advisor_approval_ends_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)]);
        // The dedicated recording exception now explicitly supports closed student windows.
        $this->postJson($path, $this->confirmation())->assertOk();
        self::assertSame(1, DB::table('user_activity_logs')->count());
        foreach (['super_admin', 'doctor_instructor', 'student'] as $role) {
            DB::table('roles')->where('role_id', 1)->update(['role_code' => $role]);
            Sanctum::actingAs(User::findOrFail(1));
            $this->postJson($path, $this->confirmation())->assertForbidden();
        }
    }

    private function confirmation(): array
    {
        return ['confirmed' => true, 'reason' => 'Confirmed examination entry', 'course_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1];
    }

    public function test_offering_scope_is_independent_and_multiple_sections_remain_distinct(): void
    {
        DB::table('colleges')->insert(['college_id' => 2, 'college_name' => 'Other']);
        DB::table('departments')->insert(['department_id' => 2, 'college_id' => 2]);
        DB::table('academic_programs')->insert(['academic_program_id' => 2, 'department_id' => 2]);
        DB::table('course_offerings')->insert(['course_offering_id' => 2, 'course_id' => 1, 'academic_program_id' => 2, 'department_id' => 2, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'open']);
        $this->getJson(self::GRID.'/catalog')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonCount(2, 'data.courses.0.offerings');
        DB::table('user_access_scopes')->update(['scope_type' => 'college', 'scope_id' => 1]);
        $this->getJson(self::GRID.'/catalog')->assertOk()->assertJsonCount(1, 'data.courses.0.offerings');
        $this->postJson(self::GRID.'/offerings/2/registration', $this->confirmation())->assertForbidden();
        $this->getJson(self::GRID.'/offerings/2/component-preview')->assertForbidden();
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_preparation_rechecks_official_lock_after_preview_and_audit_failure_rolls_back(): void
    {
        DB::table('grade_components')->delete();
        $preview = $this->getJson(self::GRID.'/offerings/1/component-preview')->assertOk()->json('data');
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1]);
        $this->postJson(self::GRID.'/offerings/1/components', ['confirmed' => true, 'revision' => $preview['revision']])->assertConflict();
        self::assertSame(0, DB::table('grade_components')->count());
        DB::table('grade_approvals')->delete();
        \App\Models\UserActivityLog::creating(fn () => throw new \RuntimeException('audit fixture failure'));
        try {
            $this->postJson(self::GRID.'/offerings/1/components', ['confirmed' => true, 'revision' => $preview['revision']])->assertStatus(500);
            self::assertSame(0, DB::table('grade_components')->count());
            self::assertSame(0, DB::table('user_activity_logs')->count());
        } finally { \App\Models\UserActivityLog::flushEventListeners(); }
    }

    public function test_catalog_query_count_does_not_grow_with_visible_course_count(): void
    {
        // Unregistered catalog rows require no per-row HTTP call or relation lookup.
        DB::table('student_course_registrations')->delete();
        $count = function () {
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->getJson(self::GRID.'/catalog?per_page=100')->assertOk();
            $count = count(DB::getQueryLog()); DB::disableQueryLog(); return $count;
        };
        $one = $count();
        for ($i = 2; $i <= 25; $i++) {
            DB::table('courses')->insert(['course_id' => $i, 'course_code' => 'C'.$i, 'course_name' => 'Catalog '.$i, 'credit_hours' => 3]);
            DB::table('course_departments')->insert(['course_id' => $i, 'department_id' => 1]);
        }
        self::assertSame($one, $count());
    }
}
