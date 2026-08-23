<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamMaterializationContractTest extends TestCase
{
    private function service(): string
    {
        return file_get_contents(app_path('Services/SupplementaryExamMaterializationService.php'));
    }

    #[Test]
    public function authority_permission_and_data_scope_are_independent_fail_closed_guards(): void
    {
        $service = $this->service();

        foreach ([
            'isExamOfficer()',
            'effectivePermissions()->contains(Governance::MATERIALIZE)',
            'canMutateProgram(',
            'supplementary_materialization_forbidden',
            'supplementary_materialization_out_of_scope',
        ] as $proof) {
            $this->assertStringContainsString($proof, $service);
        }
        $this->assertStringNotContainsString('hasPermission(', $service);

        $governance = file_get_contents(app_path('Support/SupplementaryExamMaterializationGovernance.php'));
        $this->assertStringContainsString("supplementary_exams.results.materialize", $governance);
        $this->assertStringContainsString("where('r.role_code', 'exam_officer')", $governance);
        $this->assertStringContainsString('return $allMappings === 1 && $examOfficerMappings === 1', $governance);
    }

    #[Test]
    public function one_offering_transaction_has_deterministic_lock_and_exact_source_provenance(): void
    {
        $service = $this->service();
        $this->assertStringContainsString('One transaction owns the whole offering', $service);
        $this->assertStringContainsString('DB::transaction(', $service);

        foreach ([
            'SupplementaryExamPeriod::query()',
            'SupplementaryExamOffering::query()',
            'SupplementaryExamGradeSubmission::query()',
            'SupplementaryExamRegistration::query()',
            'SupplementaryExamGradeResult::query()',
            'SupplementaryExamGradeEvent::query()',
            'StudentCourseRegistration::query()',
            'StudentCourseResult::query()',
            'SupplementaryExamMaterialization::query()',
        ] as $lockTarget) {
            $this->assertStringContainsString($lockTarget, $service);
        }
        $this->assertGreaterThanOrEqual(10, substr_count($service, 'lockForUpdate()'));

        foreach ([
            'supplementary_materialization_result_not_published',
            'supplementary_materialization_stale_submission',
            'supplementary_materialization_source_drift',
            'supplementary_materialization_source_event_mismatch',
            'supplementary_materialization_roster_mismatch',
            'supplementary_materialization_source_mismatch',
            'supplementary_materialization_target_mismatch',
            'supplementary_materialization_target_drift',
        ] as $errorCode) {
            $this->assertStringContainsString($errorCode, $service);
        }
    }

    #[Test]
    public function canonical_result_and_single_theoretical_component_are_updated_without_practical_or_attendance_writes(): void
    {
        $service = $this->service();
        $this->assertStringContainsString('buildCalculationForRequiredParts(', $service);
        $this->assertStringContainsString('lockDefaultGradingPolicy()', $service);
        $this->assertStringContainsString("'theoretical_total' => round(", $service);
        $this->assertStringContainsString("'final_mark' => round(", $service);
        $this->assertStringContainsString("'result_status_id' => \$newStatus->getKey()", $service);
        $this->assertStringContainsString("'mark' => round(\$theoreticalMark, 2)", $service);
        $this->assertStringContainsString('assertTheoreticalAggregateMatchesSnapshot(', $service);
        $this->assertStringContainsString('assertTheoreticalComponentTransition(', $service);
        $this->assertStringContainsString('supplementary_materialization_theoretical_component_ambiguous', $service);
        $this->assertStringContainsString('assertPreservedOfficialFields(', $service);
        $this->assertStringContainsString("['practical_total', 'coursework_total', 'is_deprived', 'result_announced_at']", $service);

        foreach ([
            'StudentCourseRegistration::query()->create',
            'StudentAttendance::',
            'supplementary_exam_results',
        ] as $forbiddenWrite) {
            $this->assertStringNotContainsString($forbiddenWrite, $service);
        }
    }

    #[Test]
    public function idempotency_provenance_and_period_terminal_state_are_explicit(): void
    {
        $service = $this->service();
        foreach ([
            'already_materialized',
            'supplementary_materialization_idempotency_conflict',
            'supplementary_materialization_target_conflict',
            'practical_components_snapshot',
            'before_theoretical_components_snapshot',
            'after_theoretical_components_snapshot',
            'supplementary_exam_grade_event_id',
            'sourceSnapshotMatches(',
            'approvalSnapshotMatches(',
            'targetSnapshotMatches(',
            "prefixSnapshot('before', \$before)",
            "prefixSnapshot('after', \$after)",
            'official_result_materialized',
            'results_materialized',
            'registrations->isEmpty()',
        ] as $proof) {
            $this->assertStringContainsString($proof, $service);
        }
    }

    #[Test]
    public function optional_announcement_and_post_materialization_recalculation_guards_are_explicit(): void
    {
        $governance = file_get_contents(app_path('Support/SupplementaryExamMaterializationGovernance.php'));
        $grades = file_get_contents(app_path('Services/GradeService.php'));
        $parts = file_get_contents(app_path('Services/GradePartWorkflowService.php'));

        $this->assertStringContainsString('resultAnnouncedAtAvailable()', $governance);
        $this->assertStringNotContainsString("'result_announced_at', 'calculated_by_user_id'", $governance);
        $this->assertStringContainsString('assertNotSupplementaryMaterialized(', $grades);
        $this->assertStringContainsString('supplementary_materialized_result_locked', $grades);
        $this->assertStringContainsString('assertNotSupplementaryMaterialized(', $parts);
        $this->assertStringNotContainsString('calculate_final_grade', $grades);
        $this->assertStringNotContainsString('calculate_final_grade', $parts);
    }

    #[Test]
    public function api_and_exam_office_ui_expose_only_offering_level_materialization(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));
        $page = file_get_contents(base_path('../frontend/src/features/exam-board/pages/SupplementaryGradesPage.jsx'));
        $professorPage = file_get_contents(base_path('../frontend/src/features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx'));

        $this->assertStringContainsString('exams/supplementary-offerings/{offering}/materialize', $routes);
        $this->assertStringContainsString('materialization.can_materialize', $page);
        $this->assertStringContainsString('window.confirm(', $page);
        $this->assertStringContainsString('ترحيل النتائج إلى السجل الرسمي', $page);
        $this->assertStringContainsString('العلامات العملية الأصلية دون تغيير', $page);
        $this->assertStringContainsString('السجل الأكاديمي الرسمي', $page);
        $this->assertStringNotContainsString('/materialize', $professorPage);
    }

    #[Test]
    public function transcript_and_gpa_remain_on_the_existing_official_result_path(): void
    {
        $grades = file_get_contents(app_path('Services/GradeService.php'));
        $service = $this->service();
        $progression = file_get_contents(app_path('Services/AcademicProgressionService.php'));
        $requirements = file_get_contents(app_path('Services/AcademicRequirementService.php'));
        $graduation = file_get_contents(app_path('Services/GraduationEligibilityService.php'));

        $this->assertStringContainsString('officialAcademicAttempts($student)', $grades);
        $this->assertStringContainsString("->whereHas('studentCourseResult')", $grades);
        $this->assertStringContainsString('constrainAuthoritativeApprovedGradeApproval', $grades);
        $this->assertStringNotContainsString('Transcript', $service);
        $this->assertStringNotContainsString('Gpa', $service);
        $this->assertStringNotContainsString('Progression', $service);
        $this->assertStringNotContainsString('Graduation', $service);
        $this->assertStringContainsString('officialCumulativeMetrics($student)', $progression);
        $this->assertStringContainsString('officialTermMetrics($student', $progression);
        $this->assertStringContainsString('officialAttemptResultStatus($registration)', $requirements);
        $this->assertStringContainsString('AcademicRequirementService $requirements', $graduation);
    }
}
