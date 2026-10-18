<?php

namespace App\Services\CatalogCompletion;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, AcademicProgram, Student, User, UserActivityLog};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, DataScopeService, ScientificPlanChangeService};
use App\Support\ScientificProgramAccess;
use Illuminate\Support\Facades\DB;

/** Non-locking projection inside an existing fenced read or catalog-first writer transaction. */
final class CatalogCompletionPublication
{
    public function assertTarget(User $actor, array $target, string $manifestHash): array
    {
        app(ScientificProgramAccess::class)->authorize($actor);
        $program = app(ScientificProgramAccess::class)->programs($actor)->findOrFail((int)$target['academic_program_id']);
        return $this->validate($program,(int)$target['academic_plan_version_id'],$manifestHash);
    }

    /** Internal admission evidence, not permission to browse/manage scientific plans. */
    public function assertHistoricalAdmission(User $actor,AcademicProgram $program,int $versionId,array $context): array
    {
        $fresh=$actor->fresh();
        abort_unless($fresh&&$fresh->accountStatus?->status_code==='active'&&$fresh->hasPermission('students.view')
            &&$fresh->hasPermission('grades.view')&&$fresh->hasPermission('students.manage'),403);
        \Illuminate\Support\Facades\Gate::forUser($fresh)->authorize('create',Student::class);
        abort_unless(app(DataScopeService::class)->canMutateProgram($fresh,$program),403);
        if(DB::transactionLevel()<1)throw AcademicPlanException::conflict('academic_plan_transaction_required','Historical admission requires its catalog-first transaction.');
        app(AcademicCatalogTransaction::class)->revision(true);
        if(($context['kind']??null)!=='approved_now_from_recorded_source_curriculum_not_historical_approval'
            ||!preg_match('/^[a-f0-9]{64}$/',$context['profile_hash']??'')||empty($context['event_id'])||empty($context['program_key']))$this->fail('publication_evidence_missing');
        $proof=$this->validate($program,$versionId,$context['profile_hash'],(int)$context['event_id']);
        if(($proof['new_program_created']??false)!==true||($proof['source_version_id']??null)!==null
            ||($proof['reference_kind']??null)!==$context['kind']||($proof['program_key']??null)!==$context['program_key'])$this->fail('publication_evidence_missing');
        return $proof;
    }

    private function validate(AcademicProgram $program,int $versionId,string $manifestHash,?int $eventId=null): array
    {
        \App\Services\AcademicPlacementSchema::assertReady();
        $programId=(int)$program->getKey();
        $version = AcademicPlanVersion::where('academic_program_id', $programId)->find($versionId);
        if (!$version || $version->status !== 'approved' || !$version->fixed_at) $this->fail('target_not_approved_fixed');
        if ((int) $program->default_academic_plan_version_id !== $versionId) $this->fail('default_changed');
        $event = DB::table('academic_plan_events')->where('academic_program_id', $programId)->where('academic_plan_version_id', $versionId)
            ->where('action', 'catalog_completion_published')->where('context->manifest_hash', $manifestHash)
            ->when($eventId!==null,fn($q)=>$q->where('academic_plan_event_id',$eventId))->orderByDesc('academic_plan_event_id')->first();
        $proof = $event ? json_decode($event->context, true, flags: JSON_THROW_ON_ERROR) : null;
        if (!$proof && $eventId===null) {
            $log = UserActivityLog::where('action_code', 'catalog_completion.attested_plan')->where('description->manifest_hash', $manifestHash)
                ->where('description->result->academic_program_id', $programId)->where('description->result->version_id', $versionId)->orderByDesc('activity_log_id')->first();
            if ($log) $proof = json_decode($log->description, true, flags: JSON_THROW_ON_ERROR)['result'];
        }
        if (!$proof) $this->fail('publication_evidence_missing');
        // The canonical value normalizer is shared; no scientific endpoint, capabilities or
        // permission-bearing page projection is called from admission proof validation.
        $context=AcademicPlanContext::forVersion($version);
        $actual = app(ScientificPlanChangeService::class)->values(['version'=>$version,
            'groups'=>$context->constrain(\App\Models\AcademicRequirementGroup::query())->orderBy('requirement_scope')->orderBy('requirement_type')->orderBy('requirement_group_id')->get(),
            'courses'=>$context->courses()->with('requirementMapping.requirementGroup')->orderBy('course_id')->orderBy('program_course_id')->get()]);
        if (isset($proof['content_fingerprint']) ? !hash_equals($proof['content_fingerprint'], hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR)))
            : $actual !== ($proof['after'] ?? null)) $this->fail('published_content_changed');
        $sourceId = $proof['source_version_id'] ?? null;
        if ($version->source_version_id !== null && (int) $version->source_version_id !== (int) $sourceId) $this->fail('historical_source_changed');
        if ($sourceId !== null) {
            $source = AcademicPlanVersion::where('academic_program_id', $programId)->find($sourceId);
            if (!$source || !$source->fixed_at || !in_array($source->status, ['approved', 'transitional'], true)
                || !hash_equals($proof['source_fingerprint'] ?? '', self::sourceFingerprint((int) $sourceId))) $this->fail('historical_source_changed');
        }
        return $proof;
    }

    public static function sourceFingerprint(int $version): string
    {
        return hash('sha256', json_encode([
            DB::table('academic_requirement_groups')->where('academic_plan_version_id', $version)->orderBy('requirement_group_id')->get()->all(),
            DB::table('program_courses')->where('academic_plan_version_id', $version)->orderBy('program_course_id')->get()->all(),
            DB::table('program_course_requirement_groups as m')->join('program_courses as pc', 'pc.program_course_id', '=', 'm.program_course_id')
                ->where('pc.academic_plan_version_id', $version)->orderBy('m.program_course_id')->select('m.*')->get()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function fail(string $reason): never
    {
        throw AcademicPlanException::conflict('catalog_completion_'.$reason, 'Completion context changed ('.$reason.'); review current defaults/content and explicitly resume. No default was restored.');
    }
}
