<?php

namespace Tests\Feature;

use App\Models\{Student, User};
use App\Services\{GradeService, RegistrationService};
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class ExamManualGradePreparationBehaviorTest extends ExamManualGradeGridBehaviorTest
{
    private const PATH = '/api/v1/exams/manual-grade-entry/students/1/courses/1/context-';

    private function emptyContext(bool $past = false): void
    {
        foreach (['course_offering_schedule_slots', 'student_grade_components', 'grade_components', 'student_course_registrations', 'course_offerings'] as $table) DB::table($table)->delete();
        if ($past) {
            DB::table('academic_years')->where('academic_year_id', 1)->update(['year_name' => '2020', 'is_current' => false]);
            DB::table('academic_calendar_event_versions')->delete();
        }
    }

    private function previewContext(array $selection = []): array
    {
        return $this->getJson(self::PATH.'preview?'.http_build_query($selection + ['academic_year_id' => 1, 'semester_id' => 1]))->assertOk()->json('data');
    }

    private function contextPayload(array $preview, array $marks = []): array
    {
        return ['academic_year_id' => 1, 'semester_id' => 1, 'revision' => $preview['revision'],
            'confirmed' => true, 'acknowledged' => true, 'reason' => 'Authorized record entry',
            'components' => $marks ?: [['key' => $preview['components'][0]['key'], 'mark' => 0]]];
    }

    public function test_current_term_without_context_has_independent_period_choices_and_saves_draft_only(): void
    {
        $this->emptyContext();
        $this->getJson('/api/v1/exams/manual-grade-entry/students/1/periods')->assertOk()
            ->assertJsonPath('data.terms', [])->assertJsonPath('data.academic_years.0.academic_year_id', 1)
            ->assertJsonPath('data.semesters.0.semester_id', 1);
        $preview = $this->previewContext();
        self::assertSame(0, DB::table('user_activity_logs')->count());
        $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertOk();
        self::assertSame(1, DB::table('course_offerings')->count());
        self::assertSame('closed', DB::table('course_offerings')->value('status'));
        self::assertSame(1, DB::table('student_course_registrations')->count());
        self::assertSame(0, DB::table('course_offering_schedule_slots')->count());
        self::assertSame(0, DB::table('student_course_results')->count());
    }

    public function test_no_offering_historical_recording_without_window_or_timetable_is_atomic_and_canonical(): void
    {
        $this->emptyContext(true);
        $preview = $this->previewContext();
        self::assertTrue($preview['create_offering']);
        self::assertTrue($preview['create_registration']);
        self::assertTrue($preview['create_components']);
        self::assertSame(0, DB::table('course_offerings')->count());
        $saved = $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertOk()->json('data');
        self::assertSame('closed', DB::table('course_offerings')->value('status'));
        self::assertTrue(app(RegistrationService::class)->getSelfRegistrationOfferings(Student::findOrFail(1), 1, 1)->isEmpty());
        self::assertSame(1, DB::table('student_course_registrations')->count());
        self::assertSame(2, DB::table('grade_components')->count());
        self::assertSame(0.0, (float) DB::table('student_grade_components')->value('mark'));
        self::assertSame('draft', DB::table('student_grade_components')->value('grade_status'));
        self::assertNull(DB::table('course_offerings')->value('faculty_member_id'));
        self::assertNull(DB::table('student_course_registrations')->value('advisor_user_id'));
        foreach (['student_registration_requests', 'course_offering_schedule_slots', 'grade_part_approvals', 'grade_part_approval_events', 'student_course_results', 'course_offering_instructors'] as $table) self::assertSame(0, DB::table($table)->count(), $table);
        $audit = json_decode(DB::table('user_activity_logs')->where('action_code', 'manual_grade_entry.context')->value('description'), true);
        self::assertContains('weekly_timetable_completeness_and_conflicts', $audit['waived']);
        self::assertSame(1, $audit['student_id']);
        self::assertSame($saved['registration_id'], $audit['registration_id']);
        // Repeating the old confirmation cannot create a duplicate or silently attach to the new revision.
        $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertConflict();
        self::assertSame(1, DB::table('course_offerings')->count());
        self::assertSame(1, DB::table('student_course_registrations')->count());
    }

    public function test_closed_context_reuse_correction_zero_null_and_stale_preview(): void
    {
        DB::table('course_offerings')->update(['status' => 'closed']);
        $preview = $this->previewContext();
        self::assertFalse($preview['create_offering']);
        $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertOk();
        $next = $this->previewContext();
        $payload = $this->contextPayload($next, [['key' => '1', 'mark' => null]]);
        $this->postJson(self::PATH.'save', $payload)->assertUnprocessable();
        $this->postJson(self::PATH.'save', $payload + ['correction_confirmed' => true])->assertOk();
        self::assertNull(DB::table('student_grade_components')->where('grade_component_id', 1)->value('mark'));
        self::assertSame('closed', DB::table('course_offerings')->value('status'));
        self::assertSame(2, DB::table('grade_audit_logs')->count());
        $this->postJson(self::PATH.'save', $this->contextPayload($next))->assertConflict();
    }

    public function test_prepared_past_closed_offering_completes_the_canonical_grade_cycle(): void
    {
        $this->emptyContext(true);
        $row = $this->postJson(self::PATH.'save', $this->contextPayload($this->previewContext()))->assertOk()->json('data');
        $base = '/api/v1/exams/manual-grade-entry/students/1/registrations/'.$row['registration_id'].'/parts/';
        $ready = $this->getJson($base.'practical/submission-readiness')->assertOk()->json('data');
        self::assertFalse($ready['can_submit']);
        $this->postJson($base.'practical/submit', ['confirmed' => true, 'revision' => $ready['revision']])->assertConflict();
        $preview = $this->previewContext();
        $marks = array_map(fn ($c) => ['key' => $c['key'], 'mark' => $c['component_type'] === 'theoretical' ? 50 : 30], $preview['components']);
        $this->postJson(self::PATH.'save', $this->contextPayload($preview, $marks) + ['correction_confirmed' => true])->assertOk();
        $submit = function (string $part) use ($base): void {
            $ready = $this->getJson($base.$part.'/submission-readiness')->assertOk()->json('data');
            $this->postJson($base.$part.'/submit', ['confirmed' => true, 'revision' => $ready['revision']])->assertOk();
        };
        $submit('theoretical');
        $id = DB::table('grade_part_approvals')->where('component_type', 'theoretical')->value('grade_part_approval_id');
        $this->postJson('/api/v1/grade-part-approvals/'.$id.'/return-for-correction', ['review_notes' => 'Review paper'])->assertOk();
        $preview = $this->previewContext();
        $theory = collect($preview['components'])->firstWhere('component_type', 'theoretical');
        $this->postJson(self::PATH.'save', $this->contextPayload($preview, [['key' => $theory['key'], 'mark' => 51]]) + ['correction_confirmed' => true])->assertOk();
        $submit('theoretical');
        $this->postJson('/api/v1/grade-part-approvals/'.$id.'/approve')->assertOk();
        self::assertSame(0, DB::table('student_course_results')->count());
        $submit('practical');
        $practical = DB::table('grade_part_approvals')->where('component_type', 'practical')->value('grade_part_approval_id');
        $this->postJson('/api/v1/grade-part-approvals/'.$practical.'/approve')->assertOk();
        self::assertSame(1, DB::table('student_course_results')->count());
        $grades = app(GradeService::class);
        self::assertNotEmpty($grades->getTranscript(Student::findOrFail(1))['terms']);
        self::assertSame(3.0, (float) $grades->calculateCgpa(Student::findOrFail(1))['cgpa']);
        self::assertSame('closed', DB::table('course_offerings')->value('status'));
        self::assertSame(0, DB::table('student_registration_requests')->count());
    }

    public function test_invalid_mark_and_audit_failure_roll_back_offering_registration_components_and_marks(): void
    {
        $this->emptyContext();
        $preview = $this->previewContext();
        $this->postJson(self::PATH.'save', $this->contextPayload($preview, [['key' => 'new:theoretical', 'mark' => 999]]))->assertUnprocessable();
        self::assertSame(0, DB::table('course_offerings')->count());
        \App\Models\UserActivityLog::creating(fn () => throw new \RuntimeException('audit rollback'));
        try { $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertStatus(500); }
        finally { \App\Models\UserActivityLog::flushEventListeners(); }
        foreach (['course_offerings', 'student_course_registrations', 'grade_components', 'student_grade_components', 'grade_audit_logs', 'user_activity_logs'] as $table) self::assertSame(0, DB::table($table)->count(), $table);
    }

    public function test_ordinary_registration_still_rejects_absent_and_conflicting_timetables(): void
    {
        DB::table('student_course_registrations')->where('student_id', 1)->delete();
        DB::table('course_offering_schedule_slots')->delete();
        $this->assertOrdinaryBlocked('offering_schedule_incomplete');
        foreach (['theoretical', 'practical'] as $i => $part) DB::table('course_offering_schedule_slots')->insert([
            'course_offering_id' => 1, 'component_type' => $part, 'day_of_week' => $i + 1, 'start_time' => '08:00:00', 'end_time' => '09:00:00']);
        DB::table('courses')->insert(['course_id' => 2, 'course_code' => 'Conflict', 'credit_hours' => 3, 'theoretical_hours' => 2]);
        DB::table('program_courses')->insert(['program_course_id' => 2, 'academic_program_id' => 1, 'course_id' => 2, 'is_active' => 1]);
        DB::table('program_course_requirement_groups')->insert(['program_course_id' => 2, 'requirement_group_id' => 1]);
        DB::table('course_offerings')->insert(['course_offering_id' => 2, 'course_id' => 2, 'academic_program_id' => 1, 'department_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'open']);
        DB::table('student_course_registrations')->insert(['student_id' => 1, 'course_offering_id' => 2, 'registration_status_id' => 1]);
        DB::table('course_offering_schedule_slots')->insert(['course_offering_id' => 2, 'component_type' => 'theoretical', 'day_of_week' => 1, 'start_time' => '08:30:00', 'end_time' => '09:30:00']);
        $this->assertOrdinaryBlocked('timetable_conflict');
        // Same conflict is exempt only in the explicitly authorized recording boundary.
        $this->postJson(self::PATH.'save', $this->contextPayload($this->previewContext()))->assertOk();
    }

    private function assertOrdinaryBlocked(string $code): void
    {
        try {
            DB::transaction(fn () => app(RegistrationService::class)->registerStudentWithinTransaction(['student_id' => 1, 'course_offering_id' => 1], 1));
            self::fail('Ordinary timetable guard was bypassed.');
        } catch (\App\Exceptions\RegistrationException $e) { self::assertSame($code, $e->errorCode); }
        self::assertSame(0, DB::table('student_course_registrations')->where('student_id', 1)->where('course_offering_id', 1)->count());
    }

    public function test_multicomponent_definitions_are_reused_without_reweighting(): void
    {
        DB::table('grade_components')->where('grade_component_id', 1)->update(['max_mark' => 30]);
        DB::table('grade_components')->insert(['grade_component_id' => 3, 'course_offering_id' => 1, 'component_type' => 'theoretical', 'component_name' => 'Second paper', 'max_mark' => 30, 'is_required' => true, 'status' => 'active']);
        $before = DB::table('grade_components')->get()->toJson();
        $preview = $this->previewContext();
        self::assertCount(3, $preview['components']);
        $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertOk();
        self::assertSame($before, DB::table('grade_components')->get()->toJson());
    }

    public function test_curriculum_prerequisites_and_credit_limits_are_not_exempt(): void
    {
        $this->emptyContext(true);
        DB::table('program_courses')->update(['is_active' => false]);
        $this->getJson(self::PATH.'preview?academic_year_id=1&semester_id=1')->assertUnprocessable()->assertJsonPath('error_code', 'course_not_in_program');
        DB::table('program_courses')->update(['is_active' => true]);
        DB::table('courses')->insert(['course_id' => 2, 'course_code' => 'PRE', 'course_name' => 'Prerequisite', 'credit_hours' => 3]);
        DB::table('course_prerequisites')->insert(['course_id' => 1, 'prerequisite_course_id' => 2]);
        $this->getJson(self::PATH.'preview?academic_year_id=1&semester_id=1')->assertUnprocessable()->assertJsonValidationErrors('course_offering_id');
        DB::table('course_prerequisites')->delete();
        DB::table('courses')->where('course_id', 1)->update(['credit_hours' => 22]);
        $this->getJson(self::PATH.'preview?academic_year_id=1&semester_id=1')->assertUnprocessable()->assertJsonValidationErrors('course_offering_id');
        self::assertSame(0, DB::table('course_offerings')->count());
    }

    public function test_single_part_missing_definition_uses_policy_not_ratios(): void
    {
        $this->emptyContext();
        DB::table('courses')->where('course_id', 1)->update(['practical_hours' => 0]);
        DB::table('grading_policies')->update(['minimum_final_mark' => 30]);
        $this->app->forgetInstance(GradeService::class);
        $preview = $this->previewContext();
        self::assertCount(1, $preview['components']);
        self::assertSame(60.0, (float) $preview['components'][0]['max_mark']);
        $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertOk();
    }

    public function test_explicit_offering_attempt_and_authority_cannot_be_forged(): void
    {
        $offering = (array) DB::table('course_offerings')->where('course_offering_id', 1)->first();
        DB::table('course_offerings')->insert(array_replace($offering, ['course_offering_id' => 2]));
        $this->getJson(self::PATH.'preview?academic_year_id=1&semester_id=1')->assertConflict();
        $preview = $this->previewContext(['course_offering_id' => 1, 'registration_id' => 1]);
        $this->postJson(self::PATH.'save', $this->contextPayload($preview) + ['course_offering_id' => 1, 'registration_id' => 2])->assertNotFound();
        $this->postJson(self::PATH.'save', $this->contextPayload($preview) + ['skip_timetable' => true])->assertUnprocessable();
        DB::table('roles')->where('role_id', 1)->update(['role_code' => 'super_admin']);
        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson(self::PATH.'preview?academic_year_id=1&semester_id=1')->assertForbidden();
        self::assertSame(0, DB::table('student_grade_components')->count());
    }

    public function test_preview_and_save_recheck_final_and_supplementary_locks(): void
    {
        $preview = $this->previewContext();
        DB::table('grade_approvals')->insert(['course_offering_id' => 1, 'approval_status_id' => 1]);
        $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertConflict();
        DB::table('grade_approvals')->delete();
        DB::table('supplementary_exam_materializations')->insert(['student_course_registration_id' => 1]);
        $this->getJson(self::PATH.'preview?academic_year_id=1&semester_id=1')->assertConflict();
        $this->postJson(self::PATH.'save', $this->contextPayload($preview))->assertConflict();
        self::assertSame(0, DB::table('student_grade_components')->count());
    }
}
