<?php
namespace Tests\Feature;
use App\Exceptions\GradeException;
use App\Models\StudentCourseRegistration;
use App\Services\GradeService;
use App\Services\SupplementaryExamEligibilityService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class SupplementaryExamEligibilitySchemaAbsenceRuntimeTest extends TestCase
{
 protected function setUp():void{parent::setUp();Schema::dropIfExists('supplementary_exam_theoretical_deferral_events');Schema::dropIfExists('supplementary_exam_theoretical_deferrals');}
 public function test_resolver_returns_null_without_querying_missing_phase3_table():void{$queries=[];DB::listen(function(QueryExecuted$q)use(&$queries){$queries[]=$q->sql;});$service=new SupplementaryExamEligibilityService($this->createMock(GradeService::class));$registration=new StudentCourseRegistration();$registration->setAttribute('student_course_registration_id',123);$this->assertNull($service->resolveInvalidCurrentDeferral($registration,1));$this->assertFalse(collect($queries)->contains(fn($sql)=>str_contains($sql,'supplementary_exam_theoretical_deferrals')));}
 public function test_mutation_readiness_failure_is_safe_domain_error():void{$service=new SupplementaryExamEligibilityService($this->createMock(GradeService::class));try{$service->assertSchemaReady();$this->fail('Expected safe schema readiness failure.');}catch(GradeException$e){$this->assertSame('supplementary_exam_eligibility_schema_not_ready',$e->errorCode);$this->assertSame(503,$e->status);$this->assertStringNotContainsString('SQLSTATE',$e->getMessage());$this->assertStringNotContainsString('supplementary_exam_theoretical_deferrals',$e->getMessage());}}
 public function test_cancellation_controller_uses_primitive_id_before_model_query():void{$source=file_get_contents(app_path('Http/Controllers/Api/StudentSupplementaryExamController.php'));$this->assertStringContainsString('int|string $deferral',$source);$guard=strpos($source,'$this->eligibility->assertSchemaReady();$record=');$query=strpos($source,'SupplementaryExamTheoreticalDeferral::query()->findOrFail');$this->assertNotFalse($guard);$this->assertNotFalse($query);$this->assertLessThan($query,$guard);$this->assertStringNotContainsString('SupplementaryExamTheoreticalDeferral $deferral',$source);}
}
