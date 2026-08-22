<?php
namespace Tests\Feature;
use App\Support\SupplementaryExamPolicy;
use App\Support\SupplementaryExamRegistrationGovernance;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
class SupplementaryExamRegistrationContractTest extends TestCase
{
 #[Test] public function phase_four_is_phase_three_guarded_and_has_no_superadmin_mutation():void{$g=file_get_contents(app_path('Support/SupplementaryExamRegistrationGovernance.php'));$s=file_get_contents(app_path('Services/SupplementaryExamRegistrationService.php'));$w=file_get_contents(app_path('Services/SupplementaryExamRegistrationWindowService.php'));$this->assertStringContainsString('SupplementaryExamEligibilityGovernance::schemaReady()',$g);$this->assertStringContainsString('isRegistrationOfficer()',$s.$w);$this->assertStringContainsString('effectivePermissions()->contains',$s.$w);$this->assertStringNotContainsString('hasPermission(',$s.$w);}
 #[Test] public function registration_reuses_live_eligibility_provenance_policy_and_student_lock():void{$s=file_get_contents(app_path('Services/SupplementaryExamRegistrationService.php'));foreach(['Student::query()->lockForUpdate()','SupplementaryExamOfferingSource::query()','$this->eligibility->evaluate','SupplementaryExamPolicy::maxCoursesPerStudent','where(\'current_slot\',1)'] as $proof)$this->assertStringContainsString($proof,$s);$this->assertStringNotContainsString('StudentCourseRegistration::query()->create',$s);$this->assertStringNotContainsString('CourseOffering::query()->create',$s);}
 #[Test] public function lifecycle_has_no_reopen_and_closure_revalidates():void{$routes=file_get_contents(base_path('routes/api.php'));$w=file_get_contents(app_path('Services/SupplementaryExamRegistrationWindowService.php'));$this->assertStringContainsString("'registration_open'",$w);$this->assertStringContainsString("'registration_closed'",$w);$this->assertStringContainsString('$this->eligibility->evaluate',$w);$this->assertStringNotContainsString('reopen-registration',$routes);}
 #[Test] public function centralized_summer_policy_remains_authoritative():void{$this->assertSame(3,SupplementaryExamPolicy::SUMMER_MAX_COURSES_PER_STUDENT);$s=file_get_contents(app_path('Services/SupplementaryExamRegistrationService.php'));$this->assertStringNotContainsString('semester_id === 3',$s);}
}
