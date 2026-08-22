<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\GradeComponent;
use App\Models\GradePartApproval;
use App\Models\StudentCourseRegistration;
use App\Models\StudentGradeComponent;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamOfferingSource;
use App\Models\SupplementaryExamTheoreticalDeferral;
use App\Models\SupplementaryExamTheoreticalDeferralEvent;
use App\Models\User;
use App\Support\SupplementaryExamEligibilityGovernance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplementaryExamEligibilityService
{
    public function __construct(private readonly GradeService $grades) {}

    public function evaluate(SupplementaryExamOffering $offering, StudentCourseRegistration $registration): array
    {
        $registration->loadMissing(['registrationStatus','resultStatus','studentCourseResult.resultStatus','courseOffering']);
        $offering->loadMissing('period');
        $parts = $this->parts($registration);
        $practicalRequired = $parts->contains('component_type', 'practical');
        $theoreticalRequired = $parts->contains('component_type', 'theoretical');
        $practicalMark = $this->partMark($registration, $parts, 'practical');
        $theoreticalMark = $this->partMark($registration, $parts, 'theoretical');
        $minimum = (float) $this->grades->defaultGradingPolicy()->minimum_practical_mark;
        $status = $this->grades->officialAttemptResultStatus($registration);
        $blockers = [];
        if (! SupplementaryExamEligibilityGovernance::schemaReady()) $blockers[] = 'phase3_schema_not_ready';
        if (! $offering->isOpen()) $blockers[] = 'supplementary_offering_not_open';
        if (! in_array($offering->period?->status, ['announced', 'registration_open', 'registration_closed'], true)) $blockers[] = 'supplementary_period_not_available';
        if (! in_array($registration->registrationStatus?->status_code, StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES, true)) $blockers[] = 'registration_not_academic_attempt';
        if (! $this->sourceAllowed($offering, $registration)) $blockers[] = 'source_not_allowed';
        if (! $theoreticalRequired) $blockers[] = 'theoretical_not_required';
        if ($this->deprived($registration)) $blockers[] = 'student_deprived';
        if ($practicalRequired && $practicalMark === null) $blockers[] = 'practical_mark_missing';
        if ($practicalRequired && $practicalMark !== null && $practicalMark < $minimum) $blockers[] = 'practical_failed';
        $deferral = $this->activeValidDeferral($registration);
        $reason = $deferral && (int) $deferral->supplementary_exam_offering_id === (int) $offering->getKey() ? 'voluntarily_deferred_theoretical' : null;
        if ($reason === null) {
            if (! $this->grades->isOfficiallyVisibleAttempt($registration)) $blockers[] = 'regular_result_not_official';
            elseif ($status === 'passed') $blockers[] = 'regular_result_passed';
            elseif ($status !== 'failed') $blockers[] = 'regular_result_not_failed';
            else $reason = 'failed_theoretical';
        }
        $eligible = $reason !== null && $blockers === [];
        if (! $eligible && $blockers === []) $blockers[] = 'no_eligibility_reason';
        return ['eligible'=>$eligible,'eligibility_reason'=>$eligible?$reason:null,'blockers'=>array_values(array_unique($blockers)),
            'original_registration_id'=>(int)$registration->getKey(),'source_course_offering_id'=>(int)$registration->course_offering_id,
            'practical_required'=>$practicalRequired,'practical_mark'=>$practicalMark,'practical_minimum'=>$minimum,
            'practical_passed'=>!$practicalRequired||($practicalMark!==null&&$practicalMark>=$minimum),'theoretical_required'=>$theoreticalRequired,
            'regular_result_status'=>$status,'regular_theoretical_mark'=>$theoreticalMark,
            'regular_final_mark'=>$registration->studentCourseResult?->final_mark===null?null:(float)$registration->studentCourseResult->final_mark,
            'active_deferral_id'=>$deferral?->getKey()];
    }

    /** @return array{valid: bool, reason: string|null, deferral: SupplementaryExamTheoreticalDeferral|null} */
    public function evaluateCurrentDeferralValidity(StudentCourseRegistration $registration, ?SupplementaryExamTheoreticalDeferral $row = null): array
    {
        if (! SupplementaryExamEligibilityGovernance::schemaReady()) return ['valid'=>false,'reason'=>'phase3_schema_not_ready','deferral'=>$row];
        $row ??= SupplementaryExamTheoreticalDeferral::query()->with('offering.period')->where('student_course_registration_id',$registration->getKey())->where('status','declared')->where('current_slot',1)->first();
        if (!$row) return ['valid'=>false,'reason'=>null,'deferral'=>null];
        $row->loadMissing('offering.period'); $registration->loadMissing(['registrationStatus','resultStatus','studentCourseResult.resultStatus']); $parts=$this->parts($registration);
        $reason = null;
        if (!$row->offering?->isOpen()) $reason='offering_closed';
        elseif (!in_array($row->offering?->period?->status,['announced','registration_open','registration_closed'],true)) $reason='period_not_available';
        elseif (!$this->sourceAllowed($row->offering,$registration)) $reason='source_not_allowed';
        elseif (!in_array($registration->registrationStatus?->status_code,StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES,true)) $reason='registration_not_academic_attempt';
        elseif ($this->deprived($registration)) $reason='student_deprived';
        elseif (!$parts->contains('component_type','theoretical')) $reason='theoretical_not_required';
        elseif ($this->hasAnyPartMark($registration,$parts,'theoretical')) $reason='theoretical_already_graded';
        else { $approval=GradePartApproval::query()->where('course_offering_id',$registration->course_offering_id)->where('component_type','theoretical')->first(); if($approval&&!in_array($approval->status,['draft','returned'],true)&&$approval->submitted_at?->lte($row->declared_at))$reason='theoretical_part_locked'; }
        if ($reason===null && $parts->contains('component_type','practical')) { $mark=$this->partMark($registration,$parts,'practical'); if($mark===null)$reason='practical_mark_missing'; elseif($mark<(float)$this->grades->defaultGradingPolicy()->minimum_practical_mark)$reason='practical_failed'; }
        return ['valid'=>$reason===null,'reason'=>$reason,'deferral'=>$row];
    }

    public function activeValidDeferral(StudentCourseRegistration $registration): ?SupplementaryExamTheoreticalDeferral
    {
        $state=$this->evaluateCurrentDeferralValidity($registration); return $state['valid'] ? $state['deferral'] : null;
    }

    public function resolveInvalidCurrentDeferral(StudentCourseRegistration $registration, int $actorUserId): ?SupplementaryExamTheoreticalDeferral
    {
        if (! SupplementaryExamEligibilityGovernance::schemaReady()) return null;
        $row=SupplementaryExamTheoreticalDeferral::query()->with('offering.period')->where('student_course_registration_id',$registration->getKey())->where('status','declared')->where('current_slot',1)->lockForUpdate()->first();
        if(!$row)return null;$state=$this->evaluateCurrentDeferralValidity($registration,$row);if($state['valid'])return $row;
        $row->update(['status'=>'superseded','current_slot'=>null,'superseded_at'=>now(),'supersede_reason'=>$state['reason']]);$this->event($row,'superseded','declared','superseded',$actorUserId,$state['reason']);return null;
    }

    public function declare(User $user,int $offeringId,int $registrationId): SupplementaryExamTheoreticalDeferral
    {
        $this->assertStudent($user);$this->assertSchemaReady();
        $outcome=DB::transaction(function()use($user,$offeringId,$registrationId){
            $r=StudentCourseRegistration::query()->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus','courseOffering'])->lockForUpdate()->findOrFail($registrationId);
            if((int)$r->student_id!==(int)$user->student_id)return ['error'=>['The registration is not owned by this student.','deferral_not_owned',403]];
            CourseOfferingLock::lock((int)$r->course_offering_id);$theoryApproval=GradePartApproval::query()->where('course_offering_id',$r->course_offering_id)->where('component_type','theoretical')->lockForUpdate()->first();
            $parts=$this->parts($r,true);StudentGradeComponent::query()->where('student_course_registration_id',$registrationId)->lockForUpdate()->get();$offering=SupplementaryExamOffering::query()->with('period')->lockForUpdate()->findOrFail($offeringId);
            $current=SupplementaryExamTheoreticalDeferral::query()->with('offering.period')->where('student_course_registration_id',$registrationId)->where('status','declared')->where('current_slot',1)->lockForUpdate()->first();
            if($current){$state=$this->evaluateCurrentDeferralValidity($r,$current);if($state['valid'])return ['error'=>['A current deferral already exists.','deferral_already_current',409]];$current->update(['status'=>'superseded','current_slot'=>null,'superseded_at'=>now(),'supersede_reason'=>$state['reason']]);$this->event($current,'superseded','declared','superseded',(int)$user->user_id,$state['reason']);}
            $blockers=$this->declarationBlockers($offering,$r,$parts,$theoryApproval);if($blockers!==[])return ['error'=>['Theoretical deferral is not available.',$blockers[0],409]];
            $row=SupplementaryExamTheoreticalDeferral::query()->where('supplementary_exam_offering_id',$offeringId)->where('student_course_registration_id',$registrationId)->lockForUpdate()->first();$type=$row?'redeclaration':'declared';$from=$row?->status;$values=['status'=>'declared','current_slot'=>1,'declared_by_user_id'=>$user->user_id,'declared_at'=>now(),'cancelled_by_user_id'=>null,'cancelled_at'=>null,'cancellation_reason'=>null,'superseded_at'=>null,'supersede_reason'=>null];
            $row?$row->update($values):$row=SupplementaryExamTheoreticalDeferral::query()->create($values+['supplementary_exam_offering_id'=>$offeringId,'student_course_registration_id'=>$registrationId]);$this->event($row,$type,$from,'declared',(int)$user->user_id,null);return ['row'=>$row->fresh('offering.period')];
        },3);
        if(isset($outcome['error']))$this->fail(...$outcome['error']);return $outcome['row'];
    }

    public function cancel(User $user,SupplementaryExamTheoreticalDeferral $deferral,?string $reason): SupplementaryExamTheoreticalDeferral
    {
        $this->assertStudent($user);$this->assertSchemaReady();$outcome=DB::transaction(function()use($user,$deferral,$reason){$row=SupplementaryExamTheoreticalDeferral::query()->with('offering.period')->lockForUpdate()->findOrFail($deferral->getKey());
            $r=StudentCourseRegistration::query()->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus'])->lockForUpdate()->findOrFail($row->student_course_registration_id);if((int)$r->student_id!==(int)$user->student_id)return ['error'=>['Not owned.','deferral_not_owned',403]];
            CourseOfferingLock::lock((int)$r->course_offering_id);$state=$this->evaluateCurrentDeferralValidity($r,$row);if(!$state['valid']){$row->update(['status'=>'superseded','current_slot'=>null,'superseded_at'=>now(),'supersede_reason'=>$state['reason']]);$this->event($row,'superseded','declared','superseded',(int)$user->user_id,$state['reason']);return ['error'=>['The declaration became invalid.','deferral_cannot_cancel',409]];}
            if ($row->offering?->period?->status !== 'announced') return ['error'=>['The declaration is committed after registration opens.','deferral_cannot_cancel',409]];
            $approval=GradePartApproval::query()->where('course_offering_id',$r->course_offering_id)->where('component_type','theoretical')->lockForUpdate()->first();$hasMark=StudentGradeComponent::query()->where('student_course_registration_id',$r->getKey())->whereHas('gradeComponent',fn($q)=>$q->where('component_type','theoretical'))->whereNotNull('mark')->lockForUpdate()->exists();
            if($row->status!=='declared'||$row->current_slot!=1||$hasMark||($approval&&!in_array($approval->status,['draft','returned'],true)))return ['error'=>['Cannot cancel.','deferral_cannot_cancel',409]];
            $row->update(['status'=>'cancelled','current_slot'=>null,'cancelled_by_user_id'=>$user->user_id,'cancelled_at'=>now(),'cancellation_reason'=>$reason]);$this->event($row,'cancelled','declared','cancelled',(int)$user->user_id,$reason);return ['row'=>$row->fresh()];},3);
        if(isset($outcome['error']))$this->fail(...$outcome['error']);return $outcome['row'];
    }

    private function declarationBlockers($o,$r,$parts,$approval): array {$b=[];if(!$o->isOpen())$b[]='supplementary_offering_not_open';if($o->period?->status!=='announced')$b[]='supplementary_period_not_announced';if($r->registrationStatus?->status_code!=='registered')$b[]='registration_not_academic_attempt';if(!$this->sourceAllowed($o,$r))$b[]='source_not_allowed';if(!$parts->contains('component_type','theoretical'))$b[]='theoretical_not_required';if($this->deprived($r))$b[]='student_deprived';if($this->hasAnyPartMark($r,$parts,'theoretical'))$b[]='theoretical_already_graded';if($approval&&!in_array($approval->status,['draft','returned'],true))$b[]='theoretical_part_locked';if($r->studentCourseResult&&$this->grades->isOfficiallyVisibleAttempt($r))$b[]='deferral_too_late';if($parts->contains('component_type','practical')){$pa=GradePartApproval::query()->where('course_offering_id',$r->course_offering_id)->where('component_type','practical')->lockForUpdate()->first();if($pa?->status!=='approved')$b[]='practical_result_not_approved';$m=$this->partMark($r,$parts,'practical');if($m===null)$b[]='practical_mark_missing';elseif($m<(float)$this->grades->defaultGradingPolicy()->minimum_practical_mark)$b[]='practical_failed';}return array_values(array_unique($b));}
    private function parts($r,bool $lock=false){$q=GradeComponent::query()->where('course_offering_id',$r->course_offering_id)->where('is_required',true)->whereIn('component_type',['practical','theoretical']);return ($lock?$q->lockForUpdate():$q)->get();}
    private function hasAnyPartMark(StudentCourseRegistration $registration, Collection $requiredParts, string $part): bool
    {
        $componentIds = $requiredParts->where('component_type', $part)->pluck('grade_component_id');
        return $componentIds->isNotEmpty() && StudentGradeComponent::query()
            ->where('student_course_registration_id', $registration->getKey())
            ->whereIn('grade_component_id', $componentIds)
            ->whereNotNull('mark')
            ->exists();
    }
    private function partMark($r,$parts,string $part):?float{$ids=$parts->where('component_type',$part)->pluck('grade_component_id');if($ids->isEmpty())return null;$marks=StudentGradeComponent::query()->where('student_course_registration_id',$r->getKey())->whereIn('grade_component_id',$ids)->get();if($marks->count()!==$ids->count()||$marks->contains(fn($m)=>$m->mark===null))return null;return(float)$marks->sum(fn($m)=>(float)$m->mark);}
    private function sourceAllowed($o,$r):bool{return SupplementaryExamOfferingSource::query()->where('supplementary_exam_offering_id',$o->getKey())->where('course_offering_id',$r->course_offering_id)->exists();}
    private function deprived($r):bool{return(bool)$r->studentCourseResult?->is_deprived||$r->studentCourseResult?->resultStatus?->status_code==='deprived'||$r->resultStatus?->status_code==='deprived';}
    public function assertSchemaReady(): void
    {
        if (! SupplementaryExamEligibilityGovernance::schemaReady()) {
            $this->fail('Supplementary exam eligibility is not available.', 'supplementary_exam_eligibility_schema_not_ready', 503);
        }
    }
    private function assertStudent($u):void{if(!$u->isStudent()||!$u->effectivePermissions()->contains(SupplementaryExamEligibilityGovernance::PERMISSION_SELF))$this->fail('Student self-service permission required.','deferral_not_owned',403);}
    private function event($d,$type,$from,$to,$actor,$notes):void{SupplementaryExamTheoreticalDeferralEvent::query()->create(['supplementary_exam_theoretical_deferral_id'=>$d->getKey(),'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'actor_user_id'=>$actor,'notes'=>$notes,'created_at'=>now()]);}
    private function fail(string $message,string $code,int $status=409):never{throw new GradeException($message,status:$status,errorCode:$code);}
}
