<?php

namespace Tests\Feature;

use App\Exceptions\GradeException;
use App\Models\Student;
use App\Models\SupplementaryExamOffering;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\SupplementaryExamMaterializationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamMaterializationBehaviorTest extends TestCase
{
    private const OLD_TIME = '2026-01-01 10:00:00';

    private const PUBLISHED_TIME = '2026-01-02 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->seedReferenceData();
        $this->seedPublishedCandidate();
    }

    #[Test]
    public function mutation_requires_actual_exam_officer_assigned_permission_and_scope(): void
    {
        $professor = $this->createActor(2, 'doctor_instructor', employeeId: 22);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($professor, $this->offering()),
            'supplementary_materialization_forbidden',
            403,
        );

        $superAdmin = $this->createActor(3, 'super_admin');
        $this->assertTrue($superAdmin->hasPermission('supplementary_exams.results.materialize'));
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($superAdmin, $this->offering()),
            'supplementary_materialization_forbidden',
            403,
        );

        DB::table('role_permissions')->delete();
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_forbidden',
            403,
        );
        $this->mapMaterializationPermission();

        $this->expectGradeError(
            fn () => $this->service(inScope: false)->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_out_of_scope',
            403,
        );

        $summary = $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->assertSame('materialized', $summary['status']);
    }

    #[Test]
    public function only_the_exact_published_current_roster_and_latest_version_are_accepted(): void
    {
        foreach (['draft', 'submitted', 'returned', 'approved'] as $status) {
            DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update(['status' => $status]);
            $this->expectGradeError(
                fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
                'supplementary_materialization_result_not_published',
                409,
            );
        }

        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update(['status' => 'published']);
        DB::table('supplementary_exam_registrations')->where('supplementary_exam_registration_id', 700)->update(['status' => 'cancelled']);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_empty_roster',
            409,
        );

        DB::table('supplementary_exam_registrations')->where('supplementary_exam_registration_id', 700)->update(['status' => 'registered']);
        DB::table('supplementary_exam_registrations')->where('supplementary_exam_registration_id', 700)->update(['current_slot' => null]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_empty_roster',
            409,
        );

        DB::table('supplementary_exam_registrations')->where('supplementary_exam_registration_id', 700)->update(['current_slot' => 1]);
        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update(['student_id' => 999]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_source_mismatch',
            409,
        );

        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update([
            'student_id' => 200,
            'student_course_registration_id' => 999,
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_source_mismatch',
            409,
        );

        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update([
            'student_course_registration_id' => 300,
            'supplementary_exam_offering_id' => 999,
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_roster_mismatch',
            409,
        );
    }

    #[Test]
    public function stale_or_superseded_submission_cannot_be_materialized(): void
    {
        DB::table('supplementary_exam_grade_submissions')->insert([
            'supplementary_exam_grade_submission_id' => 901,
            'supplementary_exam_offering_id' => 600,
            'submission_version' => 2,
            'status' => 'published',
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_stale_submission',
            409,
        );

        DB::table('supplementary_exam_grade_submissions')->where('supplementary_exam_grade_submission_id', 901)->update(['status' => 'returned']);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_submission_not_published',
            409,
        );
    }

    #[Test]
    public function published_source_changes_after_publication_fail_closed(): void
    {
        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update([
            'theoretical_mark' => 49,
            'updated_at' => '2026-01-03 10:00:00',
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_source_drift',
            409,
        );

        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update([
            'theoretical_mark' => 45,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_submissions')->where('supplementary_exam_grade_submission_id', 900)->update([
            'updated_at' => '2026-01-03 10:00:00',
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_source_drift',
            409,
        );
    }

    #[Test]
    public function exact_phase_five_publication_event_is_required(): void
    {
        DB::table('supplementary_exam_grade_events')->where('supplementary_exam_grade_event_id', 1000)->update([
            'theoretical_mark' => 39,
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_source_event_mismatch',
            409,
        );

        DB::table('supplementary_exam_grade_events')->where('supplementary_exam_grade_event_id', 1000)->delete();
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_source_event_mismatch',
            409,
        );
    }

    #[Test]
    public function published_theoretical_mark_must_remain_within_the_canonical_range(): void
    {
        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update([
            'theoretical_mark' => 61,
        ]);
        DB::table('supplementary_exam_grade_events')->where('supplementary_exam_grade_event_id', 1000)->update([
            'theoretical_mark' => 61,
        ]);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_mark_out_of_range',
            422,
        );
    }

    #[Test]
    public function target_drift_after_publication_fails_closed(): void
    {
        DB::table('student_course_results')->where('student_course_result_id', 400)->update([
            'theoretical_total' => 21,
            'updated_at' => '2026-01-03 10:00:00',
        ]);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_target_drift',
            409,
        );

        DB::table('student_course_results')->where('student_course_result_id', 400)->update([
            'theoretical_total' => 20,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('grade_components')->where('grade_component_id', 2)->update([
            'updated_at' => '2026-01-03 10:00:00',
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_target_drift',
            409,
        );
        $this->assertDatabaseCount('supplementary_exam_materializations', 0);
    }

    #[Test]
    public function official_result_preserves_practical_components_attendance_and_attempt_identity(): void
    {
        $beforeRegistrationCount = DB::table('student_course_registrations')->count();
        $beforePractical = DB::table('student_grade_components')->where('student_grade_component_id', 501)->first();
        $beforeAttendance = DB::table('student_attendance')->where('student_attendance_id', 1)->first();
        $beforeAnnouncement = DB::table('student_course_results')->where('student_course_result_id', 400)->value('result_announced_at');

        $summary = $this->service()->materializeOffering($this->actor(), $this->offering());

        $this->assertSame(1, $summary['candidate_count']);
        $this->assertSame(1, $summary['materialized_count']);
        $this->assertDatabaseHas('student_course_results', [
            'student_course_result_id' => 400,
            'student_course_registration_id' => 300,
            'theoretical_total' => 40,
            'practical_total' => 30,
            'coursework_total' => 7,
            'final_mark' => 70,
            'result_status_id' => 2,
            'is_deprived' => 0,
            'result_announced_at' => $beforeAnnouncement,
            'calculated_by_user_id' => 1,
        ]);
        $this->assertSame($beforeRegistrationCount, DB::table('student_course_registrations')->count());
        $this->assertEquals($beforePractical, DB::table('student_grade_components')->where('student_grade_component_id', 501)->first());
        $this->assertEquals($beforeAttendance, DB::table('student_attendance')->where('student_attendance_id', 1)->first());
    }

    #[Test]
    public function one_invalid_candidate_rolls_back_the_whole_offering(): void
    {
        $this->seedSecondCandidate(sourceStudentId: 999);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_source_mismatch',
            409,
        );

        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 400)->value('theoretical_total'));
        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 401)->value('theoretical_total'));
        $this->assertEquals(50, DB::table('student_course_results')->where('student_course_result_id', 400)->value('final_mark'));
        $this->assertSame(1, DB::table('student_course_registrations')->where('student_course_registration_id', 300)->value('result_status_id'));
        $this->assertDatabaseCount('supplementary_exam_materializations', 0);
        $this->assertDatabaseCount('supplementary_exam_materialization_events', 0);
    }

    #[Test]
    public function retries_are_no_op_and_a_different_source_version_conflicts(): void
    {
        $first = $this->service()->materializeOffering($this->actor(), $this->offering());
        $updatedAt = DB::table('student_course_results')->where('student_course_result_id', 400)->value('updated_at');
        $second = $this->service()->materializeOffering($this->actor(), $this->offering());

        $this->assertSame('materialized', $first['status']);
        $this->assertSame('already_materialized', $second['status']);
        $this->assertSame(0, $second['materialized_count']);
        $this->assertSame(1, $second['already_materialized_count']);
        $this->assertSame($updatedAt, DB::table('student_course_results')->where('student_course_result_id', 400)->value('updated_at'));
        $this->assertDatabaseCount('supplementary_exam_materializations', 1);
        $this->assertDatabaseCount('supplementary_exam_materialization_events', 1);

        DB::table('supplementary_exam_grade_submissions')->where('supplementary_exam_grade_submission_id', 900)->update(['submission_version' => 2]);
        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 800)->update(['submission_version' => 2]);
        DB::table('supplementary_exam_grade_events')->insert([
            'supplementary_exam_grade_event_id' => 1002,
            'supplementary_exam_grade_result_id' => 800,
            'supplementary_exam_grade_submission_id' => 900,
            'event_type' => 'published',
            'from_status' => 'approved',
            'to_status' => 'published',
            'submission_version' => 2,
            'theoretical_mark' => 40,
            'actor_user_id' => 1,
            'created_at' => self::PUBLISHED_TIME,
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_idempotency_conflict',
            409,
        );
    }

    #[Test]
    public function provenance_records_exact_source_actor_and_before_after_snapshots(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());

        $row = DB::table('supplementary_exam_materializations')->first();
        $this->assertSame(700, $row->supplementary_exam_registration_id);
        $this->assertSame(800, $row->supplementary_exam_grade_result_id);
        $this->assertSame(1000, $row->supplementary_exam_grade_event_id);
        $this->assertSame(900, $row->supplementary_exam_grade_submission_id);
        $this->assertSame(1, $row->source_submission_version);
        $this->assertSame(300, $row->student_course_registration_id);
        $this->assertSame(400, $row->student_course_result_id);
        $this->assertSame(1, $row->grade_approval_id);
        $this->assertSame(1, $row->preserved_registration_status_id);
        $this->assertEquals(20, $row->before_theoretical_total);
        $this->assertEquals(40, $row->after_theoretical_total);
        $this->assertEquals(30, $row->before_practical_total);
        $this->assertEquals(30, $row->after_practical_total);
        $this->assertEquals(50, $row->before_final_mark);
        $this->assertEquals(70, $row->after_final_mark);
        $this->assertSame(1, $row->materialized_by_user_id);
        $this->assertNotNull($row->materialized_at);
        $this->assertStringContainsString('"student_grade_component_id":501', $row->practical_components_snapshot);
        $this->assertDatabaseHas('supplementary_exam_materialization_events', [
            'supplementary_exam_materialization_id' => $row->supplementary_exam_materialization_id,
            'event_type' => 'official_result_materialized',
            'source_submission_version' => 1,
            'actor_user_id' => 1,
        ]);
    }

    #[Test]
    public function transcript_and_gpa_read_the_new_canonical_result_without_duplicate_credit(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        $student = Student::query()->findOrFail(200);
        $grades = new GradeService();

        $transcript = $grades->getTranscript($student);
        $course = $transcript['terms'][0]['courses'][0];
        $gpa = $grades->calculateGpa($student->fresh(), 1, 1);

        $this->assertSame(40.0, $course['theoretical_mark']);
        $this->assertSame(30.0, $course['practical_mark']);
        $this->assertSame(70.0, $course['final_mark']);
        $this->assertSame('passed', $course['result_status']['status_code']);
        $this->assertSame(1, $transcript['summary']['approved_courses_count']);
        $this->assertSame(1, $transcript['summary']['passed_courses_count']);
        $this->assertSame(2.5, $gpa['gpa']);
        $this->assertSame(3, $gpa['total_included_credit_hours']);
        $this->assertSame(1, $gpa['included_courses_count']);
    }

    #[Test]
    public function period_waits_for_every_roster_bearing_offering_and_ignores_zero_rosters(): void
    {
        $this->seedSecondOffering(withCandidate: true);

        $first = $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->assertFalse($first['period_materialized']);
        $this->assertSame('results_published', DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->value('status'));

        $second = $this->service()->materializeOffering($this->actor(), SupplementaryExamOffering::query()->findOrFail(601));
        $this->assertTrue($second['period_materialized']);
        $this->assertSame('results_materialized', DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->value('status'));
        $this->assertSame(1, DB::table('supplementary_exam_period_events')->where('event_type', 'results_materialized')->count());

        $retry = $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->assertSame('already_materialized', $retry['status']);
        $this->assertSame(1, DB::table('supplementary_exam_period_events')->where('event_type', 'results_materialized')->count());

        $this->resetMaterializationState();
        $this->seedSecondOffering(withCandidate: false);
        $zeroRoster = $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->assertTrue($zeroRoster['period_materialized']);
    }

    #[Test]
    public function period_does_not_become_terminal_when_an_earlier_official_target_drifted(): void
    {
        $this->seedSecondOffering(withCandidate: true);
        $this->service()->materializeOffering($this->actor(), $this->offering());

        DB::table('student_course_results')->where('student_course_result_id', 400)->update([
            'final_mark' => 71,
            'updated_at' => '2026-01-04 10:00:00',
        ]);

        $second = $this->service()->materializeOffering(
            $this->actor(),
            SupplementaryExamOffering::query()->findOrFail(601),
        );

        $this->assertFalse($second['period_materialized']);
        $this->assertSame(
            'results_published',
            DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->value('status'),
        );
        $this->assertSame(0, DB::table('supplementary_exam_period_events')->where('event_type', 'results_materialized')->count());
    }

    private function service(bool $inScope = true): SupplementaryExamMaterializationService
    {
        $scope = $this->createMock(DataScopeService::class);
        $scope->method('canMutateProgram')->willReturn($inScope);

        return new SupplementaryExamMaterializationService(new GradeService(), $scope);
    }

    private function actor(): User
    {
        return User::query()->findOrFail(1);
    }

    private function offering(): SupplementaryExamOffering
    {
        return SupplementaryExamOffering::query()->findOrFail(600);
    }

    private function expectGradeError(callable $callback, string $code, int $status): void
    {
        try {
            $callback();
            $this->fail("Expected GradeException {$code}.");
        } catch (GradeException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
        }
    }

    private function createActor(int $userId, string $roleCode, ?int $employeeId = null): User
    {
        $roleId = DB::table('roles')->insertGetId(['role_code' => $roleCode, 'is_active' => 1]);
        DB::table('users')->insert([
            'user_id' => $userId,
            'username' => "actor{$userId}",
            'employee_id' => $employeeId,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => self::OLD_TIME,
            'is_active' => 1,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function mapMaterializationPermission(): void
    {
        DB::table('role_permissions')->insert([
            'role_id' => 1,
            'permission_id' => 1,
            'granted_at' => self::OLD_TIME,
        ]);
    }

    private function seedReferenceData(): void
    {
        DB::table('system_modules')->insert(['module_id' => 1, 'module_code' => 'exams', 'is_active' => 1]);
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'exam_officer', 'is_active' => 1]);
        DB::table('permissions')->insert([
            'permission_id' => 1,
            'module_id' => 1,
            'permission_code' => 'supplementary_exams.results.materialize',
            'permission_name' => 'Materialize supplementary official results',
            'description' => 'owned:supplementary-exam-materialization-phase6',
            'is_active' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        $this->mapMaterializationPermission();
        DB::table('users')->insert([
            'user_id' => 1,
            'username' => 'exam.officer',
            'employee_id' => 11,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => 1,
            'role_id' => 1,
            'assigned_at' => self::OLD_TIME,
            'is_active' => 1,
        ]);

        DB::table('colleges')->insert(['college_id' => 1, 'college_code' => 'SCI', 'college_name' => 'Science']);
        DB::table('departments')->insert(['department_id' => 1, 'college_id' => 1, 'department_code' => 'CS', 'department_name' => 'Computing']);
        DB::table('academic_programs')->insert([
            'academic_program_id' => 10,
            'department_id' => 1,
            'program_code' => 'SE',
            'program_name' => 'Software Engineering',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('academic_years')->insert([
            'academic_year_id' => 1,
            'year_name' => '2025/2026',
            'start_date' => '2025-09-01',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('semesters')->insert([
            'semester_id' => 1,
            'semester_code' => 'FALL',
            'semester_name' => 'First',
            'semester_order' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('courses')->insert([
            'course_id' => 20,
            'course_code' => 'SE201',
            'course_name' => 'Algorithms',
            'credit_hours' => 3,
            'theoretical_hours' => 3,
            'practical_hours' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('registration_statuses')->insert([
            'registration_status_id' => 1,
            'status_code' => 'registered',
            'status_name' => 'Registered',
            'is_active' => 1,
        ]);
        DB::table('result_statuses')->insert([
            ['result_status_id' => 1, 'status_code' => 'failed', 'status_name' => 'Failed', 'is_active' => 1],
            ['result_status_id' => 2, 'status_code' => 'passed', 'status_name' => 'Passed', 'is_active' => 1],
            ['result_status_id' => 3, 'status_code' => 'incomplete', 'status_name' => 'Incomplete', 'is_active' => 1],
            ['result_status_id' => 4, 'status_code' => 'deprived', 'status_name' => 'Deprived', 'is_active' => 1],
        ]);
        DB::table('approval_statuses')->insert([
            'approval_status_id' => 1,
            'status_code' => 'approved',
            'status_name' => 'Approved',
            'is_active' => 1,
        ]);
        DB::table('grading_policies')->insert([
            'grading_policy_id' => 1,
            'policy_name' => 'Default',
            'theoretical_max_mark' => 60,
            'practical_max_mark' => 40,
            'minimum_theoretical_mark' => 30,
            'minimum_practical_mark' => 20,
            'minimum_final_mark' => 50,
            'is_default' => 1,
            'is_active' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => 100,
            'course_id' => 20,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'department_id' => 1,
            'academic_program_id' => 10,
            'status' => 'closed',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('grade_approvals')->insert([
            'grade_approval_id' => 1,
            'course_offering_id' => 100,
            'approval_status_id' => 1,
            'approved_by_user_id' => 1,
            'approval_date' => self::OLD_TIME,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('grade_components')->insert([
            [
                'grade_component_id' => 1,
                'course_offering_id' => 100,
                'component_name' => 'Theory',
                'component_type' => 'theoretical',
                'max_mark' => 60,
                'is_required' => 1,
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
            [
                'grade_component_id' => 2,
                'course_offering_id' => 100,
                'component_name' => 'Practical',
                'component_type' => 'practical',
                'max_mark' => 40,
                'is_required' => 1,
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
        ]);
        DB::table('supplementary_exam_periods')->insert([
            'supplementary_exam_period_id' => 500,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'results_published',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 600,
            'supplementary_exam_period_id' => 500,
            'academic_program_id' => 10,
            'course_id' => 20,
            'status' => 'closed',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_offering_sources')->insert([
            'supplementary_exam_offering_source_id' => 1,
            'supplementary_exam_offering_id' => 600,
            'course_offering_id' => 100,
            'created_at' => self::OLD_TIME,
        ]);
    }

    private function seedPublishedCandidate(): void
    {
        DB::table('students')->insert([
            'student_id' => 200,
            'student_number' => '2026001',
            'first_name' => 'Ali',
            'last_name' => 'Saleh',
            'academic_program_id' => 10,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 300,
            'student_id' => 200,
            'course_offering_id' => 100,
            'registration_status_id' => 1,
            'result_status_id' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_course_results')->insert([
            'student_course_result_id' => 400,
            'student_course_registration_id' => 300,
            'theoretical_total' => 20,
            'practical_total' => 30,
            'coursework_total' => 7,
            'final_mark' => 50,
            'result_status_id' => 1,
            'is_deprived' => 0,
            'calculated_at' => self::OLD_TIME,
            'result_announced_at' => '2026-01-01 12:00:00',
            'calculated_by_user_id' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_grade_components')->insert([
            'student_grade_component_id' => 501,
            'student_course_registration_id' => 300,
            'grade_component_id' => 2,
            'mark' => 30,
            'grade_status' => 'approved',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_attendance')->insert([
            'student_attendance_id' => 1,
            'student_id' => 200,
            'attendance_session_id' => 1,
            'attendance_status_id' => 1,
            'notes' => 'original attendance',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_registrations')->insert([
            'supplementary_exam_registration_id' => 700,
            'supplementary_exam_offering_id' => 600,
            'student_id' => 200,
            'student_course_registration_id' => 300,
            'status' => 'registered',
            'current_slot' => 1,
            'eligibility_reason' => 'failed_theoretical',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_grade_submissions')->insert([
            'supplementary_exam_grade_submission_id' => 900,
            'supplementary_exam_offering_id' => 600,
            'submission_version' => 1,
            'status' => 'published',
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_results')->insert([
            'supplementary_exam_grade_result_id' => 800,
            'supplementary_exam_registration_id' => 700,
            'supplementary_exam_offering_id' => 600,
            'student_course_registration_id' => 300,
            'student_id' => 200,
            'theoretical_mark' => 40,
            'status' => 'published',
            'submission_version' => 1,
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_events')->insert([
            'supplementary_exam_grade_event_id' => 1000,
            'supplementary_exam_grade_result_id' => 800,
            'supplementary_exam_grade_submission_id' => 900,
            'event_type' => 'published',
            'from_status' => 'approved',
            'to_status' => 'published',
            'submission_version' => 1,
            'theoretical_mark' => 40,
            'actor_user_id' => 1,
            'created_at' => self::PUBLISHED_TIME,
        ]);
    }

    private function seedSecondCandidate(int $sourceStudentId = 201): void
    {
        DB::table('students')->insert([
            'student_id' => 201,
            'student_number' => '2026002',
            'first_name' => 'Maya',
            'last_name' => 'Hassan',
            'academic_program_id' => 10,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 301,
            'student_id' => 201,
            'course_offering_id' => 100,
            'registration_status_id' => 1,
            'result_status_id' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_course_results')->insert([
            'student_course_result_id' => 401,
            'student_course_registration_id' => 301,
            'theoretical_total' => 20,
            'practical_total' => 30,
            'coursework_total' => 5,
            'final_mark' => 50,
            'result_status_id' => 1,
            'is_deprived' => 0,
            'calculated_at' => self::OLD_TIME,
            'result_announced_at' => '2026-01-01 12:00:00',
            'calculated_by_user_id' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_grade_components')->insert([
            'student_grade_component_id' => 502,
            'student_course_registration_id' => 301,
            'grade_component_id' => 2,
            'mark' => 30,
            'grade_status' => 'approved',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_registrations')->insert([
            'supplementary_exam_registration_id' => 701,
            'supplementary_exam_offering_id' => 600,
            'student_id' => 201,
            'student_course_registration_id' => 301,
            'status' => 'registered',
            'current_slot' => 1,
            'eligibility_reason' => 'failed_theoretical',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_grade_results')->insert([
            'supplementary_exam_grade_result_id' => 801,
            'supplementary_exam_registration_id' => 701,
            'supplementary_exam_offering_id' => 600,
            'student_course_registration_id' => 301,
            'student_id' => $sourceStudentId,
            'theoretical_mark' => 42,
            'status' => 'published',
            'submission_version' => 1,
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_events')->insert([
            'supplementary_exam_grade_event_id' => 1001,
            'supplementary_exam_grade_result_id' => 801,
            'supplementary_exam_grade_submission_id' => 900,
            'event_type' => 'published',
            'from_status' => 'approved',
            'to_status' => 'published',
            'submission_version' => 1,
            'theoretical_mark' => 42,
            'actor_user_id' => 1,
            'created_at' => self::PUBLISHED_TIME,
        ]);
    }

    private function seedSecondOffering(bool $withCandidate): void
    {
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 601,
            'supplementary_exam_period_id' => 500,
            'academic_program_id' => 10,
            'course_id' => 20,
            'status' => 'closed',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_offering_sources')->insert([
            'supplementary_exam_offering_source_id' => 2,
            'supplementary_exam_offering_id' => 601,
            'course_offering_id' => 100,
            'created_at' => self::OLD_TIME,
        ]);

        if (! $withCandidate) {
            return;
        }

        $this->seedSecondCandidate();
        DB::table('supplementary_exam_registrations')->where('supplementary_exam_registration_id', 701)->update(['supplementary_exam_offering_id' => 601]);
        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_grade_result_id', 801)->update(['supplementary_exam_offering_id' => 601]);
        DB::table('supplementary_exam_grade_submissions')->insert([
            'supplementary_exam_grade_submission_id' => 901,
            'supplementary_exam_offering_id' => 601,
            'submission_version' => 1,
            'status' => 'published',
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_events')->where('supplementary_exam_grade_event_id', 1001)->update([
            'supplementary_exam_grade_submission_id' => 901,
        ]);
    }

    private function resetMaterializationState(): void
    {
        DB::table('supplementary_exam_materialization_events')->delete();
        DB::table('supplementary_exam_materializations')->delete();
        DB::table('supplementary_exam_period_events')->delete();
        DB::table('supplementary_exam_offering_sources')->where('supplementary_exam_offering_id', 601)->delete();
        DB::table('supplementary_exam_grade_events')->where('supplementary_exam_grade_result_id', 801)->delete();
        DB::table('supplementary_exam_grade_results')->where('supplementary_exam_offering_id', 601)->delete();
        DB::table('supplementary_exam_grade_submissions')->where('supplementary_exam_offering_id', 601)->delete();
        DB::table('supplementary_exam_registrations')->where('supplementary_exam_offering_id', 601)->delete();
        DB::table('supplementary_exam_offerings')->where('supplementary_exam_offering_id', 601)->delete();
        DB::table('student_grade_components')->where('student_course_registration_id', 301)->delete();
        DB::table('student_course_results')->where('student_course_registration_id', 301)->delete();
        DB::table('student_course_registrations')->where('student_course_registration_id', 301)->delete();
        DB::table('students')->where('student_id', 201)->delete();
        DB::table('student_course_results')->where('student_course_result_id', 400)->update([
            'theoretical_total' => 20,
            'practical_total' => 30,
            'coursework_total' => 7,
            'final_mark' => 50,
            'result_status_id' => 1,
            'calculated_at' => self::OLD_TIME,
            'calculated_by_user_id' => 1,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_course_registrations')->where('student_course_registration_id', 300)->update([
            'result_status_id' => 1,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->update([
            'status' => 'results_published',
            'updated_at' => self::OLD_TIME,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('system_modules', function (Blueprint $table): void {
            $table->increments('module_id');
            $table->string('module_code');
            $table->boolean('is_active');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('role_id');
            $table->string('role_code');
            $table->boolean('is_active');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->increments('permission_id');
            $table->integer('module_id');
            $table->string('permission_code')->unique();
            $table->string('permission_name');
            $table->text('description')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->increments('role_permission_id');
            $table->integer('role_id');
            $table->integer('permission_id');
            $table->dateTime('granted_at')->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('username');
            $table->integer('employee_id')->nullable();
            $table->integer('student_id')->nullable();
            $table->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->increments('user_role_id');
            $table->integer('user_id');
            $table->integer('role_id');
            $table->integer('assigned_by_user_id')->nullable();
            $table->dateTime('assigned_at');
            $table->boolean('is_active');
        });
        Schema::create('user_access_scopes', function (Blueprint $table): void {
            $table->increments('user_access_scope_id');
            $table->integer('user_id');
            $table->string('scope_type');
            $table->integer('scope_id');
            $table->boolean('is_active');
        });
        Schema::create('organizational_units', function (Blueprint $table): void {
            $table->increments('organizational_unit_id');
            $table->string('unit_code');
        });

        Schema::create('colleges', function (Blueprint $table): void {
            $table->increments('college_id');
            $table->string('college_code');
            $table->string('college_name');
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->integer('college_id');
            $table->string('department_code');
            $table->string('department_name');
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->increments('academic_program_id');
            $table->integer('department_id')->nullable();
            $table->string('program_code');
            $table->string('program_name');
            $table->timestamps();
        });
        Schema::create('academic_levels', function (Blueprint $table): void {
            $table->increments('academic_level_id');
            $table->string('level_code');
            $table->string('level_name');
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->string('year_name');
            $table->date('start_date')->nullable();
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_code');
            $table->string('semester_name');
            $table->integer('semester_order');
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('credit_hours');
            $table->integer('theoretical_hours')->default(0);
            $table->integer('practical_hours')->default(0);
            $table->timestamps();
        });
        Schema::create('program_courses', function (Blueprint $table): void {
            $table->increments('program_course_id');
            $table->integer('academic_program_id');
            $table->integer('course_id');
            $table->boolean('is_active');
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->string('student_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->integer('academic_program_id')->nullable();
            $table->integer('current_academic_level_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->integer('department_id')->nullable();
            $table->integer('academic_program_id');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('registration_statuses', function (Blueprint $table): void {
            $table->increments('registration_status_id');
            $table->string('status_code');
            $table->string('status_name');
            $table->boolean('is_active');
        });
        Schema::create('result_statuses', function (Blueprint $table): void {
            $table->increments('result_status_id');
            $table->string('status_code');
            $table->string('status_name');
            $table->boolean('is_active');
        });
        Schema::create('approval_statuses', function (Blueprint $table): void {
            $table->increments('approval_status_id');
            $table->string('status_code');
            $table->string('status_name');
            $table->boolean('is_active');
        });
        Schema::create('student_course_registrations', function (Blueprint $table): void {
            $table->increments('student_course_registration_id');
            $table->integer('student_id');
            $table->integer('course_offering_id');
            $table->integer('registration_status_id');
            $table->integer('result_status_id')->nullable();
            $table->timestamps();
        });
        Schema::create('student_course_results', function (Blueprint $table): void {
            $table->increments('student_course_result_id');
            $table->integer('student_course_registration_id')->unique();
            $table->decimal('theoretical_total', 5, 2);
            $table->decimal('practical_total', 5, 2);
            $table->decimal('coursework_total', 5, 2);
            $table->decimal('final_mark', 5, 2);
            $table->integer('result_status_id');
            $table->boolean('is_deprived');
            $table->dateTime('calculated_at')->nullable();
            $table->dateTime('result_announced_at')->nullable();
            $table->integer('calculated_by_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('grade_approvals', function (Blueprint $table): void {
            $table->increments('grade_approval_id');
            $table->integer('course_offering_id');
            $table->integer('approval_status_id');
            $table->integer('approved_by_user_id')->nullable();
            $table->dateTime('approval_date')->nullable();
            $table->timestamps();
        });
        Schema::create('grade_components', function (Blueprint $table): void {
            $table->increments('grade_component_id');
            $table->integer('course_offering_id');
            $table->string('component_name');
            $table->string('component_type');
            $table->decimal('max_mark', 5, 2);
            $table->boolean('is_required');
            $table->timestamps();
        });
        Schema::create('student_grade_components', function (Blueprint $table): void {
            $table->increments('student_grade_component_id');
            $table->integer('student_course_registration_id');
            $table->integer('grade_component_id');
            $table->decimal('mark', 5, 2)->nullable();
            $table->string('grade_status');
            $table->timestamps();
        });
        Schema::create('grading_policies', function (Blueprint $table): void {
            $table->increments('grading_policy_id');
            $table->string('policy_name');
            $table->decimal('theoretical_max_mark', 5, 2);
            $table->decimal('practical_max_mark', 5, 2);
            $table->decimal('minimum_theoretical_mark', 5, 2);
            $table->decimal('minimum_practical_mark', 5, 2);
            $table->decimal('minimum_final_mark', 5, 2);
            $table->boolean('is_default');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('student_attendance', function (Blueprint $table): void {
            $table->increments('student_attendance_id');
            $table->integer('student_id');
            $table->integer('attendance_session_id');
            $table->integer('attendance_status_id');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('supplementary_exam_periods', function (Blueprint $table): void {
            $table->increments('supplementary_exam_period_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status', 32);
            $table->timestamps();
        });
        Schema::create('supplementary_exam_period_events', function (Blueprint $table): void {
            $table->increments('supplementary_exam_period_event_id');
            $table->integer('supplementary_exam_period_id');
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->integer('actor_user_id');
            $table->text('notes')->nullable();
            $table->dateTime('created_at');
        });
        Schema::create('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->increments('supplementary_exam_offering_id');
            $table->integer('supplementary_exam_period_id');
            $table->integer('academic_program_id');
            $table->integer('course_id');
            $table->string('status', 16);
            $table->timestamps();
        });
        Schema::create('supplementary_exam_offering_sources', function (Blueprint $table): void {
            $table->increments('supplementary_exam_offering_source_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('course_offering_id');
            $table->dateTime('created_at');
        });
        Schema::create('supplementary_exam_registrations', function (Blueprint $table): void {
            $table->increments('supplementary_exam_registration_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('student_id');
            $table->integer('student_course_registration_id');
            $table->string('status', 16);
            $table->tinyInteger('current_slot')->nullable();
            $table->string('eligibility_reason', 40);
            $table->timestamps();
        });
        Schema::create('supplementary_exam_grade_submissions', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grade_submission_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('submission_version');
            $table->string('status', 24);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplementary_exam_grade_results', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grade_result_id');
            $table->integer('supplementary_exam_registration_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('student_course_registration_id');
            $table->integer('student_id');
            $table->decimal('theoretical_mark', 5, 2);
            $table->string('status', 24);
            $table->integer('submission_version');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplementary_exam_grade_events', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grade_event_id');
            $table->integer('supplementary_exam_grade_result_id');
            $table->integer('supplementary_exam_grade_submission_id')->nullable();
            $table->string('event_type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->integer('submission_version');
            $table->decimal('theoretical_mark', 5, 2)->nullable();
            $table->integer('actor_user_id');
            $table->text('notes')->nullable();
            $table->dateTime('created_at');
        });

        $this->createMaterializationTables();
    }

    private function createMaterializationTables(): void
    {
        Schema::create('supplementary_exam_materializations', function (Blueprint $table): void {
            $table->increments('supplementary_exam_materialization_id');
            foreach ([
                'supplementary_exam_registration_id', 'supplementary_exam_offering_id',
                'supplementary_exam_grade_result_id', 'supplementary_exam_grade_event_id',
                'supplementary_exam_grade_submission_id',
                'source_submission_version', 'student_course_registration_id',
                'student_course_result_id', 'student_id', 'grading_policy_id',
                'grade_approval_id', 'preserved_registration_status_id',
            ] as $column) {
                $table->integer($column);
            }
            $table->decimal('source_theoretical_mark', 5, 2);
            $table->text('practical_components_snapshot');
            foreach (['source_registration_updated_at', 'source_result_published_at', 'source_submission_published_at', 'source_result_updated_at', 'source_submission_updated_at', 'grade_approval_updated_at'] as $column) {
                $table->dateTime($column);
            }
            foreach (['theoretical_total', 'practical_total', 'coursework_total', 'final_mark'] as $column) {
                $table->decimal("before_{$column}", 5, 2);
            }
            $table->integer('before_result_status_id');
            $table->integer('before_registration_result_status_id')->nullable();
            $table->boolean('before_is_deprived');
            $table->dateTime('before_calculated_at')->nullable();
            $table->dateTime('before_result_announced_at')->nullable();
            $table->integer('before_calculated_by_user_id')->nullable();
            $table->dateTime('before_result_updated_at');
            $table->dateTime('before_registration_updated_at');
            foreach (['theoretical_total', 'practical_total', 'coursework_total', 'final_mark'] as $column) {
                $table->decimal("after_{$column}", 5, 2);
            }
            $table->integer('after_result_status_id');
            $table->integer('after_registration_result_status_id');
            $table->boolean('after_is_deprived');
            $table->dateTime('after_calculated_at');
            $table->dateTime('after_result_announced_at')->nullable();
            $table->integer('after_calculated_by_user_id');
            $table->dateTime('after_result_updated_at');
            $table->dateTime('after_registration_updated_at');
            $table->integer('materialized_by_user_id');
            $table->dateTime('materialized_at');
            $table->dateTime('created_at');
            $table->unique('supplementary_exam_registration_id');
            $table->unique('supplementary_exam_grade_result_id');
            $table->unique('supplementary_exam_grade_event_id');
            $table->unique('student_course_registration_id');
            $table->unique('student_course_result_id');
            $table->unique([
                'supplementary_exam_grade_submission_id',
                'source_submission_version',
                'supplementary_exam_registration_id',
            ]);
        });
        Schema::create('supplementary_exam_materialization_events', function (Blueprint $table): void {
            $table->increments('supplementary_exam_materialization_event_id');
            $table->integer('supplementary_exam_materialization_id')->unique();
            $table->integer('supplementary_exam_offering_id');
            $table->integer('supplementary_exam_registration_id');
            $table->string('event_type', 64);
            $table->integer('source_submission_version');
            $table->integer('actor_user_id');
            $table->dateTime('created_at');
        });
    }
}
