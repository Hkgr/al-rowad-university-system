<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\SupplementaryExamRegistrationService;
use Illuminate\Http\Request;
class StudentSupplementaryExamRegistrationController extends Controller
{
 public function __construct(private readonly SupplementaryExamRegistrationService $service){}
 public function index(Request $r){return response()->json(['data'=>$this->service->registrationsForStudent($r->user())]);}
 public function store(Request $r){$d=$r->validate(['supplementary_exam_offering_id'=>'required|integer','student_course_registration_id'=>'required|integer']);return response()->json(['data'=>$this->service->registerSelf($r->user(),(int)$d['supplementary_exam_offering_id'],(int)$d['student_course_registration_id'])],201);}
 public function cancel(Request $r,int|string $registration){$d=$r->validate(['reason'=>'nullable|string|max:2000']);return response()->json(['data'=>$this->service->cancelSelf($r->user(),(int)$registration,$d['reason']??null)]);}
}
