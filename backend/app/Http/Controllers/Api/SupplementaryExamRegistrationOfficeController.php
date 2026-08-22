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
 public function index(Request $r,int|string $period){$this->service->ready();$u=$r->user();abort_unless($u->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::VIEW)||$u->hasRoleCode('super_admin'),403);$periodRecord=SupplementaryExamPeriod::query()->findOrFail((int)$period);$q=SupplementaryExamRegistration::query()->with(['student','offering.course','offering.academicProgram','originalRegistration.courseOffering.semester'])->where('status','registered')->where('current_slot',1)->whereHas('offering',fn($x)=>$x->where('supplementary_exam_period_id',$periodRecord->getKey()));$rows=$q->get()->filter(fn($x)=>$this->scope->canAccessStudent($u,$x->student)&&$this->scope->canAccessProgram($u,(int)$x->offering->academic_program_id))->values();$status=(string)$periodRecord->status;return response()->json(['period_status'=>$status,'list_status'=>$status==='registration_closed'?'fixed':'draft','data'=>$rows]);}
}
