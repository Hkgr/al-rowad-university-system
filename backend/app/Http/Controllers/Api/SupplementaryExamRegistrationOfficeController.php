<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\SupplementaryExamRegistration;
use App\Models\SupplementaryExamPeriod;
use App\Services\DataScopeService;
use App\Services\SupplementaryExamRegistrationService;
use App\Services\SupplementaryExamRegistrationWindowService;
use App\Support\SupplementaryExamRegistrationGovernance;
use Illuminate\Http\Request;
class SupplementaryExamRegistrationOfficeController extends Controller
{
 public function __construct(private readonly SupplementaryExamRegistrationService $service,private readonly SupplementaryExamRegistrationWindowService $window,private readonly DataScopeService $scope){}
 public function open(Request $r,int|string $period){return response()->json(['data'=>$this->window->open($r->user(),(int)$period)]);}
 public function close(Request $r,int|string $period){return response()->json(['data'=>$this->window->close($r->user(),(int)$period)]);}
 public function store(Request $r){$d=$r->validate(['supplementary_exam_offering_id'=>'required|integer','student_course_registration_id'=>'required|integer']);return response()->json(['data'=>$this->service->registerForStudent($r->user(),(int)$d['supplementary_exam_offering_id'],(int)$d['student_course_registration_id'])],201);}
 public function cancel(Request $r,int|string $registration){$d=$r->validate(['reason'=>'required|string|max:2000']);return response()->json(['data'=>$this->service->cancelForStudent($r->user(),(int)$registration,$d['reason'])]);}
 public function periods(Request $r){$this->service->ready();$u=$r->user();abort_unless($u->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::VIEW)||$u->hasRoleCode('super_admin'),403);$university=$this->scope->hasActualUniversityScope($u);$periods=SupplementaryExamPeriod::query()->with(['academicYear','semester','supplementaryExamOfferings'])->whereNotIn('status',['legacy'])->orderByDesc('supplementary_exam_period_id')->get()->filter(fn($p)=>$university||$p->supplementaryExamOfferings->contains(fn($o)=>$this->scope->canMutateProgram($u,(int)$o->academic_program_id)))->map(fn($p)=>['supplementary_exam_period_id'=>(int)$p->getKey(),'period_name'=>$p->period_name,'academic_year'=>$p->academicYear,'semester'=>$p->semester,'status'=>(string)$p->status,'registration_window_open'=>$p->status==='registration_open','registration_window_closed'=>$p->status==='registration_closed'])->values();return response()->json(['data'=>$periods]);}
 public function index(Request $r,int|string $period){$this->service->ready();$u=$r->user();abort_unless($u->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::VIEW)||$u->hasRoleCode('super_admin'),403);$periodRecord=SupplementaryExamPeriod::query()->findOrFail((int)$period);$q=SupplementaryExamRegistration::query()->with(['student','offering.course','offering.academicProgram','originalRegistration.courseOffering.semester'])->where('status','registered')->where('current_slot',1)->whereHas('offering',fn($x)=>$x->where('supplementary_exam_period_id',$periodRecord->getKey()));$rows=$q->get()->filter(fn($x)=>$this->scope->canAccessStudent($u,$x->student)&&$this->scope->canAccessProgram($u,(int)$x->offering->academic_program_id))->values();$status=(string)$periodRecord->status;return response()->json(['period_status'=>$status,'list_status'=>$status==='registration_closed'?'fixed':'draft','data'=>$rows]);}
}
