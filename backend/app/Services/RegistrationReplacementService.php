<?php

namespace App\Services;

use App\Exceptions\RegistrationRequestException;
use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicCalendarEventType;
use App\Models\CourseOffering;
use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationReplacementEvent;
use App\Models\StudentRegistrationReplacementItem;
use App\Models\StudentRegistrationReplacementRequest;
use App\Models\User;
use App\Support\CourseRegistrationPhase;
use App\Support\AcademicQueuePagination;
use App\Support\SemesterRegistrationPhase6;
use App\Support\RegistrationProjectionContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RegistrationReplacementService
{
    public function __construct(private RegistrationService $registration,private AcademicRequirementService $requirements,private CourseOfferingScheduleService $schedules,private SemesterOfferingGovernanceService $governance,private DataScopeService $scope) {}

    public function assertAdvisorViewAccess(User $actor): void { $this->assertReady(); $this->assertCanView($actor); }
    public function assertAdvisorReviewAccess(User $actor): void { $this->assertReady(); $this->assertCanReview($actor); }

    public function workspace(Student $student,?int $yearId,?int $semesterId): array
    {
        if(!SemesterRegistrationPhase6::schemaReady()) return ['schema_ready'=>false,'cancelled_sources'=>[],'replacement_targets'=>[],'request'=>null];
        if(!$yearId||!$semesterId) return ['schema_ready'=>true,'cancelled_sources'=>[],'replacement_targets'=>[],'request'=>null];
        $deadline=$this->registration->courseRegistrationReplacementDeadlines($yearId,$semesterId);
        $this->expireCurrentIfClosed((int) $student->getKey(), $yearId, $semesterId, $deadline);
        $request=$this->current($student,$yearId,$semesterId);
        $sources=$this->eligibleSources($student,$yearId,$semesterId)->get()->map(fn($r)=>$this->sourcePayload($r))->values();
        $targets=CourseOffering::query()->with('course')->where('academic_year_id',$yearId)->where('semester_id',$semesterId)->where('academic_program_id',$student->academic_program_id)->where('status','open')->orderBy('course_offering_id')->get()->map(fn($o)=>['course_offering_id'=>$o->getKey(),'course'=>$o->course?->only(['course_id','course_code','course_name','credit_hours'])])->values();
        return ['schema_ready'=>true,'deadline'=>$deadline->toArray(),'cancelled_sources'=>$sources,'replacement_targets'=>$targets,'request'=>$request?$this->payload($request,true):null];
    }

    public function create(Student $student,User $actor,int $yearId,int $semesterId): array
    {
        $this->assertReady(); $this->assertStudentOpen($yearId,$semesterId);
        $event=$this->replacementEvent($yearId,$semesterId);
        return DB::transaction(function()use($student,$actor,$yearId,$semesterId,$event){
            Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            $current=$this->current($student,$yearId,$semesterId,true); if($current) return $this->payload($current,true);
            if(!$this->eligibleSources($student,$yearId,$semesterId)->exists()) throw SemesterRegistrationPhase6Exception::replacementSource();
            $r=StudentRegistrationReplacementRequest::query()->create(['academic_calendar_event_id'=>$event->getKey(),'student_id'=>$student->getKey(),'academic_year_id'=>$yearId,'semester_id'=>$semesterId,'status'=>'draft','submission_version'=>0,'current_slot'=>1]);
            $this->event($r,'draft_created',$actor); return $this->payload($r,true);
        },3);
    }

    public function updateNotes(Student $student,User $actor,?string $notes,int $yearId,int $semesterId): array
    {
        return $this->mutate($student,$actor,$yearId,$semesterId,function($r)use($notes){$r->student_notes=$notes===null?null:trim($notes);$r->save();});
    }

    public function addItem(Student $student,User $actor,int $yearId,int $semesterId,int $sourceId,int $targetId): array
    {
        return $this->mutate($student,$actor,$yearId,$semesterId,function($r)use($student,$sourceId,$targetId){
            $source=$this->lockEligibleSource($student,$r,$sourceId); $target=CourseOffering::query()->with('course')->whereKey($targetId)->lockForUpdate()->firstOrFail(); $this->assertTarget($student,$r,$target);
            StudentRegistrationReplacementItem::query()->create(['student_registration_replacement_request_id'=>$r->getKey(),'source_minimum_enrollment_review_id'=>$source->minimum_review_id,'source_student_course_registration_id'=>$source->getKey(),'replacement_course_offering_id'=>$target->getKey(),'source_consumed_slot'=>null]);
        },'item_added');
    }

    public function updateItem(Student $student,User $actor,StudentRegistrationReplacementItem $item,int $targetId): array
    {
        $r=$item->request; return $this->mutate($student,$actor,(int)$r->academic_year_id,(int)$r->semester_id,function($locked)use($student,$item,$targetId){
            $lockedItem=StudentRegistrationReplacementItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail(); if((int)$lockedItem->student_registration_replacement_request_id!==(int)$locked->getKey()) throw SemesterRegistrationPhase6Exception::replacementSource();
            $target=CourseOffering::query()->with('course')->whereKey($targetId)->lockForUpdate()->firstOrFail(); $this->assertTarget($student,$locked,$target); $lockedItem->replacement_course_offering_id=$targetId;$lockedItem->save();
        },'item_updated');
    }

    public function removeItem(Student $student,User $actor,StudentRegistrationReplacementItem $item): array
    {
        $r=$item->request; return $this->mutate($student,$actor,(int)$r->academic_year_id,(int)$r->semester_id,function($locked)use($item){$row=StudentRegistrationReplacementItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();if((int)$row->student_registration_replacement_request_id!==(int)$locked->getKey())throw SemesterRegistrationPhase6Exception::replacementSource();$row->delete();},'item_removed');
    }

    public function submit(Student $student,User $actor,int $yearId,int $semesterId): array
    {
        $this->assertReady();$this->assertStudentOpen($yearId,$semesterId);
        return DB::transaction(function()use($student,$actor,$yearId,$semesterId){$r=$this->current($student,$yearId,$semesterId,true);if(!$r||!in_array($r->status,['draft','returned'],true))throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_editable','Replacement request is not editable.');$this->validateRequest($student,$r,true);$from=$r->status;$r->submission_version++;$r->status='submitted';$r->first_submitted_at??=now();$r->last_submitted_at=now();$r->save();$this->event($r,$from==='returned'?'resubmitted':'submitted',$actor,$from,'submitted');return $this->payload($r,true);},3);
    }

    public function advisorIndex(User $actor,?string $status=null,int $perPage=20): array
    {
        $this->assertReady();$this->assertCanView($actor);$this->expireVisibleRequests($actor);$status=in_array($status,SemesterRegistrationPhase6::REPLACEMENT_STATUSES,true)?$status:'submitted';$base=StudentRegistrationReplacementRequest::query()->whereHas('student',fn(Builder $s)=>$this->scope->scopeStaffStudents($s,$actor));$summary=[];foreach(SemesterRegistrationPhase6::REPLACEMENT_STATUSES as $code)$summary[$code]=(clone $base)->where('status',$code)->count();$p=$base->where('status',$status)->with(['student.academicProgram','academicYear','semester','items'])->orderByDesc('last_submitted_at')->orderByDesc('student_registration_replacement_request_id')->paginate(AcademicQueuePagination::perPage($perPage));return ['summary'=>$summary,'status'=>$status,'requests'=>$p->getCollection()->map(fn($r)=>$this->payload($r,false))->all(),'meta'=>AcademicQueuePagination::meta($p)];
    }
    public function advisorShow(User $actor,StudentRegistrationReplacementRequest $r): array {$this->assertCanView($actor);$this->assertAccess($actor,$r);$deadline=$this->registration->courseRegistrationReplacementDeadlines((int)$r->academic_year_id,(int)$r->semester_id);$this->expireCurrentIfClosed((int)$r->student_id,(int)$r->academic_year_id,(int)$r->semester_id,$deadline);return $this->payload($r->fresh()??$r,true);}
    public function returnForModification(User $actor,StudentRegistrationReplacementRequest $route,string $notes): array
    {
        $this->assertCanReview($actor);$this->assertAccess($actor,$route);if(trim($notes)==='')throw SemesterRegistrationPhase6Exception::fail('registration_replacement_return_reason_required','Return reason required.',422);
        return DB::transaction(function()use($actor,$route,$notes){$r=StudentRegistrationReplacementRequest::query()->whereKey($route->getKey())->lockForUpdate()->firstOrFail();if($r->status!=='submitted'||!$this->registration->courseRegistrationReplacementDeadlines($r->academic_year_id,$r->semester_id)->isAdvisorDecisionOpen())throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_submitted','Request is not reviewable.');$r->update(['status'=>'returned','advisor_user_id'=>$actor->user_id,'advisor_notes'=>trim($notes),'reviewed_at'=>now()]);$this->event($r,'returned',$actor,'submitted','returned',trim($notes));return $this->payload($r,true);},3);
    }

    public function approve(User $actor,StudentRegistrationReplacementRequest $route): array
    {
        $this->assertCanReview($actor);$this->assertAccess($actor,$route);
        try{return DB::transaction(function()use($actor,$route){
            $r=StudentRegistrationReplacementRequest::query()->whereKey($route->getKey())->lockForUpdate()->firstOrFail();if($r->status!=='submitted'||(int)$r->current_slot!==1)throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_submitted','Request is not submitted.');
            $student=Student::query()->whereKey($r->student_id)->lockForUpdate()->firstOrFail();$snapshot=StudentRegistrationReplacementItem::query()->where('student_registration_replacement_request_id',$r->getKey())->orderBy('source_student_course_registration_id')->get();$sourceIds=$snapshot->pluck('source_student_course_registration_id')->all();$sources=StudentCourseRegistration::query()->with('registrationStatus')->whereIn('student_course_registration_id',$sourceIds)->orderBy('student_course_registration_id')->lockForUpdate()->get();$targetIds=$snapshot->pluck('replacement_course_offering_id')->sort()->values();CourseOffering::query()->whereIn('course_offering_id',$targetIds)->orderBy('course_offering_id')->lockForUpdate()->get();$items=StudentRegistrationReplacementItem::query()->where('student_registration_replacement_request_id',$r->getKey())->orderBy('source_student_course_registration_id')->lockForUpdate()->get();
            foreach($sources as $source){if($source->registrationStatus?->status_code!==StudentCourseRegistration::CANCELLED_STATUS||StudentRegistrationReplacementItem::query()->where('source_student_course_registration_id',$source->getKey())->where('source_consumed_slot',1)->exists())throw SemesterRegistrationPhase6Exception::consumed();}
            $description=$this->validateRequest($student,$r,true);$now=CarbonImmutable::now('UTC');$materialized=[];
            foreach($items->sortBy('replacement_course_offering_id') as $item){$peers=$targetIds->reject(fn($id)=>(int)$id===(int)$item->replacement_course_offering_id)->map(fn($id)=>(int)$id)->all();$result=$this->registration->materializeAdvisorApprovedReplacementItemWithinTransaction($r,$item,$actor->user_id,$peers,$now);$item->update(['materialized_student_course_registration_id'=>$result['registration']->getKey(),'source_consumed_slot'=>1]);$materialized[]=$result['registration']->getKey();}
            $h=$description['hours'];$r->update(['status'=>'approved','current_slot'=>null,'advisor_user_id'=>$actor->user_id,'reviewed_at'=>$now,'approved_at'=>$now,'materialized_at'=>$now,'registered_hours_before_approval'=>$h['registered_hours'],'replacement_hours_at_approval'=>$h['replacement_hours'],'projected_hours_at_approval'=>$h['projected_hours'],'max_allowed_hours_at_approval'=>$h['max_allowed_hours'],'remaining_hours_after_approval'=>max($h['max_allowed_hours']-$h['projected_hours'],0)]);$this->event($r,'approved',$actor,'submitted','approved');$this->event($r,'materialized',$actor,'approved','approved');return $this->payload($r,true);
        },3);}catch(QueryException $e){if(str_contains($e->getMessage(),'uq_srrpi_source_consumed'))throw SemesterRegistrationPhase6Exception::consumed();throw $e;}
    }

    private function validateRequest(Student $student,StudentRegistrationReplacementRequest $r,bool $hard): array
    {
        $items=StudentRegistrationReplacementItem::query()->where('student_registration_replacement_request_id',$r->getKey())->with(['replacementOffering.course','sourceRegistration.registrationStatus','sourceReview'])->get();if($items->isEmpty())throw SemesterRegistrationPhase6Exception::fail('registration_replacement_no_items','At least one replacement is required.',422);
        foreach($items as $i){$this->lockEligibleSource($student,$r,(int)$i->source_student_course_registration_id,false);$this->assertTarget($student,$r,$i->replacementOffering);}
        $targets=$items->pluck('replacementOffering');$failures=$this->registration->evaluateRegistrationCandidatesForProjection($student,$targets);$requirementFailures=$this->requirements->validateProjectedCandidates($student,$targets,new RegistrationProjectionContext());$currentIds=$this->registration->currentOfferingIds($student);$timetable=$this->schedules->registrationEvaluations($student,$targets,$currentIds,$targets->pluck('course_offering_id')->all());foreach($timetable as $id=>$ev)if(($ev['reason']??null)!==null)$failures[]=['course_offering_id'=>$id,'reason'=>$ev['reason']];
        $hours=$this->registration->hoursSnapshot($student,$r->academic_year_id,$r->semester_id);$replacementHours=(int)$targets->sum(fn($o)=>(int)$o->course?->credit_hours);$projected=$hours['registered_hours']+$replacementHours;if($projected>$hours['max_allowed_hours'])$failures[]=['reason'=>'credit_hours_exceeded'];if($failures!==[]||$requirementFailures!==[])throw SemesterRegistrationPhase6Exception::fail('registration_replacement_validation_failed','Replacement selection is not eligible.',409,['items'=>array_merge($failures,$requirementFailures)]);
        return ['hours'=>['registered_hours'=>$hours['registered_hours'],'replacement_hours'=>$replacementHours,'projected_hours'=>$projected,'max_allowed_hours'=>$hours['max_allowed_hours'],'below_recommended_minimum'=>$projected<12]];
    }

    private function assertTarget(Student $student,StudentRegistrationReplacementRequest $r,?CourseOffering $o): void {if(!$o||$o->status!=='open'||(int)$o->academic_year_id!==(int)$r->academic_year_id||(int)$o->semester_id!==(int)$r->semester_id)throw SemesterRegistrationPhase6Exception::fail('replacement_target_not_eligible','Replacement target is not eligible.');$this->governance->assertFinallyApprovedForReplacement($o);if((int)$o->academic_program_id!==(int)$student->academic_program_id)throw SemesterRegistrationPhase6Exception::fail('replacement_target_not_eligible','Replacement target is outside the current curriculum.');}
    private function lockEligibleSource(Student $student,StudentRegistrationReplacementRequest $r,int $id,bool $lock=true): StudentCourseRegistration {$q=StudentCourseRegistration::query()->with('registrationStatus')->whereKey($id)->where('student_id',$student->getKey());if($lock)$q->lockForUpdate();$s=$q->first();if(!$s||$s->registrationStatus?->status_code!==StudentCourseRegistration::CANCELLED_STATUS)throw SemesterRegistrationPhase6Exception::replacementSource();$review=CourseOfferingMinimumEnrollmentReview::query()->where('course_offering_id',$s->course_offering_id)->where('status','cancelled')->first();if(!$review||(int)$review->academic_year_id!==(int)$r->academic_year_id||(int)$review->semester_id!==(int)$r->semester_id)throw SemesterRegistrationPhase6Exception::replacementSource();if(StudentRegistrationReplacementItem::query()->where('source_student_course_registration_id',$id)->where('source_consumed_slot',1)->exists())throw SemesterRegistrationPhase6Exception::consumed();$s->setAttribute('minimum_review_id',$review->getKey());return $s;}
    private function eligibleSources(Student $s,int $y,int $m): Builder {return StudentCourseRegistration::query()->with('courseOffering.course')->select('student_course_registrations.*')->addSelect(['minimum_review_id'=>CourseOfferingMinimumEnrollmentReview::query()->select('course_offering_minimum_enrollment_review_id')->whereColumn('course_offering_id','student_course_registrations.course_offering_id')->where('status','cancelled')->where('academic_year_id',$y)->where('semester_id',$m)->limit(1)])->where('student_id',$s->getKey())->whereHas('registrationStatus',fn($q)=>$q->where('status_code','cancelled'))->whereHas('courseOffering',fn($q)=>$q->where('academic_year_id',$y)->where('semester_id',$m))->whereNotExists(fn($q)=>$q->selectRaw('1')->from('student_registration_replacement_items as used')->whereColumn('used.source_student_course_registration_id','student_course_registrations.student_course_registration_id')->where('used.source_consumed_slot',1));}
    private function replacementEvent(int $y,int $m): AcademicCalendarEvent {$type=AcademicCalendarEventType::query()->where('event_type_code',SemesterRegistrationPhase6::REPLACEMENT_EVENT_TYPE)->where('is_active',true)->firstOrFail();return AcademicCalendarEvent::query()->where('academic_calendar_event_type_id',$type->getKey())->where('academic_year_id',$y)->where('semester_id',$m)->whereNull('cancelled_at')->firstOrFail();}
    private function current(Student $s,int $y,int $m,bool $lock=false): ?StudentRegistrationReplacementRequest {$q=StudentRegistrationReplacementRequest::query()->where('student_id',$s->getKey())->where('academic_year_id',$y)->where('semester_id',$m)->where('current_slot',1);if($lock)$q->lockForUpdate();return $q->first();}
    private function mutate(Student $s,User $a,int $y,int $m,callable $fn,string $event='item_updated'): array {$this->assertReady();$this->assertStudentOpen($y,$m);return DB::transaction(function()use($s,$a,$y,$m,$fn,$event){$r=$this->current($s,$y,$m,true);if(!$r||!in_array($r->status,['draft','returned'],true))throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_editable','Request is not editable.');$fn($r);$this->event($r,$event,$a);return $this->payload($r,true);},3);}
    private function payload(StudentRegistrationReplacementRequest $r,bool $full): array {$r->loadMissing(['student.academicProgram','academicYear','semester','items.sourceRegistration.courseOffering.course','items.replacementOffering.course','advisor']);return ['student_registration_replacement_request_id'=>$r->getKey(),'status'=>$r->status,'submission_version'=>$r->submission_version,'student_notes'=>$r->student_notes,'advisor_notes'=>$r->advisor_notes,'first_submitted_at'=>$r->first_submitted_at?->toIso8601String(),'last_submitted_at'=>$r->last_submitted_at?->toIso8601String(),'approved_at'=>$r->approved_at?->toIso8601String(),'student'=>$r->student?->only(['student_id','student_number','full_name']),'academic_year'=>$r->academicYear?->only(['academic_year_id','year_name']),'semester'=>$r->semester?->only(['semester_id','semester_name']),'hours'=>['approved_snapshot'=>$r->status==='approved'?['registered_hours_at_approval'=>$r->registered_hours_before_approval,'replacement_hours_at_approval'=>$r->replacement_hours_at_approval,'projected_hours_at_approval'=>$r->projected_hours_at_approval,'max_allowed_hours_at_approval'=>$r->max_allowed_hours_at_approval,'remaining_hours_after_approval'=>$r->remaining_hours_after_approval]:null],'items'=>$full?$r->items->map(fn($i)=>['student_registration_replacement_item_id'=>$i->getKey(),'source_student_course_registration_id'=>$i->source_student_course_registration_id,'replacement_course_offering_id'=>$i->replacement_course_offering_id,'source_consumed_slot'=>$i->source_consumed_slot,'materialized_student_course_registration_id'=>$i->materialized_student_course_registration_id,'source_course'=>$i->sourceRegistration?->courseOffering?->course?->only(['course_code','course_name','credit_hours']),'target_course'=>$i->replacementOffering?->course?->only(['course_code','course_name','credit_hours'])])->values():[]];}
    private function sourcePayload($r): array {return ['student_course_registration_id'=>$r->getKey(),'minimum_review_id'=>$r->minimum_review_id,'course'=>$r->courseOffering?->course?->only(['course_code','course_name','credit_hours'])];}
    private function event($r,string $type,?User $actor=null,?string $from=null,?string $to=null,?string $notes=null): void {StudentRegistrationReplacementEvent::query()->create(['student_registration_replacement_request_id'=>$r->getKey(),'event_type'=>$type,'actor_user_id'=>$actor?->user_id,'from_status'=>$from,'to_status'=>$to,'submission_version'=>$r->submission_version,'notes'=>$notes,'created_at'=>now()]);}
    private function expireCurrentIfClosed(int $studentId,int $yearId,int $semesterId,\App\Support\CourseRegistrationDeadlineResult $deadline): void
    {
        if($deadline->phase!==CourseRegistrationPhase::CLOSED)return;
        DB::transaction(function()use($studentId,$yearId,$semesterId,$deadline){$r=StudentRegistrationReplacementRequest::query()->where('student_id',$studentId)->where('academic_year_id',$yearId)->where('semester_id',$semesterId)->where('current_slot',1)->lockForUpdate()->first();if(!$r||!in_array($r->status,['draft','submitted','returned'],true))return;$from=$r->status;$r->status='expired';$r->current_slot=null;$r->expired_at=$deadline->evaluatedAt;$r->save();$this->event($r,'expired',null,$from,'expired');},3);
    }
    private function expireVisibleRequests(User $actor): void
    {
        $terms=StudentRegistrationReplacementRequest::query()->where('current_slot',1)->whereHas('student',fn(Builder $s)=>$this->scope->scopeStaffStudents($s,$actor))->select(['academic_year_id','semester_id'])->distinct()->get();
        foreach($terms as $term){$deadline=$this->registration->courseRegistrationReplacementDeadlines((int)$term->academic_year_id,(int)$term->semester_id);if($deadline->phase!==CourseRegistrationPhase::CLOSED)continue;DB::transaction(function()use($actor,$term,$deadline){$rows=StudentRegistrationReplacementRequest::query()->where('academic_year_id',$term->academic_year_id)->where('semester_id',$term->semester_id)->where('current_slot',1)->whereHas('student',fn(Builder $s)=>$this->scope->scopeStaffStudents($s,$actor))->orderBy('student_registration_replacement_request_id')->lockForUpdate()->get();foreach($rows as $r){if(!in_array($r->status,['draft','submitted','returned'],true))continue;$from=$r->status;$r->status='expired';$r->current_slot=null;$r->expired_at=$deadline->evaluatedAt;$r->save();$this->event($r,'expired',null,$from,'expired');}},3);}
    }
    private function assertStudentOpen(int $y,int $m): void {if($this->registration->courseRegistrationReplacementDeadlines($y,$m)->phase!==CourseRegistrationPhase::STUDENT_OPEN)throw SemesterRegistrationPhase6Exception::fail('registration_replacement_window_closed','Replacement student window is closed.');}
    private function assertReady(): void {if(!SemesterRegistrationPhase6::schemaReady())throw SemesterRegistrationPhase6Exception::replacementSchema();}
    private function assertCanView(User $u): void {if(!$u->hasPermission('registration_requests.view'))throw new AccessDeniedHttpException('Forbidden.');}
    private function assertCanReview(User $u): void {if(!$u->hasPermission('registration_requests.review'))throw new AccessDeniedHttpException('Forbidden.');}
    private function assertAccess(User $u,StudentRegistrationReplacementRequest $r): void {$r->loadMissing('student');if(!$r->student||!$this->scope->canStaffAccessStudent($u,$r->student))throw new AccessDeniedHttpException('Forbidden.');}
}
