<?php

namespace App\Services\HistoricalExams;

use App\Exceptions\GradeException;
use App\Models\{Course, CourseOffering, Student, StudentCourseRegistration, User};
use App\Services\{AcademicCatalogTransaction, DataScopeService, GradeService};
use App\Services\StudentCompletion\{CompletionData, CompletionReceipts};
use App\Support\{ExamManualGradeEntryAccess, HistoricalExamSchema as Tables, HistoricalOutcomeGuard, IncompleteOutcomeGuard, SupplementaryExamTargetGuard};
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Private source work is durable and paginated; staging never changes an official outcome. */
final class HistoricalExamWorkService
{
    public const REVIEW = 'historical-exam-correction.review.v1';
    public function __construct(private ExamManualGradeEntryAccess $access,private DataScopeService $scope,private AcademicCatalogTransaction $tx) {}
    private function fail(string $code,string $message='Historical source work changed; prepare and review a new proposal.'): never
    {
        throw new GradeException($message,status:409,errorCode:'historical_exam_'.$code);
    }

    public function find(User $actor,string $id,bool $lock=false): object
    {
        $this->access->authorize($actor);Tables::requireReady();
        $query=DB::table(Tables::WORK)->where('work_item_id',$id);
        $work=($lock?$query->lockForUpdate():$query)->first();abort_unless($work,404);
        $student=Student::findOrFail($work->student_id);$this->access->authorize($actor,$student);
        abort_unless($this->scope->scopeManualGradeCourses(Course::query(),$actor)->whereKey($work->course_id)->exists(),403);
        if($work->course_offering_id)$this->access->authorizeOffering($actor,$student,CourseOffering::findOrFail($work->course_offering_id));
        else abort_unless($this->scope->scopeProgramsForMutation(\App\Models\AcademicProgram::query(),$actor)->whereKey($work->academic_program_id)->exists(),403);
        return $work;
    }

