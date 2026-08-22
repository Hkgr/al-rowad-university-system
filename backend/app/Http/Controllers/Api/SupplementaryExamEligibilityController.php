<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamOffering;
use App\Services\DataScopeService;
use App\Services\SupplementaryExamEligibilityService;
use App\Support\SupplementaryExamEligibilityGovernance;
use Illuminate\Http\Request;
class SupplementaryExamEligibilityController extends Controller
{
 public function __construct(private readonly SupplementaryExamEligibilityService $eligibility,private readonly DataScopeService $scope){}
 public function index(Request $request){$u=$request->user();abort_unless($u?->hasPermission(SupplementaryExamEligibilityGovernance::PERMISSION_VIEW),403);$offerings=SupplementaryExamOffering::query()->with(['period','sources','course'])->when($request->integer('supplementary_exam_period_id'),fn($q,$v)=>$q->where('supplementary_exam_period_id',$v))->when($request->integer('supplementary_exam_offering_id'),fn($q,$v)=>$q->whereKey($v))->get();$out=[];foreach($offerings as $o){$q=$this->scope->scopeResourceQuery(StudentCourseRegistration::query(),$u)->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus','courseOffering'])->whereIn('course_offering_id',$o->sources->pluck('course_offering_id'))->when($request->integer('student_id'),fn($q,$v)=>$q->where('student_id',$v));foreach($q->get() as $r){$e=$this->eligibility->evaluate($o,$r);if($request->filled('eligible')&&$e['eligible']!==$request->boolean('eligible'))continue;if($request->filled('eligibility_reason')&&$e['eligibility_reason']!==$request->string('eligibility_reason')->toString())continue;$out[]=$e+['supplementary_exam_offering_id'=>(int)$o->getKey(),'course_name'=>$o->course?->course_name];}}return response()->json(['data'=>$out]);}
}
