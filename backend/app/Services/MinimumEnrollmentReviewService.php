<?php

namespace App\Services;

use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Models\CourseOffering;
use App\Models\CourseOfferingMinimumEnrollmentEvent;
use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Models\SemesterOfferingRequest;
use App\Models\User;
use App\Support\CourseRegistrationPhase;
use App\Support\SemesterOfferingGovernance;
use App\Support\SemesterRegistrationPhase6;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MinimumEnrollmentReviewService
{
    public function __construct(
        private AcademicCalendarPolicyService $calendar,
        private DataScopeService $scope,
        private CourseOfferingClosureWorkflowService $closures,
        private SemesterOfferingGovernanceService $governance,
    ) {}

    public function assertDeanAccess(User $actor): void { $this->assertReady(); $this->assertDeanView($actor); }
    public function assertScientificAccess(User $actor): void { $this->assertReady(); $this->assertScientific($actor); }

    public function reconcileTerm(int $yearId, int $semesterId, ?array $allowedOfferingIds = null): void
    {
        $this->assertReady();
        $deadline = $this->calendar->courseRegistrationDeadlines($yearId, $semesterId);
        if ($deadline->phase !== CourseRegistrationPhase::CLOSED || $deadline->advisorApprovalEndsAt === null) {
            throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_not_finalizable', 'Minimum enrollment becomes final only after the advisor deadline closes.');
        }

        $ids = SemesterOfferingRequest::query()
            ->where('status', SemesterOfferingGovernance::STATUS_APPROVED)
            ->where('is_selected', true)->whereNotNull('minimum_enrollment')->whereNotNull('materialized_at')
            ->whereHas('courseOffering', fn (Builder $q) => $q->where('academic_year_id', $yearId)->where('semester_id', $semesterId))
            ->when($allowedOfferingIds !== null, fn (Builder $q) => $q->whereIn('course_offering_id', $allowedOfferingIds))
            ->orderBy('course_offering_id')->pluck('course_offering_id')->map(fn ($id)=>(int)$id)->all();

        DB::transaction(function () use ($ids, $deadline, $yearId, $semesterId): void {
            foreach ($ids as $offeringId) {
                $offering = CourseOffering::query()->whereKey($offeringId)->lockForUpdate()->firstOrFail();
                $request = SemesterOfferingRequest::query()->where('course_offering_id', $offeringId)->lockForUpdate()->firstOrFail();
                $existing = CourseOfferingMinimumEnrollmentReview::query()->where('course_offering_id', $offeringId)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($offering->status === 'closed' && in_array($existing->status, ['under_minimum','dean_recommended','closure_pending'], true)) $this->supersede($existing);
                    continue;
                }
                $this->governance->assertMinimumEnrollmentApplicability($offering,$request);
                $count = $offering->studentCourseRegistrations()
                    ->current()
                    ->orderBy('student_course_registration_id')
                    ->lockForUpdate()
                    ->get(['student_course_registration_id'])
                    ->count();
                $status = $offering->status === 'closed' ? 'superseded' : ($count >= (int)$request->minimum_enrollment ? 'satisfied' : 'under_minimum');
                $now = now();
                $review = CourseOfferingMinimumEnrollmentReview::query()->create([
                    'semester_offering_request_id'=>$request->getKey(),'course_offering_id'=>$offeringId,'academic_year_id'=>$yearId,'semester_id'=>$semesterId,
                    'minimum_enrollment_snapshot'=>(int)$request->minimum_enrollment,'enrolled_count_snapshot'=>$count,
                    'finalization_deadline_at'=>$deadline->advisorApprovalEndsAt,'finalized_at'=>$now,'status'=>$status,
                    'superseded_at'=>$status==='superseded'?$now:null,
                ]);
                $this->event($review, $status==='satisfied'?'finalized_satisfied':($status==='under_minimum'?'finalized_under_minimum':'superseded_external_closure'));
            }
        }, 3);
    }

    public function query(User $actor, int $yearId, int $semesterId, bool $scientific = false): Builder
    {
        $scientific ? $this->assertScientific($actor) : $this->assertDeanView($actor);
        $allowedOfferingIds = $scientific ? null : $this->scope->scopeOfferings(CourseOffering::query(), $actor)->pluck('course_offering_id')->map(fn ($id)=>(int)$id)->all();
        $this->reconcileTerm($yearId, $semesterId, $allowedOfferingIds);
        $query = CourseOfferingMinimumEnrollmentReview::query()->with(['courseOffering.course','courseOffering.academicProgram.department.college','semesterOfferingRequest','closureRequest','dean','scientificUser']);
        $query->where('academic_year_id',$yearId)->where('semester_id',$semesterId);
        if (! $scientific) $query->whereIn('course_offering_id', $allowedOfferingIds);
        return $query;
    }

    public function recommend(User $actor, CourseOfferingMinimumEnrollmentReview $route, string $recommendation, string $notes): array
    {
        if (!in_array($recommendation,['continue','cancel'],true) || trim($notes)==='') throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_recommendation_invalid','Recommendation and reason are required.',422);
        $review = DB::transaction(function () use ($actor,$route,$recommendation,$notes) {
            $offering = CourseOffering::query()->whereKey($route->course_offering_id)->lockForUpdate()->firstOrFail();
            $locked = CourseOfferingMinimumEnrollmentReview::query()->whereKey($route->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->course_offering_id !== (int) $offering->getKey()) throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_review_stale','Minimum review identity changed.');
            $this->assertDeanManage($actor,$offering);
            if (!in_array($locked->status,['under_minimum','dean_recommended'],true) || $locked->scientific_decision!==null || $offering->status!=='open') throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_review_stale','Minimum review is no longer editable.');
            $updated=$locked->dean_recommendation!==null;
            $locked->update(['status'=>'dean_recommended','dean_recommendation'=>$recommendation,'dean_user_id'=>$actor->user_id,'dean_notes'=>trim($notes),'dean_recommended_at'=>now()]);
            $this->event($locked,$updated?'dean_recommendation_updated':'dean_recommended_'.$recommendation,$actor,trim($notes));
            return $locked;
        },3);
        return $this->payload($review);
    }

    public function decide(User $actor, CourseOfferingMinimumEnrollmentReview $route, string $decision, string $notes): array
    {
        $this->assertScientific($actor);
        if (!in_array($decision,['continue','cancel'],true) || trim($notes)==='') throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_decision_invalid','Decision and reason are required.',422);
        $review = DB::transaction(function () use ($actor,$route,$decision,$notes) {
            $offering=CourseOffering::query()->whereKey($route->course_offering_id)->lockForUpdate()->firstOrFail();
            $locked=CourseOfferingMinimumEnrollmentReview::query()->whereKey($route->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->course_offering_id !== (int) $offering->getKey()) throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_review_stale','Minimum review identity changed.');
            if ($locked->status!=='dean_recommended' || $locked->dean_user_id===null || $offering->status!=='open') throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_review_stale','Minimum review is no longer decidable.');
            $locked->scientific_decision=$decision; $locked->scientific_user_id=$actor->user_id; $locked->scientific_notes=trim($notes); $locked->scientific_decided_at=now();
            if ($decision==='continue') { $locked->status='continued_exceptionally'; $locked->continued_at=now(); $locked->save(); $this->event($locked,'scientific_continued_exceptionally',$actor,trim($notes)); }
            else { $locked->save(); $closure=$this->closures->createFromMinimumEnrollmentCancellationWithinTransaction($actor,$locked); $locked->status='closure_pending'; $locked->course_offering_closure_request_id=$closure->getKey(); $locked->save(); $this->event($locked,'closure_linked',$actor,trim($notes)); }
            return $locked;
        },3);
        return $this->payload($review);
    }

    public function assertReplacementWindowReady(int $yearId,int $semesterId): void
    {
        $this->reconcileTerm($yearId,$semesterId);
        if (CourseOfferingMinimumEnrollmentReview::query()->where('academic_year_id',$yearId)->where('semester_id',$semesterId)->whereNotIn('status',SemesterRegistrationPhase6::TERMINAL_MINIMUM_STATUSES)->exists()) throw SemesterRegistrationPhase6Exception::fail('replacement_window_not_ready','Minimum-enrollment decisions or closures remain unresolved.');
        if (!CourseOfferingMinimumEnrollmentReview::query()->where('academic_year_id',$yearId)->where('semester_id',$semesterId)->where('status','cancelled')->where('affected_registration_count','>',0)->exists()) throw SemesterRegistrationPhase6Exception::fail('replacement_window_not_required','No students require replacement.');
    }

    public function payload(CourseOfferingMinimumEnrollmentReview $r): array
    {
        $r->loadMissing(['courseOffering.course','courseOffering.academicProgram.department.college','closureRequest','dean','scientificUser']);
        return ['course_offering_minimum_enrollment_review_id'=>$r->getKey(),'course_offering_id'=>$r->course_offering_id,'academic_year_id'=>$r->academic_year_id,'semester_id'=>$r->semester_id,'minimum_enrollment_snapshot'=>$r->minimum_enrollment_snapshot,'enrolled_count_snapshot'=>$r->enrolled_count_snapshot,'finalization_deadline_at'=>$r->finalization_deadline_at?->toIso8601String(),'finalized_at'=>$r->finalized_at?->toIso8601String(),'status'=>$r->status,'dean_recommendation'=>$r->dean_recommendation,'dean_notes'=>$r->dean_notes,'scientific_decision'=>$r->scientific_decision,'scientific_notes'=>$r->scientific_notes,'affected_registration_count'=>$r->affected_registration_count,'closure_request_id'=>$r->course_offering_closure_request_id,'closure_status'=>$r->closureRequest?->status,'course'=>$r->courseOffering?->course?->only(['course_id','course_code','course_name']),'program'=>$r->courseOffering?->academicProgram?->only(['academic_program_id','program_name'])];
    }

    private function supersede(CourseOfferingMinimumEnrollmentReview $r): void { $r->update(['status'=>'superseded','superseded_at'=>now()]); $this->event($r,'superseded_external_closure'); }
    private function event($r,string $type,?User $actor=null,?string $notes=null): void { CourseOfferingMinimumEnrollmentEvent::query()->create(['course_offering_minimum_enrollment_review_id'=>$r->getKey(),'event_type'=>$type,'actor_user_id'=>$actor?->user_id,'notes'=>$notes,'created_at'=>now()]); }
    private function assertReady(): void { if(!SemesterRegistrationPhase6::schemaReady()) throw SemesterRegistrationPhase6Exception::minimumSchema(); }
    private function assertDeanView(User $u): void { if(!$u->isDean() || !$u->effectivePermissions()->contains(SemesterOfferingGovernance::PERMISSION_VIEW)) throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_forbidden','Forbidden.',403); }
    private function assertDeanManage(User $u,CourseOffering $o): void { if(!$u->isDean() || !$u->effectivePermissions()->contains(SemesterOfferingGovernance::PERMISSION_MANAGE) || !$this->scope->canAccessOffering($u,$o)) throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_forbidden','Forbidden.',403); }
    private function assertScientific(User $u): void { if(!$u->isScientificVicePresident() || !$u->effectivePermissions()->contains(SemesterOfferingGovernance::PERMISSION_REVIEW_SCIENTIFIC) || !$this->scope->hasActualUniversityScope($u)) throw SemesterRegistrationPhase6Exception::fail('minimum_enrollment_forbidden','Forbidden.',403); }
}
