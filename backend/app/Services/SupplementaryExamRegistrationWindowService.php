<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\StudentCourseRegistration;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamPeriodEvent;
use App\Models\SupplementaryExamRegistration;
use App\Models\User;
use App\Support\SupplementaryExamPolicy;
use App\Support\SupplementaryExamRegistrationGovernance;
use Illuminate\Support\Facades\DB;

class SupplementaryExamRegistrationWindowService
{
    public function __construct(private readonly SupplementaryExamEligibilityService $eligibility,private readonly DataScopeService $scope){}
    public function open(User $actor,int $id): SupplementaryExamPeriod
    {
        $this->authorize($actor);$this->ready();$out=DB::transaction(function()use($actor,$id){$p=SupplementaryExamPeriod::query()->lockForUpdate()->findOrFail($id);if($p->isLegacy())return ['e'=>['الدورة قديمة.','supplementary_exam_registration_locked',409]];if($p->status==='registration_open')return ['e'=>['التسجيل مفتوح مسبقاً.','supplementary_exam_registration_already_open',409]];if($p->status==='registration_closed')return ['e'=>['التسجيل مغلق نهائياً.','supplementary_exam_registration_already_closed',409]];if($p->status!=='announced')return ['e'=>['حالة الدورة لا تسمح بفتح التسجيل.','supplementary_exam_registration_locked',409]];$offerings=$p->supplementaryExamOfferings()->where('status','open')->lockForUpdate()->get();if($offerings->isEmpty())return ['e'=>['لا توجد مقررات مفتوحة.','supplementary_exam_registration_locked',409]];if($offerings->contains(fn($o)=>!$this->scope->canAccessProgram($actor,(int)$o->academic_program_id)))return ['e'=>['خارج نطاق الصلاحية.','supplementary_exam_registration_out_of_scope',403]];$p->forceFill(['status'=>'registration_open'])->save();$this->periodEvent($p,$actor,'registration_opened','announced','registration_open');return ['p'=>$p->fresh()];},3);if(isset($out['e']))$this->fail(...$out['e']);return $out['p'];
    }
    public function close(User $actor,int $id): SupplementaryExamPeriod
    {
        $this->authorize($actor);$this->ready();$out=DB::transaction(function()use($actor,$id){$p=SupplementaryExamPeriod::query()->with('semester')->lockForUpdate()->findOrFail($id);if($p->status==='registration_closed')return ['e'=>['التسجيل مغلق مسبقاً.','supplementary_exam_registration_already_closed',409]];if($p->status!=='registration_open')return ['e'=>['نافذة التسجيل غير مفتوحة.','supplementary_exam_registration_window_not_open',409]];$rows=SupplementaryExamRegistration::query()->with('offering')->where('status','registered')->where('current_slot',1)->whereHas('offering',fn($q)=>$q->where('supplementary_exam_period_id',$id))->orderBy('supplementary_exam_registration_id')->lockForUpdate()->get();$invalid=[];$counts=[];foreach($rows as $r){$original=StudentCourseRegistration::query()->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus'])->lockForUpdate()->find($r->student_course_registration_id);if(!$original||(int)$original->student_id!==(int)$r->student_id||!$this->scope->canAccessProgram($actor,(int)$r->offering->academic_program_id)||!$this->eligibility->evaluate($r->offering,$original)['eligible'])$invalid[]=$r->getKey();$counts[$r->student_id]=($counts[$r->student_id]??0)+1;}$limit=SupplementaryExamPolicy::maxCoursesPerStudent($p);if($limit!==null)foreach($counts as $student=>$count)if($count>$limit)$invalid[]='student:'.$student;if($invalid)return ['e'=>['تتضمن القائمة تسجيلات غير صالحة: '.implode(',',array_slice($invalid,0,20)),'supplementary_exam_registration_list_has_invalid_entries',409]];$p->forceFill(['status'=>'registration_closed'])->save();$this->periodEvent($p,$actor,'registration_closed','registration_open','registration_closed');return ['p'=>$p->fresh()];},3);if(isset($out['e']))$this->fail(...$out['e']);return $out['p'];
    }
    private function authorize(User $u):void{if(!$u->isRegistrationOfficer()||!$u->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::WINDOW))$this->fail('يتطلب موظف تسجيل فعلي وصلاحية النافذة.','supplementary_exam_registration_out_of_scope',403);}
    private function ready():void{if(!SupplementaryExamRegistrationGovernance::schemaReady())$this->fail('المخطط غير جاهز.','supplementary_exam_registration_schema_not_ready',503);}
    private function periodEvent($p,$u,$type,$from,$to):void{SupplementaryExamPeriodEvent::query()->create(['supplementary_exam_period_id'=>$p->getKey(),'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'actor_user_id'=>$u->user_id,'created_at'=>now()]);}
    private function fail(string $m,string $c,int $s):never{throw new GradeException($m,status:$s,errorCode:$c);}
}
