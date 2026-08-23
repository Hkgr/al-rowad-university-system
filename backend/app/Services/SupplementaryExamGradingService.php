<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\FacultyMember;
use App\Models\SupplementaryExamGradeEvent;
use App\Models\SupplementaryExamGradeResult;
use App\Models\SupplementaryExamGradeSubmission;
use App\Models\SupplementaryExamGraderAssignment;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamPeriodEvent;
use App\Models\SupplementaryExamRegistration;
use App\Models\User;
use App\Support\SupplementaryExamGradingGovernance as Governance;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 lock order: period, offering, current assignment, registrations,
 * results/current submission. It deliberately never writes regular grade tables.
 */
class SupplementaryExamGradingService
{
    public function __construct(private readonly GradeService $grades, private readonly DataScopeService $scope) {}

    public function professorOfferings(User $actor)
    {
        $this->professor($actor, Governance::VIEW);
        $facultyId = $this->facultyId($actor);
        return SupplementaryExamOffering::query()->with(['period','course','academicProgram'])
            ->whereHas('graderAssignments', fn ($q) => $q->where('faculty_member_id', $facultyId)->where('current_slot', 1))
            ->orderBy('supplementary_exam_offering_id')->get();
    }

    public function roster(User $actor, SupplementaryExamOffering $offering): array
    {
        $this->professor($actor, Governance::VIEW);
        $this->assertAssigned($actor, $offering);
        return $this->rosterPayload($offering);
    }

    public function reviewQueue(User $actor): array
    {
        $this->exam($actor, Governance::REVIEW);
        $offerings = SupplementaryExamOffering::query()->with(['period','course','academicProgram'])
            ->whereHas('period', fn ($q) => $q->whereIn('status', Governance::PERIOD_STATUSES))
            ->orderBy('supplementary_exam_offering_id')->get()
            ->filter(fn ($o) => $this->scope->canMutateProgram($actor, (int) $o->academic_program_id));
        return $offerings->map(fn ($o) => $this->rosterPayload($o))->values()->all();
    }

