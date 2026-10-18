<?php

namespace App\Services\HistoricalExams;

use App\Models\{AcademicPlanVersion, AcademicProgram, AcademicRequirementGroup, ProgramCourse, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, AcademicPlanWorkflow};
use App\Services\StudentCompletion\{CompletionData, CompletionException, CompletionReceipts};
use App\Support\{ApplicableCourseCredits, HistoricalExamSchema, ScientificProgramAccess};
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Separate scientific review/publication. No student transfer and no mutation of shared origins. */
final class ExamCatalogCreditService
{
    public const CONTRACT = 'historical-exam-catalog-credits.review.v1';
    private const DECISIONS = [
        'RU2-ES-TS' => ['courses'=>['ES421'=>[2,3]], 'total'=>[141,142], 'group'=>['college','mandatory',62,63]],
        'RU1-CDI-ESI' => ['courses'=>['ESI112'=>[2,3],'ESI114'=>[2,3]], 'total'=>[74,76], 'group'=>['college','mandatory',58,60]],
        // FES311 is an elective in Civil, not a reduction of its independent six-hour budget.
        'RU2-FBER-CE' => ['courses'=>['FES311'=>[3,2]], 'total'=>[165,165], 'group'=>['college','elective',6,6]],
    ];

    public function __construct(private AcademicCatalogTransaction $tx, private ScientificProgramAccess $access, private CompletionReceipts $receipts) {}
    private function fail(string $code): never { throw new CompletionException('historical_exam_catalog_'.$code, 'Catalog credit review conflict: '.$code); }
    private function authorize(User $actor, array $codes): void
    {
        foreach ([ScientificProgramAccess::PLANS,ScientificProgramAccess::APPROVE,ScientificProgramAccess::ASSIGN] as $permission) $this->access->authorize($actor,$permission);
        AcademicPlanContext::assertReady();
        if (!$codes || count(array_unique($codes))!==count($codes) || array_diff($codes,array_keys(self::DECISIONS))) $this->fail('explicit_approved_programs_required');
        foreach ($codes as $code) abort_unless($this->access->programs($actor)->where('program_code',$code)->count()===1,403);
    }
    private function bind(array $bundle): void
    {
        if (($bundle['contract']??null)!==ExamSources::APPROVED_CONTRACT || ($bundle['approved_authority']['revision']??null)!==ExamApprovedAuthority::REVISION) $this->fail('approved_bundle_required');
        HistoricalExamSchema::requireReady();
    }

    private function projection(User $actor, string $code, array $bundle): array
    {
        $program=$this->access->programs($actor)->where('program_code',$code)->firstOrFail();
        $versions=AcademicPlanVersion::where('academic_program_id',$program->getKey())->orderBy('version_number')->get();
        $source=$versions->firstWhere('academic_plan_version_id',$program->default_academic_plan_version_id);
        $blockers=[];
        if ($versions->isNotEmpty() && (!$source || $source->status!=='approved' || !$source->fixed_at)) $blockers[]='explicit_approved_default_required';
        if ($versions->isEmpty() && !in_array($program->plan_state,['legacy','preparing'],true)) $blockers[]='legacy_state_changed';
        if ($versions->isEmpty() && DB::table(HistoricalExamSchema::OUTCOMES.' as h')->join('student_course_registrations as r','r.student_course_registration_id','=','h.student_course_registration_id')
            ->join('course_offerings as o','o.course_offering_id','=','r.course_offering_id')->where('o.academic_program_id',$program->getKey())->exists()) $blockers[]='legacy_adopted_outcome_context_requires_review';
        $context=new AcademicPlanContext((int)$program->getKey(),$source?->getKey());
        $members=$context->courses()->with('course','requirementMapping.requirementGroup')->orderBy('program_course_id')->get();
        $groups=$context->constrain(AcademicRequirementGroup::query())->orderBy('requirement_group_id')->get();
        $decision=self::DECISIONS[$code];$changes=[];
        $total=$source?->total_credit_hours??$program->total_credit_hours;
        if ((int)$total!==$decision['total'][0] || $total===null) $blockers[]='reviewed_plan_total_changed';
        foreach ($decision['courses'] as $courseCode=>[$old,$new]) {
            $candidates=$members->filter(fn($m)=>$m->course?->course_code===$courseCode);
            $expected=array_values(array_filter($bundle['snapshot']['courses'],fn($c)=>$c['course_code']===$courseCode));
            if ($candidates->count()!==1 || count($expected)!==1) { $blockers[]='course_identity_unproven:'.$courseCode; continue; }
            $member=$candidates->first();$course=$member->course;
            if (!$member->is_active || !$course->is_active || ExamApprovedAuthority::label($course->course_name)!==ExamApprovedAuthority::label($expected[0]['course_name'])
                || (int)$course->credit_hours!==(int)$expected[0]['credit_hours'] || ApplicableCourseCredits::planHours($member)!==$old) $blockers[]='course_context_changed:'.$courseCode;
            $g=$member->requirementMapping?->requirementGroup;
            if (!$g || !$g->is_active || [$g->requirement_scope,$g->requirement_type,(int)$g->required_credit_hours]!==array_slice($decision['group'],0,3)) $blockers[]='requirement_context_changed:'.$courseCode;
            $changes[]=['course_id'=>(int)$course->getKey(),'course_code'=>$courseCode,'program_course_id'=>(int)$member->getKey(), 'requirement_group_id'=>$g?->getKey(), 'before'=>$old,'after'=>$new];
        }
        return CompletionData::canonical(['program_id'=>(int)$program->getKey(),'program_code'=>$code,'source_version_id'=>$source?->getKey(),
            'before'=>['program'=>$program->toArray(),'version'=>$source?->toArray(),'members'=>$members->toArray(),'groups'=>$groups->toArray()],
            'changes'=>$changes,'total'=>$decision['total'],'group'=>$decision['group'],'blockers'=>$blockers]);
    }

