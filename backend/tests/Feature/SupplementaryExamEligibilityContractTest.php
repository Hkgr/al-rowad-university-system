<?php

namespace Tests\Feature;

use App\Models\StudentCourseRegistration;
use App\Models\User;
use App\Services\GradePartWorkflowService;
use App\Services\SupplementaryExamEligibilityService;
use App\Support\SupplementaryExamEligibilityGovernance;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamEligibilityContractTest extends TestCase
{
    #[Test]
    public function phase_three_contract_is_fail_closed_and_has_only_two_reasons(): void
    {
        $governance=file_get_contents(app_path('Support/SupplementaryExamEligibilityGovernance.php'));
        $service=file_get_contents(app_path('Services/SupplementaryExamEligibilityService.php'));
        $this->assertStringContainsString('SupplementaryExamOfferingGovernance::schemaReady()', $governance); // schema dependency
        $this->assertStringContainsString("'failed_theoretical'", $service); // SUPP-ELIG-01/04/11
        $this->assertStringContainsString("'voluntarily_deferred_theoretical'", $service); // SUPP-ELIG-27
        $this->assertStringNotContainsString('grade_improvement', $service); // SUPP-ELIG-05
        $this->assertSame(['registered','completed'], StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES); // SUPP-ELIG-07/08
    }

    #[Test]
    public function practical_policy_official_result_and_provenance_are_canonical(): void
    {
        $service=file_get_contents(app_path('Services/SupplementaryExamEligibilityService.php'));
        $this->assertStringContainsString('defaultGradingPolicy()->minimum_practical_mark', $service); // SUPP-ELIG-02/03/12/20
        $this->assertStringNotContainsString('minimum_practical_mark = 10', $service);
        $this->assertStringContainsString('isOfficiallyVisibleAttempt', $service); // SUPP-ELIG-10/24
        $this->assertStringContainsString('officialAttemptResultStatus', $service);
        $this->assertStringContainsString('SupplementaryExamOfferingSource::query()', $service); // SUPP-ELIG-09/41
        $this->assertStringContainsString("'student_deprived'", $service); // SUPP-ELIG-06
        $this->assertStringContainsString("'regular_result_passed'", $service); // SUPP-ELIG-05
    }

    #[Test]
    public function student_only_mutation_does_not_use_super_admin_bypass(): void
    {
        $service=file_get_contents(app_path('Services/SupplementaryExamEligibilityService.php'));
        $this->assertStringContainsString('$u->isStudent()', $service); // SUPP-ELIG-13-18
        $this->assertStringContainsString('effectivePermissions()->contains', $service);
        $this->assertStringNotContainsString('hasPermission(', $service);
        $this->assertTrue(method_exists(User::class,'isStudent'));
    }

    #[Test]
    public function regular_grade_workflow_exempts_only_explicit_valid_theory_deferrals(): void
    {
        $workflow=file_get_contents(app_path('Services/GradePartWorkflowService.php'));
        $this->assertStringContainsString('supplementary_theoretical_deferred', $workflow); // SUPP-ELIG-28
        $this->assertStringContainsString("\$part === 'theoretical'", $workflow); // SUPP-ELIG-29/30/31
        $this->assertStringContainsString('activeValidDeferral($registration)) continue', $workflow); // SUPP-ELIG-32-34
        $this->assertStringNotContainsString("'theoretical_total' => 0", $workflow); // SUPP-ELIG-25/26
        $this->assertTrue(class_exists(SupplementaryExamEligibilityService::class));
        $this->assertTrue(class_exists(GradePartWorkflowService::class));
    }
}
