<?php

namespace App\Services;

use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicCalendarEventType;
use App\Models\CourseOffering;
use App\Models\CourseOfferingClosureRequest;
use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationReplacementEvent;
use App\Models\StudentRegistrationReplacementItem;
use App\Models\StudentRegistrationReplacementRequest;
use App\Models\User;
use App\Support\AcademicQueuePagination;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
use App\Support\RegistrationProjectionContext;
use App\Support\SemesterRegistrationPhase6;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RegistrationReplacementService
{
    public function __construct(
        private RegistrationService $registration,
        private AcademicRequirementService $requirements,
        private CourseOfferingScheduleService $schedules,
        private SemesterOfferingGovernanceService $governance,
        private DataScopeService $scope,
    ) {}

    public function assertAdvisorViewAccess(User $actor): void { $this->assertReady(); $this->assertCanView($actor); }
    public function assertAdvisorReviewAccess(User $actor): void { $this->assertReady(); $this->assertCanReview($actor); }

    public function workspace(Student $student, ?int $yearId, ?int $semesterId): array
    {
        if (! SemesterRegistrationPhase6::schemaReady()) return ['schema_ready'=>false,'cancelled_sources'=>[],'replacement_targets'=>[],'request'=>null,'history'=>[]];
        if (! $yearId || ! $semesterId) return ['schema_ready'=>true,'cancelled_sources'=>[],'replacement_targets'=>[],'request'=>null,'history'=>[]];
        $deadline=$this->registration->courseRegistrationReplacementDeadlines($yearId,$semesterId);
        $request=$this->current($student,$yearId,$semesterId);
        if ($request && ($reason=$this->staleReason($request,$student,$deadline))!==null) {
            $this->persistSuperseded((int)$request->getKey(),null,$reason); $request=null;
        } else {
            $this->expireCurrentIfClosed((int)$student->getKey(),$yearId,$semesterId,$deadline);
            $request=$request?->fresh();
        }
        $sources=$this->eligibleSources($student,$yearId,$semesterId)->get()->map(fn($row)=>$this->sourcePayload($row))->values();
        $targets=CourseOffering::query()->with('course')->where('academic_year_id',$yearId)->where('semester_id',$semesterId)
            ->where('academic_program_id',$student->academic_program_id)->where('status','open')->orderBy('course_offering_id')->get()
            ->map(fn(CourseOffering $offering)=>$this->targetPayload($student,$offering))->values();
        $history=StudentRegistrationReplacementRequest::query()->where('student_id',$student->getKey())->where('academic_year_id',$yearId)
            ->where('semester_id',$semesterId)->whereNull('current_slot')->orderByDesc('student_registration_replacement_request_id')->get()
            ->map(fn($row)=>$this->payload($row,true))->values();
        return ['schema_ready'=>true,'deadline'=>$deadline->toArray(),'cancelled_sources'=>$sources,'replacement_targets'=>$targets,
            'request'=>$request?$this->payload($request,true):null,'history'=>$history];
    }

    public function create(Student $student, User $actor, int $yearId, int $semesterId): array
    {
        $this->assertReady(); $deadline=$this->assertStudentOpen($yearId,$semesterId);
        $currentOutcome=DB::transaction(function()use($student,$actor,$yearId,$semesterId,$deadline){
            Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            $event=$this->replacementEvent($yearId,$semesterId,true);
            if ((int)$deadline->academicCalendarEventId!==(int)$event->getKey()) throw SemesterRegistrationPhase6Exception::fail('registration_replacement_calendar_invalid','Replacement calendar configuration is inconsistent.');
            if ($current=$this->current($student,$yearId,$semesterId,true)) {
                if (($reason=$this->staleReason($current,$student,$deadline,true))===null) return ['outcome'=>'current','payload'=>$this->payload($current,true)];
                $this->supersede($current,$actor,$reason);
                return ['outcome'=>'superseded'];
            }
            return ['outcome'=>'none'];
        },3);
        if($currentOutcome['outcome']==='current')return $currentOutcome['payload'];

        return DB::transaction(function()use($student,$actor,$yearId,$semesterId,$deadline){
            Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            $event=$this->replacementEvent($yearId,$semesterId,true);
            if((int)$deadline->academicCalendarEventId!==(int)$event->getKey())throw SemesterRegistrationPhase6Exception::fail('registration_replacement_calendar_invalid','Replacement calendar configuration is inconsistent.');
            if($current=$this->current($student,$yearId,$semesterId,true))return $this->payload($current,true);
            if (! $this->eligibleSources($student,$yearId,$semesterId)->exists()) throw SemesterRegistrationPhase6Exception::replacementSource();
            $request=StudentRegistrationReplacementRequest::query()->create(['academic_calendar_event_id'=>$event->getKey(),'student_id'=>$student->getKey(),
                'academic_year_id'=>$yearId,'semester_id'=>$semesterId,'status'=>'draft','submission_version'=>0,'current_slot'=>1]);
            $this->event($request,'draft_created',$actor); return $this->payload($request,true);
        },3);
    }

    public function updateNotes(Student $student, User $actor, ?string $notes, int $yearId, int $semesterId): array
    {
        return $this->mutate($student,$actor,$yearId,$semesterId,function($request)use($notes){$request->student_notes=$notes===null?null:trim($notes);$request->save();});
    }

    public function addItem(Student $student, User $actor, int $yearId, int $semesterId, int $sourceId, int $targetId): array
    {
        return $this->mutate($student,$actor,$yearId,$semesterId,function($request)use($student,$sourceId,$targetId){
            $items=StudentRegistrationReplacementItem::query()->where('student_registration_replacement_request_id',$request->getKey());
            if ((clone $items)->where('source_student_course_registration_id',$sourceId)->exists()) throw SemesterRegistrationPhase6Exception::duplicateSource();
            if ((clone $items)->where('replacement_course_offering_id',$targetId)->exists()) throw SemesterRegistrationPhase6Exception::duplicateTarget();
            $source=$this->lockEligibleSource($student,$request,$sourceId);
            $target=CourseOffering::query()->with('course')->whereKey($targetId)->lockForUpdate()->firstOrFail(); $this->assertTarget($student,$request,$target);
            StudentRegistrationReplacementItem::query()->create(['student_registration_replacement_request_id'=>$request->getKey(),
                'source_minimum_enrollment_review_id'=>$source->minimum_review_id,'source_student_course_registration_id'=>$source->getKey(),
                'replacement_course_offering_id'=>$target->getKey(),'source_consumed_slot'=>null]);
        },'item_added');
    }

    public function updateItem(Student $student, User $actor, StudentRegistrationReplacementItem $item, int $targetId): array
    {
        $request=$item->request;
        return $this->mutate($student,$actor,(int)$request->academic_year_id,(int)$request->semester_id,function($locked)use($student,$item,$targetId){
            $row=StudentRegistrationReplacementItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ((int)$row->student_registration_replacement_request_id!==(int)$locked->getKey()) throw SemesterRegistrationPhase6Exception::replacementSource();
            if (StudentRegistrationReplacementItem::query()->where('student_registration_replacement_request_id',$locked->getKey())
                ->where('replacement_course_offering_id',$targetId)->where('student_registration_replacement_item_id','<>',$row->getKey())->exists()) throw SemesterRegistrationPhase6Exception::duplicateTarget();
            $target=CourseOffering::query()->with('course')->whereKey($targetId)->lockForUpdate()->firstOrFail(); $this->assertTarget($student,$locked,$target);
            $row->replacement_course_offering_id=$targetId; $row->save();
        },'item_updated');
    }

    public function removeItem(Student $student, User $actor, StudentRegistrationReplacementItem $item): array
    {
        $request=$item->request;
        return $this->mutate($student,$actor,(int)$request->academic_year_id,(int)$request->semester_id,function($locked)use($item){
            $row=StudentRegistrationReplacementItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ((int)$row->student_registration_replacement_request_id!==(int)$locked->getKey()) throw SemesterRegistrationPhase6Exception::replacementSource();
            $row->delete();
        },'item_removed');
    }

    public function submit(Student $student, User $actor, int $yearId, int $semesterId): array
    {
        $this->assertReady(); $deadline=$this->registration->courseRegistrationReplacementDeadlines($yearId,$semesterId);
        $outcome=DB::transaction(function()use($student,$actor,$yearId,$semesterId,$deadline){
            $request=$this->current($student,$yearId,$semesterId,true);
            if (! $request || ! in_array($request->status,['draft','returned'],true)) throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_editable','Replacement request is not editable.');
            if (($reason=$this->staleReason($request,$student,$deadline,true))!==null) {$this->supersede($request,$actor,$reason); return ['outcome'=>'stale'];}
            if ($deadline->phase!==CourseRegistrationPhase::STUDENT_OPEN) throw SemesterRegistrationPhase6Exception::fail('registration_replacement_window_closed','Replacement student window is closed.');
            $this->describeRequest($student,$request,true); $from=$request->status; $request->submission_version++;
            $request->status='submitted'; $request->first_submitted_at??=$deadline->evaluatedAt; $request->last_submitted_at=$deadline->evaluatedAt; $request->save();
            $this->event($request,$from==='returned'?'resubmitted':'submitted',$actor,$from,'submitted');
            return ['outcome'=>'ok','payload'=>$this->payload($request,true)];
        },3);
        return $this->finishOutcome($outcome);
    }

    public function advisorIndex(User $actor, ?string $status=null, int $perPage=20): array
    {
        $this->assertReady(); $this->assertCanView($actor); $this->expireVisibleRequests($actor);
        $status=in_array($status,SemesterRegistrationPhase6::REPLACEMENT_STATUSES,true)?$status:'submitted';
        $base=StudentRegistrationReplacementRequest::query()->whereHas('student',fn(Builder $q)=>$this->scope->scopeStaffStudents($q,$actor));
        $summary=[]; foreach(SemesterRegistrationPhase6::REPLACEMENT_STATUSES as $code) $summary[$code]=(clone $base)->where('status',$code)->count();
        $page=$base->where('status',$status)->with(['student.academicProgram','academicYear','semester','items'])
            ->orderByDesc('last_submitted_at')->orderByDesc('student_registration_replacement_request_id')->paginate(AcademicQueuePagination::perPage($perPage));
        return ['summary'=>$summary,'status'=>$status,'requests'=>$page->getCollection()->map(fn($r)=>$this->payload($r,false))->all(),'meta'=>AcademicQueuePagination::meta($page)];
    }

    public function advisorShow(User $actor, StudentRegistrationReplacementRequest $request): array
    {
        $this->assertCanView($actor); $this->assertAccess($actor,$request);
        $deadline=$this->registration->courseRegistrationReplacementDeadlines((int)$request->academic_year_id,(int)$request->semester_id);
        $this->expireCurrentIfClosed((int)$request->student_id,(int)$request->academic_year_id,(int)$request->semester_id,$deadline);
        return $this->payload($request->fresh()??$request,true);
    }

    public function returnForModification(User $actor, StudentRegistrationReplacementRequest $route, string $notes): array
    {
        $this->assertCanReview($actor); $this->assertAccess($actor,$route);
        if (trim($notes)==='') throw SemesterRegistrationPhase6Exception::fail('registration_replacement_return_reason_required','Return reason required.',422);
        $outcome=DB::transaction(function()use($actor,$route,$notes){
            $request=StudentRegistrationReplacementRequest::query()->whereKey($route->getKey())->lockForUpdate()->firstOrFail();
            $student=Student::query()->whereKey($request->student_id)->lockForUpdate()->firstOrFail();
            $deadline=$this->registration->courseRegistrationReplacementDeadlines((int)$request->academic_year_id,(int)$request->semester_id);
            if (($reason=$this->staleReason($request,$student,$deadline,true))!==null) {$this->supersede($request,$actor,$reason); return ['outcome'=>'stale'];}
            if ($request->status!=='submitted'||!$deadline->isAdvisorDecisionOpen()) throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_submitted','Request is not reviewable.');
            $request->update(['status'=>'returned','advisor_user_id'=>$actor->user_id,'advisor_notes'=>trim($notes),'reviewed_at'=>$deadline->evaluatedAt]);
            $this->event($request,'returned',$actor,'submitted','returned',trim($notes)); return ['outcome'=>'ok','payload'=>$this->payload($request,true)];
        },3);
        return $this->finishOutcome($outcome);
    }

    public function approve(User $actor, StudentRegistrationReplacementRequest $route): array
    {
        $this->assertCanReview($actor); $this->assertAccess($actor,$route);
        try {
            $outcome=DB::transaction(function()use($actor,$route){
                $request=StudentRegistrationReplacementRequest::query()->whereKey($route->getKey())->lockForUpdate()->firstOrFail();
                if ($request->status!=='submitted'||(int)$request->current_slot!==1) throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_submitted','Request is not submitted.');
                $student=Student::query()->whereKey($request->student_id)->lockForUpdate()->firstOrFail();
                $deadline=$this->registration->courseRegistrationReplacementDeadlines((int)$request->academic_year_id,(int)$request->semester_id);
                if (($reason=$this->staleReason($request,$student,$deadline,true))!==null) {$this->supersede($request,$actor,$reason); return ['outcome'=>'stale'];}
                if (! $deadline->isAdvisorDecisionOpen()) throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_submitted','Request is outside the advisor decision window.');
                $description=$this->describeRequest($student,$request,true);
                $items=StudentRegistrationReplacementItem::query()->where('student_registration_replacement_request_id',$request->getKey())->orderBy('student_registration_replacement_item_id')->get();
                $result=$this->registration->materializeAdvisorApprovedReplacementItemWithinTransaction($request,$items->firstOrFail(),(int)$actor->user_id,$deadline->evaluatedAt);
                foreach($items as $item){$registration=$result['registrations'][(int)$item->getKey()]??null;if(!$registration)throw SemesterRegistrationPhase6Exception::stale();
                    $item->refresh();
                    if((int)$item->materialized_student_course_registration_id!==(int)$registration->getKey()||(int)$item->source_consumed_slot!==1)throw SemesterRegistrationPhase6Exception::stale();}
                $hours=$description['hours']; $request->update(['status'=>'approved','current_slot'=>null,'advisor_user_id'=>$actor->user_id,
                    'reviewed_at'=>$deadline->evaluatedAt,'approved_at'=>$deadline->evaluatedAt,'materialized_at'=>$deadline->evaluatedAt,
                    'registered_hours_before_approval'=>$hours['registered_hours'],'replacement_hours_at_approval'=>$hours['replacement_hours'],
                    'projected_hours_at_approval'=>$hours['projected_hours'],'max_allowed_hours_at_approval'=>$hours['max_allowed_hours'],
                    'remaining_hours_after_approval'=>max($hours['max_allowed_hours']-$hours['projected_hours'],0)]);
                $this->event($request,'approved',$actor,'submitted','approved'); $this->event($request,'materialized',$actor,'approved','approved');
                return ['outcome'=>'ok','payload'=>$this->payload($request,true)];
            },3);
        } catch(QueryException $exception) {
            throw $this->mapUnique($exception);
        } catch(SemesterRegistrationPhase6Exception $exception) {
            if ($exception->errorCode === 'registration_replacement_stale') {
                $eventType=SemesterRegistrationPhase6::EVENT_REPLACEMENT_SOURCE_CHANGED;
                $current=StudentRegistrationReplacementRequest::query()->whereKey($route->getKey())->first();
                $student=$current===null?null:Student::query()->whereKey($current->student_id)->first();
                if($current&&$student){
                    $deadline=$this->registration->courseRegistrationReplacementDeadlines((int)$current->academic_year_id,(int)$current->semester_id);
                    $eventType=$this->staleReason($current,$student,$deadline)??$eventType;
                }
                $this->persistSuperseded((int) $route->getKey(), $actor, $eventType);
            }
            throw $exception;
        }
        return $this->finishOutcome($outcome);
    }

    private function describeRequest(Student $student, StudentRegistrationReplacementRequest $request, bool $validate): array
    {
        $request->loadMissing(['items.replacementOffering.course','items.sourceRegistration.registrationStatus','items.sourceReview']);
        $items=$request->items; $failures=[];
        if ($items->isEmpty()) $failures[]=['reason'=>'registration_replacement_no_items'];
        $targets=$items->pluck('replacementOffering')->filter()->values();
        foreach($items as $item){
            try {$this->lockEligibleSource($student,$request,(int)$item->source_student_course_registration_id,false,(int)$item->source_minimum_enrollment_review_id);}
            catch(SemesterRegistrationPhase6Exception $e){$failures[]=['student_registration_replacement_item_id'=>(int)$item->getKey(),'reason'=>$e->errorCode];}
            try {$this->assertTarget($student,$request,$item->replacementOffering);}
            catch(SemesterRegistrationPhase6Exception $e){$failures[]=['student_registration_replacement_item_id'=>(int)$item->getKey(),'course_offering_id'=>(int)$item->replacement_course_offering_id,'reason'=>$e->errorCode];}
        }
        $failures=[...$failures,...$this->registration->evaluateRegistrationCandidatesForProjection($student,$targets)];
        $requirementFailures=$this->requirements->validateProjectedCandidates($student,$targets,new RegistrationProjectionContext(proposedAddOfferingIds:$targets->pluck('course_offering_id')->map(fn($id)=>(int)$id)->all()));
        $currentIds=$this->registration->currentOfferingIds($student);
        foreach($targets as $target) if(in_array((int)$target->getKey(),$currentIds,true)) $failures[]=['course_offering_id'=>(int)$target->getKey(),'reason'=>'registration_duplicate'];
        $timetables=$this->schedules->registrationEvaluations($student,$targets,$currentIds,$targets->pluck('course_offering_id')->all());
        foreach($timetables as $id=>$evaluation) if(is_string($evaluation['reason']??null)) $failures[]=[
            'course_offering_id'=>(int)$id,'reason'=>$evaluation['reason'],'conflicts'=>$evaluation['conflicts']??[],
            'incomplete_timetable_sources'=>$evaluation['incomplete_timetable_sources']??[],
        ];
        $hours=$this->registration->hoursSnapshot($student,(int)$request->academic_year_id,(int)$request->semester_id);
        $replacementHours=(int)$targets->sum(fn(CourseOffering $offering)=>(int)($offering->course?->credit_hours??0));
        $projected=(int)$hours['registered_hours']+$replacementHours;
        if($projected>(int)$hours['max_allowed_hours'])$failures[]=['reason'=>'credit_hours_exceeded'];
        $allFailures=collect([...$failures,...$requirementFailures])->unique(fn(array $failure)=>(string)($failure['student_registration_replacement_item_id']??$failure['course_offering_id']??'term').':'.($failure['reason']??'unknown'))->values()->all();
        $description=['hours'=>['registered_hours'=>(int)$hours['registered_hours'],'replacement_hours'=>$replacementHours,'projected_hours'=>$projected,
            'max_allowed_hours'=>(int)$hours['max_allowed_hours'],'remaining_hours'=>max((int)$hours['max_allowed_hours']-$projected,0),
            'recommended_minimum_hours'=>RegistrationService::RECOMMENDED_MINIMUM_CREDIT_HOURS,
            'below_recommended_minimum'=>$projected<RegistrationService::RECOMMENDED_MINIMUM_CREDIT_HOURS,'official_cgpa'=>$hours['official_cgpa']],
            'schedules'=>$timetables,'failures'=>$allFailures];
        if($validate&&$allFailures!==[])throw SemesterRegistrationPhase6Exception::fail('registration_replacement_validation_failed','Replacement selection is not eligible.',409,['items'=>$allFailures]);
        return $description;
    }

    private function assertTarget(Student $student, StudentRegistrationReplacementRequest $request, ?CourseOffering $offering): void
    {
        if(!$offering||$offering->status!=='open'||(int)$offering->academic_year_id!==(int)$request->academic_year_id
            ||(int)$offering->semester_id!==(int)$request->semester_id||(int)$offering->academic_program_id!==(int)$student->academic_program_id)
            throw SemesterRegistrationPhase6Exception::fail('replacement_target_not_eligible','Replacement target is not eligible.');
        if(in_array((int)$offering->getKey(),$this->registration->currentOfferingIds($student),true))
            throw SemesterRegistrationPhase6Exception::fail('replacement_target_already_registered','The replacement target is already currently registered.');
        $this->governance->assertFinallyApprovedForReplacement($offering);
    }

    private function lockEligibleSource(Student $student, StudentRegistrationReplacementRequest $request, int $id, bool $lock=true, ?int $expectedReviewId=null): StudentCourseRegistration
    {
        $query=StudentCourseRegistration::query()->with('registrationStatus')->whereKey($id)->where('student_id',$student->getKey()); if($lock)$query->lockForUpdate();
        $source=$query->first();
        if(!$source||$source->registrationStatus?->status_code!==StudentCourseRegistration::CANCELLED_STATUS)throw SemesterRegistrationPhase6Exception::replacementSource();
        $reviews=CourseOfferingMinimumEnrollmentReview::query()->where('course_offering_id',$source->course_offering_id)->where('status','cancelled')
            ->where('academic_year_id',$request->academic_year_id)->where('semester_id',$request->semester_id)->get();
        if($reviews->count()!==1)throw SemesterRegistrationPhase6Exception::replacementSource(); $review=$reviews->first();
        if($expectedReviewId!==null&&(int)$review->getKey()!==$expectedReviewId)throw SemesterRegistrationPhase6Exception::replacementSource();
        $closure=$review->course_offering_closure_request_id===null?null:CourseOfferingClosureRequest::query()->whereKey($review->course_offering_closure_request_id)->first();
        if(!$closure||(int)$closure->course_offering_id!==(int)$source->course_offering_id||$closure->status!=='approved'||$closure->materialized_at===null)throw SemesterRegistrationPhase6Exception::replacementSource();
        if(StudentRegistrationReplacementItem::query()->where('source_student_course_registration_id',$id)->where('source_consumed_slot',1)->exists())throw SemesterRegistrationPhase6Exception::consumed();
        $source->setAttribute('minimum_review_id',$review->getKey()); return $source;
    }

    private function eligibleSources(Student $student, int $yearId, int $semesterId): Builder
    {
        return StudentCourseRegistration::query()->with('courseOffering.course')->select('student_course_registrations.*')
            ->addSelect(['minimum_review_id'=>CourseOfferingMinimumEnrollmentReview::query()->select('course_offering_minimum_enrollment_review_id')
                ->whereColumn('course_offering_id','student_course_registrations.course_offering_id')->where('status','cancelled')
                ->where('academic_year_id',$yearId)->where('semester_id',$semesterId)->limit(1)])
            ->where('student_id',$student->getKey())->whereHas('registrationStatus',fn($q)=>$q->where('status_code',StudentCourseRegistration::CANCELLED_STATUS))
            ->whereHas('courseOffering',fn($q)=>$q->where('academic_year_id',$yearId)->where('semester_id',$semesterId))
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('course_offering_minimum_enrollment_reviews as source_review')
                ->join('course_offering_closure_requests as source_closure','source_closure.course_offering_closure_request_id','=','source_review.course_offering_closure_request_id')
                ->whereColumn('source_review.course_offering_id','student_course_registrations.course_offering_id')->where('source_review.status','cancelled')
                ->where('source_review.academic_year_id',$yearId)->where('source_review.semester_id',$semesterId)->where('source_closure.status','approved')->whereNotNull('source_closure.materialized_at'))
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('student_registration_replacement_items as used')
                ->whereColumn('used.source_student_course_registration_id','student_course_registrations.student_course_registration_id')->where('used.source_consumed_slot',1));
    }

    private function replacementEvent(int $yearId, int $semesterId, bool $lock=false): AcademicCalendarEvent
    {
        $types=AcademicCalendarEventType::query()->where('event_type_code',SemesterRegistrationPhase6::REPLACEMENT_EVENT_TYPE)->where('is_active',true)->get();
        if($types->count()!==1)throw SemesterRegistrationPhase6Exception::fail('registration_replacement_calendar_invalid','Replacement calendar event type is not canonical.');
        $query=AcademicCalendarEvent::query()->where('academic_calendar_event_type_id',$types->first()->getKey())->where('academic_year_id',$yearId)
            ->where('semester_id',$semesterId)->whereNull('cancelled_at'); if($lock)$query->lockForUpdate(); $events=$query->get();
        if($events->count()!==1)throw SemesterRegistrationPhase6Exception::fail('registration_replacement_calendar_invalid','Replacement calendar event is not canonical.');
        return $events->first();
    }

    private function current(Student $student, int $yearId, int $semesterId, bool $lock=false): ?StudentRegistrationReplacementRequest
    {
        $query=StudentRegistrationReplacementRequest::query()->where('student_id',$student->getKey())->where('academic_year_id',$yearId)
            ->where('semester_id',$semesterId)->where('current_slot',1); if($lock)$query->lockForUpdate(); return $query->first();
    }

    private function mutate(Student $student, User $actor, int $yearId, int $semesterId, callable $mutation, string $event='item_updated'): array
    {
        $this->assertReady(); $deadline=$this->registration->courseRegistrationReplacementDeadlines($yearId,$semesterId);
        try{$outcome=DB::transaction(function()use($student,$actor,$yearId,$semesterId,$mutation,$event,$deadline){
            $request=$this->current($student,$yearId,$semesterId,true);
            if(!$request||!in_array($request->status,['draft','returned'],true))throw SemesterRegistrationPhase6Exception::fail('registration_replacement_not_editable','Request is not editable.');
            if(($reason=$this->staleReason($request,$student,$deadline,true))!==null){$this->supersede($request,$actor,$reason);return ['outcome'=>'stale'];}
            if($deadline->phase!==CourseRegistrationPhase::STUDENT_OPEN)throw SemesterRegistrationPhase6Exception::fail('registration_replacement_window_closed','Replacement student window is closed.');
            $mutation($request);$this->event($request,$event,$actor);return ['outcome'=>'ok','payload'=>$this->payload($request,true)];
        },3);}catch(QueryException $exception){throw $this->mapUnique($exception);} return $this->finishOutcome($outcome);
    }

    private function payload(StudentRegistrationReplacementRequest $request, bool $full): array
    {
        $request->loadMissing(['student.academicProgram','academicYear','semester','advisor.employee','items.sourceRegistration.courseOffering.course',
            'items.replacementOffering.course','items.materializedRegistration.registrationStatus','events.actor.employee']);
        $student=$request->student;
        $presentation=$full&&$student&&in_array($request->status,['draft','submitted','returned'],true)?$this->describeRequest($student,$request,false):null;
        $terminalSchedules=$full&&$presentation===null?$this->schedules->describeMany($request->items->pluck('replacementOffering')->filter()->values()):[];
        return ['student_registration_replacement_request_id'=>(int)$request->getKey(),'academic_calendar_event_id'=>(int)$request->academic_calendar_event_id,
            'status'=>$request->status,'submission_version'=>(int)$request->submission_version,'student_notes'=>$request->student_notes,'advisor_notes'=>$request->advisor_notes,
            'first_submitted_at'=>$request->first_submitted_at?->utc()->toIso8601String(),'last_submitted_at'=>$request->last_submitted_at?->utc()->toIso8601String(),
            'approved_at'=>$request->approved_at?->utc()->toIso8601String(),'expired_at'=>$request->expired_at?->utc()->toIso8601String(),
            'superseded_at'=>$request->superseded_at?->utc()->toIso8601String(),'materialized_at'=>$request->materialized_at?->utc()->toIso8601String(),
            'student'=>$student?->only(['student_id','student_number','full_name']),'academic_year'=>$request->academicYear?->only(['academic_year_id','year_name']),
            'semester'=>$request->semester?->only(['semester_id','semester_name']),
            'hours'=>$request->status==='approved'?$this->approvalSnapshot($request):($presentation['hours']??null),'failures'=>$presentation['failures']??[],
            'items'=>$full?$request->items->map(function(StudentRegistrationReplacementItem $item)use($presentation,$terminalSchedules){$targetId=(int)$item->replacement_course_offering_id;
                return ['student_registration_replacement_item_id'=>(int)$item->getKey(),'source_minimum_enrollment_review_id'=>(int)$item->source_minimum_enrollment_review_id,
                    'source_student_course_registration_id'=>(int)$item->source_student_course_registration_id,'replacement_course_offering_id'=>$targetId,
                    'source_consumed_slot'=>$item->source_consumed_slot,'materialized_student_course_registration_id'=>$item->materialized_student_course_registration_id,
                    'source_course'=>$item->sourceRegistration?->courseOffering?->course?->only(['course_code','course_name','credit_hours']),
                    'target_course'=>$item->replacementOffering?->course?->only(['course_code','course_name','credit_hours']),
                    'target_phase1_approved'=>!collect($presentation['failures']??[])->contains(fn($failure)=>(int)($failure['course_offering_id']??0)===$targetId&&($failure['reason']??null)==='replacement_target_not_finally_approved'),
                    'official_timetable'=>$presentation['schedules'][$targetId]['schedule']??($terminalSchedules[$targetId]??null),'timetable_conflicts'=>$presentation['schedules'][$targetId]['conflicts']??[],
                    'eligibility_failures'=>collect($presentation['failures']??[])->filter(fn($failure)=>(int)($failure['course_offering_id']??0)===$targetId)->values()->all()];
            })->values()->all():[],
            'events'=>$full?$request->events->map(fn(StudentRegistrationReplacementEvent $event)=>['event_type'=>$event->event_type,'from_status'=>$event->from_status,
                'to_status'=>$event->to_status,'submission_version'=>(int)$event->submission_version,'notes'=>$event->notes,
                'created_at'=>$event->created_at?->utc()->toIso8601String(),'actor'=>$event->actor===null?null:['username'=>$event->actor->username]])->values()->all():[]];
    }

    private function targetPayload(Student $student, CourseOffering $offering): array
    {
        $failures=[];$phase1Approved=true;
        try{$this->governance->assertFinallyApprovedForReplacement($offering);}catch(SemesterRegistrationPhase6Exception $e){$phase1Approved=false;$failures[]=['reason'=>$e->errorCode];}
        try{$request=new StudentRegistrationReplacementRequest(['academic_year_id'=>$offering->academic_year_id,'semester_id'=>$offering->semester_id]);$this->assertTarget($student,$request,$offering);}
        catch(SemesterRegistrationPhase6Exception $e){if(!collect($failures)->contains(fn($failure)=>$failure['reason']===$e->errorCode))$failures[]=['reason'=>$e->errorCode];}
        return ['course_offering_id'=>(int)$offering->getKey(),'course'=>$offering->course?->only(['course_code','course_name','credit_hours']),
            'phase1_finally_approved'=>$phase1Approved,'official_timetable'=>$this->schedules->describe($offering),'eligibility_failures'=>$failures];
    }

    private function sourcePayload(StudentCourseRegistration $registration): array
    {
        return ['student_course_registration_id'=>(int)$registration->getKey(),'minimum_review_id'=>(int)$registration->minimum_review_id,
            'course'=>$registration->courseOffering?->course?->only(['course_code','course_name','credit_hours'])];
    }

    private function staleReason(StudentRegistrationReplacementRequest $request, Student $student, CourseRegistrationDeadlineResult $deadline, bool $lock=false): ?string
    {
        if($deadline->academicCalendarEventId===null||(int)$deadline->academicCalendarEventId!==(int)$request->academic_calendar_event_id)
            return SemesterRegistrationPhase6::EVENT_REPLACEMENT_CALENDAR_CHANGED;
        try{if((int)$this->replacementEvent((int)$request->academic_year_id,(int)$request->semester_id,$lock)->getKey()!==(int)$request->academic_calendar_event_id)
            return SemesterRegistrationPhase6::EVENT_REPLACEMENT_CALENDAR_CHANGED;}
        catch(SemesterRegistrationPhase6Exception){return SemesterRegistrationPhase6::EVENT_REPLACEMENT_CALENDAR_CHANGED;}
        $query=StudentRegistrationReplacementItem::query()->where('student_registration_replacement_request_id',$request->getKey())->orderBy('student_registration_replacement_item_id');if($lock)$query->lockForUpdate();
        foreach($query->get() as $item){try{$this->lockEligibleSource($student,$request,(int)$item->source_student_course_registration_id,$lock,(int)$item->source_minimum_enrollment_review_id);}
            catch(SemesterRegistrationPhase6Exception){return SemesterRegistrationPhase6::EVENT_REPLACEMENT_SOURCE_CHANGED;}}
        return null;
    }

    private function supersede(StudentRegistrationReplacementRequest $request, ?User $actor, string $eventType): void
    {
        $from=$request->status;$request->update(['status'=>'superseded','current_slot'=>null,'superseded_at'=>CarbonImmutable::now('UTC')]);
        $this->event($request,$eventType,$actor,$from,'superseded');
    }

    private function persistSuperseded(int $requestId, ?User $actor, string $eventType): void
    {
        DB::transaction(function()use($requestId,$actor,$eventType){$request=StudentRegistrationReplacementRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if($request&&(int)$request->current_slot===1&&in_array($request->status,['draft','submitted','returned'],true))$this->supersede($request,$actor,$eventType);},3);
    }

    private function finishOutcome(array $outcome): array
    {
        if(($outcome['outcome']??null)==='stale')throw SemesterRegistrationPhase6Exception::stale(); return $outcome['payload'];
    }

    private function mapUnique(QueryException $exception): SemesterRegistrationPhase6Exception
    {
        $message=strtolower($exception->getMessage());
        if(str_contains($message,'uq_srrpi_source_consumed')||str_contains($message,'source_student_course_registration_id, student_registration_replacement_items.source_consumed_slot'))return SemesterRegistrationPhase6Exception::consumed();
        if(str_contains($message,'uq_srrpi_source_in_request')||str_contains($message,'student_registration_replacement_request_id, student_registration_replacement_items.source_student_course_registration_id'))return SemesterRegistrationPhase6Exception::duplicateSource();
        if(str_contains($message,'uq_srrpi_target_in_request')||str_contains($message,'student_registration_replacement_request_id, student_registration_replacement_items.replacement_course_offering_id'))return SemesterRegistrationPhase6Exception::duplicateTarget();
        throw $exception;
    }

    private function event(StudentRegistrationReplacementRequest $request, string $type, ?User $actor=null, ?string $from=null, ?string $to=null, ?string $notes=null): void
    {
        StudentRegistrationReplacementEvent::query()->create(['student_registration_replacement_request_id'=>$request->getKey(),'event_type'=>$type,
            'actor_user_id'=>$actor?->user_id,'from_status'=>$from,'to_status'=>$to,'submission_version'=>$request->submission_version,
            'notes'=>$notes,'created_at'=>CarbonImmutable::now('UTC')]);
    }

    private function expireCurrentIfClosed(int $studentId, int $yearId, int $semesterId, CourseRegistrationDeadlineResult $deadline): void
    {
        if($deadline->phase!==CourseRegistrationPhase::CLOSED)return;
        DB::transaction(function()use($studentId,$yearId,$semesterId,$deadline){$request=StudentRegistrationReplacementRequest::query()->where('student_id',$studentId)
            ->where('academic_year_id',$yearId)->where('semester_id',$semesterId)->where('current_slot',1)->lockForUpdate()->first();
            if(!$request||!in_array($request->status,['draft','submitted','returned'],true))return;$from=$request->status;
            $request->update(['status'=>'expired','current_slot'=>null,'expired_at'=>$deadline->evaluatedAt]);$this->event($request,'expired',null,$from,'expired');},3);
    }

    private function expireVisibleRequests(User $actor): void
    {
        $terms=StudentRegistrationReplacementRequest::query()->where('current_slot',1)->whereHas('student',fn(Builder $q)=>$this->scope->scopeStaffStudents($q,$actor))
            ->select(['academic_year_id','semester_id'])->distinct()->get();
        foreach($terms as $term){$deadline=$this->registration->courseRegistrationReplacementDeadlines((int)$term->academic_year_id,(int)$term->semester_id);
            if($deadline->phase!==CourseRegistrationPhase::CLOSED)continue;
            DB::transaction(function()use($actor,$term,$deadline){$requests=StudentRegistrationReplacementRequest::query()->where('academic_year_id',$term->academic_year_id)
                ->where('semester_id',$term->semester_id)->where('current_slot',1)->whereHas('student',fn(Builder $q)=>$this->scope->scopeStaffStudents($q,$actor))
                ->orderBy('student_registration_replacement_request_id')->lockForUpdate()->get();
                foreach($requests as $request){if(!in_array($request->status,['draft','submitted','returned'],true))continue;$from=$request->status;
                    $request->update(['status'=>'expired','current_slot'=>null,'expired_at'=>$deadline->evaluatedAt]);$this->event($request,'expired',null,$from,'expired');}},3);}
    }

    private function assertStudentOpen(int $yearId, int $semesterId): CourseRegistrationDeadlineResult
    {
        $deadline=$this->registration->courseRegistrationReplacementDeadlines($yearId,$semesterId);
        if($deadline->phase!==CourseRegistrationPhase::STUDENT_OPEN)throw SemesterRegistrationPhase6Exception::fail('registration_replacement_window_closed','Replacement student window is closed.');return $deadline;
    }

    private function approvalSnapshot(StudentRegistrationReplacementRequest $request): array
    {
        return ['registered_hours'=>$request->registered_hours_before_approval,'replacement_hours'=>$request->replacement_hours_at_approval,
            'projected_hours'=>$request->projected_hours_at_approval,'max_allowed_hours'=>$request->max_allowed_hours_at_approval,
            'remaining_hours'=>$request->remaining_hours_after_approval,'below_recommended_minimum'=>(int)$request->projected_hours_at_approval<RegistrationService::RECOMMENDED_MINIMUM_CREDIT_HOURS];
    }

    private function assertReady(): void {if(!SemesterRegistrationPhase6::schemaReady())throw SemesterRegistrationPhase6Exception::replacementSchema();}
    private function assertCanView(User $actor): void {if(!$actor->hasPermission('registration_requests.view'))throw new AccessDeniedHttpException('Forbidden.');}
    private function assertCanReview(User $actor): void {if(!$actor->hasPermission('registration_requests.review'))throw new AccessDeniedHttpException('Forbidden.');}
    private function assertAccess(User $actor, StudentRegistrationReplacementRequest $request): void
    {$request->loadMissing('student');if(!$request->student||!$this->scope->canStaffAccessStudent($actor,$request->student))throw new AccessDeniedHttpException('Forbidden.');}
}
