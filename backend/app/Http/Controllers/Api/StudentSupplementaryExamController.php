<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamTheoreticalDeferral;
use App\Services\SupplementaryExamEligibilityService;
use App\Support\SupplementaryExamEligibilityGovernance;
use Illuminate\Http\Request;
class StudentSupplementaryExamController extends Controller
{
 public function __construct(private readonly SupplementaryExamEligibilityService $eligibility){}
 private function student(Request $r){$u=$r->user();abort_unless($u?->isStudent()&&$u->effectivePermissions()->contains(SupplementaryExamEligibilityGovernance::PERMISSION_SELF),403);return $u;}
 public function eligibility(Request $request){$u=$this->student($request);$offerings=SupplementaryExamOffering::query()->with(['period','course','sources'])->where('status','open')->when($request->integer('supplementary_exam_period_id'),fn($q,$v)=>$q->where('supplementary_exam_period_id',$v))->get();$rows=[];foreach($offerings as $o){$ids=$o->sources->pluck('course_offering_id');$registrations=StudentCourseRegistration::query()->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus','courseOffering.semester'])->where('student_id',$u->student_id)->whereIn('course_offering_id',$ids)->get();foreach($registrations as $r)$rows[]=['course'=>$o->course,'period'=>$o->period,'supplementary_offering'=>$o,'original_registration'=>$r,'eligibility'=>$this->eligibility->evaluate($o,$r)];}return response()->json(['data'=>$rows]);}
 public function deferrals(Request $request){$u=$this->student($request);return response()->json(['data'=>SupplementaryExamTheoreticalDeferral::query()->with(['offering.period','registration.courseOffering.course'])->whereHas('registration',fn($q)=>$q->where('student_id',$u->student_id))->latest('declared_at')->get()]);}
 public function declare(Request $request){$u=$this->student($request);$data=$request->validate(['supplementary_exam_offering_id'=>'required|integer','student_course_registration_id'=>'required|integer']);return response()->json(['data'=>$this->eligibility->declare($u,(int)$data['supplementary_exam_offering_id'],(int)$data['student_course_registration_id'])],201);}
 public function cancel(Request $request,SupplementaryExamTheoreticalDeferral $deferral){$u=$this->student($request);$data=$request->validate(['reason'=>'nullable|string|max:1000']);return response()->json(['data'=>$this->eligibility->cancel($u,$deferral,$data['reason']??null)]);}
}
