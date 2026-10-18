<?php
namespace App\Services\HistoricalExams;

use App\Models\{Course,CourseOffering,Student,StudentCourseRegistration,User};
use App\Services\{AcademicCatalogTransaction,ExamManualGradePreparationService};
use App\Services\StudentCompletion\{CompletionData,CompletionException,CompletionReceipts};
use App\Support\ExamManualGradeEntryAccess;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** CLI reviewed missing-record completion only. Never submits/approves, announces, corrects or retries grades. */
final class ExamCompletionService
{
    public function __construct(private ExamDatabase $db,private ExamProjection $projection,private CompletionReceipts $receipts,private AcademicCatalogTransaction $tx,private ExamManualGradeEntryAccess $access){}
    public function preview(User $actor,array $bundle): array
    {
        $this->access->authorize($actor);
        return $this->tx->snapshot(function()use($actor,$bundle) {
            $state=$this->db->read($actor);
            if(isset($bundle['approved_authority'])) {
                $state['_actor_id']=(int)$actor->getKey();
                $state['_historical_exam_schema_ready']=\App\Support\HistoricalExamSchema::ready();
            }
            return $this->projection->build($bundle,$state,$this->tx->revision());
        });
    }
    public function authorizeReview(User $actor,array $review): void
    {
        $this->access->authorize($actor);
        $students=[];$offerings=[];$courses=[];
        foreach($review['actions'] as $a)if($id=$a['baseline']['student']['student_id']??null) {
            $student=$students[$id]??Student::findOrFail($id);if(!isset($students[$id])){$this->access->authorize($actor,$student);$students[$id]=$student;}
            if(($offering=$a['baseline']['offering']['course_offering_id']??null)&&!isset($offerings[$offering])){$this->access->authorizeOffering($actor,$student,CourseOffering::findOrFail($offering));$offerings[$offering]=true;}
            if(!$offering && isset($a['baseline']['program_id']))abort_unless(app(\App\Services\DataScopeService::class)->scopeProgramsForMutation(\App\Models\AcademicProgram::query(),$actor)->whereKey($a['baseline']['program_id'])->exists(),403);
            $course=$a['baseline']['course']['course_id']??null;
            if($course&&!isset($courses[$course])){abort_unless(app(\App\Services\DataScopeService::class)->scopeManualGradeCourses(Course::query(),$actor)->whereKey($course)->exists(),403);$courses[$course]=true;}
        }
    }
    private function fail(string $code): never {throw new CompletionException('historical_exam_'.$code,'Historical examination review conflict: '.$code);}
    private function check(User $actor,array $receipt,string $digest): void
    {
        if($receipt['actor_id']!==(int)$actor->getKey()||!hash_equals($receipt['value']['digest'],$digest))$this->fail('operation_identity_changed');
    }
    public function apply(User $actor,array $bundle,array $review,string $hash,string $id,string $expected,bool $resume=false): array
    {
        if(($review['contract']??null)===ExamReconciliationProjection::CONTRACT)return app(ExamApprovedCompletionService::class)->apply($actor,$bundle,$review,$hash,$id,$expected,$resume);
        if(!Uuid::isValid($id)||($review['contract']??null)!==ExamProjection::CONTRACT||!hash_equals(CompletionData::hash($review),$hash)||!hash_equals($review['bundle_digest'],CompletionData::hash($bundle)))$this->fail('review_invalid');
        $this->authorizeReview($actor,$review);$digest=CompletionData::hash([$hash,$bundle['sources'],(int)$actor->getKey()]);
        $old=$this->tx->run(function()use($actor,$review,$hash,$id,$digest,$expected,$resume,$bundle) {
            $this->authorizeReview($actor,$review);
            if($r=$this->receipts->find('exam_result',$id)){$this->check($actor,$r,$digest);return $r['value']['result'];}
            if($r=$this->receipts->find('exam_start',$id)) {$this->check($actor,$r,$digest);if(!$resume)$this->fail('explicit_resume_required');}
            else {
                if($resume)$this->fail('no_operation_to_resume');
                if($expected!==$review['revision']||$expected!==$this->tx->revision())$this->fail('stale');
                $fresh=$this->projection->build($bundle,$this->db->read($actor),$this->tx->revision());
                if(!hash_equals($hash,$fresh['review_hash']))$this->fail('review_changed');
                $this->receipts->put($actor,'exam_start',$id,['digest'=>$digest,'review'=>$review,'preserved'=>app(ExamEvidence::class)->capture($actor,$review)]);
            }
            if($expected!==$this->tx->revision())$this->fail('stale');return null;
        });if($old!==null)return $old;
        $start=$this->receipts->find('exam_start',$id);
        if(!app(ExamEvidence::class)->verify($actor,$start['value']['preserved']))$this->fail('preserved_evidence_changed');
        $out=[];$next=$expected;$owned=[];
        foreach($review['actions'] as $a) {
            if(!$a['eligible']){$out[]=['action_id'=>$a['action_id'],'status'=>$a['classification']==='existing_equivalent'&&!$a['blockers']?'UNCHANGED':'BLOCKED','blockers'=>$a['blockers']];continue;}
            $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$id.'/'.$a['action_id']);
            try {
                $done=$this->tx->run(function()use($actor,$bundle,$review,$a,$stage,$digest,$next,$owned) {
                    $this->authorizeReview($actor,['actions'=>[$a]]);
                    $student=Student::whereKey($a['baseline']['student']['student_id'])->lockForUpdate()->firstOrFail();
                    Course::whereKey($a['baseline']['course']['course_id'])->lockForUpdate()->firstOrFail();
                    $this->lock($a);
                    if($r=$this->receipts->find('exam_action',$stage)) {
                        $this->check($actor,$r,$digest);$this->assertAfter($actor,$r['value']['result'],$a,$bundle);
                        return ['result'=>$r['value']['result'],'revision'=>$this->tx->revision()];
                    }
                    if($next!==$this->tx->revision())$this->fail('stale');
                    $row=collect($bundle['rows'])->firstWhere('id',$a['source_id']);$current=$this->projection->row($row,$this->db->read($actor),$bundle['student_master']??[]);
                    $baseline=$current['baseline'];
                    foreach($owned as $created) {
                        if($baseline['offering']&&isset($created['course_offerings'][$baseline['offering']['course_offering_id']])) {
                            if(CompletionData::hash($baseline['offering'])!==CompletionData::hash($created['course_offerings'][$baseline['offering']['course_offering_id']]))$this->fail('owned_context_changed');
                            $baseline['offering']=null;
                        }
                        $baseline['components']=array_values(array_filter($baseline['components'],function($c)use($created){$saved=$created['grade_components'][$c['grade_component_id']]??null;if($saved&&CompletionData::hash($saved)!==CompletionData::hash($c))$this->fail('owned_context_changed');return !$saved;}));
                    }
                    if(!hash_equals($a['baseline_hash'],CompletionData::hash($baseline))||!$current['eligible'])$this->fail('action_baseline_changed');
                    $service=app(ExamManualGradePreparationService::class);$course=Course::findOrFail($a['baseline']['course']['course_id']);$p=$a['period'];
                    $input=['academic_year_id'=>$p['academic_year_id'],'semester_id'=>$p['semester_id']];
                    if($reg=$current['baseline']['registration'])$input['registration_id']=(int)$reg['student_course_registration_id'];
                    if($offering=$current['baseline']['offering'])$input['course_offering_id']=(int)$offering['course_offering_id'];
                    $prepared=$service->previewHistorical($actor,$student,$course,$input);
                    $saved=$service->saveHistorical($actor,$student,$course,$input+['revision'=>$prepared['revision'],'confirmed'=>true,'acknowledged'=>true,'reason'=>'Reviewed historical examination completion: '.$a['action_id'],
                        'components'=>$current['proposed_components']]);
                    $after=$this->projection->row($row,$this->db->read($actor),$bundle['student_master']??[])['baseline'];
                    // The writer may fill missing marks only. Never rewrite an existing result, approval or lifecycle.
                    foreach(['student','course','result','approvals','parts','lifecycle'] as $key)if(CompletionData::hash($after[$key])!==CompletionData::hash($current['baseline'][$key]))$this->fail('preserved_evidence_changed');
                    $created=['course_offerings'=>[],'grade_components'=>[]];
                    if(!$current['baseline']['offering'])$created['course_offerings'][$after['offering']['course_offering_id']]=$after['offering'];
                    $oldIds=array_column($current['baseline']['components'],'grade_component_id');foreach($after['components'] as $c)if(!in_array($c['grade_component_id'],$oldIds,true))$created['grade_components'][$c['grade_component_id']]=$c;
                    $result=['action_id'=>$a['action_id'],'source_row'=>$row,'registration_id'=>$saved['registration_id'],'status'=>'SAVED_PENDING_APPROVAL','official_result_created'=>false,'after'=>$after,'created'=>$created,'period_provenance'=>$a['period'],
                        'assessment_evidence'=>$a['component_mapping']];
                    $this->receipts->put($actor,'exam_action',$stage,['digest'=>$digest,'result'=>$result]);return ['result'=>$result,'revision'=>$this->tx->revision()];
                });$out[]=$done['result'];$owned[]=$done['result']['created'];$next=$done['revision'];
            } catch(\Throwable $e) {
                $out[]=['action_id'=>$a['action_id'],'status'=>'BLOCKED','blockers'=>[$e->errorCode??'historical_exam_validation_failed'],'message'=>$e->getMessage()];
                if($next!==$this->tx->revision())break;
            }
        }
        $result=CompletionData::canonical(['operation_id'=>$id,'status'=>count($out)===count($review['actions'])&&!collect($out)->contains(fn($x)=>$x['status']==='BLOCKED')?'COMPLETE':'PARTIAL','actions'=>$out,'saved_pending_approval'=>collect($out)->where('status','SAVED_PENDING_APPROVAL')->count()]);
        $this->tx->run(function()use($actor,$bundle,$review,$id,$digest,$result,$next,$start){$this->authorizeReview($actor,$review);if($next!==$this->tx->revision())$this->fail('stale');if(!app(ExamEvidence::class)->verify($actor,$start['value']['preserved']))$this->fail('preserved_evidence_changed');$this->validateSavedActions($actor,$bundle,$review,$id,$digest,$result);$this->receipts->put($actor,'exam_result',$id,['digest'=>$digest,'result'=>$result]);});return $result;
    }
    public function lock(array $a): void
    {
        $id=$a['baseline']['offering']['course_offering_id']??null;if(!$id)return;
        DB::table('course_offerings')->where('course_offering_id',$id)->lockForUpdate()->get();
        foreach(['grade_approvals'=>'grade_approval_id','grade_part_approvals'=>'grade_part_approval_id'] as $t=>$key)DB::table($t)->where('course_offering_id',$id)->orderBy($key)->lockForUpdate()->get();
        $regs=DB::table('student_course_registrations')->where('course_offering_id',$id)->orderBy('student_course_registration_id')->lockForUpdate()->pluck('student_course_registration_id');
        DB::table('grade_components')->where('course_offering_id',$id)->orderBy('grade_component_id')->lockForUpdate()->get();
        DB::table('student_grade_components')->whereIn('student_course_registration_id',$regs)->orderBy('student_grade_component_id')->lockForUpdate()->get();
        DB::table('student_course_results')->whereIn('student_course_registration_id',$regs)->orderBy('student_course_result_id')->lockForUpdate()->get();
    }
    private function assertAfter(User $actor,array $result,array $reviewed,array $bundle): void
    {
        $source=collect($bundle['rows'])->firstWhere('id',$reviewed['source_id']);$target=$reviewed['baseline'];$after=$result['after'];
        if(isset($reviewed['component_mapping']['profile'])&&!hash_equals(CompletionData::hash($reviewed['component_mapping']),CompletionData::hash($result['assessment_evidence']??null)))$this->fail('receipt_assessment_changed');
        if(!$source||$result['action_id']!==$reviewed['action_id']||!hash_equals(CompletionData::hash($source),CompletionData::hash($result['source_row']))
            ||(int)$after['student']['student_id']!==(int)$target['student']['student_id']||(int)$after['course']['course_id']!==(int)$target['course']['course_id']
            ||(int)$after['offering']['academic_program_id']!==(int)$target['program_id']||(int)$after['offering']['academic_year_id']!==(int)$reviewed['period']['academic_year_id']
            ||(int)$after['offering']['semester_id']!==(int)$reviewed['period']['semester_id']||(int)$result['registration_id']!==(int)$after['registration']['student_course_registration_id'])$this->fail('receipt_target_changed');
        $a=$this->projection->row($result['source_row'],$this->db->read($actor),$bundle['student_master']??[]);
        if(!hash_equals(CompletionData::hash($result['after']),CompletionData::hash($a['baseline'])))$this->fail('completed_action_changed');
    }
    private function validateSavedActions(User $actor,array $bundle,array $review,string $id,string $digest,array $status): void
    {
        $saved=array_column(array_filter($status['actions']??[],fn($a)=>$a['status']==='SAVED_PENDING_APPROVAL'),null,'action_id');
        foreach($review['actions'] as $a)if($a['eligible']) {
            $stage=(string)Uuid::uuid5(Uuid::NAMESPACE_URL,$id.'/'.$a['action_id']);$r=$this->receipts->find('exam_action',$stage);
            if(!$r){if(isset($saved[$a['action_id']]))$this->fail('completed_receipt_missing');continue;}
            $this->check($actor,$r,$digest);$this->assertAfter($actor,$r['value']['result'],$a,$bundle);
        }
    }
    public function status(User $actor,string $id): array
    {
        $this->access->authorize($actor);
        if($correction=$this->receipts->find('exam_correction',$id)) {
            if($correction['actor_id']!==(int)$actor->getKey())$this->fail('operation_unavailable');
            app(HistoricalExamWorkService::class)->find($actor,$correction['value']['result']['work_item_id']);
            return $correction['value']['result'];
        }
        $this->access->authorize($actor);$start=$this->receipts->find('exam_start',$id);if(!$start||$start['actor_id']!==(int)$actor->getKey())$this->fail('operation_unavailable');
        $this->authorizeReview($actor,$start['value']['review']);$r=$this->receipts->find('exam_result',$id);
        if($r)$this->check($actor,$r,$start['value']['digest']);
        return $r?$r['value']['result']:['operation_id'=>$id,'status'=>'INTERRUPTED','note'=>'Inspect committed action receipts; explicit resume with the same bundle/review/UUID only.'];
    }
    public function verify(User $actor,array $bundle,string $id): array
    {
        $this->access->authorize($actor);
        if($this->receipts->find('exam_correction',$id))return app(HistoricalExamWorkService::class)->verifyCorrection($actor,$bundle,$id);
        $start=$this->receipts->find('exam_start',$id);
        if(($start['value']['review']['contract']??null)===ExamReconciliationProjection::CONTRACT)return app(ExamApprovedCompletionService::class)->verify($actor,$bundle,$id);
        $this->access->authorize($actor);
        return $this->tx->snapshot(function()use($actor,$bundle,$id){$status=$this->status($actor,$id);$start=$this->receipts->find('exam_start',$id);$review=$start['value']['review'];
            if(!hash_equals($review['bundle_digest'],CompletionData::hash($bundle)))$this->fail('bundle_changed');
            if(!app(ExamEvidence::class)->verify($actor,$start['value']['preserved']))$this->fail('preserved_evidence_changed');
            $this->validateSavedActions($actor,$bundle,$review,$id,$start['value']['digest'],$status);
            return ['status'=>$status['status'],'writes_performed'=>false,'operation_id'=>$id,'saved_pending_approval'=>$status['saved_pending_approval']??null,'note'=>'Saved components are drafts; canonical submission, review, approval and finalization are still mandatory.'];});
    }
}
