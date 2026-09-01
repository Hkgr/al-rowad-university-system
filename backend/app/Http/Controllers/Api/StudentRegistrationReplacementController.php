<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentRegistrationReplacementItem;
use App\Services\RegistrationReplacementService;
use App\Support\SemesterRegistrationPhase6;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class StudentRegistrationReplacementController extends Controller
{
    public function __construct(private RegistrationReplacementService $service) {}
    public function store(Request $r):JsonResponse{$v=$this->term($r);return $this->ok($this->service->create($this->student($r),$r->user(),$v['academic_year_id'],$v['semester_id']));}
    public function update(Request $r):JsonResponse{$v=$r->validate(['academic_year_id'=>['required','integer','min:1'],'semester_id'=>['required','integer','min:1'],'student_notes'=>['nullable','string','max:1000']]);return $this->ok($this->service->updateNotes($this->student($r),$r->user(),$v['student_notes']??null,$v['academic_year_id'],$v['semester_id']));}
    public function addItem(Request $r):JsonResponse{$v=$r->validate(['academic_year_id'=>['required','integer','min:1'],'semester_id'=>['required','integer','min:1'],'source_student_course_registration_id'=>['required','integer','min:1'],'replacement_course_offering_id'=>['required','integer','min:1']]);return $this->ok($this->service->addItem($this->student($r),$r->user(),$v['academic_year_id'],$v['semester_id'],$v['source_student_course_registration_id'],$v['replacement_course_offering_id']));}
    public function updateItem(Request $r,int $replacementItem):JsonResponse{$v=$r->validate(['replacement_course_offering_id'=>['required','integer','min:1']]);return $this->ok($this->service->updateItem($this->student($r),$r->user(),$this->item($replacementItem),$v['replacement_course_offering_id']));}
    public function removeItem(Request $r,int $replacementItem):JsonResponse{return $this->ok($this->service->removeItem($this->student($r),$r->user(),$this->item($replacementItem)));}
    public function submit(Request $r):JsonResponse{$v=$this->term($r);return $this->ok($this->service->submit($this->student($r),$r->user(),$v['academic_year_id'],$v['semester_id']));}
    private function item(int $id):StudentRegistrationReplacementItem{$this->assertReady();return StudentRegistrationReplacementItem::query()->findOrFail($id);}
    private function student(Request $r):Student{$id=$r->user()?->student_id;if(!$id||!($student=Student::query()->find($id)))throw new AccessDeniedHttpException('Student identity required.');return $student;}
    private function term(Request $r):array{return $r->validate(['academic_year_id'=>['required','integer','min:1'],'semester_id'=>['required','integer','min:1']]);}
    private function assertReady():void{if(!SemesterRegistrationPhase6::schemaReady())throw SemesterRegistrationPhase6Exception::replacementSchema();}
    private function ok($data):JsonResponse{return response()->json(['success'=>true,'data'=>$data]);}
}
