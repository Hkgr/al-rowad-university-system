<?php

namespace Tests\Feature;

use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamTheoreticalDeferral;
use App\Models\User;
use App\Services\SupplementaryExamEligibilityService;
use App\Services\SupplementaryExamGradingService;
use App\Services\SupplementaryExamMaterializationService;
use App\Services\SupplementaryExamOfferingService;
use App\Services\SupplementaryExamPeriodGovernanceService;
use App\Services\SupplementaryExamRegistrationService;
use App\Services\SupplementaryExamRegistrationWindowService;
use App\Support\SupplementaryExamEligibilityGovernance;
use App\Support\SupplementaryExamGradingGovernance;
use App\Support\SupplementaryExamMaterializationGovernance;
use App\Support\SupplementaryExamOfferingGovernance;
use App\Support\SupplementaryExamPeriodGovernance;
use App\Support\SupplementaryExamRegistrationGovernance;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamPhase7AuthorizationMatrixTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function mutationRoutes(): iterable
    {
        yield 'student declares deferral' => ['post', 'student/supplementary-exams/deferrals', 'academic', 'declare_deferral', 'isStudent', SupplementaryExamEligibilityGovernance::PERMISSION_SELF];
        yield 'student cancels deferral' => ['post', 'student/supplementary-exams/deferrals/{deferral}/cancel', 'academic', 'cancel_deferral', 'isStudent', SupplementaryExamEligibilityGovernance::PERMISSION_SELF];
        yield 'student registers' => ['post', 'student/supplementary-exams/registrations', 'operational', 'register_self', 'isStudent', SupplementaryExamRegistrationGovernance::SELF];
        yield 'student cancels registration' => ['post', 'student/supplementary-exams/registrations/{registration}/cancel', 'operational', 'cancel_self', 'isStudent', SupplementaryExamRegistrationGovernance::SELF];
        yield 'registration office opens window' => ['post', 'registration-office/supplementary-exam-periods/{period}/open-registration', 'operational', 'open_registration', 'isRegistrationOfficer', SupplementaryExamRegistrationGovernance::WINDOW];
        yield 'registration office closes window' => ['post', 'registration-office/supplementary-exam-periods/{period}/close-registration', 'operational', 'close_registration', 'isRegistrationOfficer', SupplementaryExamRegistrationGovernance::WINDOW];
        yield 'registration office registers student' => ['post', 'registration-office/supplementary-exam-registrations', 'operational', 'register_for_student', 'isRegistrationOfficer', SupplementaryExamRegistrationGovernance::MANAGE];
        yield 'registration office cancels student registration' => ['post', 'registration-office/supplementary-exam-registrations/{registration}/cancel', 'operational', 'cancel_for_student', 'isRegistrationOfficer', SupplementaryExamRegistrationGovernance::MANAGE];
        yield 'professor saves theory marks' => ['put', 'professor/supplementary-exams/{offering}/grades', 'academic', 'save_drafts', 'isProfessor', SupplementaryExamGradingGovernance::ENTER];
        yield 'professor submits batch' => ['post', 'professor/supplementary-exams/{offering}/submit', 'academic', 'submit', 'isProfessor', SupplementaryExamGradingGovernance::ENTER];
        yield 'professor resubmits batch' => ['post', 'professor/supplementary-exams/{offering}/resubmit', 'academic', 'resubmit', 'isProfessor', SupplementaryExamGradingGovernance::ENTER];
        yield 'exam office returns batch' => ['post', 'exams/supplementary-grades/{submission}/return', 'academic', 'return_submission', 'isExamOfficer', SupplementaryExamGradingGovernance::REVIEW];
        yield 'exam office approves batch' => ['post', 'exams/supplementary-grades/{submission}/approve', 'academic', 'approve_submission', 'isExamOfficer', SupplementaryExamGradingGovernance::REVIEW];
        yield 'exam office publishes batch' => ['post', 'exams/supplementary-grades/{submission}/publish', 'academic', 'publish_submission', 'isExamOfficer', SupplementaryExamGradingGovernance::PUBLISH];
        yield 'exam office materializes offering' => ['post', 'exams/supplementary-offerings/{offering}/materialize', 'academic', 'materialize', 'isExamOfficer', SupplementaryExamMaterializationGovernance::MATERIALIZE];
        yield 'exam office assigns grader' => ['post', 'exams/supplementary-offerings/{offering}/grader', 'operational', 'assign_grader', 'isExamOfficer', SupplementaryExamGradingGovernance::ASSIGN];
        yield 'exam office opens grading' => ['post', 'exams/supplementary-periods/{period}/open-grading', 'operational', 'open_grading', 'isExamOfficer', SupplementaryExamGradingGovernance::REVIEW];
        yield 'dean creates offering' => ['post', 'dean/supplementary-exam-offerings', 'operational', 'open_offering', 'isDean', SupplementaryExamOfferingGovernance::PERMISSION_MANAGE];
        yield 'dean closes offering' => ['post', 'dean/supplementary-exam-offerings/{offering}/close', 'operational', 'close_offering', 'isDean', SupplementaryExamOfferingGovernance::PERMISSION_MANAGE];
        yield 'dean reopens offering' => ['post', 'dean/supplementary-exam-offerings/{offering}/reopen', 'operational', 'reopen_offering', 'isDean', SupplementaryExamOfferingGovernance::PERMISSION_MANAGE];
        yield 'vice president announces period' => ['post', 'vice-presidency/scientific/supplementary-exam-periods', 'academic', 'announce_period', 'isScientificVicePresident', SupplementaryExamPeriodGovernance::PERMISSION_DECIDE];
    }

    #[Test]
    #[DataProvider('mutationRoutes')]
    public function every_supplementary_mutation_route_is_classified_and_present(
        string $verb,
        string $path,
        string $classification,
        string $operation,
        string $roleMethod,
        string $permission,
    ): void {
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertContains($classification, ['operational', 'academic']);
        $this->assertNotSame('', $operation);
        $this->assertStringStartsWith('is', $roleMethod);
        $this->assertStringStartsWith('supplementary_exams.', $permission);
        $this->assertStringContainsString("Route::{$verb}('{$path}'", $routes);
        $this->assertSame(1, substr_count($routes, "Route::{$verb}('{$path}'"));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function mutationAuthorities(): iterable
    {
        foreach (self::mutationRoutes() as $name => $case) {
            yield $name => [$case[3], $case[4], $case[5]];
        }
    }

    #[Test]
    #[DataProvider('mutationAuthorities')]
    public function every_mutation_rejects_an_assigned_permission_without_the_actual_role(
        string $operation,
        string $roleMethod,
        string $permission,
    ): void {
        $this->assertMutationForbidden($operation, $this->actor($roleMethod, false, [$permission]));
    }

    #[Test]
    #[DataProvider('mutationAuthorities')]
    public function every_mutation_rejects_the_actual_role_without_the_assigned_permission(
        string $operation,
        string $roleMethod,
        string $permission,
    ): void {
        $this->assertMutationForbidden($operation, $this->actor($roleMethod, true, []));
    }

    /** @return iterable<string, array{string}> */
    public static function readOnlyRoutes(): iterable
    {
        foreach ([
            'student/supplementary-exams/eligibility',
            'student/supplementary-exams/deferrals',
            'supplementary-exam-eligibility',
            'student/supplementary-exams/registrations',
            'supplementary-exam-registration-periods',
            'registration-office/supplementary-exam-periods/{period}/registrations',
            'supplementary-exam-periods/{period}/registrations',
            'professor/supplementary-exams',
            'professor/supplementary-exams/{offering}/grades',
            'supplementary-exam-periods',
            'supplementary-exam-periods/{period}',
            'exams/supplementary-grades',
            'exams/supplementary-periods/{period}/reconciliation',
            'exams/supplementary-offerings/{offering}/graders',
            'dean/supplementary-exam-offerings/context',
            'dean/supplementary-exam-offerings/catalog',
            'dean/supplementary-exam-offerings',
            'dean/supplementary-exam-offerings/{offering}',
            'vice-presidency/scientific/supplementary-exam-periods',
            'vice-presidency/scientific/supplementary-exam-periods/{period}',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[Test]
    #[DataProvider('readOnlyRoutes')]
    public function supplementary_read_paths_are_explicit_get_routes(string $path): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString("Route::get('{$path}'", $routes);
    }

    #[Test]
    public function academic_mutators_require_actual_roles_assigned_permissions_and_mutation_scope(): void
    {
        $sources = [
            'period' => file_get_contents(app_path('Services/SupplementaryExamPeriodGovernanceService.php')),
            'offering' => file_get_contents(app_path('Services/SupplementaryExamOfferingService.php')),
            'eligibility' => file_get_contents(app_path('Services/SupplementaryExamEligibilityService.php')),
            'registration' => file_get_contents(app_path('Services/SupplementaryExamRegistrationService.php')),
            'window' => file_get_contents(app_path('Services/SupplementaryExamRegistrationWindowService.php')),
            'grading' => file_get_contents(app_path('Services/SupplementaryExamGradingService.php')),
            'materialization' => file_get_contents(app_path('Services/SupplementaryExamMaterializationService.php')),
        ];

        $this->assertStringContainsString('isScientificVicePresident()', $sources['period']);
        $this->assertStringContainsString('isDean()', $sources['offering']);
        $this->assertStringContainsString('isStudent()', $sources['eligibility']);
        $this->assertStringContainsString('isStudent()', $sources['registration']);
        $this->assertStringContainsString('isRegistrationOfficer()', $sources['registration']);
        $this->assertStringContainsString('isRegistrationOfficer()', $sources['window']);
        $this->assertStringContainsString('isProfessor()', $sources['grading']);
        $this->assertStringContainsString('isExamOfficer()', $sources['grading']);
        $this->assertStringContainsString('isExamOfficer()', $sources['materialization']);

        foreach ($sources as $source) {
            $this->assertStringContainsString('effectivePermissions()', $source);
        }
        foreach (['offering', 'registration', 'grading', 'materialization'] as $scoped) {
            $this->assertStringContainsString('canMutateProgram(', $sources[$scoped]);
        }
        $this->assertStringContainsString('canMutateStudent(', $sources['registration']);
        $this->assertStringContainsString('hasActualUniversityScope(', $sources['window']);
        $this->assertStringContainsString('hasActualUniversityScope(', $sources['grading']);
    }

    private function actor(string $roleMethod, bool $hasActualRole, array $permissions): User
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('user_id', 9001);
        $actor->setAttribute('student_id', 9002);
        $actor->setAttribute('employee_id', 9003);
        $actor->shouldReceive($roleMethod)->andReturn($hasActualRole);
        $actor->shouldReceive('effectivePermissions')->andReturn(collect($permissions));

        return $actor;
    }

    private function assertMutationForbidden(string $operation, User $actor): void
    {
        $thrown = null;
        try {
            $this->invokeMutation($operation, $actor);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "{$operation} did not reject the unauthorized actor.");
        $this->assertTrue(
            property_exists($thrown, 'status'),
            "{$operation} raised an unexpected ".get_class($thrown).': '.$thrown->getMessage(),
        );
        $this->assertSame(403, $thrown->status, "{$operation} did not fail with HTTP 403.");
    }

    private function invokeMutation(string $operation, User $actor): mixed
    {
        $offering = new SupplementaryExamOffering(['supplementary_exam_offering_id' => 701]);
        $period = new SupplementaryExamPeriod(['supplementary_exam_period_id' => 702]);

        return match ($operation) {
            'declare_deferral' => $this->app->make(SupplementaryExamEligibilityService::class)
                ->declare($actor, 701, 703),
            'cancel_deferral' => $this->app->make(SupplementaryExamEligibilityService::class)
                ->cancel($actor, new SupplementaryExamTheoreticalDeferral, null),
            'register_self' => $this->app->make(SupplementaryExamRegistrationService::class)
                ->registerSelf($actor, 701, 703),
            'cancel_self' => $this->app->make(SupplementaryExamRegistrationService::class)
                ->cancelSelf($actor, 704, null),
            'register_for_student' => $this->app->make(SupplementaryExamRegistrationService::class)
                ->registerForStudent($actor, 701, 703),
            'cancel_for_student' => $this->app->make(SupplementaryExamRegistrationService::class)
                ->cancelForStudent($actor, 704, 'authorization matrix'),
            'open_registration' => $this->app->make(SupplementaryExamRegistrationWindowService::class)
                ->open($actor, 702),
            'close_registration' => $this->app->make(SupplementaryExamRegistrationWindowService::class)
                ->close($actor, 702),
            'save_drafts' => $this->app->make(SupplementaryExamGradingService::class)
                ->saveDrafts($actor, $offering, []),
            'submit' => $this->app->make(SupplementaryExamGradingService::class)
                ->submit($actor, $offering),
            'resubmit' => $this->app->make(SupplementaryExamGradingService::class)
                ->submit($actor, $offering, true),
            'return_submission' => $this->app->make(SupplementaryExamGradingService::class)
                ->review($actor, 705, 'return', 'authorization matrix'),
            'approve_submission' => $this->app->make(SupplementaryExamGradingService::class)
                ->review($actor, 705, 'approve'),
            'publish_submission' => $this->app->make(SupplementaryExamGradingService::class)
                ->review($actor, 705, 'publish'),
            'materialize' => $this->app->make(SupplementaryExamMaterializationService::class)
                ->materializeOffering($actor, $offering),
            'assign_grader' => $this->app->make(SupplementaryExamGradingService::class)
                ->assign($actor, $offering, 706),
            'open_grading' => $this->app->make(SupplementaryExamGradingService::class)
                ->openGrading($actor, $period),
            'open_offering' => $this->app->make(SupplementaryExamOfferingService::class)
                ->open($actor, []),
            'close_offering' => $this->app->make(SupplementaryExamOfferingService::class)
                ->close($actor, $offering),
            'reopen_offering' => $this->app->make(SupplementaryExamOfferingService::class)
                ->reopen($actor, $offering),
            'announce_period' => $this->app->make(SupplementaryExamPeriodGovernanceService::class)
                ->announce($actor, []),
            default => throw new \LogicException("Unknown mutation matrix operation: {$operation}"),
        };
    }
}
