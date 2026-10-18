<?php
namespace Tests\Feature;
use App\Models\GradeComponent;
use App\Models\RegistrationStatus;
use App\Models\StudentCourseRegistration;
use App\Models\StudentGradeComponent;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Services\GradeService;
use App\Services\SupplementaryExamEligibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;
class SupplementaryExamEligibilityPartialTheoryBehaviorTest extends TestCase
{
 private SupplementaryExamEligibilityService $service; private StudentCourseRegistration $registration; private SupplementaryExamOffering $offering; private $parts;
 protected function setUp():void{parent::setUp();foreach(['student_grade_components','grade_part_approvals','supplementary_exam_offering_sources','grade_components']as$t)Schema::dropIfExists($t);Schema::create('grade_components',function(Blueprint$t){$t->increments('grade_component_id');$t->integer('course_offering_id');$t->string('component_type');$t->boolean('is_required');});Schema::create('student_grade_components',function(Blueprint$t){$t->increments('student_grade_component_id');$t->integer('student_course_registration_id');$t->integer('grade_component_id');$t->decimal('mark',8,2)->nullable();});Schema::create('supplementary_exam_offering_sources',function(Blueprint$t){$t->increments('supplementary_exam_offering_source_id');$t->integer('supplementary_exam_offering_id');$t->integer('course_offering_id');});Schema::create('grade_part_approvals',function(Blueprint$t){$t->increments('grade_part_approval_id');$t->integer('course_offering_id');$t->string('component_type');$t->string('status');});DB::table('grade_components')->insert([['grade_component_id'=>1,'course_offering_id'=>10,'component_type'=>'theoretical','is_required'=>1],['grade_component_id'=>2,'course_offering_id'=>10,'component_type'=>'theoretical','is_required'=>1]]);DB::table('supplementary_exam_offering_sources')->insert(['supplementary_exam_offering_id'=>20,'course_offering_id'=>10]);$this->parts=GradeComponent::query()->get();$this->registration=new StudentCourseRegistration(['student_id'=>1,'course_offering_id'=>10]);$this->registration->setAttribute('student_course_registration_id',30);$this->registration->setRelation('registrationStatus',new RegistrationStatus(['status_code'=>'registered']));$this->registration->setRelation('studentCourseResult',null);$this->registration->setRelation('resultStatus',null);$this->offering=new SupplementaryExamOffering(['status'=>'open']);$this->offering->setAttribute('supplementary_exam_offering_id',20);$this->offering->setRelation('period',new SupplementaryExamPeriod(['status'=>'announced']));$this->service=new SupplementaryExamEligibilityService($this->createMock(GradeService::class));}
 public function test_no_theoretical_component_mark_keeps_declaration_open():void{$this->assertNotContains('theoretical_already_graded',$this->blockers());}
 public function test_first_of_two_theoretical_marks_blocks_declaration():void{$this->mark(1,15);$this->assertContains('theoretical_already_graded',$this->blockers());}
 public function test_second_of_two_theoretical_marks_blocks_declaration():void{$this->mark(2,15);$this->assertContains('theoretical_already_graded',$this->blockers());}
 public function test_new_deferral_is_blocked_after_registration_opens():void{$this->offering->period->status='registration_open';$this->assertContains('supplementary_period_not_announced',$this->blockers());}
 private function mark(int$id,float$mark):void{StudentGradeComponent::query()->create(['student_course_registration_id'=>30,'grade_component_id'=>$id,'mark'=>$mark]);}
 private function blockers():array{$method=new ReflectionMethod($this->service,'declarationBlockers');return $method->invoke($this->service,$this->offering,$this->registration,$this->parts,null);}
}