    public function preview(User $actor,array $bundle,array $codes): array
    {
        $this->authorize($actor,$codes);$this->bind($bundle);sort($codes);
        return $this->tx->snapshot(function()use($actor,$bundle,$codes) {
            $programs=array_map(fn($code)=>$this->projection($actor,$code,$bundle),$codes);
            $review=CompletionData::canonical(['contract'=>self::CONTRACT,'actor_id'=>(int)$actor->getKey(),'bundle_digest'=>CompletionData::hash($bundle),
                'authority_hashes'=>$bundle['approved_authority']['document_hashes'],'revision'=>$this->tx->revision(),'program_codes'=>$codes,'programs'=>$programs]);
            return ['status'=>collect($programs)->contains(fn($p)=>$p['blockers'])?'BLOCKED':'READY','writes_performed'=>false,'review'=>$review,'review_hash'=>CompletionData::hash($review),
                'note'=>'Context review only: canonical approval validates the entire resulting plan inside the atomic writer and can reject it without publishing any stage. Publication affects future admissions only; existing student assignments, origins and results are preserved.'];
        });
    }

    public function apply(User $actor,array $bundle,array $review,string $hash,string $id,string $expected,bool $resume): array
    {
        $codes=$review['program_codes']??[];$this->authorize($actor,$codes);$this->bind($bundle);
        if (!Uuid::isValid($id) || ($review['contract']??null)!==self::CONTRACT || ($review['actor_id']??null)!==(int)$actor->getKey()
            || !hash_equals(CompletionData::hash($review),$hash) || !hash_equals($review['bundle_digest'],CompletionData::hash($bundle))) $this->fail('review_invalid');
        $digest=CompletionData::hash([$hash,(int)$actor->getKey()]);
        return $this->tx->run(function()use($actor,$bundle,$review,$hash,$id,$expected,$resume,$digest,$codes) {
            $this->authorize($actor,$codes);
            foreach ($review['programs'] as $p) $this->access->programs($actor)->whereKey($p['program_id'])->lockForUpdate()->firstOrFail();
            if ($r=$this->receipts->find('exam_catalog_result',$id)) {
                if ($r['actor_id']!==(int)$actor->getKey() || !hash_equals($r['value']['digest'],$digest)) $this->fail('operation_identity_changed');
                return $r['value']['result'];
            }
            // One atomic, at most three-program publication has no partial committed stages to resume.
            if ($resume) $this->fail('no_partial_operation_to_resume');
            if ($expected!==$review['revision'] || $expected!==$this->tx->revision()) $this->fail('stale');
            $fresh=$this->preview($actor,$bundle,$codes);
            if ($fresh['status']!=='READY' || !hash_equals($hash,$fresh['review_hash'])) $this->fail('review_changed_or_blocked');
            $published=[];$workflow=app(AcademicPlanWorkflow::class);
            foreach ($review['programs'] as $p) {
                $programId=$p['program_id'];$sourceId=$p['source_version_id'];
                $assignments=DB::table('student_academic_plan_assignments')->where('academic_program_id',$programId)->orderBy('student_academic_plan_assignment_id')->get()->toArray();
                if (!$sourceId) $sourceId=$workflow->fixTransition($actor,$programId,['revision'=>$this->tx->revision(),'confirmed'=>true])['current_version_id'];
                $copy=$workflow->copy($actor,$programId,$sourceId,['revision'=>$this->tx->revision(),'label'=>'تصحيح الساعات المعتمد — '.$p['program_code']]);
                $versionId=$copy['version']['academic_plan_version_id'];$version=AcademicPlanVersion::findOrFail($versionId);
                $event=DB::table('academic_plan_events')->insertGetId(['academic_program_id'=>$programId,'academic_plan_version_id'=>$versionId,
                    'actor_user_id'=>$actor->getKey(),'action'=>'historical_exam_credit_decision','context'=>CompletionData::json(['before'=>$p['changes'],'total'=>$p['total'],
                        'group'=>$p['group'],'authority_hashes'=>$review['authority_hashes'],'operation_id'=>$id,'bundle_digest'=>$review['bundle_digest']]),'created_at'=>now()], 'academic_plan_event_id');
                foreach ($p['changes'] as $change) {
                    $member=ProgramCourse::where('academic_plan_version_id',$versionId)->where('course_id',$change['course_id'])->sole();
                    $last=DB::table(HistoricalExamSchema::PLAN)->where('program_course_id',$member->getKey())->max('revision');
                    DB::table(HistoricalExamSchema::PLAN)->insert(['program_course_id'=>$member->getKey(),'revision'=>1+(int)$last,'credit_hours'=>$change['after'],
                        'academic_plan_event_id'=>$event,'created_at'=>now('UTC')->format('Y-m-d H:i:s')]);
                }
                $targetGroup=AcademicRequirementGroup::where('academic_plan_version_id',$versionId)->where('requirement_scope',$p['group'][0])->where('requirement_type',$p['group'][1])->sole();
                if ((int)$targetGroup->required_credit_hours!==$p['group'][2] || !$targetGroup->is_active) $this->fail('group_changed');
                if ($p['group'][2]!==$p['group'][3]) $targetGroup->update(['required_credit_hours'=>$p['group'][3]]);
                $version->update(['total_credit_hours'=>$p['total'][1]]);
                $workflow->approve($actor,$programId,$versionId,['revision'=>$this->tx->revision(),'confirmed'=>true]);
                $workflow->setDefault($actor,$programId,$versionId,['revision'=>$this->tx->revision(),'confirmed'=>true]);
                // Legacy fixation adds assignments; publication must never replace them or transfer students.
                if ($p['source_version_id'] && CompletionData::hash($assignments)!==CompletionData::hash(DB::table('student_academic_plan_assignments')->where('academic_program_id',$programId)->orderBy('student_academic_plan_assignment_id')->get()->toArray())) $this->fail('student_assignments_changed');
                $published[]=['program_id'=>$programId,'program_code'=>$p['program_code'],'source_version_id'=>$sourceId,'published_version_id'=>$versionId,'event_id'=>$event,
                    'published_hash'=>$this->versionHash($versionId)];
            }
            $result=CompletionData::canonical(['status'=>'COMPLETE','operation_id'=>$id,'program_codes'=>$codes,'programs'=>$published,'student_transfers'=>0]);
            $this->receipts->put($actor,'exam_catalog_result',$id,['digest'=>$digest,'review'=>$review,'result'=>$result]);
            return $result;
        });
    }

