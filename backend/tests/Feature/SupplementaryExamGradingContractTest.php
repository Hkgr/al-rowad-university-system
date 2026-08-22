<?php
namespace Tests\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
class SupplementaryExamGradingContractTest extends TestCase
{
 private function service():string{return file_get_contents(app_path('Services/SupplementaryExamGradingService.php'));}
 #[Test] public function fixed_roster_and_canonical_theoretical_only_policy_are_enforced():void{$s=$this->service();foreach(["where('status','registered')","where('current_slot',1)",'gradingPolicyLimits()','theoretical_max_mark','buildCalculation(','preserved_practical_mark'] as $x)$this->assertStringContainsString($x,$s);foreach(['StudentCourseResult::query()->update','StudentGradeComponent::','GradeApproval::','GradePartApproval::'] as $x)$this->assertStringNotContainsString($x,$s);}
 #[Test] public function actual_roles_permissions_assignment_and_scope_are_all_required():void{$s=$this->service();foreach(['isProfessor()','isExamOfficer()','effectivePermissions()->contains','assertAssigned(','canMutateProgram('] as $x)$this->assertStringContainsString($x,$s);$this->assertStringNotContainsString('hasPermission(',$s);}
 #[Test] public function lock_order_and_complete_versioned_batch_are_explicit():void{$s=$this->service();$this->assertStringContainsString('period, offering, current assignment, registrations',$s);foreach(['lockForUpdate()','supplementary_grade_batch_incomplete','submission_version + 1','supplementary_grade_stale_submission','supplementary_grade_version_mismatch'] as $x)$this->assertStringContainsString($x,$s);}
 #[Test] public function return_approve_publish_and_audit_are_isolated():void{$s=$this->service();foreach(["\$action==='return'","\$action==='approve'","\$action==='publish'",'SupplementaryExamGradeEvent::query()->create','results_published','official_record_materialized'] as $x)$this->assertStringContainsString($x,$s);}
 #[Test] public function professor_and_exam_routes_exist():void{$r=file_get_contents(base_path('routes/api.php'));foreach(['professor/supplementary-exams','/grades\'','/submit\'','/resubmit\'','exams/supplementary-grades','/return\'','/approve\'','/publish\'','/grader\'','open-grading'] as $x)$this->assertStringContainsString($x,$r);}
}
