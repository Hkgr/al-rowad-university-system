<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamOfferingSource;
use App\Models\SupplementaryExamRegistration;
use App\Models\SupplementaryExamRegistrationEvent;
use App\Models\User;
use App\Support\SupplementaryExamPolicy;
use App\Support\SupplementaryExamRegistrationGovernance;
use Illuminate\Support\Facades\DB;

class SupplementaryExamRegistrationService
{
    public function __construct(private readonly SupplementaryExamEligibilityService $eligibility, private readonly DataScopeService $scope) {}

    public function registerSelf(User $actor,int $offeringId,int $registrationId): SupplementaryExamRegistration
    {
        if(!$actor->isStudent()||!$actor->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::SELF))$this->fail('غير مصرح بالتسجيل.','supplementary_exam_registration_not_owned',403);
        return $this->register($actor,$offeringId,$registrationId,(int)$actor->student_id,'student_self');
    }
    public function registerForStudent(User $actor,int $offeringId,int $registrationId): SupplementaryExamRegistration
    {
        $this->staff($actor,SupplementaryExamRegistrationGovernance::MANAGE);
        $original=StudentCourseRegistration::query()->findOrFail($registrationId);
        return $this->register($actor,$offeringId,$registrationId,(int)$original->student_id,'student_affairs');
    }
    private function register(User $actor,int $offeringId,int $registrationId,int $studentId,string $channel): SupplementaryExamRegistration
    {
        $this->ready();
        $periodId=(int)SupplementaryExamOffering::query()->whereKey($offeringId)->value('supplementary_exam_period_id');
        $out=DB::transaction(function()use($actor,$offeringId,$registrationId,$studentId,$channel,$periodId){
            $student=Student::query()->lockForUpdate()->findOrFail($studentId);
            $period=\App\Models\SupplementaryExamPeriod::query()->with('semester')->lockForUpdate()->findOrFail($periodId);
            $offering=SupplementaryExamOffering::query()->lockForUpdate()->findOrFail($offeringId);
            if($period->status!=='registration_open')return ['error'=>['نافذة التسجيل غير مفتوحة.','supplementary_exam_registration_window_not_open',409]];
            if(!$offering->isOpen())return ['error'=>['المقرر التكميلي مغلق.','supplementary_exam_registration_locked',409]];
            $original=StudentCourseRegistration::query()->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus'])->lockForUpdate()->findOrFail($registrationId);
            if((int)$original->student_id!==$studentId)return ['error'=>['المحاولة لا تخص الطالب.','supplementary_exam_registration_wrong_student',409]];
            if($channel==='student_affairs'&&(!$this->scope->canAccessStudent($actor,$student)||!$this->scope->canAccessProgram($actor,(int)$offering->academic_program_id)))return ['error'=>['خارج نطاق الصلاحية.','supplementary_exam_registration_out_of_scope',403]];
            if(!SupplementaryExamOfferingSource::query()->where('supplementary_exam_offering_id',$offeringId)->where('course_offering_id',$original->course_offering_id)->exists())return ['error'=>['المحاولة الأصلية ليست مصدراً معتمداً.','supplementary_exam_registration_source_invalid',409]];
            SupplementaryExamRegistration::query()->where('student_id',$studentId)->where('current_slot',1)->lockForUpdate()->get();
            $current=SupplementaryExamRegistration::query()->where('supplementary_exam_offering_id',$offeringId)->where('student_id',$studentId)->where('current_slot',1)->lockForUpdate()->first();
            if($current)return ['error'=>['الطالب مسجل مسبقاً.','supplementary_exam_already_registered',409]];
            $evaluation=$this->eligibility->evaluate($offering,$original);if(!$evaluation['eligible'])return ['error'=>['الطالب غير مؤهل أكاديمياً.','supplementary_exam_registration_not_eligible',409]];
            $limit=SupplementaryExamPolicy::maxCoursesPerStudent($period);
            if($limit!==null){$count=SupplementaryExamRegistration::query()->where('student_id',$studentId)->where('status','registered')->where('current_slot',1)->whereHas('offering',fn($q)=>$q->where('supplementary_exam_period_id',$periodId))->count();if($count>=$limit)return ['error'=>['تم بلوغ الحد الأعلى للدورة الصيفية.','supplementary_exam_summer_limit_reached',409]];}
            $row=SupplementaryExamRegistration::query()->where('supplementary_exam_offering_id',$offeringId)->where('student_course_registration_id',$registrationId)->lockForUpdate()->first();$type=$row?'reregistered':'registered';$from=$row?->status;
            $values=['student_id'=>$studentId,'status'=>'registered','current_slot'=>1,'eligibility_reason'=>$evaluation['eligibility_reason'],'registration_channel'=>$channel,'registered_by_user_id'=>$actor->user_id,'registered_at'=>now(),'cancelled_by_user_id'=>null,'cancelled_at'=>null,'cancellation_reason'=>null,'eligibility_checked_at'=>now()];
            $row?$row->update($values):$row=SupplementaryExamRegistration::query()->create($values+['supplementary_exam_offering_id'=>$offeringId,'student_course_registration_id'=>$registrationId]);
            $this->event($row,$type,$from,'registered',$actor,null);return ['row'=>$row->fresh()];
        },3);if(isset($out['error']))$this->fail(...$out['error']);return $out['row'];
    }
    public function cancelSelf(User $actor,int $id,?string $reason): SupplementaryExamRegistration { if(!$actor->isStudent()||!$actor->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::SELF))$this->fail('غير مصرح.','supplementary_exam_registration_not_owned',403);return $this->cancel($actor,$id,$reason,false); }
    public function cancelForStudent(User $actor,int $id,string $reason): SupplementaryExamRegistration { $this->staff($actor,SupplementaryExamRegistrationGovernance::MANAGE);return $this->cancel($actor,$id,$reason,true); }
    private function cancel(User $actor,int $id,?string $reason,bool $staff): SupplementaryExamRegistration
    {
        $this->ready();$seed=SupplementaryExamRegistration::query()->with('offering')->findOrFail($id);$out=DB::transaction(function()use($actor,$id,$reason,$staff,$seed){
            $student=Student::query()->lockForUpdate()->findOrFail($seed->student_id);$period=\App\Models\SupplementaryExamPeriod::query()->lockForUpdate()->findOrFail($seed->offering->supplementary_exam_period_id);SupplementaryExamOffering::query()->lockForUpdate()->findOrFail($seed->supplementary_exam_offering_id);StudentCourseRegistration::query()->lockForUpdate()->findOrFail($seed->student_course_registration_id);$row=SupplementaryExamRegistration::query()->lockForUpdate()->findOrFail($id);
            if($period->status!=='registration_open')return ['error'=>['القائمة مثبتة ولا يمكن تعديلها.','supplementary_exam_registration_locked',409]];
            if($row->status!=='registered'||$row->current_slot!=1)return ['error'=>['لا يوجد تسجيل نشط.','supplementary_exam_not_registered',409]];
            if(!$staff&&(int)$row->student_id!==(int)$actor->student_id)return ['error'=>['التسجيل لا يخص الطالب.','supplementary_exam_registration_not_owned',403]];
            if($staff&&(!$this->scope->canAccessStudent($actor,$student)||!$this->scope->canAccessProgram($actor,(int)$seed->offering->academic_program_id)))return ['error'=>['خارج النطاق.','supplementary_exam_registration_out_of_scope',403]];
            $row->update(['status'=>'cancelled','current_slot'=>null,'cancelled_by_user_id'=>$actor->user_id,'cancelled_at'=>now(),'cancellation_reason'=>$reason]);$this->event($row,'cancelled','registered','cancelled',$actor,$reason);return ['row'=>$row->fresh()];
        },3);if(isset($out['error']))$this->fail(...$out['error']);return $out['row'];
    }
    private function staff(User $u,string $permission): void { if(!$u->isRegistrationOfficer()||!$u->effectivePermissions()->contains($permission))$this->fail('يتطلب موظف تسجيل فعلي وصلاحية مسندة.','supplementary_exam_registration_out_of_scope',403); }
    public function ready(): void { if(!SupplementaryExamRegistrationGovernance::schemaReady())$this->fail('مخطط التسجيل التكميلي غير جاهز.','supplementary_exam_registration_schema_not_ready',503); }
    private function event($r,$type,$from,$to,$actor,$notes):void{SupplementaryExamRegistrationEvent::query()->create(['supplementary_exam_registration_id'=>$r->getKey(),'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'actor_user_id'=>$actor->user_id,'notes'=>$notes,'created_at'=>now()]);}
    private function fail(string $message,string $code,int $status):never{throw new GradeException($message,status:$status,errorCode:$code);}
}