    private function versionHash(int $id): string
    {
        $members=ProgramCourse::where('academic_plan_version_id',$id)->orderBy('program_course_id')->get();
        return CompletionData::hash([AcademicPlanVersion::findOrFail($id)->toArray(),$members->toArray(),
            AcademicRequirementGroup::where('academic_plan_version_id',$id)->orderBy('requirement_group_id')->get()->toArray(),
            DB::table(HistoricalExamSchema::PLAN)->whereIn('program_course_id',$members->modelKeys())->orderBy('credit_evidence_id')->get()->toArray()]);
    }

    public function status(User $actor,string $id,?array $bundle=null): array
    {
        $receipt=$this->receipts->find('exam_catalog_result',$id);abort_unless($receipt,404);
        $value=$receipt['value'];$this->authorize($actor,$value['result']['program_codes']);HistoricalExamSchema::requireReady();
        abort_unless($receipt['actor_id']===(int)$actor->getKey(),403);
        if ($bundle && !hash_equals($value['review']['bundle_digest'],CompletionData::hash($bundle))) $this->fail('bundle_changed');
        return $this->tx->snapshot(function()use($value) {
            foreach ($value['result']['programs'] as $p) if (!hash_equals($p['published_hash'],$this->versionHash($p['published_version_id']))) $this->fail('publication_changed');
            return $value['result']+['publication_verified'=>true];
        });
    }
}
