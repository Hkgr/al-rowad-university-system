<?php

namespace App\Services\StudentCompletion;

use App\Models\{AcademicProgram,Student,User};
use App\Services\{AcademicCatalogTransaction,AcademicPlanAdmission,AcademicPlanContext,AcademicRecordGraphLocker,DataScopeService};
use Illuminate\Support\Facades\{DB,Gate,Validator};
use Ramsey\Uuid\Uuid;

/** Explicit CLI-only reconciliation. No automatic uncertain-write retry or permission grants. */
final class StudentCompletionService
{
    public function __construct(private AcademicCatalogTransaction $transactions,private CompletionDatabase $database,
        private CompletionProjection $projection,private CompletionReceipts $receipts,private DataScopeService $scope) {}

    public function preview(User $actor,array $bundle): array
    {
        $this->authorize($actor,false); AcademicPlanContext::assertReady();
        return $this->transactions->snapshot(function()use($actor,$bundle) {
            $state=$this->database->read($actor);
            $report=$this->projection->build($bundle,$state,'live_authorized_database'); $proposals=[];
            $allowedPrograms=array_fill_keys(array_column($state['academic_programs'],'academic_program_id'),true);
            $university=$actor->isSuperAdmin()||collect($this->scope->scopes($actor))->contains(fn($s)=>$s['type']==='university');
            foreach($report['students'] as &$row) {
                $id=$row['program_id'];
                $allowed=$id!==null&&isset($allowedPrograms[$id]);
                if (!$allowed) foreach(['creation','status_change'] as $key) {$row[$key]['ready']=false;$row[$key]['blockers'][]='program_out_of_scope_or_missing';}
                if(!$allowed&&!$university&&!$row['exists']) {
                    $row['exists']=null;$row['creation']['action']='existence_unavailable';
                }
                if($row['creation']['ready']) $proposals[]=['number'=>$row['number'],'action'=>'create_historical','row'=>$row];
                elseif($row['status_change']['ready']) $proposals[]=['number'=>$row['number'],'action'=>'record_discontinuation','row'=>$row];
            } unset($row);
            $report['counts']['existing']=count(array_filter($report['students'],fn($r)=>$r['exists']===true));
            $report['counts']['missing']=count(array_filter($report['students'],fn($r)=>$r['exists']===false));
            $report['counts']['existence_unavailable']=count(array_filter($report['students'],fn($r)=>$r['exists']===null));
            $report['counts']['ready_create']=count(array_filter($report['students'],fn($r)=>$r['creation']['ready']));
            $report['counts']['ready_status']=count(array_filter($report['students'],fn($r)=>$r['status_change']['ready']));
            $report['counts']['blocked_create']=count(array_filter($report['students'],fn($r)=>$r['exists']===false&&!$r['creation']['ready']));
            $report['review_gaps']=array_values(array_map(fn($r)=>['number'=>$r['number'],'identity'=>$r['identity_blockers'],'level'=>$r['derived_level']['blockers'],'examination'=>$r['exam_classification']],
                array_filter($report['students'],fn($r)=>$r['identity_blockers']||$r['derived_level']['blockers']||in_array($r['exam_classification'],['no_examination_data','possible_absence_incomplete_evidence'],true))));
            $ids=array_map('intval',array_column($state['students'],'student_id'));
            $programs=array_map('intval',array_column($state['academic_programs'],'academic_program_id'));
            $report['review']=['contract'=>'student-completion.review.v1','actor_id'=>(int)$actor->getKey(),
                'bundle_hash'=>CompletionData::hash($bundle),'revision'=>$this->transactions->revision(),'proposals'=>$proposals,
                'review_gaps'=>$report['review_gaps'],
                'evidence_students'=>$ids,'evidence_programs'=>$programs,'evidence_hash'=>$this->database->evidence($ids,$programs),
                'blocked'=>array_map(fn($r)=>['number'=>$r['number'],'creation'=>$r['creation']['blockers'],'status'=>$r['status_change']['blockers']],
                    array_values(array_filter($report['students'],fn($r)=>!$r['exists']&&!$r['creation']['ready'])))];
            $report['review_hash']=CompletionData::hash($report['review']);
            return $report;
        });
    }

