<?php

namespace App\Services\HistoricalExams;

use App\Models\{Course, Student, User};
use App\Services\{AcademicCatalogTransaction, ExamManualGradePreparationService};
use App\Services\StudentCompletion\{CompletionData, CompletionException, CompletionReceipts};
use App\Support\HistoricalExamSchema;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** v4 reviewed drafts/staging only. Adoption is a separate explicit reviewed action. */
final class ExamApprovedCompletionService
{
    public function __construct(private AcademicCatalogTransaction $tx,private ExamDatabase $db,private CompletionReceipts $receipts) {}

    private function authorize(User $actor,array $review): void { app(ExamCompletionService::class)->authorizeReview($actor,$review); }
    private function fail(string $code): never { throw new CompletionException('historical_exam_'.$code,'Approved examination review conflict: '.$code); }
    private function identity(array $receipt,User $actor,string $digest): void
    {
        if($receipt['actor_id']!==(int)$actor->getKey()||!hash_equals($receipt['value']['digest'],$digest))$this->fail('operation_identity_changed');
    }

    public function apply(User $actor,array $bundle,array $review,string $hash,string $id,string $expected,bool $resume): array
    {
        if(!Uuid::isValid($id)||($review['actor_id']??null)!==(int)$actor->getKey()||!($review['schema_readiness_checked']??false)||($bundle['contract']??null)!==ExamSources::APPROVED_CONTRACT||($review['contract']??null)!==ExamReconciliationProjection::CONTRACT
            ||!hash_equals(CompletionData::hash($review),$hash)||!hash_equals($review['bundle_digest'],CompletionData::hash($bundle))
            ||!hash_equals(CompletionData::hash($review['authority_hashes']),CompletionData::hash($bundle['approved_authority']['document_hashes'])))$this->fail('review_invalid');
        $this->authorize($actor,$review);$digest=CompletionData::hash([$hash,$bundle['sources'],(int)$actor->getKey()]);
        $old=$this->tx->run(function()use($actor,$bundle,$review,$hash,$id,$expected,$resume,$digest) {
            $this->authorize($actor,$review);
            if($r=$this->receipts->find('exam_result',$id)){$this->identity($r,$actor,$digest);return $r['value']['result'];}
            HistoricalExamSchema::requireReady();
            if($r=$this->receipts->find('exam_start',$id)){$this->identity($r,$actor,$digest);if(!$resume)$this->fail('explicit_resume_required');}
            else {
                if($resume)$this->fail('no_operation_to_resume');
                if($expected!==$review['revision']||$expected!==$this->tx->revision())$this->fail('stale');
                $fresh=app(ExamCompletionService::class)->preview($actor,$bundle);
                if(!hash_equals($hash,$fresh['review_hash']))$this->fail('review_changed');
                $this->receipts->put($actor,'exam_start',$id,['digest'=>$digest,'review'=>$review,'preserved'=>app(ExamEvidence::class)->capture($actor,$review)]);
            }
            if($expected!==$this->tx->revision())$this->fail('stale');
            return null;
        });
        if($old!==null)return $old;
        $start=$this->receipts->find('exam_start',$id);
        if(!app(ExamEvidence::class)->verify($actor,$start['value']['preserved']))$this->fail('preserved_evidence_changed');
        $next=$expected;$out=[];$owned=[];
        foreach($review['actions'] as $a) {
            if(!$a['eligible']) {
                $status=match($a['classification']){'existing_equivalent'=>'UNCHANGED','retained_source_history'=>'RETAINED','explicitly_deferred','deferred_same_attempt'=>'DEFERRED',default=>'BLOCKED'};
                $out[]=['action_id'=>$a['action_id'],'source_id'=>$a['source_id'],'status'=>$status,'blockers'=>$a['blockers']];continue;
            }
            $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$id.'/'.$a['action_id']);
            try {
                $done=$this->tx->run(function()use($actor,$bundle,$review,$a,$stage,$id,$digest,$next,$owned) {
                    $this->authorize($actor,['actions'=>[$a]]);
                    DB::table('academic_programs')->where('academic_program_id',$a['baseline']['program_id'])->lockForUpdate()->first();
                    $student=Student::whereKey($a['baseline']['student']['student_id'])->lockForUpdate()->firstOrFail();
                    $course=Course::whereKey($a['baseline']['course']['course_id'])->lockForUpdate()->firstOrFail();
                    app(ExamCompletionService::class)->lock($a);
                    if($r=$this->receipts->find('exam_action',$stage)) {
                        $this->identity($r,$actor,$digest);$this->assertAfter($actor,$bundle,$a,$r['value']['result']);
                        return ['result'=>$r['value']['result'],'revision'=>$this->tx->revision()];
                    }
                    if($next!==$this->tx->revision())$this->fail('stale');
                    $state=(new ExamReconciliationProjection)->state($bundle,$this->db->read($actor));
                    $source=$a['original_source'];$row=$source;$row['values']=$a['reconciled_values'];
                    $current=(new ExamProjection)->row($row,$state,$bundle['student_master']??[]);
                    $baseline=$current['baseline'];
                    foreach($owned as $created) {
                        if($baseline['offering']&&isset($created['course_offerings'][$baseline['offering']['course_offering_id']])) {
                            if(CompletionData::hash($baseline['offering'])!==CompletionData::hash($created['course_offerings'][$baseline['offering']['course_offering_id']]))$this->fail('owned_context_changed');
                            $baseline['offering']=null;
                        }
                        $baseline['components']=array_values(array_filter($baseline['components'],function($c)use($created) {
                            $saved=$created['grade_components'][$c['grade_component_id']]??null;
                            if($saved&&CompletionData::hash($saved)!==CompletionData::hash($c))$this->fail('owned_context_changed');
                            return !$saved;
                        }));
                    }
                    if(!hash_equals($a['baseline_hash'],CompletionData::hash($baseline)))$this->fail('action_baseline_changed');
                    if($a['execution_kind']==='stage') {
                        $result=app(HistoricalExamWorkService::class)->stage($actor,$bundle,$review,$a,$id);
                        $result['created']=['course_offerings'=>[],'grade_components'=>[]];
                    } elseif($a['execution_kind']==='draft') {
                        if(!$current['eligible'])$this->fail('action_no_longer_eligible');
                        $service=app(ExamManualGradePreparationService::class);$p=$a['period'];
                        $input=['academic_year_id'=>$p['academic_year_id'],'semester_id'=>$p['semester_id']];
                        if($r=$current['baseline']['registration'])$input['registration_id']=(int)$r['student_course_registration_id'];
                        if($o=$current['baseline']['offering'])$input['course_offering_id']=(int)$o['course_offering_id'];
                        $prepared=$service->previewHistorical($actor,$student,$course,$input);
                        $saved=$service->saveHistorical($actor,$student,$course,$input+['revision'=>$prepared['revision'],'confirmed'=>true,'acknowledged'=>true,
                            'reason'=>'Reviewed approved historical draft: '.$a['action_id'],'components'=>$current['proposed_components']]);
                        $this->recordCredits($actor,(int)$saved['registration_id'],(int)$a['source_credits'],$id,$a['action_id'],$source['reference']['sha256']);
                        $after=(new ExamProjection)->row($row,(new ExamReconciliationProjection)->state($bundle,$this->db->read($actor)),$bundle['student_master']??[])['baseline'];
                        foreach(['student','course','result','approvals','parts','lifecycle','historical_outcomes'] as $key)if(CompletionData::hash($after[$key])!==CompletionData::hash($current['baseline'][$key]))$this->fail('preserved_evidence_changed');
                        $created=['course_offerings'=>[],'grade_components'=>[]];
                        if(!$current['baseline']['offering'])$created['course_offerings'][$after['offering']['course_offering_id']]=$after['offering'];
                        $oldIds=array_column($current['baseline']['components'],'grade_component_id');
                        foreach($after['components'] as $c)if(!in_array($c['grade_component_id'],$oldIds,true))$created['grade_components'][$c['grade_component_id']]=$c;
                        $result=['action_id'=>$a['action_id'],'source_id'=>$a['source_id'],'status'=>'SAVED_PENDING_APPROVAL','registration_id'=>$saved['registration_id'],
                            'source_row'=>$source,'after'=>$after,'created'=>$created,'official_result_created'=>false,'assessment_evidence'=>$a['component_mapping']];
                    } else $this->fail('unsupported_execution_kind');
                    $this->receipts->put($actor,'exam_action',$stage,['digest'=>$digest,'result'=>$result]);
                    return ['result'=>$result,'revision'=>$this->tx->revision()];
                });
                $out[]=$done['result'];$owned[]=$done['result']['created'];$next=$done['revision'];
            } catch(\Throwable $e) {
                $out[]=['action_id'=>$a['action_id'],'source_id'=>$a['source_id'],'status'=>'BLOCKED','blockers'=>[$e->errorCode??'historical_exam_validation_failed'],'message'=>$e->getMessage()];
                if($next!==$this->tx->revision())break;
            }
        }
        $result=CompletionData::canonical(['operation_id'=>$id,'status'=>count($out)===count($review['actions'])&&!collect($out)->contains(fn($r)=>in_array($r['status'],['BLOCKED','DEFERRED','STAGED_PENDING_REVIEW'],true))?'COMPLETE':'PARTIAL',
            'actions'=>$out,'saved_pending_approval'=>collect($out)->where('status','SAVED_PENDING_APPROVAL')->count(),'staged_pending_review'=>collect($out)->where('status','STAGED_PENDING_REVIEW')->count()]);
        $this->tx->run(function()use($actor,$bundle,$review,$id,$digest,$next,$start,$result) {
            $this->authorize($actor,$review);
            if($next!==$this->tx->revision())$this->fail('stale');
            if(!app(ExamEvidence::class)->verify($actor,$start['value']['preserved']))$this->fail('preserved_evidence_changed');
            $this->validateSaved($actor,$bundle,$review,$id,$digest,$result);
            $this->receipts->put($actor,'exam_result',$id,['digest'=>$digest,'result'=>$result]);
        });
        return $result;
    }

    public function recordCredits(User $actor,int $registrationId,int $hours,string $operation,string $action,string $sourceHash,?string $work=null): void
    {
        HistoricalExamSchema::requireReady();
        if(DB::transactionLevel()===0||$hours<1||$hours>30||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash))$this->fail('credit_evidence_invalid');
        $registration=\App\Models\StudentCourseRegistration::with('student','courseOffering')->findOrFail($registrationId);
        app(\App\Support\ExamManualGradeEntryAccess::class)->authorize($actor,$registration->student,$registration);
        abort_unless(app(\App\Services\DataScopeService::class)->scopeManualGradeCourses(Course::query(),$actor)->whereKey($registration->courseOffering->course_id)->exists(),403);
        $old=DB::table(HistoricalExamSchema::CREDITS)->where('student_course_registration_id',$registrationId)->orderByDesc('revision')->lockForUpdate()->first();
        if($old&&(int)$old->credit_hours===$hours)return;
        DB::table(HistoricalExamSchema::CREDITS)->insert(['student_course_registration_id'=>$registrationId,'revision'=>1+(int)($old?->revision??0),'credit_hours'=>$hours,
            'basis'=>'approved_source_attempt','work_item_id'=>$work,'operation_id'=>$operation,'action_id'=>$action,'source_hash'=>$sourceHash,'actor_user_id'=>$actor->getKey(),'created_at'=>now('UTC')->format('Y-m-d H:i:s')]);
    }

    private function assertAfter(User $actor,array $bundle,array $a,array $result): void
    {
        if($result['action_id']!==$a['action_id']||$result['source_id']!==$a['source_id'])$this->fail('receipt_target_changed');
        if($result['status']==='STAGED_PENDING_REVIEW') {
            $work=app(HistoricalExamWorkService::class)->find($actor,$result['work_item_id']);
            if(!hash_equals($result['work_item_hash'],CompletionData::hash((array)$work)))$this->fail('work_item_changed');
        } else {
            $row=$a['original_source'];$row['values']=$a['reconciled_values'];
            $current=(new ExamProjection)->row($row,(new ExamReconciliationProjection)->state($bundle,$this->db->read($actor)),$bundle['student_master']??[])['baseline'];
            if(!hash_equals(CompletionData::hash($result['after']),CompletionData::hash($current)))$this->fail('completed_action_changed');
        }
    }

    private function validateSaved(User $actor,array $bundle,array $review,string $id,string $digest,array $result): void
    {
        $saved=array_column(array_filter($result['actions']??[],fn($a)=>in_array($a['status'],['SAVED_PENDING_APPROVAL','STAGED_PENDING_REVIEW'],true)),null,'action_id');
        foreach($review['actions'] as $a)if($a['eligible']) {
            $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$id.'/'.$a['action_id']);$r=$this->receipts->find('exam_action',$stage);
            if(!$r){if(isset($saved[$a['action_id']]))$this->fail('completed_receipt_missing');continue;}
            $this->identity($r,$actor,$digest);$this->assertAfter($actor,$bundle,$a,$r['value']['result']);
        }
    }

    public function verify(User $actor,array $bundle,string $id): array
    {
        return $this->tx->snapshot(function()use($actor,$bundle,$id) {
            $status=app(ExamCompletionService::class)->status($actor,$id);$start=$this->receipts->find('exam_start',$id);$review=$start['value']['review'];
            if(!hash_equals($review['bundle_digest'],CompletionData::hash($bundle)))$this->fail('bundle_changed');
            if(!app(ExamEvidence::class)->verify($actor,$start['value']['preserved']))$this->fail('preserved_evidence_changed');
            $this->validateSaved($actor,$bundle,$review,$id,$start['value']['digest'],$status);
            $workIds=array_column(array_filter($status['actions']??[],fn($a)=>$a['status']==='STAGED_PENDING_REVIEW'),'work_item_id');
            $adopted=DB::table(HistoricalExamSchema::OUTCOMES)->whereIn('work_item_id',$workIds)->count();
            return ['operation_id'=>$id,'status'=>$status['status'],'writes_performed'=>false,'saved_pending_approval'=>$status['saved_pending_approval']??0,
                'staged_pending_review'=>$status['staged_pending_review']??0,'current_adopted_work_items'=>$adopted,
                'note'=>'The staging count describes the immutable original operation; current adopted work is reported separately. Staging is not approval or publication, and ordinary ungraded work is not an I grant.'];
        });
    }
}
