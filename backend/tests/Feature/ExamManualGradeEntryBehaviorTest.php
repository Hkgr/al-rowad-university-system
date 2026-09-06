<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentCourseResult;
use App\Models\User;
use App\Services\GradeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Real routes and persistence. SQLite does not establish MariaDB lock scheduling. */
class ExamManualGradeEntryBehaviorTest extends TestCase
{
    private const BASE = '/api/v1/exams/manual-grade-entry';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        Schema::dropAllTables();
        $this->schema();
        $this->seed();
        Sanctum::actingAs(User::findOrFail(1));
    }

    public function test_authorized_officer_without_assignment_can_save_zero_and_correct_with_audit(): void
    {
        $this->getJson(self::BASE.'/students?q=Student&per_page=1')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->save([['grade_component_id' => 1, 'mark' => 0]])->assertOk();
        self::assertSame(0.0, (float) DB::table('student_grade_components')->value('mark'));
        self::assertSame(1, DB::table('grade_audit_logs')->count());
        $this->save([['grade_component_id' => 1, 'mark' => 0]])->assertOk();
        self::assertSame(1, DB::table('grade_audit_logs')->count());
        $this->save([['grade_component_id' => 1, 'mark' => 20]])->assertUnprocessable();
        $this->save([['grade_component_id' => 1, 'mark' => 20]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح من الورقة'])->assertOk();
        $audit = DB::table('grade_audit_logs')->orderByDesc('grade_audit_log_id')->first();
        self::assertSame(1, $audit->changed_by_user_id);
        self::assertSame(0.0, (float) $audit->old_mark);
        self::assertSame(20.0, (float) $audit->new_mark);
        self::assertSame('exam_board_manual_entry', json_decode($audit->change_reason, true)['origin']);
        $this->save([['grade_component_id' => 1, 'mark' => null]], ['correction_confirmed' => true, 'correction_reason' => 'إزالة قيمة خاطئة'])->assertOk();
        self::assertNull(DB::table('student_grade_components')->value('mark'));
        self::assertSame(0, DB::table('student_course_results')->count());
    }

    public function test_authorization_requires_real_role_assigned_permissions_active_account_and_scope(): void
    {
        foreach (['super_admin', 'doctor_instructor', 'employee'] as $role) {
            DB::table('roles')->where('role_id', 1)->update(['role_code' => $role]);
            $this->getJson(self::BASE.'/students?q=Student')->assertForbidden();
        }
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'exam_officer']);
        DB::table('role_permissions')->where('permission_id', 2)->delete();
        $this->getJson(self::BASE.'/students?q=Student')->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => 2]);
        DB::table('account_statuses')->where('account_status_id', 1)->update(['status_code' => 'inactive']);
        $this->getJson(self::BASE.'/students?q=Student')->assertForbidden();
        DB::table('account_statuses')->where('account_status_id', 1)->update(['status_code' => 'active']);
        Sanctum::actingAs(User::findOrFail(1));
        DB::table('user_access_scopes')->delete();
        $this->getJson(self::BASE.'/students/1/registrations')->assertForbidden();
        $this->getJson(self::BASE.'/students?q=Student')->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE.'/students?q=Student')->assertUnauthorized();
    }

    public function test_actual_student_and_offering_scope_are_independent_and_shared_courses_survive(): void
    {
        DB::table('colleges')->insert(['college_id' => 2, 'college_name' => 'Other']);
        DB::table('departments')->insert(['department_id' => 2, 'college_id' => 2]);
        DB::table('academic_programs')->insert(['academic_program_id' => 2, 'department_id' => 2]);
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['department_id' => 2, 'academic_program_id' => 2]);
        // University scope can access the actual shared registration across student/ offering colleges.
        $this->save([['grade_component_id' => 1, 'mark' => 10]])->assertOk();
        $payload = $this->payload([['grade_component_id' => 1, 'mark' => 20]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح']);
        DB::table('user_access_scopes')->update(['scope_type' => 'college', 'scope_id' => 1]);
        $this->getJson(self::BASE.'/students/1/registrations')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->putJson(self::BASE.'/students/1/registrations/1/marks', $payload)->assertForbidden();
        DB::table('user_access_scopes')->update(['scope_type' => 'college', 'scope_id' => 2]);
        $this->getJson(self::BASE.'/students/1/registrations')->assertForbidden();
        self::assertSame(1, DB::table('grade_audit_logs')->count());
    }

    public function test_missing_confirmation_and_fixed_supplementary_roster_are_blocked(): void
    {
        $this->save([['grade_component_id' => 1, 'mark' => 20]])->assertOk();
        $this->save([['grade_component_id' => 1, 'mark' => 21]], ['correction_confirmed' => true, 'correction_reason' => ' '])->assertUnprocessable();
        $ready = $this->readiness('theoretical');
        $this->postJson($this->partPath('theoretical').'/submit', ['revision' => $ready['revision']])->assertUnprocessable();
        DB::table('supplementary_exam_periods')->insert(['supplementary_exam_period_id' => 1, 'status' => 'grading_open']);
        DB::table('supplementary_exam_offerings')->insert(['supplementary_exam_offering_id' => 1, 'supplementary_exam_period_id' => 1]);
        DB::table('supplementary_exam_registrations')->insert(['supplementary_exam_offering_id' => 1, 'student_course_registration_id' => 1, 'status' => 'registered', 'current_slot' => 1]);
        $this->save([['grade_component_id' => 1, 'mark' => 21]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح'])->assertConflict();
        self::assertSame(1, DB::table('grade_audit_logs')->count());
    }

    public function test_unknown_payload_invalid_marks_acknowledgement_and_cross_relations_write_nothing(): void
    {
        foreach ([-1, '1.001', 'NaN', 61] as $mark) $this->save([['grade_component_id' => 1, 'mark' => $mark]])->assertUnprocessable();
        $this->save([['grade_component_id' => 1, 'mark' => 2]], ['acknowledged' => false])->assertUnprocessable();
        $this->save([['grade_component_id' => 1, 'mark' => 2]], ['entered_by_user_id' => 5])->assertUnprocessable();
        $this->save([['grade_component_id' => 1, 'mark' => 2], ['grade_component_id' => 1, 'mark' => 3]])->assertUnprocessable();
        $this->save([['grade_component_id' => 999, 'mark' => 2]])->assertUnprocessable();
        $this->putJson(self::BASE.'/students/2/registrations/1/marks', $this->payload([['grade_component_id' => 1, 'mark' => 2]]))->assertNotFound();
        self::assertSame(0, DB::table('student_grade_components')->count());
        self::assertSame(0, DB::table('grade_audit_logs')->count());
    }

    public function test_stale_save_and_locked_second_part_cannot_partially_write(): void
    {
        $old = $this->payload([['grade_component_id' => 1, 'mark' => 15]]);
        $this->save([['grade_component_id' => 1, 'mark' => 10]])->assertOk();
        $this->putJson(self::BASE.'/students/1/registrations/1/marks', $old)->assertConflict()->assertJsonPath('error_code', 'manual_grade_entry_stale');
        DB::table('grade_part_approvals')->insert(['course_offering_id' => 1, 'component_type' => 'practical', 'status' => 'submitted', 'submission_version' => 1]);
        $before = DB::table('student_grade_components')->get()->toJson();
        $this->save([['grade_component_id' => 1, 'mark' => 20], ['grade_component_id' => 2, 'mark' => 30]],
            ['correction_confirmed' => true, 'correction_reason' => 'تصحيح'])->assertConflict();
        self::assertSame($before, DB::table('student_grade_components')->get()->toJson());
    }

    public function test_second_part_audit_failure_rolls_back_both_parts(): void
    {
        $this->withoutExceptionHandling();
        \App\Models\GradeAuditLog::creating(function (): void {
            if (DB::table('grade_audit_logs')->count() === 1) throw new \RuntimeException('Injected audit failure');
        });
        try {
            $this->save([['grade_component_id' => 1, 'mark' => 20], ['grade_component_id' => 2, 'mark' => 15]]);
            self::fail('The second audit should fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected audit failure', $exception->getMessage());
        } finally {
            \App\Models\GradeAuditLog::flushEventListeners();
        }
        self::assertSame(0, DB::table('grade_audit_logs')->count());
        self::assertSame(0, DB::table('student_grade_components')->count());
    }

    public function test_deprived_inactive_and_materialized_attempts_are_read_only(): void
    {
        foreach (['registration_status_id' => 2, 'result_status_id' => 3] as $field => $value) {
            DB::table('student_course_registrations')->where('student_course_registration_id', 1)->update([$field => $value]);
            $this->save([['grade_component_id' => 1, 'mark' => 30]])->assertConflict();
            DB::table('student_course_registrations')->where('student_course_registration_id', 1)->update([$field => $field === 'registration_status_id' ? 1 : null]);
        }
        DB::table('supplementary_exam_materializations')->insert(['student_course_registration_id' => 1]);
        $this->save([['grade_component_id' => 1, 'mark' => 30]])->assertConflict();
        self::assertSame(0, DB::table('student_grade_components')->count());
    }

    public function test_historical_selected_registration_cannot_submit_an_offering_part(): void
    {
        $this->save([['grade_component_id' => 1, 'mark' => 30]], [], 2)->assertOk();
        DB::table('student_course_registrations')->where('student_course_registration_id', 1)->update(['registration_status_id' => 2]);
        self::assertFalse($this->row()['parts']['theoretical']['can_check_submission']);
        $ready = $this->readiness('theoretical');
        self::assertFalse($ready['can_submit']);
        $this->postJson($this->partPath('theoretical').'/submit', ['revision' => $ready['revision'], 'confirmed' => true])->assertConflict();
        self::assertSame(0, DB::table('grade_part_approvals')->count());
    }

    public function test_official_offering_cannot_be_submitted_when_every_student_is_exempt(): void
    {
        DB::table('student_course_registrations')->update(['result_status_id' => 3]);
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1, 'approved_by_user_id' => 1]);
        foreach ([null, 'draft', 'returned'] as $partStatus) {
            DB::table('grade_part_approvals')->delete();
            if ($partStatus !== null) DB::table('grade_part_approvals')->insert([
                'course_offering_id' => 1, 'component_type' => 'theoretical', 'status' => $partStatus, 'submission_version' => 1,
            ]);
            $tables = ['grade_part_approvals', 'grade_part_approval_events', 'grade_approvals',
                'student_grade_components', 'grade_audit_logs', 'student_course_results', 'student_course_registrations'];
            $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()]);
            $ready = $this->readiness('theoretical');
            self::assertSame(2, $ready['counts']['exempt']);
            self::assertFalse($ready['can_submit']);
            self::assertSame('official_result_locked', $ready['blocked_reason']);
            $this->postJson($this->partPath('theoretical').'/submit', ['revision' => $ready['revision'], 'confirmed' => true])
                ->assertConflict()->assertJsonPath('error_code', 'official_result_locked');
            foreach ($tables as $table) self::assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
        }
    }

    public function test_official_approval_after_readiness_is_rechecked_inside_locked_submit(): void
    {
        DB::table('student_course_registrations')->update(['result_status_id' => 3]);
        $ready = $this->readiness('theoretical');
        self::assertTrue($ready['can_submit']);
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1, 'approved_by_user_id' => 1]);
        $this->postJson($this->partPath('theoretical').'/submit', ['revision' => $ready['revision'], 'confirmed' => true])
            ->assertConflict()->assertJsonPath('error_code', 'official_result_locked');
        foreach (['grade_part_approvals', 'grade_part_approval_events', 'student_grade_components', 'grade_audit_logs', 'student_course_results'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }
        self::assertSame(1, DB::table('grade_approvals')->count());
    }

    public function test_complete_offering_submission_return_correction_and_official_finalization(): void
    {
        $ready = $this->readiness('theoretical');
        self::assertFalse($ready['can_submit']);
        self::assertSame(2, $ready['counts']['incomplete']);
        $this->postJson($this->partPath('theoretical').'/submit', ['revision' => $ready['revision'], 'confirmed' => true])->assertConflict();
        foreach ([1, 2] as $id) $this->save([['grade_component_id' => 1, 'mark' => 50], ['grade_component_id' => 2, 'mark' => 30]], [], $id)->assertOk();
        $stale = $this->readiness('theoretical');
        $this->save([['grade_component_id' => 1, 'mark' => 51]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح'])->assertOk();
        $this->postJson($this->partPath('theoretical').'/submit', ['revision' => $stale['revision'], 'confirmed' => true])->assertConflict();
        $this->submitPart('theoretical')->assertOk();
        $this->save([['grade_component_id' => 1, 'mark' => 52]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح'])->assertConflict();
        $id = DB::table('grade_part_approvals')->where('component_type', 'theoretical')->value('grade_part_approval_id');
        $this->postJson('/api/v1/grade-part-approvals/'.$id.'/return-for-correction', ['review_notes' => 'راجع الورقة'])->assertOk();
        $this->save([['grade_component_id' => 1, 'mark' => 50]], ['correction_confirmed' => true, 'correction_reason' => 'مراجعة الورقة'])->assertOk();
        $this->submitPart('theoretical')->assertOk();
        self::assertSame(2, DB::table('grade_part_approvals')->where('grade_part_approval_id', $id)->value('submission_version'));
        $this->postJson('/api/v1/grade-part-approvals/'.$id.'/approve')->assertOk();
        $grades = app(GradeService::class);
        self::assertSame(0, $grades->officialAcademicAttempts(Student::findOrFail(1))->count());
        self::assertSame(0, DB::table('student_course_results')->count());
        $this->submitPart('practical')->assertOk();
        $practical = DB::table('grade_part_approvals')->where('component_type', 'practical')->value('grade_part_approval_id');
        $this->postJson('/api/v1/grade-part-approvals/'.$practical.'/approve')->assertOk();
        self::assertSame(2, DB::table('student_course_results')->count());
        self::assertSame(1, $grades->officialAcademicAttempts(Student::findOrFail(1))->count());
        self::assertSame(2, $grades->scopeOfficialApprovedResults(StudentCourseResult::query())->count());
        $transcript = $grades->getTranscript(Student::findOrFail(1));
        self::assertNotEmpty($transcript['terms']);
        self::assertSame(3.0, (float) $grades->calculateCgpa(Student::findOrFail(1))['cgpa']);
        $this->save([['grade_component_id' => 2, 'mark' => 31]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح'])->assertConflict();
    }

    public function test_required_single_and_multi_component_shapes_and_omission(): void
    {
        DB::table('grade_components')->where('grade_component_id', 2)->delete();
        self::assertSame(['theoretical'], array_keys($this->row()['parts']));
        DB::table('grade_components')->where('grade_component_id', 1)->update(['component_type' => 'practical']);
        self::assertSame(['practical'], array_keys($this->row()['parts']));
        DB::table('grade_components')->insert(['grade_component_id' => 3, 'course_offering_id' => 1, 'component_type' => 'practical', 'component_name' => 'مخبر', 'max_mark' => 20, 'is_required' => 1]);
        $this->save([['grade_component_id' => 1, 'mark' => 10]])->assertOk();
        self::assertFalse($this->readiness('practical')['can_submit']);
        $this->save([['grade_component_id' => 3, 'mark' => 15]])->assertOk();
        self::assertSame(10.0, (float) DB::table('student_grade_components')->where('grade_component_id', 1)->value('mark'));
        self::assertCount(2, $this->row()['components']);
    }

    public function test_instructor_endpoint_still_requires_assignment(): void
    {
        $this->putJson('/api/v1/registrations/1/grade-parts/theoretical', ['components' => [['grade_component_id' => 1, 'mark' => 20]]])->assertForbidden();
        self::assertSame(0, DB::table('student_grade_components')->count());
    }

    public function test_existing_assigned_instructor_save_still_works_without_manual_capability(): void
    {
        DB::table('employees')->insert(['employee_id' => 1, 'first_name' => 'Professor']);
        DB::table('faculty_members')->insert(['faculty_member_id' => 1, 'employee_id' => 1, 'is_active' => 1]);
        DB::table('users')->where('user_id', 1)->update(['employee_id' => 1]);
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'doctor_instructor']);
        DB::table('course_offering_instructors')->insert(['course_offering_id' => 1, 'faculty_member_id' => 1, 'instructor_role' => 'theoretical', 'is_active' => 1, 'current_slot' => 1]);
        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson(self::BASE.'/students?q=Student')->assertForbidden();
        $this->putJson('/api/v1/registrations/1/grade-parts/theoretical', ['components' => [['grade_component_id' => 1, 'mark' => 20]]])->assertOk();
        self::assertSame(20.0, (float) DB::table('student_grade_components')->value('mark'));
    }

    public function test_revision_detects_a_mark_changed_and_changed_back_within_the_same_clock_tick(): void
    {
        $this->freezeTime();
        $this->save([['grade_component_id' => 1, 'mark' => 10]])->assertOk();
        $old = $this->payload([['grade_component_id' => 1, 'mark' => 15]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح']);
        foreach ([20, 10] as $mark) $this->save([['grade_component_id' => 1, 'mark' => $mark]], ['correction_confirmed' => true, 'correction_reason' => 'تصحيح'])->assertOk();
        $this->putJson(self::BASE.'/students/1/registrations/1/marks', $old)->assertConflict();
    }

    public function test_real_valid_theoretical_deferral_is_read_only_and_counted_as_exempt(): void
    {
        $this->installDeferralSchema();
        self::assertTrue(\App\Support\SupplementaryExamEligibilityGovernance::schemaReady());
        $this->save([['grade_component_id' => 2, 'mark' => 30]])->assertOk();
        DB::table('supplementary_exam_periods')->insert(['supplementary_exam_period_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'announced']);
        DB::table('supplementary_exam_offerings')->insert(['supplementary_exam_offering_id' => 1, 'supplementary_exam_period_id' => 1, 'academic_program_id' => 1, 'course_id' => 1, 'status' => 'open', 'opened_by_user_id' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplementary_exam_offering_sources')->insert(['supplementary_exam_offering_id' => 1, 'course_offering_id' => 1, 'created_at' => now()]);
        DB::table('supplementary_exam_theoretical_deferrals')->insert(['supplementary_exam_offering_id' => 1, 'student_course_registration_id' => 1, 'status' => 'declared', 'current_slot' => 1, 'declared_by_user_id' => 1, 'declared_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame('supplementary_theoretical_deferred', $this->row()['parts']['theoretical']['blocked_reason']);
        $this->save([['grade_component_id' => 1, 'mark' => 30]])->assertConflict();
        self::assertSame(1, $this->readiness('theoretical')['counts']['exempt']);
        self::assertSame(0, DB::table('student_grade_components')->where('grade_component_id', 1)->count());
        self::assertSame('declared', DB::table('supplementary_exam_theoretical_deferrals')->value('status'));
    }

    private function installDeferralSchema(): void
    {
        // Full, schema-ready test-only supplementary contract; no mocked deferral or grading decision.
        Schema::drop('supplementary_exam_offerings');
        Schema::drop('supplementary_exam_periods');
        Schema::create('supplementary_exam_periods', function (Blueprint $t): void {
            $t->integer('supplementary_exam_period_id')->autoIncrement();
            $t->integer('academic_year_id'); $t->integer('semester_id'); $t->string('status');
            $t->integer('opened_by_user_id')->nullable(); $t->dateTime('opened_at')->nullable(); $t->text('decision_note')->nullable(); $t->timestamps();
            $t->unique(['academic_year_id', 'semester_id']);
        });
        Schema::create('supplementary_exam_offerings', function (Blueprint $t): void {
            $t->integer('supplementary_exam_offering_id')->autoIncrement();
            $t->integer('supplementary_exam_period_id'); $t->integer('academic_program_id'); $t->integer('course_id'); $t->string('status', 16);
            $t->integer('opened_by_user_id'); $t->dateTime('opened_at'); $t->integer('closed_by_user_id')->nullable(); $t->dateTime('closed_at')->nullable(); $t->timestamp('created_at'); $t->timestamp('updated_at');
            $t->unique(['supplementary_exam_period_id', 'academic_program_id', 'course_id']);
            foreach (['supplementary_exam_period_id' => 'supplementary_exam_periods', 'academic_program_id' => 'academic_programs', 'course_id' => 'courses'] as $key => $table) $t->foreign($key)->references($key)->on($table);
            $t->foreign('opened_by_user_id')->references('user_id')->on('users'); $t->foreign('closed_by_user_id')->references('user_id')->on('users');
        });
        Schema::create('supplementary_exam_offering_sources', function (Blueprint $t): void {
            $t->integer('supplementary_exam_offering_source_id')->autoIncrement(); $t->integer('supplementary_exam_offering_id'); $t->integer('course_offering_id'); $t->timestamp('created_at');
            $t->unique(['supplementary_exam_offering_id', 'course_offering_id']);
            $t->foreign('supplementary_exam_offering_id')->references('supplementary_exam_offering_id')->on('supplementary_exam_offerings'); $t->foreign('course_offering_id')->references('course_offering_id')->on('course_offerings');
        });
        foreach (['period', 'offering'] as $kind) {
            Schema::create('supplementary_exam_'.$kind.'_events', function (Blueprint $t) use ($kind): void {
                $key = 'supplementary_exam_'.$kind.'_id';
                $t->integer('supplementary_exam_'.$kind.'_event_id')->autoIncrement(); $t->integer($key); $t->string('event_type'); $t->string('from_status')->nullable(); $t->string('to_status'); $t->integer('actor_user_id'); $t->text('notes')->nullable(); $t->timestamp('created_at');
                $t->index($key); $t->index('actor_user_id'); $t->index(['event_type', 'to_status']);
                $t->foreign($key)->references($key)->on('supplementary_exam_'.$kind.'s'); $t->foreign('actor_user_id')->references('user_id')->on('users');
            });
        }
        Schema::create('supplementary_exam_theoretical_deferrals', function (Blueprint $t): void {
            $t->integer('supplementary_exam_theoretical_deferral_id')->autoIncrement(); $t->integer('supplementary_exam_offering_id'); $t->integer('student_course_registration_id'); $t->string('status', 16); $t->tinyInteger('current_slot')->nullable(); $t->integer('declared_by_user_id'); $t->dateTime('declared_at');
            $t->integer('cancelled_by_user_id')->nullable(); $t->dateTime('cancelled_at')->nullable(); $t->text('cancellation_reason')->nullable(); $t->dateTime('superseded_at')->nullable(); $t->text('supersede_reason')->nullable(); $t->timestamp('created_at'); $t->timestamp('updated_at');
            $t->unique(['supplementary_exam_offering_id', 'student_course_registration_id']); $t->unique(['student_course_registration_id', 'current_slot']); $t->index('declared_by_user_id'); $t->index('cancelled_by_user_id');
            $t->foreign('supplementary_exam_offering_id')->references('supplementary_exam_offering_id')->on('supplementary_exam_offerings'); $t->foreign('student_course_registration_id')->references('student_course_registration_id')->on('student_course_registrations');
            $t->foreign('declared_by_user_id')->references('user_id')->on('users'); $t->foreign('cancelled_by_user_id')->references('user_id')->on('users');
        });
        Schema::create('supplementary_exam_theoretical_deferral_events', function (Blueprint $t): void {
            $t->integer('supplementary_exam_theoretical_deferral_event_id')->autoIncrement(); $t->integer('supplementary_exam_theoretical_deferral_id'); $t->string('event_type', 16); $t->string('from_status', 16)->nullable(); $t->string('to_status', 16); $t->integer('actor_user_id'); $t->text('notes')->nullable(); $t->dateTime('created_at');
            $t->index(['supplementary_exam_theoretical_deferral_id', 'created_at']); $t->index('actor_user_id'); $t->foreign('supplementary_exam_theoretical_deferral_id')->references('supplementary_exam_theoretical_deferral_id')->on('supplementary_exam_theoretical_deferrals'); $t->foreign('actor_user_id')->references('user_id')->on('users');
        });
        foreach (['supplementary_exams.eligibility.view', 'supplementary_exams.deferrals.self'] as $code) DB::table('permissions')->insert(['permission_code' => $code, 'is_active' => 1]);
    }

    public function test_query_count_for_student_page_is_bounded_as_registrations_increase(): void
    {
        DB::enableQueryLog();
        $this->row();
        $first = count(DB::getQueryLog());
        for ($i = 3; $i <= 8; $i++) DB::table('student_course_registrations')->insert(['student_course_registration_id' => $i, 'student_id' => 1, 'course_offering_id' => 1, 'registration_status_id' => 1]);
        DB::flushQueryLog();
        $this->row();
        self::assertLessThanOrEqual($first + 2, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    private function row(int $id = 1): array
    {
        return collect($this->getJson(self::BASE.'/students/'.$id.'/registrations')->assertOk()->json('data.registrations'))->firstWhere('registration_id', $id);
    }
    private function payload(array $components, array $extra = [], int $id = 1): array
    {
        return array_replace(['revision' => $this->row($id)['revision'], 'acknowledged' => true, 'components' => $components], $extra);
    }
    private function save(array $components, array $extra = [], int $id = 1)
    {
        return $this->putJson(self::BASE.'/students/'.$id.'/registrations/'.$id.'/marks', $this->payload($components, $extra, $id));
    }
    private function partPath(string $part): string { return self::BASE.'/students/1/registrations/1/parts/'.$part; }
    private function readiness(string $part): array { return $this->getJson($this->partPath($part).'/submission-readiness')->assertOk()->json('data'); }
    private function submitPart(string $part) { return $this->postJson($this->partPath($part).'/submit', ['revision' => $this->readiness($part)['revision'], 'confirmed' => true]); }

    private function schema(): void
    {
        // Test-only projection of every column queried by these real production paths.
        $tables = [
            'account_statuses' => ['account_status_id', ['status_code']],
            'users' => ['user_id', ['username'], ['account_status_id', 'employee_id', 'student_id']],
            'roles' => ['role_id', ['role_code'], [], ['is_active']],
            'permissions' => ['permission_id', ['permission_code'], [], ['is_active']],
            'user_roles' => ['user_role_id', [], ['user_id', 'role_id'], ['is_active']],
            'role_permissions' => ['role_permission_id', [], ['role_id', 'permission_id']],
            'organizational_units' => ['organizational_unit_id', ['unit_code']],
            'user_access_scopes' => ['user_access_scope_id', ['scope_type'], ['user_id', 'scope_id'], ['is_active']],
            'colleges' => ['college_id', ['college_name']],
            'departments' => ['department_id', ['department_name'], ['college_id']],
            'academic_programs' => ['academic_program_id', ['program_name'], ['department_id']],
            'academic_levels' => ['academic_level_id', ['level_name']],
            'academic_years' => ['academic_year_id', ['year_name', 'start_date', 'end_date']],
            'semesters' => ['semester_id', ['semester_name'], ['semester_order']],
            'students' => ['student_id', ['student_number', 'first_name', 'last_name'], ['academic_program_id', 'current_academic_level_id']],
            'courses' => ['course_id', ['course_code', 'course_name'], ['credit_hours', 'theoretical_hours', 'practical_hours']],
            'course_offerings' => ['course_offering_id', ['status'], ['course_id', 'academic_year_id', 'semester_id', 'department_id', 'academic_program_id', 'faculty_member_id']],
            'registration_statuses' => ['registration_status_id', ['status_code', 'status_name'], [], ['is_active']],
            'result_statuses' => ['result_status_id', ['status_code', 'status_name'], [], ['is_active']],
            'student_course_registrations' => ['student_course_registration_id', [], ['student_id', 'course_offering_id', 'registration_status_id', 'result_status_id']],
            'grade_components' => ['grade_component_id', ['component_name', 'component_type'], ['course_offering_id'], ['is_required'], ['max_mark']],
            'student_grade_components' => ['student_grade_component_id', ['grade_status', 'notes', 'entered_at'], ['student_course_registration_id', 'grade_component_id', 'entered_by_user_id'], [], ['mark']],
            'grade_audit_logs' => ['grade_audit_log_id', ['change_reason', 'changed_at'], ['student_grade_component_id', 'changed_by_user_id'], [], ['old_mark', 'new_mark']],
            'grade_part_approvals' => ['grade_part_approval_id', ['component_type', 'status', 'submitted_at', 'reviewed_at', 'review_notes'], ['course_offering_id', 'submission_version', 'submitted_by_user_id', 'reviewed_by_user_id']],
            'grade_part_approval_events' => ['grade_part_approval_event_id', ['action', 'old_values', 'new_values', 'performed_at'], ['grade_part_approval_id', 'submission_version', 'performed_by_user_id']],
            'approval_statuses' => ['approval_status_id', ['status_code'], [], ['is_active']],
            'grade_approvals' => ['grade_approval_id', ['submitted_at', 'approval_date', 'approval_role', 'approval_notes'], ['course_offering_id', 'approval_status_id', 'submitted_by_user_id', 'approved_by_user_id']],
            'student_course_results' => ['student_course_result_id', ['calculated_at'], ['student_course_registration_id', 'result_status_id', 'calculated_by_user_id'], ['is_deprived'], ['theoretical_total', 'practical_total', 'coursework_total', 'final_mark']],
            'grading_policies' => ['grading_policy_id', ['policy_name'], [], ['is_default', 'is_active'], ['theoretical_max_mark', 'practical_max_mark', 'minimum_theoretical_mark', 'minimum_practical_mark', 'minimum_final_mark', 'absence_deprivation_percentage']],
            'employees' => ['employee_id', ['first_name', 'last_name']],
            'faculty_members' => ['faculty_member_id', [], ['employee_id'], ['is_active']],
            'course_offering_instructors' => ['course_offering_instructor_id', ['instructor_role'], ['course_offering_id', 'faculty_member_id', 'current_slot'], ['is_active', 'is_primary']],
            'program_courses' => ['program_course_id', [], ['academic_program_id', 'course_id', 'requirement_group_id'], ['is_active']],
            'supplementary_exam_materializations' => ['supplementary_exam_materialization_id', [], ['student_course_registration_id']],
            'supplementary_exam_periods' => ['supplementary_exam_period_id', ['status']],
            'supplementary_exam_offerings' => ['supplementary_exam_offering_id', [], ['supplementary_exam_period_id']],
            'supplementary_exam_registrations' => ['supplementary_exam_registration_id', ['status'], ['student_course_registration_id', 'supplementary_exam_offering_id', 'current_slot']],
        ];
        foreach ($tables as $name => $definition) {
            Schema::create($name, function (Blueprint $t) use ($definition, $name): void {
                $t->increments($definition[0]);
                foreach ($definition[1] as $column) $t->text($column)->nullable();
                foreach ($definition[2] ?? [] as $column) $t->integer($column)->nullable();
                foreach ($definition[3] ?? [] as $column) $t->boolean($column)->default($column !== 'is_deprived');
                foreach ($definition[4] ?? [] as $column) $t->decimal($column, 8, 2)->nullable();
                $t->timestamps();
                if ($name === 'students') $t->softDeletes();
            });
        }
        Schema::table('grade_part_approvals', fn (Blueprint $t) => $t->unique(['course_offering_id', 'component_type']));
        Schema::table('student_grade_components', fn (Blueprint $t) => $t->unique(['student_course_registration_id', 'grade_component_id']));
    }

    private function seed(): void
    {
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active']);
        DB::table('users')->insert(['user_id' => 1, 'username' => 'officer', 'account_status_id' => 1]);
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'exam_officer', 'is_active' => 1]);
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1, 'is_active' => 1]);
        foreach (['exams.manage', 'grades.manage', 'students.view', 'grades.view'] as $i => $code) {
            DB::table('permissions')->insert(['permission_id' => $i + 1, 'permission_code' => $code, 'is_active' => 1]);
            DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $i + 1]);
        }
        DB::table('organizational_units')->insert(['organizational_unit_id' => 1, 'unit_code' => 'PRES']);
        DB::table('user_access_scopes')->insert(['user_id' => 1, 'scope_type' => 'university', 'scope_id' => 1, 'is_active' => 1]);
        DB::table('colleges')->insert(['college_id' => 1, 'college_name' => 'College']);
        DB::table('departments')->insert(['department_id' => 1, 'college_id' => 1]);
        DB::table('academic_programs')->insert(['academic_program_id' => 1, 'department_id' => 1, 'program_name' => 'Program']);
        DB::table('academic_levels')->insert(['academic_level_id' => 1, 'level_name' => 'First']);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_name' => 'First', 'semester_order' => 1]);
        foreach ([1 => 'registered', 2 => 'dropped', 3 => 'cancelled'] as $id => $code) DB::table('registration_statuses')->insert(['registration_status_id' => $id, 'status_code' => $code]);
        foreach ([1 => 'passed', 2 => 'failed', 3 => 'deprived'] as $id => $code) DB::table('result_statuses')->insert(['result_status_id' => $id, 'status_code' => $code, 'is_active' => 1]);
        DB::table('approval_statuses')->insert(['approval_status_id' => 1, 'status_code' => 'approved', 'is_active' => 1]);
        DB::table('courses')->insert(['course_id' => 1, 'course_code' => 'C1', 'course_name' => 'Course', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 1]);
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'academic_program_id' => 1, 'department_id' => 1, 'status' => 'open']);
        foreach ([1, 2] as $id) {
            DB::table('students')->insert(['student_id' => $id, 'student_number' => 'S'.$id, 'first_name' => 'Student', 'last_name' => (string) $id, 'academic_program_id' => 1, 'current_academic_level_id' => 1]);
            DB::table('student_course_registrations')->insert(['student_course_registration_id' => $id, 'student_id' => $id, 'course_offering_id' => 1, 'registration_status_id' => 1]);
        }
        DB::table('grade_components')->insert([
            ['grade_component_id' => 1, 'course_offering_id' => 1, 'component_name' => 'Theory', 'component_type' => 'theoretical', 'max_mark' => 60, 'is_required' => 1],
            ['grade_component_id' => 2, 'course_offering_id' => 1, 'component_name' => 'Practice', 'component_type' => 'practical', 'max_mark' => 40, 'is_required' => 1],
        ]);
        DB::table('grading_policies')->insert(['grading_policy_id' => 1, 'policy_name' => 'Canonical', 'theoretical_max_mark' => 60, 'practical_max_mark' => 40, 'minimum_theoretical_mark' => 15, 'minimum_practical_mark' => 10, 'minimum_final_mark' => 50, 'is_default' => 1, 'is_active' => 1]);
    }
}
