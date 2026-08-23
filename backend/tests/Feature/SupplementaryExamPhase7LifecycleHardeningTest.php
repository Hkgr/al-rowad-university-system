<?php

namespace Tests\Feature;

use App\Exceptions\GradeException;
use App\Models\SupplementaryExamPeriod;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\SupplementaryExamGradingService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class SupplementaryExamPhase7LifecycleHardeningTest extends TestCase
{
    private function grading(): SupplementaryExamGradingService
    {
        return new SupplementaryExamGradingService(
            $this->createMock(GradeService::class),
            $this->createMock(DataScopeService::class),
        );
    }

    private function invoke(object $service, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$arguments);
    }

    #[Test]
    public function review_transition_matrix_accepts_only_the_supported_source_states(): void
    {
        $service = $this->grading();

        $this->assertSame('returned', $this->invoke($service, 'reviewTargetStatus', 'return', 'submitted', 'Fix mark'));
        $this->assertSame('approved', $this->invoke($service, 'reviewTargetStatus', 'approve', 'submitted', null));
        $this->assertSame('published', $this->invoke($service, 'reviewTargetStatus', 'publish', 'approved', null));

        try {
            $this->invoke($service, 'reviewTargetStatus', 'approve', 'returned', null);
            $this->fail('A returned submission must be resubmitted before approval.');
        } catch (GradeException $exception) {
            $this->assertSame('supplementary_grade_approve_invalid', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
    }

    #[Test]
    public function terminal_period_rejects_every_review_mutation(): void
    {
        $period = new SupplementaryExamPeriod();
        $period->setAttribute('status', 'results_materialized');

        try {
            $this->invoke($this->grading(), 'assertReviewPeriodState', $period, 'publish', 'published');
            $this->fail('A materialized period must remain terminal.');
        } catch (GradeException $exception) {
            $this->assertSame('supplementary_period_terminal', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
    }

    #[Test]
    public function duplicate_targets_and_official_mutation_paths_are_guarded(): void
    {
        $eligibility = file_get_contents(app_path('Services/SupplementaryExamEligibilityService.php'));
        $registration = file_get_contents(app_path('Services/SupplementaryExamRegistrationService.php'));
        $grading = file_get_contents(app_path('Services/SupplementaryExamGradingService.php'));
        $attendance = file_get_contents(app_path('Services/AttendanceService.php'));
        $discipline = file_get_contents(app_path('Services/DisciplinaryCaseService.php'));
        $ordinaryRegistration = file_get_contents(app_path('Services/RegistrationService.php'));

        $this->assertStringContainsString('SupplementaryExamTargetGuard::isMaterialized', $eligibility);
        $this->assertStringContainsString('SupplementaryExamTargetGuard::assertAvailable', $eligibility);
        $this->assertStringContainsString('SupplementaryExamTargetGuard::assertAvailable', $registration);
        $this->assertStringContainsString('SupplementaryExamTargetGuard::assertAllAvailable', $grading);
        $this->assertStringContainsString('lockAndValidateFixedRosterTargets', $grading);
        $this->assertStringContainsString('assertNotSupplementaryMaterialized', $attendance);
        $this->assertSame(2, substr_count($discipline, 'assertNotSupplementaryMaterialized'));
        $this->assertSame(2, substr_count($ordinaryRegistration, 'SupplementaryExamTargetGuard::assertAvailable'));
        $this->assertSame(2, substr_count($ordinaryRegistration, 'SupplementaryExamTargetGuard::assertFixedRosterAvailable'));
    }

    #[Test]
    public function grading_guards_scope_roster_versions_and_assignment_state(): void
    {
        $source = file_get_contents(app_path('Services/SupplementaryExamGradingService.php'));

        foreach ([
            'hasActualUniversityScope',
            'lockExactPeriodRoster',
            'lockExactSubmissionRoster',
            'supplementary_grade_roster_empty',
            'supplementary_grade_roster_mismatch',
            'supplementary_grade_version_mismatch',
            "['submitted', 'approved', 'published']",
            'student_course_registration_id',
            'current_grader_assignment',
            'action_flags',
            'public function graderOptions',
            'limit(50)',
            'scopeFacultyMembersForMutation',
            'canMutateFacultyMember',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    #[Test]
    public function eligibility_list_reads_use_one_batched_evaluation_context(): void
    {
        $service = file_get_contents(app_path('Services/SupplementaryExamEligibilityService.php'));
        $student = file_get_contents(app_path('Http/Controllers/Api/StudentSupplementaryExamController.php'));
        $staff = file_get_contents(app_path('Http/Controllers/Api/SupplementaryExamEligibilityController.php'));

        foreach ([
            'public function evaluationContext',
            'materializedTargetIds($registrationIds)',
            "'parts_by_course_offering'",
            "'marks_by_registration'",
            "'deferrals_by_registration'",
            "'theory_approvals_by_course_offering'",
        ] as $batchedContract) {
            $this->assertStringContainsString($batchedContract, $service);
        }
        foreach ([$student, $staff] as $controller) {
            $this->assertStringContainsString('$sourceOfferingIds=', $controller);
            $this->assertStringContainsString('->evaluationContext($offerings,$registrations)', $controller);
            $this->assertStringContainsString('->evaluate($o,$r,$context)', $controller);
        }
    }
}
