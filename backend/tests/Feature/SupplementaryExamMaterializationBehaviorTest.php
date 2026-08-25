<?php

namespace Tests\Feature;

use App\Exceptions\GradeException;
use App\Models\Student;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\SupplementaryExamMaterializationService;
use App\Services\SupplementaryExamReconciliationService;
use App\Support\SupplementaryExamTargetGuard;
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
    public function duplicate_latest_submission_version_blocks_materialization_and_reconciliation(): void
    {
        DB::table('supplementary_exam_grade_submissions')->insert([
            'supplementary_exam_grade_submission_id' => 901,
            'supplementary_exam_offering_id' => 600,
            'submission_version' => 1,
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
        $this->assertDatabaseCount('supplementary_exam_materializations', 0);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period());
        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('source_submission_version_ambiguous', array_column($report['issues'], 'code'));
        $this->assertNotContains('source_submission_missing', array_column($report['issues'], 'code'));
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
        $beforeTheoretical = DB::table('student_grade_components')->where('student_grade_component_id', 500)->first();
        $beforePractical = DB::table('student_grade_components as grades')
            ->join('grade_components as components', 'components.grade_component_id', '=', 'grades.grade_component_id')
            ->where('grades.student_course_registration_id', 300)
            ->where('components.component_type', 'practical')
            ->orderBy('grades.student_grade_component_id')
            ->get([
                'grades.student_grade_component_id',
                'grades.grade_component_id',
                'grades.mark',
                'grades.grade_status',
                'grades.updated_at',
            ])->map(fn (object $row): array => (array) $row)->all();
        $beforePracticalTotal = DB::table('student_course_results')
            ->where('student_course_result_id', 400)
            ->value('practical_total');
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
        $afterTheoretical = DB::table('student_grade_components')->where('student_grade_component_id', 500)->first();
        $this->assertEquals(40, $afterTheoretical->mark);
        $this->assertSame($beforeTheoretical->grade_status, $afterTheoretical->grade_status);
        $this->assertSame($beforeTheoretical->created_at, $afterTheoretical->created_at);
        $afterPractical = DB::table('student_grade_components as grades')
            ->join('grade_components as components', 'components.grade_component_id', '=', 'grades.grade_component_id')
            ->where('grades.student_course_registration_id', 300)
            ->where('components.component_type', 'practical')
            ->orderBy('grades.student_grade_component_id')
            ->get([
                'grades.student_grade_component_id',
                'grades.grade_component_id',
                'grades.mark',
                'grades.grade_status',
                'grades.updated_at',
            ])->map(fn (object $row): array => (array) $row)->all();
        $this->assertSame($beforePractical, $afterPractical);
        $this->assertEquals(
            $beforePracticalTotal,
            DB::table('student_course_results')->where('student_course_result_id', 400)->value('practical_total'),
        );
        $this->assertEquals($beforeAttendance, DB::table('student_attendance')->where('student_attendance_id', 1)->first());
    }

    #[Test]
    public function materialization_updates_only_the_explicit_original_attempt_when_course_is_repeated(): void
    {
        $secondOffering = (array) DB::table('course_offerings')->where('course_offering_id', 100)->first();
        $secondOffering['course_offering_id'] = 101;
        DB::table('course_offerings')->insert($secondOffering);

        $secondRegistration = (array) DB::table('student_course_registrations')
            ->where('student_course_registration_id', 300)->first();
        $secondRegistration['student_course_registration_id'] = 302;
        $secondRegistration['course_offering_id'] = 101;
        DB::table('student_course_registrations')->insert($secondRegistration);

        $secondResult = (array) DB::table('student_course_results')
            ->where('student_course_result_id', 400)->first();
        $secondResult['student_course_result_id'] = 402;
        $secondResult['student_course_registration_id'] = 302;
        DB::table('student_course_results')->insert($secondResult);

        $beforeRegistration = (array) DB::table('student_course_registrations')
            ->where('student_course_registration_id', 302)->first();
        $beforeResult = (array) DB::table('student_course_results')
            ->where('student_course_result_id', 402)->first();

        $this->service()->materializeOffering($this->actor(), $this->offering());

        $this->assertSame($beforeRegistration, (array) DB::table('student_course_registrations')
            ->where('student_course_registration_id', 302)->first());
        $this->assertSame($beforeResult, (array) DB::table('student_course_results')
            ->where('student_course_result_id', 402)->first());
        $this->assertDatabaseHas('supplementary_exam_materializations', [
            'student_course_registration_id' => 300,
            'student_course_result_id' => 400,
        ]);
        $this->assertDatabaseMissing('supplementary_exam_materializations', [
            'student_course_registration_id' => 302,
        ]);
    }

    #[Test]
    public function result_announcement_snapshot_is_optional_when_the_source_column_is_absent(): void
    {
        Schema::table('student_course_results', function (Blueprint $table): void {
            $table->dropColumn('result_announced_at');
        });

        $summary = $this->service()->materializeOffering($this->actor(), $this->offering());
        $materialization = DB::table('supplementary_exam_materializations')->first();

        $this->assertSame('materialized', $summary['status']);
        $this->assertNull($materialization->before_result_announced_at);
        $this->assertNull($materialization->after_result_announced_at);
        $this->assertEquals(40, DB::table('student_course_results')->where('student_course_result_id', 400)->value('theoretical_total'));
    }

    #[Test]
    public function theoretical_component_evidence_must_be_complete_unambiguous_and_match_the_official_aggregate(): void
    {
        DB::table('student_grade_components')->where('student_grade_component_id', 500)->update(['mark' => 21]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_theoretical_drift',
            409,
        );

        DB::table('student_grade_components')->where('student_grade_component_id', 500)->update(['mark' => 10]);
        DB::table('grade_components')->where('grade_component_id', 1)->update(['max_mark' => 30]);
        DB::table('grade_components')->insert([
            'grade_component_id' => 3,
            'course_offering_id' => 100,
            'component_name' => 'Second theory component',
            'component_type' => 'theoretical',
            'max_mark' => 30,
            'is_required' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('student_grade_components')->insert([
            'student_grade_component_id' => 504,
            'student_course_registration_id' => 300,
            'grade_component_id' => 3,
            'mark' => 10,
            'grade_status' => 'approved',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_theoretical_component_ambiguous',
            409,
        );
        $this->assertDatabaseCount('supplementary_exam_materializations', 0);
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
        $componentUpdatedAt = DB::table('student_grade_components')->where('student_grade_component_id', 500)->value('updated_at');
        $second = $this->service()->materializeOffering($this->actor(), $this->offering());

        $this->assertSame('materialized', $first['status']);
        $this->assertSame('already_materialized', $second['status']);
        $this->assertSame(0, $second['materialized_count']);
        $this->assertSame(1, $second['already_materialized_count']);
        $this->assertSame($updatedAt, DB::table('student_course_results')->where('student_course_result_id', 400)->value('updated_at'));
        $this->assertSame($componentUpdatedAt, DB::table('student_grade_components')->where('student_grade_component_id', 500)->value('updated_at'));
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
        $this->assertStringContainsString('"student_grade_component_id":500', $row->before_theoretical_components_snapshot);
        $this->assertStringContainsString('"mark":"20.00"', $row->before_theoretical_components_snapshot);
        $this->assertStringContainsString('"student_grade_component_id":500', $row->after_theoretical_components_snapshot);
        $this->assertStringContainsString('"mark":"40.00"', $row->after_theoretical_components_snapshot);
        $this->assertDatabaseHas('supplementary_exam_materialization_events', [
            'supplementary_exam_materialization_id' => $row->supplementary_exam_materialization_id,
            'event_type' => 'official_result_materialized',
            'source_submission_version' => 1,
            'actor_user_id' => 1,
        ]);
    }

    #[Test]
    public function published_handoff_materializes_through_official_reads_and_idempotent_retry_consistently(): void
    {
        // This fixture intentionally starts at the immutable Phase-5 handoff.
        // The public assign/open/draft/submit/approve/publish service journey is
        // exercised by SupplementaryExamRegistrationBehaviorTest; this test owns
        // Phase-6 posting, official reads, reconciliation, and retry boundaries.
        $this->assertDatabaseHas('student_course_results', [
            'student_course_result_id' => 400,
            'theoretical_total' => 20,
            'practical_total' => 30,
            'result_status_id' => 1,
            'is_deprived' => 0,
        ]);
        $this->assertDatabaseHas('supplementary_exam_periods', [
            'supplementary_exam_period_id' => 500,
            'status' => 'results_published',
        ]);
        $this->assertDatabaseHas('supplementary_exam_offerings', [
            'supplementary_exam_offering_id' => 600,
            'supplementary_exam_period_id' => 500,
        ]);
        $this->assertDatabaseHas('supplementary_exam_registrations', [
            'supplementary_exam_registration_id' => 700,
            'status' => 'registered',
            'current_slot' => 1,
            'eligibility_reason' => 'failed_theoretical',
        ]);
        $this->assertDatabaseHas('supplementary_exam_grader_assignments', [
            'supplementary_exam_grader_assignment_id' => 750,
            'supplementary_exam_offering_id' => 600,
            'current_slot' => 1,
        ]);
        $this->assertDatabaseHas('supplementary_exam_grade_results', [
            'supplementary_exam_grade_result_id' => 800,
            'theoretical_mark' => 40,
            'status' => 'published',
            'submission_version' => 1,
        ]);
        $this->assertDatabaseHas('supplementary_exam_grade_submissions', [
            'supplementary_exam_grade_submission_id' => 900,
            'status' => 'published',
            'submission_version' => 1,
        ]);
        $this->assertDatabaseHas('supplementary_exam_grade_events', [
            'supplementary_exam_grade_event_id' => 998,
            'supplementary_exam_grade_submission_id' => 900,
            'event_type' => 'submitted',
            'from_status' => 'draft',
            'to_status' => 'submitted',
        ]);
        $this->assertDatabaseHas('supplementary_exam_grade_events', [
            'supplementary_exam_grade_event_id' => 999,
            'supplementary_exam_grade_submission_id' => 900,
            'event_type' => 'approved',
            'from_status' => 'submitted',
            'to_status' => 'approved',
        ]);
        $this->assertDatabaseHas('supplementary_exam_grade_events', [
            'supplementary_exam_grade_event_id' => 1000,
            'supplementary_exam_grade_submission_id' => 900,
            'event_type' => 'published',
            'from_status' => 'approved',
            'to_status' => 'published',
        ]);

        $beforePractical = DB::table('student_grade_components as grades')
            ->join('grade_components as components', 'components.grade_component_id', '=', 'grades.grade_component_id')
            ->where('grades.student_course_registration_id', 300)
            ->where('components.component_type', 'practical')
            ->orderBy('grades.student_grade_component_id')
            ->get([
                'grades.student_grade_component_id', 'grades.grade_component_id', 'grades.mark',
                'grades.grade_status', 'grades.updated_at',
            ])->map(fn (object $row): array => (array) $row)->all();
        $beforePracticalTotal = DB::table('student_course_results')
            ->where('student_course_result_id', 400)
            ->value('practical_total');
        $beforeAttendance = DB::table('student_attendance')->where('student_attendance_id', 1)->first();
        $student = Student::query()->findOrFail(200);
        $grades = new GradeService();
        $publishedOnlyTranscript = $grades->getTranscript($student);
        $publishedOnlyCourse = $publishedOnlyTranscript['terms'][0]['courses'][0];
        $publishedOnlyGpa = $grades->calculateGpa($student, 1, 1);
        $this->assertSame(20.0, $publishedOnlyCourse['theoretical_mark']);
        $this->assertSame(30.0, $publishedOnlyCourse['practical_mark']);
        $this->assertSame(50.0, $publishedOnlyCourse['final_mark']);
        $this->service()->materializeOffering($this->actor(), $this->offering());

        $this->expectGradeError(
            fn () => $grades->calculateRegistrationResult(300, 1),
            'supplementary_materialized_result_locked',
            409,
        );

        $detail = $grades->getRegistrationGrades(300);
        $gradeSheet = $grades->getGradeSheet(100);
        $transcript = $grades->getTranscript($student);
        $course = $transcript['terms'][0]['courses'][0];
        $gpa = $grades->calculateGpa($student->fresh(), 1, 1);
        $componentTheoretical = DB::table('student_grade_components as grades')
            ->join('grade_components as components', 'components.grade_component_id', '=', 'grades.grade_component_id')
            ->where('grades.student_course_registration_id', 300)
            ->where('components.component_type', 'theoretical')
            ->sum('grades.mark');

        $this->assertEquals(40, $componentTheoretical);
        $this->assertSame(40.0, $detail['theoretical_mark']);
        $this->assertSame(30.0, $detail['practical_mark']);
        $this->assertSame(70.0, $detail['final_mark']);
        $this->assertSame(40.0, $gradeSheet['students'][0]['theoretical_mark']);
        $this->assertSame(30.0, $gradeSheet['students'][0]['practical_mark']);
        $this->assertSame(70.0, $gradeSheet['students'][0]['final_mark']);
        $this->assertSame(40.0, $course['theoretical_mark']);
        $this->assertSame(30.0, $course['practical_mark']);
        $this->assertSame(70.0, $course['final_mark']);
        $this->assertSame('passed', $course['result_status']['status_code']);
        $this->assertSame(1, $transcript['summary']['approved_courses_count']);
        $this->assertSame(1, $transcript['summary']['passed_courses_count']);
        $this->assertSame(2.5, $gpa['gpa']);
        $this->assertNotSame($publishedOnlyGpa['gpa'], $gpa['gpa']);
        $this->assertSame(3, $gpa['total_included_credit_hours']);
        $this->assertSame(1, $gpa['included_courses_count']);
        $this->assertEquals(40, DB::table('student_grade_components')->where('student_grade_component_id', 500)->value('mark'));
        $afterPractical = DB::table('student_grade_components as grades')
            ->join('grade_components as components', 'components.grade_component_id', '=', 'grades.grade_component_id')
            ->where('grades.student_course_registration_id', 300)
            ->where('components.component_type', 'practical')
            ->orderBy('grades.student_grade_component_id')
            ->get([
                'grades.student_grade_component_id', 'grades.grade_component_id', 'grades.mark',
                'grades.grade_status', 'grades.updated_at',
            ])->map(fn (object $row): array => (array) $row)->all();
        $this->assertSame($beforePractical, $afterPractical);
        $this->assertEquals(
            $beforePracticalTotal,
            DB::table('student_course_results')->where('student_course_result_id', 400)->value('practical_total'),
        );
        $this->assertEquals($beforeAttendance, DB::table('student_attendance')->where('student_attendance_id', 1)->first());

        $reconciliation = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $retry = $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->assertSame('PASS', $reconciliation['state']);
        $this->assertTrue($reconciliation['scope_complete']);
        $this->assertSame(['roster' => 1, 'graded' => 1, 'published' => 1, 'materialized' => 1], $reconciliation['counts']);
        $this->assertSame('already_materialized', $retry['status']);
        $this->assertSame(1, DB::table('supplementary_exam_materializations')->count());
        $this->assertSame(1, DB::table('supplementary_exam_materialization_events')->count());
        $this->assertSame(1, DB::table('supplementary_exam_period_events')->where('event_type', 'results_materialized')->count());
        $this->assertSame('results_materialized', $this->period()->fresh()->status);
        $this->assertDatabaseHas('supplementary_exam_materializations', [
            'supplementary_exam_registration_id' => 700,
            'supplementary_exam_grade_result_id' => 800,
            'supplementary_exam_grade_event_id' => 1000,
            'supplementary_exam_grade_submission_id' => 900,
            'source_submission_version' => 1,
            'student_course_registration_id' => 300,
            'student_course_result_id' => 400,
        ]);
    }

    #[Test]
    public function theory_only_materialization_stays_consistent_in_transcript_and_gpa(): void
    {
        DB::table('student_grade_components')
            ->where('student_grade_component_id', 501)
            ->delete();
        DB::table('grade_components')
            ->where('grade_component_id', 2)
            ->delete();
        DB::table('student_course_results')
            ->where('student_course_result_id', 400)
            ->update([
                'practical_total' => 0,
                'final_mark' => 20,
            ]);
        DB::table('supplementary_exam_grade_results')
            ->where('supplementary_exam_grade_result_id', 800)
            ->update(['theoretical_mark' => 55]);
        DB::table('supplementary_exam_grade_events')
            ->whereIn('supplementary_exam_grade_event_id', [998, 999, 1000])
            ->update(['theoretical_mark' => 55]);

        $preview = (new GradeService())->buildCalculationForRequiredParts(
            theoretical: 55,
            practical: 30,
            requiresTheoretical: true,
            requiresPractical: false,
            theoreticalMax: 60,
            practicalMax: 0,
        );
        $summary = $this->service()->materializeOffering($this->actor(), $this->offering());
        $student = Student::query()->findOrFail(200);
        $grades = new GradeService();
        $transcript = $grades->getTranscript($student);
        $course = $transcript['terms'][0]['courses'][0];
        $gpa = $grades->calculateGpa($student->fresh(), 1, 1);

        $this->assertSame('materialized', $summary['status']);
        $this->assertSame(55.0, $preview['final_mark']);
        $this->assertSame('passed', $preview['result_status_code']);
        $this->assertSame('D+', $preview['letter_grade']);
        $this->assertSame(1.75, $preview['grade_points']);
        $this->assertFalse($preview['calculation_details']['requires_practical']);
        $this->assertDatabaseHas('student_course_results', [
            'student_course_result_id' => 400,
            'theoretical_total' => 55,
            'practical_total' => 0,
            'final_mark' => 55,
            'result_status_id' => 2,
        ]);
        $this->assertSame(55.0, $course['theoretical_mark']);
        $this->assertNull($course['practical_mark']);
        $this->assertSame(55.0, $course['final_mark']);
        $this->assertSame('D+', $course['letter_grade']);
        $this->assertSame(1.75, $course['grade_points']);
        $this->assertSame('passed', $course['result_status']['status_code']);
        $this->assertSame(1.75, $gpa['gpa']);
        $this->assertSame(1, $gpa['included_courses_count']);
        $this->assertSame('PASS', $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh())['state']);
    }

    #[Test]
    public function materialized_configuration_and_referenced_policy_guards_return_stable_conflicts(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('grading_policies')->insert([
            'grading_policy_id' => 2,
            'policy_name' => 'Unreferenced',
            'theoretical_max_mark' => 60,
            'practical_max_mark' => 40,
            'minimum_theoretical_mark' => 30,
            'minimum_practical_mark' => 20,
            'minimum_final_mark' => 50,
            'is_default' => 0,
            'is_active' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);

        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertCourseOfferingConfigurationsMutable([100]),
            SupplementaryExamTargetGuard::CONFIGURATION_ERROR_CODE,
            409,
        );
        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyMutable(1),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );

        SupplementaryExamTargetGuard::assertGradingPolicyMutable(2);
        $this->addToAssertionCount(1);
        $this->assertDatabaseHas('grade_components', [
            'grade_component_id' => 1,
            'course_offering_id' => 100,
            'max_mark' => 60,
        ]);
        $this->assertDatabaseHas('grading_policies', [
            'grading_policy_id' => 1,
            'is_active' => 1,
        ]);
    }

    #[Test]
    public function fixed_roster_locks_only_the_selected_policy_and_selection_changing_catalog_mutations(): void
    {
        $this->seedUnrelatedGradingPolicy();

        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
            'policy_name' => 'Harmless display rename',
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
            'minimum_final_mark' => 50,
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
            'is_default' => 0,
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(2, [
            'minimum_final_mark' => 81,
            'is_active' => 0,
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyMutable(2);
        SupplementaryExamTargetGuard::assertGradingPolicyCreationMutable(
            $this->gradingPolicyPayload('Future noncanonical policy'),
        );
        $this->addToAssertionCount(6);

        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyCreationMutable(
                $this->gradingPolicyPayload('Competing default', isDefault: true),
            ),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
                'minimum_final_mark' => 51,
            ]),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
                'is_active' => 0,
            ]),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(2, [
                'is_default' => 1,
            ]),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyMutable(1),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
    }

    #[Test]
    public function posted_policy_allows_name_idempotent_and_default_changes_but_locks_scoring_active_and_destroy(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->seedUnrelatedGradingPolicy();

        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
            'policy_name' => 'Historical policy display name',
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
            'minimum_final_mark' => 50,
            'is_active' => 1,
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
            'is_default' => 0,
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(2, [
            'minimum_final_mark' => 81,
            'is_active' => 0,
            'is_default' => 1,
        ]);
        SupplementaryExamTargetGuard::assertGradingPolicyMutable(2);
        SupplementaryExamTargetGuard::assertGradingPolicyCreationMutable(
            $this->gradingPolicyPayload('Future post-publication default', isDefault: true),
        );
        $this->addToAssertionCount(6);

        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
                'minimum_final_mark' => 51,
            ]),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable(1, [
                'is_active' => 0,
            ]),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
        $this->expectGradeError(
            fn () => SupplementaryExamTargetGuard::assertGradingPolicyMutable(1),
            SupplementaryExamTargetGuard::POLICY_ERROR_CODE,
            409,
        );
    }

    #[Test]
    public function fixed_roster_status_guards_lock_only_concrete_dependencies_and_canonical_outcomes(): void
    {
        $this->seedUnrelatedOfficialStatuses();

        SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['status_name' => 'Failed display']);
        SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['status_code' => 'failed', 'is_active' => 1]);
        SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['status_name' => 'Approved display']);
        SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['status_code' => 'approved', 'is_active' => 1]);
        SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(1, ['status_name' => 'Registered display']);
        SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(1, ['status_code' => 'registered']);
        SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(5, ['status_code' => 'future_result_changed', 'is_active' => 0]);
        SupplementaryExamTargetGuard::assertResultStatusDestroyable(5);
        SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(2, ['status_code' => 'future_approval_changed', 'is_active' => 0]);
        SupplementaryExamTargetGuard::assertApprovalStatusDestroyable(2);
        SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(2, ['status_code' => 'future_registration_changed']);
        SupplementaryExamTargetGuard::assertRegistrationStatusDestroyable(2);
        $this->addToAssertionCount(12);

        foreach ([
            fn () => SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['status_code' => 'failed_changed']),
            fn () => SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['is_active' => 0]),
            fn () => SupplementaryExamTargetGuard::assertResultStatusDestroyable(1),
            fn () => SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(2, ['status_code' => 'passed_changed']),
            fn () => SupplementaryExamTargetGuard::assertResultStatusDestroyable(2),
            fn () => SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['status_code' => 'approved_changed']),
            fn () => SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['is_active' => 0]),
            fn () => SupplementaryExamTargetGuard::assertApprovalStatusDestroyable(1),
            fn () => SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(1, ['status_code' => 'registered_changed']),
            fn () => SupplementaryExamTargetGuard::assertRegistrationStatusDestroyable(1),
        ] as $mutation) {
            $this->expectGradeError(
                $mutation,
                SupplementaryExamTargetGuard::STATUS_ERROR_CODE,
                409,
            );
        }
    }

    #[Test]
    public function referenced_official_statuses_allow_name_and_idempotent_updates_but_lock_semantics_and_destroy(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->seedUnrelatedOfficialStatuses();

        SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['status_name' => 'Failed display']);
        SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['status_code' => 'failed', 'is_active' => 1]);
        SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['status_name' => 'Approved display']);
        SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['status_code' => 'approved', 'is_active' => 1]);
        SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(1, ['status_name' => 'Registered display']);
        SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(1, ['status_code' => 'registered']);
        SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(5, ['status_code' => 'future_result_changed', 'is_active' => 0]);
        SupplementaryExamTargetGuard::assertResultStatusDestroyable(5);
        SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(2, ['status_code' => 'future_approval_changed', 'is_active' => 0]);
        SupplementaryExamTargetGuard::assertApprovalStatusDestroyable(2);
        SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(2, ['status_code' => 'future_registration_changed']);
        SupplementaryExamTargetGuard::assertRegistrationStatusDestroyable(2);
        $this->addToAssertionCount(12);

        foreach ([
            fn () => SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['status_code' => 'failed_changed']),
            fn () => SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(1, ['is_active' => 0]),
            fn () => SupplementaryExamTargetGuard::assertResultStatusDestroyable(1),
            fn () => SupplementaryExamTargetGuard::assertResultStatusUpdateMutable(2, ['status_code' => 'passed_changed']),
            fn () => SupplementaryExamTargetGuard::assertResultStatusDestroyable(2),
            fn () => SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['status_code' => 'approved_changed']),
            fn () => SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable(1, ['is_active' => 0]),
            fn () => SupplementaryExamTargetGuard::assertApprovalStatusDestroyable(1),
            fn () => SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable(1, ['status_code' => 'registered_changed']),
            fn () => SupplementaryExamTargetGuard::assertRegistrationStatusDestroyable(1),
        ] as $mutation) {
            $this->expectGradeError(
                $mutation,
                SupplementaryExamTargetGuard::STATUS_ERROR_CODE,
                409,
            );
        }
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
        $zeroRosterAudit = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $this->assertSame('WARNING', $zeroRosterAudit['state']);
        $this->assertContains('empty_roster', array_column($zeroRosterAudit['issues'], 'code'));
        $this->assertNotContains('terminal_coverage_incomplete', array_column($zeroRosterAudit['issues'], 'code'));
    }

    #[Test]
    public function terminal_completion_rejects_zero_roster_offering_with_missing_or_mismatched_source(): void
    {
        $this->seedSecondOffering(withCandidate: false);
        DB::table('supplementary_exam_offering_sources')
            ->where('supplementary_exam_offering_id', 601)
            ->delete();

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_period_source_conflict',
            409,
        );
        $this->assertDatabaseCount('supplementary_exam_materializations', 0);

        DB::table('supplementary_exam_offering_sources')->insert([
            'supplementary_exam_offering_source_id' => 2,
            'supplementary_exam_offering_id' => 601,
            'course_offering_id' => 100,
            'created_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_offerings')
            ->where('supplementary_exam_offering_id', 601)
            ->update(['course_id' => 999]);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_period_source_conflict',
            409,
        );
        $this->assertDatabaseCount('supplementary_exam_materializations', 0);
        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 400)->value('theoretical_total'));
    }

    #[Test]
    public function terminal_completion_rejects_malformed_registration_excluded_from_current_roster(): void
    {
        $this->seedSecondOffering(withCandidate: false);
        DB::table('supplementary_exam_registrations')->insert([
            'supplementary_exam_registration_id' => 799,
            'supplementary_exam_offering_id' => 601,
            'student_id' => 200,
            'student_course_registration_id' => 300,
            'status' => 'cancelled',
            'current_slot' => 1,
            'eligibility_reason' => 'failed_theoretical',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_roster_mismatch',
            409,
        );

        $this->assertDatabaseCount('supplementary_exam_materializations', 0);
        $this->assertSame('results_published', $this->period()->fresh()->status);
        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 400)->value('theoretical_total'));
    }

    #[Test]
    public function zero_roster_offering_with_submission_blocks_terminal_completion_and_reconciliation(): void
    {
        $this->seedSecondOffering(withCandidate: false);
        DB::table('supplementary_exam_grade_submissions')->insert([
            'supplementary_exam_grade_submission_id' => 901,
            'supplementary_exam_offering_id' => 601,
            'submission_version' => 1,
            'status' => 'published',
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_roster_mismatch',
            409,
        );

        $this->assertDatabaseCount('supplementary_exam_materializations', 0);
        $this->assertDatabaseCount('supplementary_exam_materialization_events', 0);
        $this->assertSame('results_published', $this->period()->fresh()->status);
        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 400)->value('theoretical_total'));

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $emptyOffering = collect($report['offerings'])
            ->firstWhere('supplementary_exam_offering_id', 601);

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertNotNull($emptyOffering);
        $this->assertSame('CONFLICT', $emptyOffering['state']);
        $this->assertContains('empty_roster', array_column($emptyOffering['issues'], 'code'));
        $this->assertContains('zero_roster_grading_artifacts', array_column($emptyOffering['issues'], 'code'));
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

        $beforeSecondResult = DB::table('student_course_results')
            ->where('student_course_result_id', 401)
            ->first();
        $beforeSecondTheory = DB::table('student_grade_components')
            ->where('student_grade_component_id', 502)
            ->first();

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering(
                $this->actor(),
                SupplementaryExamOffering::query()->findOrFail(601),
            ),
            'supplementary_materialization_target_conflict',
            409,
        );

        $this->assertDatabaseCount('supplementary_exam_materializations', 1);
        $this->assertDatabaseHas('supplementary_exam_materializations', [
            'supplementary_exam_registration_id' => 700,
        ]);
        $this->assertDatabaseMissing('supplementary_exam_materializations', [
            'supplementary_exam_registration_id' => 701,
        ]);
        $this->assertEquals(
            $beforeSecondResult,
            DB::table('student_course_results')->where('student_course_result_id', 401)->first(),
        );
        $this->assertEquals(
            $beforeSecondTheory,
            DB::table('student_grade_components')->where('student_grade_component_id', 502)->first(),
        );
        $this->assertSame(
            'results_published',
            DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->value('status'),
        );
        $this->assertDatabaseCount('supplementary_exam_materialization_events', 1);
        $this->assertSame(0, DB::table('supplementary_exam_period_events')->where('event_type', 'results_materialized')->count());
    }

    #[Test]
    public function reconciliation_is_read_only_and_reports_published_work_as_a_warning(): void
    {
        $before = [
            'period' => DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->first(),
            'result' => DB::table('student_course_results')->where('student_course_result_id', 400)->first(),
            'materializations' => DB::table('supplementary_exam_materializations')->count(),
            'events' => DB::table('supplementary_exam_period_events')->count(),
        ];

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period());

        $this->assertSame('WARNING', $report['state']);
        $this->assertTrue($report['scope_complete']);
        $this->assertSame(['roster' => 1, 'graded' => 1, 'published' => 1, 'materialized' => 0], $report['counts']);
        $this->assertContains('published_result_not_materialized', array_column($report['issues'], 'code'));
        $this->assertEquals($before['period'], DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->first());
        $this->assertEquals($before['result'], DB::table('student_course_results')->where('student_course_result_id', 400)->first());
        $this->assertSame($before['materializations'], DB::table('supplementary_exam_materializations')->count());
        $this->assertSame($before['events'], DB::table('supplementary_exam_period_events')->count());
    }

    #[Test]
    public function zero_roster_reconciliation_is_warning_no_candidates_without_submission_ambiguity(): void
    {
        $this->seedSecondOffering(withCandidate: false);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period());
        $emptyOffering = collect($report['offerings'])
            ->firstWhere('supplementary_exam_offering_id', 601);

        $this->assertNotNull($emptyOffering);
        $this->assertSame('WARNING', $emptyOffering['state']);
        $this->assertSame('no_candidates', $emptyOffering['operational_status']);
        $this->assertSame(['empty_roster'], array_column($emptyOffering['issues'], 'code'));
        $this->assertNotContains('source_submission_version_ambiguous', array_column($report['issues'], 'code'));
    }

    #[Test]
    public function manually_terminal_all_zero_period_is_a_reconciliation_conflict(): void
    {
        DB::table('supplementary_exam_grade_events')->delete();
        DB::table('supplementary_exam_grade_results')->delete();
        DB::table('supplementary_exam_grade_submissions')->delete();
        DB::table('supplementary_exam_registrations')->update([
            'status' => 'cancelled',
            'current_slot' => null,
        ]);
        DB::table('supplementary_exam_periods')
            ->where('supplementary_exam_period_id', 500)
            ->update(['status' => 'results_materialized']);
        DB::table('supplementary_exam_period_events')->insert([
            'supplementary_exam_period_id' => 500,
            'event_type' => 'results_materialized',
            'from_status' => 'results_published',
            'to_status' => 'results_materialized',
            'actor_user_id' => 1,
            'created_at' => self::PUBLISHED_TIME,
        ]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertSame(['roster' => 0, 'graded' => 0, 'published' => 0, 'materialized' => 0], $report['counts']);
        $this->assertContains('terminal_period_empty_roster', array_column($report['issues'], 'code'));
    }

    #[Test]
    public function published_unmaterialized_candidate_with_missing_or_mismatched_official_target_is_conflict(): void
    {
        DB::table('student_course_registrations')
            ->where('student_course_registration_id', 300)
            ->update(['student_id' => 999]);

        $mismatched = $this->reconciliation()->reconcile($this->actor(), $this->period());
        $this->assertSame('CONFLICT', $mismatched['state']);
        $this->assertContains('offering_source_target_mismatch', array_column($mismatched['issues'], 'code'));

        DB::table('student_course_registrations')
            ->where('student_course_registration_id', 300)
            ->update(['student_id' => 200]);
        DB::table('student_course_results')
            ->where('student_course_result_id', 400)
            ->delete();

        $missing = $this->reconciliation()->reconcile($this->actor(), $this->period());
        $this->assertSame('CONFLICT', $missing['state']);
        $this->assertContains('official_target_missing_or_ambiguous', array_column($missing['issues'], 'code'));
    }

    #[Test]
    public function unmaterialized_reconciliation_uses_current_target_status_for_eligibility(): void
    {
        DB::table('student_course_results')
            ->where('student_course_result_id', 400)
            ->update(['result_status_id' => 2]);
        DB::table('student_course_registrations')
            ->where('student_course_registration_id', 300)
            ->update(['result_status_id' => 2]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('materialization_precondition_conflict', array_column($report['issues'], 'code'));
    }

    #[Test]
    public function materialized_reconciliation_uses_preserved_before_status_for_eligibility(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());

        $valid = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $this->assertSame('PASS', $valid['state']);

        DB::table('supplementary_exam_materializations')->update([
            'before_result_status_id' => 2,
        ]);
        $tampered = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $tampered['state']);
        $this->assertContains('materialization_precondition_conflict', array_column($tampered['issues'], 'code'));
    }

    #[Test]
    public function registration_closed_reconciliation_allows_opening_grading_without_premature_publication_conflicts(): void
    {
        DB::table('supplementary_exam_grade_events')->delete();
        DB::table('supplementary_exam_grade_results')->delete();
        DB::table('supplementary_exam_grade_submissions')->delete();
        DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->update([
            'status' => 'registration_closed',
        ]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('WARNING', $report['state']);
        $this->assertTrue($report['action_flags']['can_open_grading']);
        $this->assertContains('source_result_missing_or_ambiguous', array_column($report['issues'], 'code'));
        $this->assertNotContains('CONFLICT', array_column($report['issues'], 'severity'));
    }

    #[Test]
    public function registration_closed_reconciliation_rejects_premature_grading_artifacts(): void
    {
        DB::table('supplementary_exam_periods')
            ->where('supplementary_exam_period_id', 500)
            ->update(['status' => 'registration_closed']);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertFalse($report['action_flags']['can_open_grading']);
        $this->assertContains('premature_grading_artifacts', array_column($report['issues'], 'code'));
    }

    #[Test]
    public function reconciliation_detects_target_component_drift_and_terminal_incompleteness(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('student_grade_components')->where('student_grade_component_id', 501)->update([
            'mark' => 31,
            'updated_at' => '2026-01-04 10:00:00',
        ]);

        $drift = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $this->assertSame('CONFLICT', $drift['state']);
        $this->assertContains('practical_component_drift', array_column($drift['issues'], 'code'));

        $this->resetMaterializationState();
        DB::table('supplementary_exam_periods')->where('supplementary_exam_period_id', 500)->update(['status' => 'results_materialized']);
        $incomplete = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $this->assertSame('CONFLICT', $incomplete['state']);
        $this->assertContains('terminal_coverage_incomplete', array_column($incomplete['issues'], 'code'));
        $this->assertContains('terminal_event_mismatch', array_column($incomplete['issues'], 'code'));
    }

    #[Test]
    public function reconciliation_detects_materialization_target_registration_id_drift(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('supplementary_exam_materializations')->update([
            'student_course_registration_id' => 999,
        ]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('materialization_target_mismatch', array_column($report['issues'], 'code'));
    }

    #[Test]
    public function coordinated_final_mark_and_provenance_tampering_fails_reconciliation_and_retry(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('student_course_results')
            ->where('student_course_result_id', 400)
            ->update(['final_mark' => 71]);
        DB::table('supplementary_exam_materializations')->update([
            'after_final_mark' => 71,
        ]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('CONFLICT', array_column($report['issues'], 'severity'));
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_target_conflict',
            409,
        );
    }

    #[Test]
    public function incompatible_grading_policy_id_drift_is_detected_by_reconciliation_and_retry(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('grading_policies')->insert([
            'grading_policy_id' => 2,
            'policy_name' => 'Alternate',
            'theoretical_max_mark' => 60,
            'practical_max_mark' => 40,
            'minimum_theoretical_mark' => 30,
            'minimum_practical_mark' => 20,
            'minimum_final_mark' => 80,
            'is_default' => 0,
            'is_active' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_materializations')->update([
            'grading_policy_id' => 2,
        ]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('grading_policy_or_outcome_mismatch', array_column($report['issues'], 'code'));
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_target_conflict',
            409,
        );
    }

    #[Test]
    public function zero_default_fallback_is_deterministic_and_stored_policy_may_cease_to_be_default_but_must_stay_active(): void
    {
        DB::table('grading_policies')->where('grading_policy_id', 1)->update([
            'is_default' => 0,
        ]);
        DB::table('grading_policies')->insert([
            'grading_policy_id' => 2,
            'policy_name' => 'Later default',
            'theoretical_max_mark' => 60,
            'practical_max_mark' => 40,
            'minimum_theoretical_mark' => 50,
            'minimum_practical_mark' => 30,
            'minimum_final_mark' => 80,
            'is_default' => 0,
            'is_active' => 1,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);

        $summary = $this->service()->materializeOffering($this->actor(), $this->offering());

        $this->assertSame('materialized', $summary['status']);
        $this->assertEquals(
            1,
            DB::table('supplementary_exam_materializations')->value('grading_policy_id'),
        );

        DB::table('grading_policies')->where('grading_policy_id', 2)->update([
            'is_default' => 1,
        ]);

        $valid = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $retry = $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->assertSame('PASS', $valid['state']);
        $this->assertSame('already_materialized', $retry['status']);

        DB::table('grading_policies')->where('grading_policy_id', 1)->update([
            'is_active' => 0,
        ]);

        $inactive = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());
        $this->assertSame('CONFLICT', $inactive['state']);
        $this->assertContains('grading_policy_or_outcome_mismatch', array_column($inactive['issues'], 'code'));
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_target_conflict',
            409,
        );
    }

    #[Test]
    public function predating_materialization_evidence_is_detected_by_reconciliation_and_retry(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('supplementary_exam_materializations')->update([
            'materialized_at' => '2026-01-01 09:00:00',
            'created_at' => '2026-01-01 09:00:00',
        ]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('materialization_source_mismatch', array_column($report['issues'], 'code'));
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_idempotency_conflict',
            409,
        );
    }

    #[Test]
    public function predating_posting_event_is_detected_by_reconciliation_and_retry(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('supplementary_exam_materialization_events')->update([
            'created_at' => '2026-01-01 09:00:00',
        ]);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('materialization_event_mismatch', array_column($report['issues'], 'code'));
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_event_conflict',
            409,
        );
    }

    #[Test]
    public function predating_terminal_event_is_detected_by_reconciliation_and_retry(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('supplementary_exam_period_events')
            ->where('event_type', 'results_materialized')
            ->update(['created_at' => '2026-01-01 09:00:00']);

        $report = $this->reconciliation()->reconcile($this->actor(), $this->period()->fresh());

        $this->assertSame('CONFLICT', $report['state']);
        $this->assertContains('terminal_event_mismatch', array_column($report['issues'], 'code'));
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_terminal_event_conflict',
            409,
        );
    }

    #[Test]
    public function reconciliation_requires_actual_exam_officer_review_permission_and_mutation_scope(): void
    {
        $professor = $this->createActor(2, 'doctor_instructor', employeeId: 22);
        $this->expectGradeError(
            fn () => $this->reconciliation()->reconcile($professor, $this->period()),
            'supplementary_reconciliation_forbidden',
            403,
        );

        DB::table('role_permissions')->where('permission_id', 2)->delete();
        $this->expectGradeError(
            fn () => $this->reconciliation()->reconcile($this->actor(), $this->period()),
            'supplementary_reconciliation_forbidden',
            403,
        );
        $this->mapReviewPermission();

        $this->expectGradeError(
            fn () => $this->reconciliation(inScope: false)->reconcile($this->actor(), $this->period()),
            'supplementary_reconciliation_out_of_scope',
            403,
        );
    }

    #[Test]
    public function exact_retry_requires_one_correct_terminal_event_and_rejects_pretransition_evidence(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        DB::table('supplementary_exam_period_events')->where('event_type', 'results_materialized')->delete();
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_terminal_event_conflict',
            409,
        );

        $this->resetMaterializationState();
        DB::table('supplementary_exam_period_events')->insert([
            'supplementary_exam_period_id' => 500,
            'event_type' => 'results_materialized',
            'from_status' => 'results_published',
            'to_status' => 'results_materialized',
            'actor_user_id' => 1,
            'created_at' => self::PUBLISHED_TIME,
        ]);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_terminal_event_conflict',
            409,
        );
        $this->assertSame(0, DB::table('supplementary_exam_materializations')->count());
        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 400)->value('theoretical_total'));
    }

    #[Test]
    public function rogue_terminal_event_is_rejected_before_incomplete_period_coverage_can_short_circuit(): void
    {
        $this->seedSecondOffering(withCandidate: true);
        DB::table('supplementary_exam_period_events')->insert([
            'supplementary_exam_period_id' => 500,
            'event_type' => 'results_materialized',
            'from_status' => 'results_published',
            'to_status' => 'results_materialized',
            'actor_user_id' => 1,
            'created_at' => self::PUBLISHED_TIME,
        ]);

        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $this->offering()),
            'supplementary_materialization_terminal_event_conflict',
            409,
        );

        $this->assertDatabaseCount('supplementary_exam_materializations', 0);
        $this->assertSame('results_published', $this->period()->fresh()->status);
        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 400)->value('theoretical_total'));
        $this->assertEquals(20, DB::table('student_course_results')->where('student_course_result_id', 401)->value('theoretical_total'));
    }

    #[Test]
    public function cross_period_repeat_target_is_visible_in_queue_and_rejected_with_stable_code(): void
    {
        $this->service()->materializeOffering($this->actor(), $this->offering());
        $this->seedRepeatPeriodSource();
        $repeatOffering = SupplementaryExamOffering::query()->with('period')->findOrFail(610);
        $submission = \App\Models\SupplementaryExamGradeSubmission::query()->findOrFail(910);
        $queue = $this->service()->decorateReviewQueue($this->actor(), [[
            'offering' => $repeatOffering,
            'submission' => $submission,
            'workflow_status' => 'published',
            'roster' => [[
                'supplementary_exam_registration_id' => 710,
                'student_course_registration_id' => 300,
                'supplementary_theoretical_mark' => 41,
                'result_status' => 'published',
            ]],
        ]]);

        $this->assertSame('conflict', $queue[0]['materialization']['state']);
        $this->assertSame('regular_attempt_already_materialized', $queue[0]['materialization']['reason']);
        $this->assertSame('regular_attempt_already_materialized', $queue[0]['roster'][0]['materialization_conflict_reason']);
        $this->expectGradeError(
            fn () => $this->service()->materializeOffering($this->actor(), $repeatOffering),
            'supplementary_materialization_repeat_attempt_unsupported',
            409,
        );
        $this->assertSame(1, DB::table('supplementary_exam_materializations')->count());
    }

    private function service(bool $inScope = true): SupplementaryExamMaterializationService
    {
        $scope = $this->createMock(DataScopeService::class);
        $scope->method('canMutateProgram')->willReturn($inScope);

        return new SupplementaryExamMaterializationService(new GradeService(), $scope);
    }

    private function reconciliation(bool $inScope = true): SupplementaryExamReconciliationService
    {
        $scope = $this->createMock(DataScopeService::class);
        $scope->method('scopes')->willReturn($inScope
            ? [['type' => 'university', 'id' => 1]]
            : []);

        return new SupplementaryExamReconciliationService($scope);
    }

    private function actor(): User
    {
        return User::query()->findOrFail(1);
    }

    private function offering(): SupplementaryExamOffering
    {
        return SupplementaryExamOffering::query()->findOrFail(600);
    }

    private function period(int $periodId = 500): SupplementaryExamPeriod
    {
        return SupplementaryExamPeriod::query()->findOrFail($periodId);
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

    private function mapReviewPermission(): void
    {
        DB::table('role_permissions')->insert([
            'role_id' => 1,
            'permission_id' => 2,
            'granted_at' => self::OLD_TIME,
        ]);
    }

    private function seedUnrelatedGradingPolicy(): void
    {
        DB::table('grading_policies')->insert(array_merge(
            ['grading_policy_id' => 2],
            $this->gradingPolicyPayload('Future noncanonical policy'),
            ['created_at' => self::OLD_TIME, 'updated_at' => self::OLD_TIME],
        ));
    }

    private function gradingPolicyPayload(string $name, bool $isDefault = false): array
    {
        return [
            'policy_name' => $name,
            'theoretical_max_mark' => 60,
            'practical_max_mark' => 40,
            'minimum_theoretical_mark' => 45,
            'minimum_practical_mark' => 25,
            'minimum_final_mark' => 80,
            'is_default' => $isDefault ? 1 : 0,
            'is_active' => 1,
        ];
    }

    private function seedUnrelatedOfficialStatuses(): void
    {
        DB::table('result_statuses')->insert([
            'result_status_id' => 5,
            'status_code' => 'future_result',
            'status_name' => 'Future result',
            'is_active' => 1,
        ]);
        DB::table('approval_statuses')->insert([
            'approval_status_id' => 2,
            'status_code' => 'future_approval',
            'status_name' => 'Future approval',
            'is_active' => 1,
        ]);
        DB::table('registration_statuses')->insert([
            'registration_status_id' => 2,
            'status_code' => 'future_registration',
            'status_name' => 'Future registration',
            'is_active' => 1,
        ]);
    }

    private function seedReferenceData(): void
    {
        DB::table('system_modules')->insert(['module_id' => 1, 'module_code' => 'exams', 'is_active' => 1]);
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'exam_officer', 'is_active' => 1]);
        DB::table('permissions')->insert([
            [
                'permission_id' => 1,
                'module_id' => 1,
                'permission_code' => 'supplementary_exams.results.materialize',
                'permission_name' => 'Materialize supplementary official results',
                'description' => 'owned:supplementary-exam-materialization-phase6',
                'is_active' => 1,
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
            [
                'permission_id' => 2,
                'module_id' => 1,
                'permission_code' => 'supplementary_exams.grades.review',
                'permission_name' => 'Review supplementary grades',
                'description' => 'assigned Phase-7 reconciliation authority',
                'is_active' => 1,
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
        ]);
        $this->mapMaterializationPermission();
        $this->mapReviewPermission();
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
            [
                'student_grade_component_id' => 500,
                'student_course_registration_id' => 300,
                'grade_component_id' => 1,
                'mark' => 20,
                'grade_status' => 'approved',
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
            [
                'student_grade_component_id' => 501,
                'student_course_registration_id' => 300,
                'grade_component_id' => 2,
                'mark' => 30,
                'grade_status' => 'approved',
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
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
        DB::table('supplementary_exam_grader_assignments')->insert([
            'supplementary_exam_grader_assignment_id' => 750,
            'supplementary_exam_offering_id' => 600,
            'faculty_member_id' => 77,
            'current_slot' => 1,
            'assigned_by_user_id' => 1,
            'assigned_at' => self::OLD_TIME,
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
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
            [
                'supplementary_exam_grade_event_id' => 998,
                'supplementary_exam_grade_result_id' => 800,
                'supplementary_exam_grade_submission_id' => 900,
                'event_type' => 'submitted',
                'from_status' => 'draft',
                'to_status' => 'submitted',
                'submission_version' => 1,
                'theoretical_mark' => 40,
                'actor_user_id' => 77,
                'created_at' => self::PUBLISHED_TIME,
            ],
            [
                'supplementary_exam_grade_event_id' => 999,
                'supplementary_exam_grade_result_id' => 800,
                'supplementary_exam_grade_submission_id' => 900,
                'event_type' => 'approved',
                'from_status' => 'submitted',
                'to_status' => 'approved',
                'submission_version' => 1,
                'theoretical_mark' => 40,
                'actor_user_id' => 1,
                'created_at' => self::PUBLISHED_TIME,
            ],
            [
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
            ],
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
            [
                'student_grade_component_id' => 502,
                'student_course_registration_id' => 301,
                'grade_component_id' => 1,
                'mark' => 20,
                'grade_status' => 'approved',
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
            [
                'student_grade_component_id' => 503,
                'student_course_registration_id' => 301,
                'grade_component_id' => 2,
                'mark' => 30,
                'grade_status' => 'approved',
                'created_at' => self::OLD_TIME,
                'updated_at' => self::OLD_TIME,
            ],
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

    private function seedRepeatPeriodSource(): void
    {
        DB::table('supplementary_exam_periods')->insert([
            'supplementary_exam_period_id' => 510,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'results_published',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 610,
            'supplementary_exam_period_id' => 510,
            'academic_program_id' => 10,
            'course_id' => 20,
            'status' => 'closed',
            'created_at' => self::OLD_TIME,
            'updated_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_offering_sources')->insert([
            'supplementary_exam_offering_source_id' => 10,
            'supplementary_exam_offering_id' => 610,
            'course_offering_id' => 100,
            'created_at' => self::OLD_TIME,
        ]);
        DB::table('supplementary_exam_registrations')->insert([
            'supplementary_exam_registration_id' => 710,
            'supplementary_exam_offering_id' => 610,
            'student_id' => 200,
            'student_course_registration_id' => 300,
            'status' => 'registered',
            'current_slot' => 1,
            'eligibility_reason' => 'failed_theoretical',
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_submissions')->insert([
            'supplementary_exam_grade_submission_id' => 910,
            'supplementary_exam_offering_id' => 610,
            'submission_version' => 1,
            'status' => 'published',
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_results')->insert([
            'supplementary_exam_grade_result_id' => 810,
            'supplementary_exam_registration_id' => 710,
            'supplementary_exam_offering_id' => 610,
            'student_course_registration_id' => 300,
            'student_id' => 200,
            'theoretical_mark' => 41,
            'status' => 'published',
            'submission_version' => 1,
            'published_at' => self::PUBLISHED_TIME,
            'created_at' => self::PUBLISHED_TIME,
            'updated_at' => self::PUBLISHED_TIME,
        ]);
        DB::table('supplementary_exam_grade_events')->insert([
            'supplementary_exam_grade_event_id' => 1010,
            'supplementary_exam_grade_result_id' => 810,
            'supplementary_exam_grade_submission_id' => 910,
            'event_type' => 'published',
            'from_status' => 'approved',
            'to_status' => 'published',
            'submission_version' => 1,
            'theoretical_mark' => 41,
            'actor_user_id' => 1,
            'created_at' => self::PUBLISHED_TIME,
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
        DB::table('student_grade_components')->where('student_grade_component_id', 500)->update([
            'mark' => 20,
            'grade_status' => 'approved',
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
        Schema::create('supplementary_exam_grader_assignments', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grader_assignment_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('faculty_member_id');
            $table->tinyInteger('current_slot')->nullable();
            $table->integer('assigned_by_user_id');
            $table->dateTime('assigned_at');
            $table->dateTime('ended_at')->nullable();
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
            $table->text('before_theoretical_components_snapshot');
            $table->text('after_theoretical_components_snapshot');
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