    public function stage(User $actor,array $bundle,array $review,array $action,string $operation): array
    {
        Tables::requireReady();
        if(DB::transactionLevel()===0||$action['execution_kind']!=='stage')$this->fail('staging_boundary_required');
        app(ExamCompletionService::class)->authorizeReview($actor,['actions'=>[$action]]);
        $proposal=CompletionData::canonical(['contract'=>'historical-exam-work.v1','bundle_digest'=>$review['bundle_digest'],
            'bundle_file_hash'=>$review['bundle_file_hash'],'sources'=>$bundle['sources'],'authority_hashes'=>$review['authority_hashes'],'action'=>$action]);
        $work=DB::table(Tables::WORK)->where('action_id',$action['action_id'])->where('baseline_hash',$action['baseline_hash'])->first();
        if($work) {
            if(!hash_equals(CompletionData::hash($proposal),CompletionData::hash(json_decode($work->proposal,true,flags:JSON_THROW_ON_ERROR))))$this->fail('staged_intent_changed');
        } else {
            $base=$action['baseline'];$id=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$operation.'/'.$action['action_id']);
            DB::table(Tables::WORK)->insert(['work_item_id'=>$id,'operation_id'=>$operation,'action_id'=>$action['action_id'],'actor_user_id'=>$actor->getKey(),
                'student_id'=>$base['student']['student_id'],'course_id'=>$base['course']['course_id'],'academic_program_id'=>$base['program_id'],
                'academic_year_id'=>$action['period']['academic_year_id'],'semester_id'=>$action['period']['semester_id'],
                'course_offering_id'=>$base['offering']['course_offering_id']??null,'student_course_registration_id'=>$base['registration']['student_course_registration_id']??null,
                'kind'=>$action['classification'],'source_reference'=>$action['source_id'],'baseline_hash'=>$action['baseline_hash'],'proposal'=>CompletionData::json($proposal),'created_at'=>now('UTC')->format('Y-m-d H:i:s')]);
            $work=DB::table(Tables::WORK)->where('work_item_id',$id)->first();
        }
        return ['action_id'=>$action['action_id'],'source_id'=>$action['source_id'],'status'=>'STAGED_PENDING_REVIEW',
            'work_item_id'=>$work->work_item_id,'work_item_hash'=>CompletionData::hash((array)$work),'official_result_changed'=>false,'incomplete_case_created'=>false];
    }

    /** Every list query independently scopes the person, course and optional offering before pagination. */
    public function listing(User $actor,array $filters): array
    {
        $this->access->authorize($actor);Tables::requireReady();
        return $this->tx->snapshot(function()use($actor,$filters) {
            $query=DB::table(Tables::WORK.' as w')->whereIn('w.student_id',$this->scope->scopeManualGradeStudents(Student::query(),$actor)->select('student_id'))
                ->whereIn('w.course_id',$this->scope->scopeManualGradeCourses(Course::query(),$actor)->select('course_id'))
                ->where(fn($q)=>$q->where(fn($none)=>$none->whereNull('w.course_offering_id')->whereIn('w.academic_program_id',$this->scope->scopeProgramsForMutation(\App\Models\AcademicProgram::query(),$actor)->select('academic_program_id')))
                    ->orWhereIn('w.course_offering_id',$this->scope->scopeManualGradeOfferings(CourseOffering::query(),$actor)->select('course_offering_id')))
                ->whereIn('w.kind',['ungraded_followup','aggregate_incomplete_followup','final_absence_review'])
                ->join('students as s','s.student_id','=','w.student_id')->join('courses as c','c.course_id','=','w.course_id')
                ->join('academic_years as y','y.academic_year_id','=','w.academic_year_id')->join('semesters as t','t.semester_id','=','w.semester_id')
                ->leftJoin('student_course_results as result','result.student_course_registration_id','=','w.student_course_registration_id')
                ->leftJoin('result_statuses as status','status.result_status_id','=','result.result_status_id');
            $terms=(clone $query)->select('w.academic_year_id','y.year_name','w.semester_id','t.semester_name')->distinct()->orderBy('w.academic_year_id')->orderBy('w.semester_id')->get();
            foreach(['student_id','academic_year_id','semester_id'] as $key)if(isset($filters[$key]))$query->where('w.'.$key,$filters[$key]);
            if(isset($filters['kind']))$query->where('w.kind',$filters['kind']);
            $page=$query->orderByDesc('w.created_at')->orderBy('w.work_item_id')->paginate($filters['per_page']??20,
                ['w.work_item_id','w.source_reference','w.kind','w.created_at','s.student_id','s.student_number','s.first_name','s.last_name','c.course_id','c.course_code','c.course_name','y.year_name','t.semester_name','status.status_code','result.final_mark',
                    DB::raw('(SELECT a.historical_outcome_id FROM '.Tables::OUTCOMES.' a WHERE a.work_item_id=w.work_item_id ORDER BY a.revision DESC LIMIT 1) AS historical_outcome_id'),
                    DB::raw("(result.student_course_result_id IS NOT NULL AND EXISTS(SELECT 1 FROM grade_approvals ga JOIN approval_statuses aps ON aps.approval_status_id=ga.approval_status_id WHERE ga.course_offering_id=w.course_offering_id AND ga.grade_approval_id=(SELECT MAX(last_ga.grade_approval_id) FROM grade_approvals last_ga WHERE last_ga.course_offering_id=w.course_offering_id) AND aps.status_code='approved')) AS is_official")],page:$filters['page']??1);
            $items=collect($page->items())->map(function($row){$row->is_official=(bool)(int)$row->is_official;return $row;})->all();
            return ['items'=>$items,'meta'=>['total'=>$page->total(),'current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage()],
                'filter_options'=>['terms'=>$terms],
                'definition'=>'Imported source work is not a documented Incomplete grant. Only explicitly staged, authorized work is listed.'];
        });
    }

    public function details(User $actor,string $id): array
    {
        $this->access->authorize($actor);Tables::requireReady();
        return $this->tx->snapshot(function()use($actor,$id) {
            $work=$this->find($actor,$id);$proposal=json_decode($work->proposal,true,flags:JSON_THROW_ON_ERROR);$a=$proposal['action'];
            $current=$work->student_course_registration_id?app(\App\Services\GradePartWorkflowService::class)->manualSnapshots(collect([StudentCourseRegistration::findOrFail($work->student_course_registration_id)]))->first():null;
            return ['work_item_id'=>$id,'kind'=>$work->kind,'source_reference'=>$work->source_reference,'source_values'=>$a['source_values'],
                'assessment_groups'=>$a['component_mapping']['groups'],'absence_evidence'=>$a['absence_evidence'],'effective_final_absence'=>$a['effective_final_absence'],'ordinary_ungraded'=>$a['ordinary_ungraded'],
                'student'=>array_intersect_key($a['baseline']['student'],array_flip(['student_id','student_number','first_name','last_name'])),
                'course'=>array_intersect_key($a['baseline']['course'],array_flip(['course_id','course_code','course_name'])),
                'period'=>$a['period']+['year_name'=>DB::table('academic_years')->where('academic_year_id',$work->academic_year_id)->value('year_name'),
                    'semester_name'=>DB::table('semesters')->where('semester_id',$work->semester_id)->value('semester_name')],
                'current_registration'=>$current,'blockers'=>$a['blockers'],'required_next_action'=>$a['required_next_action'],
                'adopted'=>DB::table(Tables::OUTCOMES)->where('work_item_id',$id)->exists(),
                'capabilities'=>['can_open_manual_entry'=>$current!==null&&$current['blocked_reason']===null,'can_grant_I_automatically'=>false,'can_adopt_from_this_read'=>false],
                'current_result'=>$work->student_course_registration_id?StudentCourseRegistration::with('studentCourseResult.resultStatus')->findOrFail($work->student_course_registration_id)->studentCourseResult?->only(['final_mark','result_status_id','result_announced_at']):null,
                'is_official'=>$work->student_course_registration_id?app(GradeService::class)->isOfficiallyVisibleAttempt(StudentCourseRegistration::with('studentCourseResult','courseOffering')->findOrFail($work->student_course_registration_id)):false];
        });
    }

    public function reviewCorrection(User $actor,array $bundle,string $id): array
    {
        return $this->tx->snapshot(function()use($actor,$bundle,$id) {
            $work=$this->find($actor,$id);$stored=json_decode($work->proposal,true,flags:JSON_THROW_ON_ERROR);
            if(!hash_equals($stored['bundle_digest'],CompletionData::hash($bundle)))$this->fail('bundle_changed');
            $state=(new ExamReconciliationProjection)->state($bundle,app(ExamDatabase::class)->read($actor));
            $a=$stored['action'];$row=$a['original_source'];$row['values']=$a['reconciled_values'];
            $fresh=(new ExamProjection)->row($row,$state,$bundle['student_master']??[]);
            if(!hash_equals($work->baseline_hash,$fresh['baseline_hash']))$this->fail('correction_baseline_changed');
            if(!in_array($work->kind,['correction_review','credit_context_review','final_absence_review'],true)||!$work->student_course_registration_id)$this->fail('actual_component_evidence_required','Actual missing activities or a canonical registration are required; staged aggregates cannot be allocated automatically.');
            $this->assertAdoptable($actor,$work,$a);
            $proposal=$this->outcome($a);
            $review=['contract'=>self::REVIEW,'actor_id'=>(int)$actor->getKey(),'work_item_id'=>$id,'work_item_hash'=>CompletionData::hash((array)$work),
                'bundle_digest'=>CompletionData::hash($bundle),'revision'=>$this->tx->revision(),'baseline_hash'=>$fresh['baseline_hash'],'proposal'=>$proposal];
            return ['status'=>'READY','writes_performed'=>false,'review'=>$review,'review_hash'=>CompletionData::hash($review),'official_result_unchanged'=>true];
        });
    }

    private function assertAdoptable(User $actor,object $work,array $a): void
    {
        $r=StudentCourseRegistration::with(['student','registrationStatus','resultStatus','courseOffering.course','studentCourseResult.resultStatus'])->findOrFail($work->student_course_registration_id);
        $this->access->authorize($actor,$r->student,$r);
        if(!in_array($r->registrationStatus?->status_code,['registered','completed'],true)||$r->student_id!=$work->student_id||$r->course_offering_id!=$work->course_offering_id
            ||$r->courseOffering->course_id!=$work->course_id||$r->courseOffering->academic_program_id!=$work->academic_program_id)$this->fail('correction_registration_changed');
        if(IncompleteOutcomeGuard::caseFor((int)$r->getKey())||in_array($r->resultStatus?->status_code,['withdrawn','deprived','incomplete'],true)
            ||in_array($r->studentCourseResult?->resultStatus?->status_code,['withdrawn','deprived','incomplete'],true)||$r->studentCourseResult?->is_deprived)$this->fail('dedicated_lifecycle_required');
        SupplementaryExamTargetGuard::assertOrdinaryMutationAvailable((int)$r->getKey());
        if(DB::getSchemaBuilder()->hasTable('supplementary_exam_theoretical_deferrals')&&DB::table('supplementary_exam_theoretical_deferrals')->where('student_course_registration_id',$r->getKey())->where('current_slot',1)->exists())$this->fail('dedicated_supplementary_workflow_required');
        if(HistoricalOutcomeGuard::forRegistration($r))HistoricalOutcomeGuard::preservesOutcome($r,DB::transactionLevel()>0);
        if($a['explicit_deferrals']||!$a['source_credits']||$a['source_credits']>30)$this->fail('deferred_or_invalid_source');
    }

    private function outcome(array $a): array
    {
        $base=$a['baseline'];$old=$base['result'];$marks=[];
        if($a['classification']==='credit_context_review')return ['result'=>null,'marks'=>[],'credit_hours'=>$a['source_credits'],'outcome'=>'retain_equivalent_result'];
        if($a['effective_final_absence']) {
            // Absence is not an observed zero. Retained components remain evidence, not a new examination sitting.
            $status=DB::table('result_statuses')->where('status_code','failed')->where('is_active',1)->value('result_status_id');
            if(!$status)$this->fail('result_status_missing');
            $total=ExamProjection::cents($a['reconciled_values']['total']);
            return ['result'=>['theoretical_total'=>$old['theoretical_total']??null,'practical_total'=>$old['practical_total']??null,
                'coursework_total'=>$old['coursework_total']??'0.00','final_mark'=>$total===null?null:sprintf('%d.%02d',intdiv($total,100),$total%100),'result_status_id'=>(int)$status,'is_deprived'=>false],
                'marks'=>[],'credit_hours'=>$a['source_credits'],'outcome'=>'failed_explicit_final_absence_not_observed_zero'];
        }
        if(!$a['component_mapping']['complete']||!$a['component_mapping']['expected_aggregates'])$this->fail('actual_component_evidence_required');
        foreach($a['component_mapping']['mapping'] as $item) {
            $value=$item['source_cents']??ExamProjection::cents($item['stored_mark']['mark']??null);
            if($value===null||$value>$item['max_cents'])$this->fail('actual_component_evidence_required');
            $marks[]=['grade_component_id'=>(int)$item['grade_component_id'],'mark'=>sprintf('%d.%02d',intdiv($value,100),$value%100)];
        }
        $defs=collect($base['components'])->where('is_required',true);$totals=$a['component_mapping']['expected_aggregates'];
        $theory=$defs->where('component_type','theoretical');$practice=$defs->where('component_type','practical');
        $calc=app(GradeService::class)->buildCalculationForRequiredParts($theory->isEmpty()?null:$totals['theoretical']/100,$practice->isEmpty()?null:$totals['practical']/100,
            $theory->isNotEmpty(),$practice->isNotEmpty(),(float)$theory->sum('max_mark'),(float)$practice->sum('max_mark'));
        if(ExamProjection::cents($calc['final_mark'])!==ExamProjection::cents($a['reconciled_values']['total'])||$calc['result_status_code']!==$a['component_mapping']['canonical_outcome']
            ||(CompletionData::text($a['reconciled_values']['condition'])==='F'&&$calc['result_status_code']!=='failed'))$this->fail('source_policy_discrepancy');
        $status=DB::table('result_statuses')->where('status_code',$calc['result_status_code'])->where('is_active',1)->value('result_status_id');
        if(!$status)$this->fail('result_status_missing');
        return ['result'=>['theoretical_total'=>$theory->isEmpty()?null:sprintf('%.2f',$totals['theoretical']/100),
            'practical_total'=>$practice->isEmpty()?null:sprintf('%.2f',$totals['practical']/100),'coursework_total'=>$old['coursework_total']??'0.00',
            'final_mark'=>sprintf('%.2f',ExamProjection::cents($a['reconciled_values']['total'])/100),'result_status_id'=>(int)$status,'is_deprived'=>false],
            'marks'=>$marks,'credit_hours'=>$a['source_credits'],'outcome'=>$calc['result_status_code']];
    }

    public function adopt(User $actor,array $bundle,array $review,string $hash,string $requestId,string $expected,string $reason): array
    {
        if(!Uuid::isValid($requestId)||trim($reason)===''||mb_strlen($reason)>4000||($review['contract']??null)!==self::REVIEW
            ||(int)($review['actor_id']??0)!==(int)$actor->getKey()||!hash_equals(CompletionData::hash($review),$hash)||!hash_equals($review['bundle_digest'],CompletionData::hash($bundle)))$this->fail('correction_review_invalid');
        return $this->tx->run(function()use($actor,$bundle,$review,$hash,$requestId,$expected,$reason) {
            $work=$this->find($actor,$review['work_item_id'],true);$stored=json_decode($work->proposal,true,flags:JSON_THROW_ON_ERROR);$a=$stored['action'];
            $digest=CompletionData::hash([$hash,$requestId,(int)$actor->getKey(),$reason]);
            $old=DB::table(Tables::OUTCOMES)->where('request_id',$requestId)->first();
            if($old) {
                if((int)$old->actor_user_id!==(int)$actor->getKey()||!hash_equals($old->request_hash,$digest)||$old->work_item_id!==$work->work_item_id)$this->fail('operation_identity_changed');
                return ['work_item_id'=>$work->work_item_id,'historical_outcome_id'=>$old->historical_outcome_id,'status'=>'ADOPTED','replayed'=>true];
            }
            if(app(CompletionReceipts::class)->find('exam_start',$requestId))$this->fail('operation_identity_changed');
            if($expected!==$review['revision']||$expected!==$this->tx->revision())$this->fail('stale');
            DB::table('academic_programs')->where('academic_program_id',$work->academic_program_id)->lockForUpdate()->first();
            Student::whereKey($work->student_id)->lockForUpdate()->firstOrFail();Course::whereKey($work->course_id)->lockForUpdate()->firstOrFail();
            app(ExamCompletionService::class)->lock($a);
            $fresh=$this->reviewCorrection($actor,$bundle,$work->work_item_id);
            if(!hash_equals($hash,$fresh['review_hash']))$this->fail('correction_review_changed');
            $this->assertAdoptable($actor,$work,$a);
            if(DB::table(Tables::OUTCOMES)->where('work_item_id',$work->work_item_id)->exists())$this->fail('work_already_adopted');
            $preserved=app(ExamEvidence::class)->capture($actor,['actions'=>[]]);
            $before=['snapshot'=>HistoricalOutcomeGuard::snapshot((int)$work->student_course_registration_id),'context'=>$a['baseline'],
                'registration_full'=>(array)DB::table('student_course_registrations')->where('student_course_registration_id',$work->student_course_registration_id)->first()];
            $now=now('UTC')->format('Y-m-d H:i:s');$proposal=$review['proposal'];
            foreach($proposal['marks'] as $input) {
                $oldMark=DB::table('student_grade_components')->where('student_course_registration_id',$work->student_course_registration_id)->where('grade_component_id',$input['grade_component_id'])->first();
                if($oldMark&&ExamProjection::cents($oldMark->mark)===ExamProjection::cents($input['mark']))continue;
                $values=['mark'=>$input['mark'],'entered_by_user_id'=>$actor->getKey(),'entered_at'=>$now,'updated_at'=>$now];
                if($oldMark){$markId=$oldMark->student_grade_component_id;DB::table('student_grade_components')->where('student_grade_component_id',$markId)->update($values);}
                else $markId=DB::table('student_grade_components')->insertGetId($values+['student_course_registration_id'=>$work->student_course_registration_id,'grade_component_id'=>$input['grade_component_id'],'grade_status'=>'draft','created_at'=>$now]);
                DB::table('grade_audit_logs')->insert(['student_grade_component_id'=>$markId,'old_mark'=>$oldMark?->mark,'new_mark'=>$input['mark'],'changed_by_user_id'=>$actor->getKey(),
                    'change_reason'=>CompletionData::json(['origin'=>'reviewed_historical_correction','work_item_id'=>$work->work_item_id,'request_id'=>$requestId,'reason'=>$reason,'authority_hashes'=>$stored['authority_hashes']]),'changed_at'=>$now]);
            }
            if($proposal['result']!==null) {
                $result=DB::table('student_course_results')->where('student_course_registration_id',$work->student_course_registration_id)->first();
                $values=$proposal['result']+['calculated_at'=>$now,'calculated_by_user_id'=>$actor->getKey(),'updated_at'=>$now];
                if($result)DB::table('student_course_results')->where('student_course_result_id',$result->student_course_result_id)->update($values);
                else DB::table('student_course_results')->insert($values+['student_course_registration_id'=>$work->student_course_registration_id,'created_at'=>$now,'result_announced_at'=>null]);
                DB::table('student_course_registrations')->where('student_course_registration_id',$work->student_course_registration_id)->update(['result_status_id'=>$proposal['result']['result_status_id'],'updated_at'=>$now]);
            }
            app(ExamApprovedCompletionService::class)->recordCredits($actor,(int)$work->student_course_registration_id,(int)$proposal['credit_hours'],$requestId,$a['action_id'],$a['original_source']['reference']['sha256'],$work->work_item_id);
            $snapshot=HistoricalOutcomeGuard::snapshot((int)$work->student_course_registration_id);
            $registration=(array)DB::table('student_course_registrations')->where('student_course_registration_id',$work->student_course_registration_id)->first();
            // Only these exact reviewed target rows may differ; every unrelated pre-existing row must remain identical.
            foreach($snapshot['results'] as $row)if(isset($preserved['student_course_results'][$row['student_course_result_id']]))$preserved['student_course_results'][$row['student_course_result_id']]=CompletionData::hash($row);
            foreach($snapshot['marks'] as $row)if(isset($preserved['student_grade_components'][$row['student_grade_component_id']]))$preserved['student_grade_components'][$row['student_grade_component_id']]=CompletionData::hash($row);
            if(isset($preserved['student_course_registrations'][$work->student_course_registration_id]))$preserved['student_course_registrations'][$work->student_course_registration_id]=CompletionData::hash($registration);
            if(!app(ExamEvidence::class)->verify($actor,$preserved))$this->fail('preserved_evidence_changed');
            $revision=1+(int)DB::table(Tables::OUTCOMES)->where('student_course_registration_id',$work->student_course_registration_id)->max('revision');
            $outcome=DB::table(Tables::OUTCOMES)->insertGetId(['student_course_registration_id'=>$work->student_course_registration_id,'work_item_id'=>$work->work_item_id,'revision'=>$revision,
                'request_id'=>$requestId,'request_hash'=>$digest,'actor_user_id'=>$actor->getKey(),'reason'=>$reason,'before_values'=>CompletionData::json($before),
                'after_values'=>CompletionData::json(['snapshot'=>$snapshot,'registration_full'=>$registration]),'created_at'=>$now],'historical_outcome_id');
            $response=['work_item_id'=>$work->work_item_id,'historical_outcome_id'=>$outcome,'status'=>'ADOPTED','replayed'=>false];
            app(CompletionReceipts::class)->put($actor,'exam_correction',$requestId,['digest'=>$digest,'result'=>$response,'before'=>$before,'after'=>['snapshot'=>$snapshot,'registration_full'=>$registration],
                'reason'=>$reason,'source_authority'=>$stored['authority_hashes'],'review_hash'=>$hash]);
            return $response;
        });
    }

    public function verifyCorrection(User $actor,array $bundle,string $id): array
    {
        return $this->tx->snapshot(function()use($actor,$bundle,$id) {
            $receipt=app(CompletionReceipts::class)->find('exam_correction',$id);
            abort_unless($receipt&&$receipt['actor_id']===(int)$actor->getKey(),404);
            $work=$this->find($actor,$receipt['value']['result']['work_item_id']);
            $proposal=json_decode($work->proposal,true,flags:JSON_THROW_ON_ERROR);
            if(!hash_equals($proposal['bundle_digest'],CompletionData::hash($bundle)))$this->fail('bundle_changed');
            $event=DB::table(Tables::OUTCOMES)->where('request_id',$id)->first();
            if(!$event||(int)$event->actor_user_id!==(int)$actor->getKey()||!hash_equals($event->request_hash,$receipt['value']['digest'])
                ||!hash_equals(CompletionData::hash(json_decode($event->before_values,true,flags:JSON_THROW_ON_ERROR)),CompletionData::hash($receipt['value']['before']))
                ||!hash_equals(CompletionData::hash(json_decode($event->after_values,true,flags:JSON_THROW_ON_ERROR)),CompletionData::hash($receipt['value']['after'])))$this->fail('correction_receipt_changed');
            $r=StudentCourseRegistration::findOrFail($work->student_course_registration_id);
            HistoricalOutcomeGuard::preservesOutcome($r);
            $latest=HistoricalOutcomeGuard::forRegistration($r);
            return ['status'=>'ADOPTED','operation_id'=>$id,'writes_performed'=>false,'historical_outcome_id'=>$event->historical_outcome_id,
                'current_outcome_revision'=>(int)$latest->revision,'superseded'=>(int)$latest->revision>(int)$event->revision];
        });
    }
}