    public function saveDrafts(User $actor, SupplementaryExamOffering $seed, array $marks): array
    {
        $this->professor($actor, Governance::ENTER); $this->ready();
        return DB::transaction(function () use ($actor, $seed, $marks) {
            [$period,$offering] = $this->lockGraph($seed);
            $this->assertAssigned($actor, $offering, true);
            if ($period->status !== 'grading_open') $this->fail('التصحيح غير مفتوح.','supplementary_grading_not_open',409);
            $registrations = $this->lockedRoster($offering);
            $allowed = $registrations->keyBy('supplementary_exam_registration_id');
            $limits = $this->grades->gradingPolicyLimits();
            foreach ($marks as $item) {
                $id = (int) ($item['supplementary_exam_registration_id'] ?? 0);
                $registration = $allowed->get($id);
                if (! $registration) $this->fail('التسجيل ليس ضمن القائمة المثبتة.','supplementary_grade_registration_invalid',422);
                $mark = filter_var($item['theoretical_mark'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($mark === false || $mark < 0 || $mark > $limits['theoretical_max_mark']) $this->fail('العلامة النظرية خارج المجال المعتمد.','supplementary_theoretical_mark_out_of_range',422);
                $result = SupplementaryExamGradeResult::query()->where('supplementary_exam_registration_id', $id)->lockForUpdate()->first();
                if ($result && ! in_array($result->status, ['draft','returned'], true)) $this->fail('العلامات مقفلة في هذه الحالة.','supplementary_grade_locked',409);
                $from = $result?->status;
                $result ??= new SupplementaryExamGradeResult(['supplementary_exam_registration_id'=>$id]);
                $result->fill(['supplementary_exam_offering_id'=>$offering->getKey(),'student_course_registration_id'=>$registration->student_course_registration_id,'student_id'=>$registration->student_id,'theoretical_mark'=>round((float)$mark,2),'status'=>$from === 'returned' ? 'returned' : 'draft','submission_version'=>(int)($result->submission_version ?: 1),'last_edited_by_user_id'=>$actor->user_id]);
                $result->save();
                $this->event($result, null, 'draft_saved', $from, $result->status, $actor);
            }
            return $this->rosterPayload($offering->fresh());
        }, 3);
    }

    public function submit(User $actor, SupplementaryExamOffering $seed, bool $resubmit = false): array
    {
        $this->professor($actor, Governance::ENTER); $this->ready();
        return DB::transaction(function () use ($actor,$seed,$resubmit) {
            [$period,$offering] = $this->lockGraph($seed); $assignment = $this->assertAssigned($actor,$offering,true);
            if ($period->status !== 'grading_open') $this->fail('التصحيح غير مفتوح.','supplementary_grading_not_open',409);
            $registrations = $this->lockedRoster($offering);
            if ($registrations->isEmpty()) $this->fail('قائمة التسجيل فارغة.','supplementary_grade_roster_empty',409);
            $results = SupplementaryExamGradeResult::query()->where('supplementary_exam_offering_id',$offering->getKey())->orderBy('supplementary_exam_grade_result_id')->lockForUpdate()->get()->keyBy('supplementary_exam_registration_id');
            $current = SupplementaryExamGradeSubmission::query()->where('supplementary_exam_offering_id',$offering->getKey())->orderByDesc('submission_version')->lockForUpdate()->first();
            if ($resubmit && $current?->status !== 'returned') $this->fail('إعادة الإرسال متاحة فقط بعد الإرجاع.','supplementary_grade_not_returned',409);
            if (! $resubmit && $current) $this->fail('تم إرسال هذه الدفعة مسبقاً.','supplementary_grade_already_submitted',409);
            foreach ($registrations as $registration) {
                $result = $results->get($registration->getKey());
                if (! $result || $result->theoretical_mark === null || ! in_array($result->status,['draft','returned'],true)) $this->fail('يجب استكمال جميع علامات القائمة المثبتة.','supplementary_grade_batch_incomplete',422);
            }
            if ($results->count() !== $registrations->count()) $this->fail('تتضمن الدفعة تسجيلات خارج القائمة المثبتة.','supplementary_grade_roster_mismatch',409);
            $version = $current ? (int)$current->submission_version + 1 : 1;
            $submission = SupplementaryExamGradeSubmission::query()->create(['supplementary_exam_offering_id'=>$offering->getKey(),'grader_assignment_id'=>$assignment->getKey(),'submission_version'=>$version,'status'=>'submitted','submitted_by_user_id'=>$actor->user_id,'submitted_at'=>now()]);
            foreach ($results as $result) { $from=$result->status; $result->update(['status'=>'submitted','submission_version'=>$version]); $this->event($result,$submission,'submitted',$from,'submitted',$actor); }
            if ($this->allPeriodOfferingsAt($period, ['submitted','approved','published'])) $this->periodStatus($period,$actor,'grading_submitted','grading_submitted');
            return $this->rosterPayload($offering->fresh());
        }, 3);
    }

    public function review(User $actor, int $submissionId, string $action, ?string $reason = null): array
    {
        $permission = $action === 'publish' ? Governance::PUBLISH : Governance::REVIEW;
        $this->exam($actor,$permission); $this->ready();
        return DB::transaction(function () use ($actor,$submissionId,$action,$reason) {
            $seed=SupplementaryExamGradeSubmission::query()->findOrFail($submissionId);
            $offeringSeed=SupplementaryExamOffering::query()->findOrFail($seed->supplementary_exam_offering_id);
            [$period,$offering]=$this->lockGraph($offeringSeed);
            if(!$this->scope->canMutateProgram($actor,(int)$offering->academic_program_id))$this->fail('خارج نطاق الصلاحية.','supplementary_grade_out_of_scope',403);
            SupplementaryExamGraderAssignment::query()->where('supplementary_exam_offering_id',$offering->getKey())->where('current_slot',1)->lockForUpdate()->first();
            $submission=SupplementaryExamGradeSubmission::query()->whereKey($submissionId)->lockForUpdate()->firstOrFail();
            $latest=SupplementaryExamGradeSubmission::query()->where('supplementary_exam_offering_id',$offering->getKey())->max('submission_version');
            if((int)$submission->submission_version!==(int)$latest)$this->fail('لا يمكن مراجعة إصدار قديم.','supplementary_grade_stale_submission',409);
            $results=SupplementaryExamGradeResult::query()->where('supplementary_exam_offering_id',$offering->getKey())->orderBy('supplementary_exam_grade_result_id')->lockForUpdate()->get();
            $from=$submission->status;
            if($action==='return') { if($from!=='submitted'||trim((string)$reason)==='')$this->fail('سبب الإرجاع مطلوب والحالة يجب أن تكون مرسلة.','supplementary_grade_return_invalid',422); $to='returned'; }
            elseif($action==='approve') { if($from!=='submitted')$this->fail('لا يمكن اعتماد هذه الحالة.','supplementary_grade_approve_invalid',409); $to='approved'; }
            elseif($action==='publish') { if($from==='published') return $this->rosterPayload($offering); if($from!=='approved')$this->fail('يجب الاعتماد قبل النشر.','supplementary_grade_publish_invalid',409); $to='published'; }
            else $this->fail('إجراء غير صالح.','supplementary_grade_action_invalid',422);
            $submission->update(['status'=>$to,'reviewed_by_user_id'=>$action==='publish'?$submission->reviewed_by_user_id:$actor->user_id,'reviewed_at'=>$action==='publish'?$submission->reviewed_at:now(),'review_reason'=>$reason,'published_by_user_id'=>$action==='publish'?$actor->user_id:null,'published_at'=>$action==='publish'?now():null]);
            foreach($results as $result){if((int)$result->submission_version!==(int)$submission->submission_version)$this->fail('إصدار النتيجة لا يطابق الدفعة.','supplementary_grade_version_mismatch',409);$rf=$result->status;$result->update(['status'=>$to,'published_at'=>$action==='publish'?now():null]);$this->event($result,$submission,$to,$rf,$to,$actor,$reason);}
            if ($action === 'return') $this->periodStatus($period,$actor,'grading_open','grading_returned');
            elseif ($action === 'approve' && $this->allPeriodOfferingsAt($period,['approved','published'])) $this->periodStatus($period,$actor,'results_approved','grading_approved');
            elseif ($action === 'publish' && $this->allPeriodOfferingsAt($period,['published'])) $this->periodStatus($period,$actor,'results_published','grading_published');
            return $this->rosterPayload($offering->fresh());
        },3);
    }

    public function openGrading(User $actor, SupplementaryExamPeriod $seed): SupplementaryExamPeriod
    {
        $this->exam($actor,Governance::REVIEW); $this->ready();
        return DB::transaction(function()use($actor,$seed){$period=SupplementaryExamPeriod::query()->lockForUpdate()->findOrFail($seed->getKey());if($period->status==='grading_open')return $period;if($period->status!=='registration_closed')$this->fail('يجب تثبيت قائمة التسجيل أولاً.','supplementary_grading_open_invalid',409);$period->supplementaryExamOfferings()->orderBy('supplementary_exam_offering_id')->lockForUpdate()->get();$this->periodStatus($period,$actor,'grading_open','grading_opened');return $period->fresh();},3);
    }

    public function assign(User $actor, SupplementaryExamOffering $seed, int $facultyId): SupplementaryExamGraderAssignment
    {
        $this->exam($actor,Governance::ASSIGN); $this->ready();
        return DB::transaction(function()use($actor,$seed,$facultyId){[$period,$offering]=$this->lockGraph($seed);if(!in_array($period->status,['registration_closed','grading_open'],true))$this->fail('لا يمكن تغيير المصحح في هذه الحالة.','supplementary_grader_assignment_locked',409);if(!$this->scope->canMutateProgram($actor,(int)$offering->academic_program_id))$this->fail('خارج النطاق.','supplementary_grade_out_of_scope',403);$faculty=FacultyMember::query()->whereKey($facultyId)->where('is_active',true)->lockForUpdate()->firstOrFail();$hasProfessor=User::query()->where('employee_id',$faculty->employee_id)->whereHas('userRoleRecords',fn($q)=>$q->where('is_active',true)->whereHas('role',fn($r)=>$r->where('role_code','doctor_instructor')->where('is_active',true)))->exists();if(!$hasProfessor)$this->fail('المصحح ليس عضو هيئة تدريس فعالاً.','supplementary_grader_invalid',422);$current=SupplementaryExamGraderAssignment::query()->where('supplementary_exam_offering_id',$offering->getKey())->where('current_slot',1)->lockForUpdate()->first();if($current&&(int)$current->faculty_member_id===$facultyId)return $current;if($current)$current->update(['current_slot'=>null,'ended_at'=>now()]);return SupplementaryExamGraderAssignment::query()->create(['supplementary_exam_offering_id'=>$offering->getKey(),'faculty_member_id'=>$facultyId,'current_slot'=>1,'assigned_by_user_id'=>$actor->user_id,'assigned_at'=>now()]);},3);
    }

    private function rosterPayload(SupplementaryExamOffering $offering): array
    {
        $offering->loadMissing(['period','course','academicProgram']);
        $registrations=SupplementaryExamRegistration::query()->with(['student','originalRegistration.studentCourseResult','gradeResult'])->where('supplementary_exam_offering_id',$offering->getKey())->where('status','registered')->where('current_slot',1)->orderBy('supplementary_exam_registration_id')->get();
        $submission=SupplementaryExamGradeSubmission::query()->where('supplementary_exam_offering_id',$offering->getKey())->orderByDesc('submission_version')->first();
        return ['offering'=>$offering,'workflow_status'=>$submission?->status ?? 'waiting','submission'=>$submission,'roster'=>$registrations->map(function($r){$practical=$r->originalRegistration?->studentCourseResult?->practical_total;$theory=$r->gradeResult?->theoretical_mark;$preview=$theory===null?null:$this->grades->buildCalculation((float)$theory,$practical===null?null:(float)$practical);return ['supplementary_exam_registration_id'=>$r->getKey(),'supplementary_exam_grade_result_id'=>$r->gradeResult?->getKey(),'student'=>$r->student,'preserved_practical_mark'=>$practical,'supplementary_theoretical_mark'=>$theory,'result_status'=>$r->gradeResult?->status,'submission_version'=>$r->gradeResult?->submission_version,'preview'=>$preview,'official_record_materialized'=>false];})->all()];
    }
    private function lockGraph($seed):array{$offering0=SupplementaryExamOffering::query()->findOrFail($seed->getKey());$period=SupplementaryExamPeriod::query()->lockForUpdate()->findOrFail($offering0->supplementary_exam_period_id);$offering=SupplementaryExamOffering::query()->lockForUpdate()->findOrFail($seed->getKey());return[$period,$offering];}
    private function lockedRoster($offering){return SupplementaryExamRegistration::query()->where('supplementary_exam_offering_id',$offering->getKey())->where('status','registered')->where('current_slot',1)->orderBy('supplementary_exam_registration_id')->lockForUpdate()->get();}
    private function assertAssigned(User $u,$o,bool $locked=false){$q=SupplementaryExamGraderAssignment::query()->where('supplementary_exam_offering_id',$o->getKey())->where('faculty_member_id',$this->facultyId($u))->where('current_slot',1);if($locked)$q->lockForUpdate();$a=$q->first();if(!$a)$this->fail('لست المصحح المكلف بهذا المقرر.','supplementary_grader_not_assigned',403);return$a;}
    private function facultyId(User $u):int{$id=(int)FacultyMember::query()->where('employee_id',$u->employee_id)->where('is_active',true)->value('faculty_member_id');if(!$id)$this->fail('هوية عضو هيئة التدريس غير فعالة.','supplementary_grader_identity_invalid',403);return$id;}
    private function professor(User $u,string $p):void{if(!$u->isProfessor()||!$u->effectivePermissions()->contains($p))$this->fail('يتطلب دور أستاذ فعلي وصلاحية مسندة.','supplementary_professor_forbidden',403);}
    private function exam(User $u,string $p):void{if(!$u->isExamOfficer()||!$u->effectivePermissions()->contains($p))$this->fail('يتطلب دور موظف امتحانات فعلي وصلاحية مسندة.','supplementary_exam_officer_forbidden',403);}
    private function ready():void{if(!Governance::schemaReady())$this->fail('مخطط تصحيح التكميلي غير جاهز.','supplementary_grading_schema_not_ready',503);}
    private function periodStatus($p,$u,$to,$type):void{$from=$p->status;if($from===$to)return;$p->forceFill(['status'=>$to])->save();SupplementaryExamPeriodEvent::query()->create(['supplementary_exam_period_id'=>$p->getKey(),'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'actor_user_id'=>$u->user_id,'created_at'=>now()]);}
    private function allPeriodOfferingsAt($period,array $statuses):bool{$ids=SupplementaryExamOffering::query()->where('supplementary_exam_period_id',$period->getKey())->whereHas('registrations',fn($q)=>$q->where('status','registered')->where('current_slot',1))->pluck('supplementary_exam_offering_id');if($ids->isEmpty())return false;return $ids->every(fn($id)=>SupplementaryExamGradeSubmission::query()->where('supplementary_exam_offering_id',$id)->whereIn('status',$statuses)->whereRaw('submission_version = (SELECT MAX(s2.submission_version) FROM supplementary_exam_grade_submissions s2 WHERE s2.supplementary_exam_offering_id = supplementary_exam_grade_submissions.supplementary_exam_offering_id)')->exists());}
    private function event($r,$s,$type,$from,$to,$u,$notes=null):void{SupplementaryExamGradeEvent::query()->create(['supplementary_exam_grade_result_id'=>$r->getKey(),'supplementary_exam_grade_submission_id'=>$s?->getKey(),'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'submission_version'=>$r->submission_version,'theoretical_mark'=>$r->theoretical_mark,'actor_user_id'=>$u->user_id,'notes'=>$notes,'created_at'=>now()]);}
    private function fail(string $m,string $c,int $s):never{throw new GradeException($m,status:$s,errorCode:$c);}
}
