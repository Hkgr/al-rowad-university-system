<?php
namespace Tests\Feature;
use App\Exceptions\GradeException;
use App\Http\Controllers\Api\SupplementaryExamRegistrationOfficeController;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\SupplementaryExamEligibilityService;
use App\Services\SupplementaryExamRegistrationService;
use App\Services\SupplementaryExamRegistrationWindowService;
use App\Support\SupplementaryExamRegistrationGovernance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
class SupplementaryExamRegistrationBehaviorTest extends TestCase
{
 public function test_period_governance_requires_actual_university_scope():void{$eligibility=Mockery::mock(SupplementaryExamEligibilityService::class);$scope=Mockery::mock(DataScopeService::class);$service=new SupplementaryExamRegistrationWindowService($eligibility,$scope);$actor=Mockery::mock(User::class);$actor->shouldReceive('isRegistrationOfficer')->andReturnTrue();$actor->shouldReceive('effectivePermissions')->andReturn(collect([SupplementaryExamRegistrationGovernance::WINDOW]));$scope->shouldReceive('hasActualUniversityScope')->with($actor)->andReturnFalse();$method=new \ReflectionMethod($service,'assertCanGovernPeriod');$method->setAccessible(true);$this->expectException(GradeException::class);$method->invoke($service,$actor);}
 public function test_period_governance_accepts_actual_officer_permission_and_university_scope():void{$scope=Mockery::mock(DataScopeService::class);$service=new SupplementaryExamRegistrationWindowService(Mockery::mock(SupplementaryExamEligibilityService::class),$scope);$actor=Mockery::mock(User::class);$actor->shouldReceive('isRegistrationOfficer')->andReturnTrue();$actor->shouldReceive('effectivePermissions')->andReturn(collect([SupplementaryExamRegistrationGovernance::WINDOW]));$scope->shouldReceive('hasActualUniversityScope')->with($actor)->andReturnTrue();$method=new \ReflectionMethod($service,'assertCanGovernPeriod');$method->setAccessible(true);$method->invoke($service,$actor);$this->addToAssertionCount(1);}
 public function test_empty_closed_period_is_reported_as_fixed():void{Schema::create('supplementary_exam_periods',function(Blueprint$t){$t->integer('supplementary_exam_period_id')->primary();$t->string('status');$t->timestamps();});Schema::create('supplementary_exam_offerings',function(Blueprint$t){$t->integer('supplementary_exam_offering_id')->primary();$t->integer('supplementary_exam_period_id');});Schema::create('supplementary_exam_registrations',function(Blueprint$t){$t->integer('supplementary_exam_registration_id')->primary();$t->integer('supplementary_exam_offering_id');$t->string('status');$t->tinyInteger('current_slot')->nullable();});DB::table('supplementary_exam_periods')->insert(['supplementary_exam_period_id'=>77,'status'=>'registration_closed','created_at'=>now(),'updated_at'=>now()]);$registration=Mockery::mock(SupplementaryExamRegistrationService::class);$registration->shouldReceive('ready')->once();$controller=new SupplementaryExamRegistrationOfficeController($registration,Mockery::mock(SupplementaryExamRegistrationWindowService::class),Mockery::mock(DataScopeService::class));$user=Mockery::mock(User::class);$user->shouldReceive('effectivePermissions')->andReturn(collect([SupplementaryExamRegistrationGovernance::VIEW]));$request=Request::create('/','GET');$request->setUserResolver(fn()=>$user);$response=$controller->index($request,77);$payload=$response->getData(true);$this->assertSame('registration_closed',$payload['period_status']);$this->assertSame('fixed',$payload['list_status']);$this->assertSame([],$payload['data']);}
 protected function tearDown():void{Schema::disableForeignKeyConstraints();foreach(['supplementary_exam_registrations','supplementary_exam_offerings','supplementary_exam_periods']as$t)Schema::dropIfExists($t);Schema::enableForeignKeyConstraints();parent::tearDown();}
}
