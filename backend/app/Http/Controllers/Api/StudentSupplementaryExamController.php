<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamTheoreticalDeferral;
use App\Models\SupplementaryExamRegistration;
use App\Services\SupplementaryExamEligibilityService;
use App\Support\SupplementaryExamEligibilityGovernance;
use App\Support\SupplementaryExamRegistrationGovernance;
use App\Support\SupplementaryExamPolicy;
use Illuminate\Http\Request;
class StudentSupplementaryExamController extends Controller
{
 public function __construct(private readonly SupplementaryExamEligibilityService $eligibility){}
 private function student(Request $r){$u=$r->user();abort_unless($u?->isStudent()&&$u->effectivePermissions()->contains(SupplementaryExamEligibilityGovernance::PERMISSION_SELF),403);return $u;}
 public function eligibility(Request $request)
 {
  $u=$this->student($request);
  $offerings=SupplementaryExamOffering::query()->with(['period.semester','course','sources'])->where('status','open')->when($request->integer('supplementary_exam_period_id'),fn($q,$v)=>$q->where('supplementary_exam_period_id',$v))->get();
  $sourceOfferingIds=$offerings->flatMap(fn($offering)=>$offering->sources->pluck('course_offering_id'))->map(fn($id)=>(int)$id)->unique()->values();
  $registrations=$sourceOfferingIds->isEmpty()?collect():StudentCourseRegistration::query()->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus','courseOffering.semester','courseOffering.gradeApprovals.approvalStatus'])->where('student_id',$u->student_id)->whereIn('course_offering_id',$sourceOfferingIds)->get();
  $context=$this->eligibility->evaluationContext($offerings,$registrations);
  $rows=[];$counts=[];
  if(SupplementaryExamRegistrationGovernance::schemaReady()){
   $periodByOffering=$offerings->pluck('supplementary_exam_period_id','supplementary_exam_offering_id');
   $counts=SupplementaryExamRegistration::query()->where('student_id',$u->student_id)->where('status','registered')->where('current_slot',1)->whereIn('supplementary_exam_offering_id',$offerings->pluck('supplementary_exam_offering_id'))->get()->groupBy(fn($registration)=>$periodByOffering->get($registration->supplementary_exam_offering_id))->map->count()->all();
  }
  foreach($offerings as $o){
   $candidates=$registrations->whereIn('course_offering_id',$o->sources->pluck('course_offering_id'));
   foreach($candidates as $r){
    $limit=SupplementaryExamPolicy::maxCoursesPerStudent($o->period);$count=(int)($counts[$o->supplementary_exam_period_id]??0);
    $rows[]=['course'=>$o->course,'period'=>$o->period,'supplementary_offering'=>$o,'original_registration'=>$r,'eligibility'=>$this->eligibility->evaluate($o,$r,$context),'registration_meta'=>['registration_window_open'=>$o->period?->status==='registration_open','registration_window_closed'=>SupplementaryExamRegistrationGovernance::isRosterFixed((string)$o->period?->status),'course_limit'=>$limit,'current_registered_count'=>$count,'remaining_slots'=>$limit===null?null:max(0,$limit-$count)]];
   }
  }
  return response()->json(['data'=>$rows]);
 }
 public function deferrals(Request $request){$u=$this->student($request);$this->eligibility->assertSchemaReady();return response()->json(['data'=>SupplementaryExamTheoreticalDeferral::query()->with(['offering.period','registration.courseOffering.course'])->whereHas('registration',fn($q)=>$q->where('student_id',$u->student_id))->latest('declared_at')->get()]);}
 public function declare(Request $request){$u=$this->student($request);$data=$request->validate(['supplementary_exam_offering_id'=>'required|integer','student_course_registration_id'=>'required|integer']);return response()->json(['data'=>$this->eligibility->declare($u,(int)$data['supplementary_exam_offering_id'],(int)$data['student_course_registration_id'])],201);}
 public function cancel(Request $request,int|string $deferral){$u=$this->student($request);$data=$request->validate(['reason'=>'nullable|string|max:1000']);$this->eligibility->assertSchemaReady();$record=SupplementaryExamTheoreticalDeferral::query()->findOrFail((int)$deferral);return response()->json(['data'=>$this->eligibility->cancel($u,$record,$data['reason']??null)]);}
}