    public function apply(User $actor,array $bundle,array $review,string $reviewHash,string $operationId,string $revision,bool $resume=false): array
    {
        $this->authorize($actor,true); AcademicPlanContext::assertReady();
        Validator::make(['operation_id'=>$operationId,'revision'=>$revision],['operation_id'=>'required|uuid','revision'=>'required|regex:/^[0-9]+$/'])->validate();
        $operationId=strtolower($operationId);
        if(($review['contract']??null)!=='student-completion.review.v1' || ($review['actor_id']??null)!==(int)$actor->getKey()
            || !hash_equals($reviewHash,CompletionData::hash($review)) || !hash_equals($review['bundle_hash']??'',CompletionData::hash($bundle)))
            throw new CompletionException('student_completion_review_invalid','Review identity or source fingerprint differs.');
        $payloadHash=CompletionData::hash(['bundle_hash'=>$review['bundle_hash'],'review_hash'=>$reviewHash]);
        $start=$this->transactions->run(function()use($actor,$bundle,$review,$payloadHash,$operationId,$revision,$resume) {
            $this->authorize($actor,true);$this->authorizeTargets($actor,$review);
            $old=$this->receipts->find('started',$operationId);
            if($old) {
                $this->check($actor,$old,$payloadHash);
                if(!$resume) return ['replay'=>true,'result'=>$this->receipts->find('result',$operationId)['value']['result']??['status'=>'INTERRUPTED','operation_id'=>$operationId]];
            }
            if($this->transactions->revision()!==$revision) $this->stale();
            if(!$old) {
                if($revision!==$review['revision']) $this->stale();
                if(!hash_equals($review['evidence_hash'],$this->database->evidence($review['evidence_students'],$review['evidence_programs']))) $this->stale();
                $storedReview=$review;
                foreach($storedReview['proposals'] as &$stored) {
                    $stored['row_hash']=CompletionData::hash($stored['row']);
                    $stored['row']=['program_id'=>$stored['row']['program_id']];
                }unset($stored);
                // Raw examination marks/source names stay in the access-controlled private review,
                // not in the generic activity feed. Its complete immutable hash is retained.
                $this->receipts->put($actor,'started',$operationId,['payload_hash'=>$payloadHash,'review'=>$storedReview]);
            }
            return ['replay'=>false,'revision'=>$this->transactions->revision()];
        });
        if($start['replay']) return $start['result'];
        $next=$start['revision'];$outcomes=[];
        foreach($review['proposals'] as $proposal) {
            $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$operationId.'/'.$proposal['number']);
            try {
                $outcome=$this->transactions->run(function()use($actor,$bundle,$review,$proposal,$operationId,$payloadHash,$stage,$next) {
                    $this->authorize($actor,true);$this->authorizeTargets($actor,$review);
                    if($saved=$this->receipts->find('student',$stage)) {$this->check($actor,$saved,$payloadHash);return $saved['value']['result']+['replayed'=>true];}
                    if($this->transactions->revision()!==$next) $this->stale();
                    $program=AcademicProgram::whereKey($proposal['row']['program_id'])->lockForUpdate()->firstOrFail();
                    abort_unless($this->scope->canMutateProgram($actor,$program),403);
                    $student=Student::withTrashed()->where('student_number',$proposal['number'])->lockForUpdate()->first();
                    if($student) app(AcademicRecordGraphLocker::class)->lockStudentAcademicGraph((int)$student->getKey());
                    $current=$this->projection->build($bundle,$this->database->read($actor,[$proposal['number']]),'locked_database',[$proposal['number']])['students'][0]??null;
                    if(!$current || !hash_equals(CompletionData::hash($proposal['row']),CompletionData::hash($current)))
                        throw new CompletionException('student_completion_student_changed','Student evidence changed; retain the original request and review again.');
                    $beforeEvidence=$this->database->evidence($student?[(int)$student->getKey()]:[],[(int)$program->getKey()]);
                    if($proposal['action']==='create_historical') {
                        if($student || !$current['creation']['ready']) throw new CompletionException('student_completion_creation_blocked','Creation is no longer ready.');
                        $fields=$current['creation']['fields'];
                        Validator::make($fields,(new \App\Http\Requests\Student\StoreStudentRequest)->rules())->validate();
                        $student=AcademicPlanAdmission::createHistorical($actor,$fields,$current['historical_plan']['version_id'],$operationId);
                        $result=['number'=>$proposal['number'],'action'=>'created','student_id'=>(int)$student->getKey(),'before'=>null,
                            'after'=>$fields,'historical_plan'=>$current['historical_plan'],'source_bundle_hash'=>$review['bundle_hash']];
                    } else {
                        if(!$student || !$current['status_change']['ready']) throw new CompletionException('student_completion_status_blocked','Discontinuation is no longer proven.');
                        Gate::forUser($actor)->authorize('update',$student);
                        $before=$student->student_status_id;
                        $student->update(['student_status_id'=>$current['status_change']['to_status_id']]);
                        $result=['number'=>$proposal['number'],'action'=>'discontinuation_recorded','student_id'=>(int)$student->getKey(),
                            'before'=>['student_status_id'=>$before],'after'=>['student_status_id'=>$student->student_status_id],
                            'period_evidence'=>array_map(fn($period)=>[
                                'year_id'=>$period['year_id'],'semester_id'=>$period['semester_id'],'classification'=>$period['classification'],
                                'coverage_basis'=>$period['coverage_basis'],'expected'=>$period['expected'],
                                'absent_examinations'=>$period['absent_examinations'],
                                'source_references'=>array_merge(...array_map(fn($observations)=>array_merge(...array_column($observations,'references')),$period['observations'])),
                            ],$current['periods']),'source_bundle_hash'=>$review['bundle_hash']];
                    }
                    // Existing students' material academic evidence must remain byte-for-byte unchanged.
                    $originalId=$proposal['row']['student_id'];
                    if(!hash_equals($beforeEvidence,$this->database->evidence($originalId?[(int)$originalId]:[],[(int)$program->getKey()])))
                        throw new CompletionException('student_completion_history_changed','Academic evidence changed; the entire student transaction was rolled back.');
                    $result['revision']=$this->transactions->revision();
                    $this->receipts->put($actor,'student',$stage,['payload_hash'=>$payloadHash,'result'=>$result]);
                    return $result;
                });
                $outcomes[]=$outcome;if(empty($outcome['replayed'])) $next=$outcome['revision'];
            } catch(CompletionException $e) {
                $outcomes[]=['number'=>$proposal['number'],'action'=>'blocked','blockers'=>[$e->errorCode]];
                if($e->errorCode==='student_completion_stale') return $this->finish($actor,$review,$payloadHash,$operationId,$outcomes,'INTERRUPTED');
            } catch(\Illuminate\Validation\ValidationException|\App\Exceptions\AcademicPlanException $e) {
                $outcomes[]=['number'=>$proposal['number'],'action'=>'blocked','blockers'=>[$e->errorCode??'student_validation_failed']];
            }
        }
        $blocked=count($review['blocked'])+count(array_filter($outcomes,fn($r)=>$r['action']==='blocked'));
        return $this->finish($actor,$review,$payloadHash,$operationId,$outcomes,$blocked?($outcomes?'PARTIAL':'BLOCKED'):'COMPLETE');
    }

    public function status(User $actor,string $id): array
    {
        $this->authorize($actor,false);Validator::make(['id'=>$id],['id'=>'required|uuid'])->validate();
        return $this->transactions->snapshot(function()use($actor,$id) {
            $start=$this->receipts->find('started',strtolower($id));
            if(!$start) return ['status'=>'NOT_FOUND','note'=>'Absence is not proof that an uncertain write rolled back.'];
            abort_unless($start['actor_id']===(int)$actor->getKey(),403);$this->authorizeTargets($actor,$start['value']['review']);
            $finished=$this->receipts->find('result',strtolower($id));
            if($finished)return $finished['value']['result'];
            $confirmed=[];$unknown=[];
            foreach($start['value']['review']['proposals'] as $proposal) {
                $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,strtolower($id).'/'.$proposal['number']);
                $saved=$this->receipts->find('student',$stage);
                if($saved)$confirmed[]=$saved['value']['result'];else $unknown[]=$proposal['number'];
            }
            return ['operation_id'=>$id,'status'=>'INTERRUPTED','confirmed_students'=>$confirmed,'unconfirmed_numbers'=>$unknown,
                'note'=>'A missing final response or individual receipt is not permission to retry automatically.'];
        });
    }

    public function verify(User $actor,array $bundle,string $id): array
    {
        $this->authorize($actor,false);
        return $this->transactions->snapshot(function()use($actor,$bundle,$id) {
            $start=$this->receipts->find('started',strtolower($id));
            if(!$start) throw new CompletionException('student_completion_operation_missing','Read status before verification.');
            abort_unless($start['actor_id']===(int)$actor->getKey(),403);
            $review=$start['value']['review'];$this->authorizeTargets($actor,$review);
            if(!hash_equals($review['bundle_hash'],CompletionData::hash($bundle))) throw new CompletionException('student_completion_request_mismatch','Sources differ.');
            $results=[];
            foreach($review['proposals'] as $proposal) {
                $receipt=$this->receipts->find('student',(string)Uuid::uuid5(Uuid::NAMESPACE_URL,strtolower($id).'/'.$proposal['number']));
                $student=Student::withTrashed()->where('student_number',$proposal['number'])->first();
                $saved=$receipt['value']['result']??null;$matches=false;
                if($student&&$saved) {
                    $matches=(int)$student->getKey()===$saved['student_id'];
                    foreach($saved['after'] as $field=>$value) {
                        $actual=$student->getRawOriginal($field);if((string)$actual!==(string)$value)$matches=false;
                    }
                    if($saved['action']==='created') $matches=$matches&&AcademicPlanContext::forStudent($student)->versionId===$saved['historical_plan']['version_id'];
                }
                $results[]=['number'=>$proposal['number'],'receipt_found'=>$saved!==null,'current_state_matches'=>$matches];
            }
            return ['operation_id'=>$id,'writes_performed'=>false,'revision'=>$this->transactions->revision(),'students'=>$results,
                'existing_academic_evidence_matches'=>hash_equals($review['evidence_hash'],$this->database->evidence($review['evidence_students'],$review['evidence_programs']))];
        });
    }

    private function finish(User $actor,array $review,string $hash,string $id,array $outcomes,string $status): array
    {
        return $this->transactions->run(function()use($actor,$review,$hash,$id,$outcomes,$status) {
            $this->authorize($actor,true);$this->authorizeTargets($actor,$review);
            $result=['operation_id'=>$id,'status'=>$status,'students'=>$outcomes,'blocked_source_students'=>$review['blocked'],
                'completion_scope'=>'the_immutable_reviewed_ready_actions_only','review_gaps'=>$review['review_gaps'],
                'revision'=>$this->transactions->revision(),'note'=>'A receipt is historical; verify current state before explicit resumption.'];
            $result=CompletionData::canonical($result);
            $this->receipts->put($actor,'result',$id,['payload_hash'=>$hash,'result'=>$result]);return $result;
        });
    }
    private function authorize(User $actor,bool $write): void
    {
        $fresh=$actor->fresh();abort_unless($fresh&&$fresh->accountStatus?->status_code==='active',403);
        abort_unless($fresh->hasPermission('students.view')&&$fresh->hasPermission('grades.view')&&(!$write||$fresh->hasPermission('students.manage')),403);
        abort_unless($fresh->isSuperAdmin()||collect($this->scope->scopes($fresh))->contains(fn($s)=>in_array($s['type'],['university','college','department','program'],true)),403);
    }
    private function authorizeTargets(User $actor,array $review): void
    {
        $allowed=$this->scope->scopeProgramsForMutation(AcademicProgram::query(),$actor)->pluck('academic_program_id')->map(fn($id)=>(int)$id)->all();
        abort_if(array_diff(array_map('intval',$review['evidence_programs']),$allowed),403);
        $programs=array_map(fn($p)=>(int)$p['row']['program_id'],$review['proposals']);abort_if(array_diff($programs,$allowed),403);
        abort_if(DB::table('students')->whereIn('student_id',$review['evidence_students'])->whereNotIn('academic_program_id',$allowed)->exists(),403);
        abort_if(DB::table('students')->whereIn('student_number',array_column($review['proposals'],'number'))->whereNotIn('academic_program_id',$allowed)->exists(),403);
    }
    private function check(User $actor,array $receipt,string $hash): void
    {
        abort_unless($receipt['actor_id']===(int)$actor->getKey(),403);
        if(!hash_equals($receipt['value']['payload_hash'],$hash)) throw new CompletionException('student_completion_request_mismatch','Operation ID belongs to different reviewed content.');
    }
    private function stale(): never {throw new CompletionException('student_completion_stale','The reviewed revision changed. Inspect status and explicitly review/resume; no mutation was replayed.');}
}
