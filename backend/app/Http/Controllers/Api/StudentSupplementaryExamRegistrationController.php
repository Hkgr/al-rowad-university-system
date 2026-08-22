<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\SupplementaryExamRegistration;
use App\Services\SupplementaryExamRegistrationService;
use Illuminate\Http\Request;
class StudentSupplementaryExamRegistrationController extends Controller
{
 public function __construct(private readonly SupplementaryExamRegistrationService $service){}
 public function index(Request $r){$this->service->ready();$u=$r->user();abort_unless($u?->isStudent(),403);return response()->json(['data'=>SupplementaryExamRegistration::query()->with(['offering.period','offering.course','originalRegistration.courseOffering.semester'])->where('student_id',$u->student_id)->latest('registered_at')->get()]);}
 public function store(Request $r){$d=$r->validate(['supplementary_exam_offering_id'=>'required|integer','student_course_registration_id'=>'required|integer']);return response()->json(['data'=>$this->service->registerSelf($r->user(),(int)$d['supplementary_exam_offering_id'],(int)$d['student_course_registration_id'])],201);}
 public function cancel(Request $r,int|string $registration){$d=$r->validate(['reason'=>'nullable|string|max:2000']);return response()->json(['data'=>$this->service->cancelSelf($r->user(),(int)$registration,$d['reason']??null)]);}
}
