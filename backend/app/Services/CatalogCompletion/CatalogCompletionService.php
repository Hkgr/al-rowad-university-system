<?php

namespace App\Services\CatalogCompletion;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, AcademicProgram, Course, User, UserActivityLog};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, AcademicPlanWorkflow, ScientificCourseManagementService, ScientificPlanChangeService};
use App\Support\{ScientificCourseAccess, ScientificProgramAccess};
use Illuminate\Support\Facades\{DB, Validator};
use Ramsey\Uuid\Uuid;

/** Maintenance CLI orchestration over canonical writers. No HTTP route or general bypass. */
final class CatalogCompletionService
{
    public function __construct(private AcademicCatalogTransaction $transaction, private AcademicPlanWorkflow $plans,
        private ScientificPlanChangeService $workspace, private ScientificCourseManagementService $catalog,
        private ScientificProgramAccess $access, private ScientificCourseAccess $courses, private CatalogCompletionProjection $projection) {}

    public function preview(User $actor, ApprovedCatalogManifest $manifest): array
    {
        $this->authorize($actor, $manifest);
        return $this->transaction->snapshot(function () use ($actor, $manifest) {
            $report = $this->projection->build($manifest);
            foreach ($report['programs'] as $id => &$p) {
                $program = $this->access->programs($actor)->find($id);
                if (!$program) continue;
                try {
                    $context = AcademicPlanContext::forProgram($id);
                    $current = $context->versionId ? $this->plans->versionProjection($actor, $program, AcademicPlanVersion::findOrFail($context->versionId)) : $this->plans->currentPlanProjection($actor, $program);
                    $p['before'] = $this->workspace->values($current);
                    $p['proposed'] = $this->target($manifest, $report, $id, fn ($identity) => str_starts_with($identity, 'id:') ? (int) substr($identity, 3) : $identity);
                    $p['academic_values_changed'] = !$this->sameValues($p['before'], $p['proposed']);
                } catch (AcademicPlanException $e) { $p['blockers'][] = $e->errorCode; }
            } unset($p);
            $report['groups'] = $this->groups($report);
            $report['students'] = [];
            foreach (array_keys($report['programs']) as $id) {
                $ids = DB::table('students')->where('academic_program_id', $id)->orderBy('student_id')->pluck('student_id')->all();
                foreach (array_chunk($ids, 50) as $chunk) {
                    try { array_push($report['students'], ...$this->plans->catalogCompletionStudentReadiness($actor, $id, $chunk)); }
                    catch (AcademicPlanException $e) {
                        foreach ($chunk as $studentId) $report['students'][] = ['student_id' => (int) $studentId, 'before' => ['available' => false, 'reason' => $e->errorCode], 'blockers' => [$e->errorCode]];
                    }
                }
            }
            $report['writes_performed'] = false;
            return $report;
        });
    }

    public function apply(User $actor, ApprovedCatalogManifest $manifest, string $revision, string $operationId, bool $resume = false): array
    {
        Validator::make(['revision' => $revision, 'operation_id' => $operationId], ['revision' => 'required|regex:/^[0-9]+$/', 'operation_id' => 'required|uuid'])->validate();
        $operationId = strtolower($operationId);
        $this->authorize($actor, $manifest);
        // Receipt lookup precedes epoch comparison, but NEVER authorization or the catalog lock.
        $start = $this->transaction->run(function () use ($actor, $manifest, $revision, $operationId, $resume) {
            $this->authorize($actor, $manifest);
            $existing = $this->find('started', $operationId);
            if ($existing) {
                $this->checkReceipt($actor, $manifest, $existing);
                $result = $this->find('result', $operationId);
                if (!$resume) return ['replay' => true, 'result' => $result['result'] ?? ['status' => 'IN_PROGRESS', 'operation_id' => $operationId]];
            }
            if ($this->transaction->revision() !== $revision) $this->fail('academic_catalog_stale', 'The reviewed catalog revision changed; preview again.');
            \App\Services\AcademicPlacementSchema::assertReady();
            $report = $this->projection->build($manifest);
            $groups = $this->groups($report);
            if (!$existing) $this->receipt($actor, $manifest, 'started', $operationId, ['groups' => array_column($groups, 'program_ids'),
                'reviewed_revision' => $revision, 'baseline' => $report['baseline']]);
            return ['replay' => false, 'groups' => $groups, 'revision' => $this->transaction->revision()];
        });
        if ($start['replay']) return $start['result'];
        $outcomes = []; $nextRevision = $start['revision'];
        foreach ($start['groups'] as $group) {
            $groupId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $operationId.'/programs/'.implode(',', $group['program_ids']));
            try {
                $outcome = $this->transaction->run(function () use ($actor, $manifest, $operationId, $groupId, $group, $nextRevision) {
                    $this->authorize($actor, $manifest);
                    \App\Services\AcademicPlacementSchema::assertReady();
                    if ($saved = $this->find('group', $groupId)) {
                        $this->checkReceipt($actor, $manifest, $saved);
                        foreach ($saved['result']['targets'] ?? [] as $target) app(CatalogCompletionPublication::class)->assertTarget($actor, $target, $manifest->hash);
                        return $saved['result'] + ['replayed_stage' => true];
                    }
                    if ($this->transaction->revision() !== $nextRevision) $this->fail('academic_catalog_stale', 'Another writer changed the reviewed stage; explicitly review and resume.');
                    $report = $this->projection->build($manifest);
                    $current = collect($this->groups($report))->first(fn ($g) => $g['program_ids'] === $group['program_ids']);
                    if (!$current || $current['blockers']) return ['program_ids' => $group['program_ids'], 'status' => 'BLOCKED', 'blockers' => $current['blockers'] ?? ['dependency_group_changed']];
                    $result = $this->publish($actor, $manifest, $report, $group['program_ids'], $operationId);
                    $result['revision'] = $this->transaction->revision();
                    $this->receipt($actor, $manifest, 'group', $groupId, $result);
                    return $result;
                });
                $outcomes[] = $outcome;
                // Replayed stages do not restore an obsolete epoch. The root resume check already fenced the current one.
                if (isset($outcome['revision']) && !isset($outcome['replayed_stage'])) $nextRevision = $outcome['revision'];
            } catch (\Illuminate\Validation\ValidationException|AcademicPlanException $e) {
                // Independent groups can proceed; the failed group's entire transaction rolls back.
                $outcomes[] = ['program_ids' => $group['program_ids'], 'status' => 'BLOCKED', 'blockers' => [$e instanceof AcademicPlanException ? $e->errorCode : 'canonical_plan_validation_failed'],
                    'detail' => $e instanceof \Illuminate\Validation\ValidationException ? $e->errors() : $e->getMessage()];
            }
        }
        $students = [];
        foreach ($outcomes as $outcome) foreach ($outcome['targets'] ?? [] as $target) {
            $students[$target['academic_program_id']] = $this->assignStudents($actor, $manifest, $target, $operationId);
        }
        return $this->transaction->run(function () use ($actor, $manifest, $operationId, $outcomes, $students) {
            $this->authorize($actor, $manifest);
            \App\Services\AcademicPlacementSchema::assertReady();
            $confirmed = $this->find('result', $operationId);
            if ($confirmed) $this->checkReceipt($actor, $manifest, $confirmed);
            $blocked = count(array_filter($outcomes, fn ($g) => $g['status'] === 'BLOCKED'));
            $studentBlocked = array_sum(array_map(fn ($r) => count($r['blocked']), $students));
            foreach ($outcomes as &$outcome) foreach ($outcome['targets'] ?? [] as $index => $target) {
                $outcome['targets'][$index]['context_valid_at_confirmation'] = true;
                try { app(CatalogCompletionPublication::class)->assertTarget($actor, $target, $manifest->hash); }
                catch (AcademicPlanException $e) {
                    $outcome['targets'][$index]['context_valid_at_confirmation'] = false;
                    $outcome['targets'][$index]['confirmation_blockers'] = [$e->errorCode];
                    $students[$target['academic_program_id']]['context_blockers'] = [$e->errorCode];
                    $studentBlocked++;
                }
                $pending = DB::table('students as s')->where('s.academic_program_id', $target['academic_program_id'])
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('student_academic_plan_assignments as a')->whereColumn('a.student_id', 's.student_id')
                        ->where('a.current_slot', 1)->where('a.academic_plan_version_id', $target['academic_plan_version_id']))->count();
                $students[$target['academic_program_id']]['remaining_count_at_confirmation'] = $pending;
                $studentBlocked += $pending;
            }
            unset($outcome);
            $result = ['operation_id' => $operationId, 'manifest_hash' => $manifest->hash, 'groups' => $outcomes, 'students' => $students,
                'status' => $blocked || $studentBlocked ? (count(array_filter($outcomes, fn ($g) => isset($g['targets']))) ? 'PARTIAL' : 'BLOCKED') : 'COMPLETE',
                'revision' => $this->transaction->revision()];
            $this->receipt($actor, $manifest, 'result', $operationId, $result);
            return $result;
        });
    }

    public function status(User $actor, ApprovedCatalogManifest $manifest, string $operationId): array
    {
        $this->authorize($actor, $manifest);
        Validator::make(['id' => $operationId], ['id' => 'required|uuid'])->validate();
        return $this->transaction->snapshot(function () use ($actor, $manifest, $operationId) {
            $started = $this->find('started', strtolower($operationId));
            if (!$started) return ['status' => 'NOT_FOUND', 'note' => 'Absence is not evidence that a disconnected write rolled back.'];
            $this->checkReceipt($actor, $manifest, $started);
            return $this->find('result', strtolower($operationId))['result'] ?? ['status' => 'IN_PROGRESS', 'operation_id' => $operationId, 'started' => $started['result']];
        });
    }

    public function verify(User $actor, ApprovedCatalogManifest $manifest): array
    {
        $this->authorize($actor, $manifest);
        return $this->transaction->snapshot(function () use ($actor, $manifest) {
            $report = $this->projection->build($manifest); $result = [];
            foreach ($report['programs'] as $id => $p) {
                $proof = DB::table('academic_plan_events')->where('academic_program_id', $id)->where('action', 'catalog_completion_published')
                    ->where('context->manifest_hash', $manifest->hash)->orderByDesc('academic_plan_event_id')->first();
                $saved = $proof ? json_decode($proof->context, true, flags: JSON_THROW_ON_ERROR) : null;
                if (!$saved) {
                    $attestation = UserActivityLog::where('action_code', 'catalog_completion.attested_plan')->where('description->manifest_hash', $manifest->hash)
                        ->where('description->result->academic_program_id', $id)->orderByDesc('activity_log_id')->first();
                    if ($attestation) $saved = json_decode($attestation->description, true, flags: JSON_THROW_ON_ERROR)['result'];
                }
                $versionId = $proof?->academic_plan_version_id ?? $saved['version_id'] ?? null;
                $students = $versionId ? DB::table('students as s')->where('s.academic_program_id', $id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('student_academic_plan_assignments as a')
                    ->whereColumn('a.student_id', 's.student_id')->where('a.current_slot', 1)->where('a.academic_plan_version_id', $versionId))->count() : $p['student_count'];
                $result[$id] = ['academic_program_id' => $id, 'version_id' => $versionId, 'default_matches' => $versionId && (int) $p['default_version_id'] === (int) $versionId,
                    'students_not_on_completed_plan' => $students, 'student_count' => $p['student_count'], 'blockers' => $p['blockers']];
                if ($saved) {
                    try {
                        app(CatalogCompletionPublication::class)->assertTarget($actor, ['academic_program_id' => $id, 'academic_plan_version_id' => $versionId], $manifest->hash);
                        $result[$id]['publication_context_matches'] = true;
                    } catch (AcademicPlanException $e) {
                        $result[$id]['publication_context_matches'] = false;
                        $result[$id]['blockers'][] = $e->errorCode;
                    }
                    $program = $this->access->programs($actor)->findOrFail($id);
                    $actual = $this->workspace->values($this->plans->versionProjection($actor, $program, AcademicPlanVersion::findOrFail($versionId)));
                    $prior = $saved['before'] ?? $actual; // A no-op attestation has identical before/after values.
                    $result[$id]['before'] = ['memberships' => count($prior['courses']), 'requirement_groups' => count($prior['groups']), 'total_credit_hours' => $prior['total_credit_hours']];
                    $result[$id]['after'] = ['memberships' => count($actual['courses']), 'requirement_groups' => count($actual['groups']), 'total_credit_hours' => $actual['total_credit_hours']];
                    $result[$id]['plan_content_matches'] = isset($saved['content_fingerprint'])
                        ? hash_equals($saved['content_fingerprint'], hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR))) : $actual === $saved['after'];
                    $result[$id]['historical_source_matches'] = $saved['source_version_id'] === null
                        || $this->sourceFingerprint((int) $saved['source_version_id']) === $saved['source_fingerprint'];
                }
            }
            return ['writes_performed' => false, 'manifest_hash' => $manifest->hash, 'revision' => $this->transaction->revision(), 'programs' => $result,
                'schema_prerequisites' => $report['schema_prerequisites'],
                'status' => $report['can_apply'] && collect($result)->every(fn ($r) => $r['default_matches'] && $r['students_not_on_completed_plan'] === 0
                    && !$r['blockers'] && ($r['plan_content_matches'] ?? false) && ($r['historical_source_matches'] ?? false)) ? 'COMPLETE' : 'PARTIAL/BLOCKED'];
        });
    }

    private function publish(User $actor, ApprovedCatalogManifest $manifest, array $report, array $programIds, string $operationId): array
    {
        $sources = []; $before = []; $targets = []; $definitions = []; $originMap = [];
        foreach ($programIds as $id) {
            $program = $this->access->programs($actor)->lockForUpdate()->findOrFail($id);
            if (AcademicPlanVersion::where('academic_program_id', $id)->where('status', 'draft')->exists()) $this->fail('catalog_completion_existing_draft', 'Resolve existing drafts explicitly before completion.');
            $context = AcademicPlanContext::forProgram($id);
            $projection = $context->versionId ? $this->plans->version($actor, $id, $context->versionId) : $this->plans->currentPlanProjection($actor, $program);
            $before[$id] = $this->workspace->values($projection);
            $sources[$id] = $context->versionId;
        }
        $historyBefore = $this->academicFingerprint();
        // All affected old sources are fixed before ANY catalog-origin or curriculum write.
        foreach ($sources as $id => &$source) if ($source === null) $source = (int) $this->plans->fixTransition($actor, $id, $this->confirmation())['current_version_id']; unset($source);
        $corrections = array_values(array_filter($report['rows'], fn ($r) => in_array($r['program_id'], $programIds, true) && !empty($r['code_correction'])));
        if ($corrections) app(ApprovedCatalogCodeCorrection::class)->apply($actor, $manifest, $operationId, $corrections);
        foreach ($report['rows'] as $row) if (in_array($row['program_id'], $programIds, true) && $row['state'] === 'create') {
            $key = $row['new_course_key'];
            if (isset($definitions[$key]) && $definitions[$key] !== $row['definition']) $this->fail('catalog_completion_shared_definition_mismatch', 'Shared subject definitions disagree.');
            $definitions[$key] = $row['definition'];
        }
        if (count($definitions) > 50) $this->fail('catalog_completion_batch_limit', 'A connected publication exceeds the existing 50-origin limit.');
        foreach ($definitions as $key => $definition) {
            unset($definition['key']);
            $departments = [];
            foreach ($report['rows'] as $r) if (($r['new_course_key'] ?? null) === $key && in_array($r['program_id'], $programIds, true)) $departments[] = (int) $report['programs'][$r['program_id']]['definition']['department_id'];
            $definition['departments'] = array_map(fn ($id) => ['department_id' => $id, 'is_primary' => false], array_values(array_unique($departments)));
            $created = $this->catalog->saveCourse($actor, null, $definition + ['revision' => $this->transaction->revision()]);
            $id = (int) $created['data']['course_id']; $originMap[$key] = $id;
            UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'academic_structure', 'action_code' => 'catalog_completion.origin',
                'description' => json_encode(['manifest_hash' => $manifest->hash, 'operation_id' => $operationId, 'key' => $key,
                    'course_id' => $id, 'after' => $this->projection->fields(Course::findOrFail($id))], JSON_THROW_ON_ERROR)]);
        }
        $resolve = fn ($identity) => str_starts_with($identity, 'id:') ? (int) substr($identity, 3) : ($originMap[substr($identity, 4)] ?? null);
        foreach ($report['prerequisites'] as $edge) if (in_array((int) $edge['source']['program_id'], $programIds, true)) {
            $from = $resolve($edge['from']); $to = $resolve($edge['to']);
            if (!$from || !$to || $from === $to) $this->fail('catalog_completion_prerequisite_invalid', 'Prerequisite identity is unresolved.');
            if (!DB::table('course_prerequisites')->where('course_id', $from)->where('prerequisite_course_id', $to)->exists()) {
                // The canonical service protects used origins and checks the complete graph.
                $existing = DB::table('course_prerequisites')->where('course_id', $from)->get(['prerequisite_course_id', 'minimum_result_status_id'])->map(fn ($r) => (array) $r)->all();
                $existing[] = ['prerequisite_course_id' => $to, 'minimum_result_status_id' => null];
                $this->catalog->saveCourse($actor, $from, ['revision' => $this->transaction->revision(), 'impact_confirmed' => true, 'prerequisites' => $existing]);
            }
        }
        $this->transaction->assertAcyclic();
        foreach ($programIds as $id) {
            $source = $sources[$id];
            $target = $this->target($manifest, $report, $id, $resolve);
            $sourceProjection = $this->plans->version($actor, $id, $source);
            $sourceFingerprint = $this->sourceFingerprint($source);
            $sourceVersion = AcademicPlanVersion::findOrFail($source);
            if ($sourceVersion->status === 'approved' && $this->sameValues($this->workspace->values($sourceProjection), $target)) {
                if (!DB::table('academic_plan_events')->where('academic_plan_version_id', $source)->where('action', 'catalog_completion_published')->where('context->manifest_hash', $manifest->hash)->exists()
                    && !UserActivityLog::where('action_code', 'catalog_completion.attested_plan')->where('description->result->version_id', $source)->where('description->manifest_hash', $manifest->hash)->exists()) {
                    $this->receipt($actor, $manifest, 'attested_plan', $operationId, ['academic_program_id' => $id, 'version_id' => $source,
                        'source_version_id' => $sourceVersion->source_version_id, 'content_fingerprint' => hash('sha256', json_encode($before[$id], JSON_THROW_ON_ERROR)),
                        'source_fingerprint' => $sourceVersion->source_version_id ? $this->sourceFingerprint((int) $sourceVersion->source_version_id) : null]);
                }
                $program = AcademicProgram::findOrFail($id); $targetName = $report['programs'][$id]['definition']['target_name'];
                $renamed = $program->program_name !== $targetName;
                if ($renamed) {
                    UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'academic_structure', 'action_code' => 'catalog_completion.program_name',
                        'description' => json_encode(['operation_id' => $operationId, 'academic_program_id' => $id, 'before' => $program->program_name, 'after' => $targetName], JSON_THROW_ON_ERROR)]);
                    $program->update(['program_name' => $targetName]);
                }
                $targets[] = ['academic_program_id' => $id, 'academic_plan_version_id' => $source, 'changed' => $renamed]; continue;
            }
            $draft = $this->plans->copy($actor, $id, $source, ['revision' => $this->transaction->revision(), 'label' => 'الخطة المكتملة المعتمدة']);
            $versionId = (int) $draft['version']->getKey();
            $this->plans->saveRequirements($actor, $id, $versionId, $target['requirements'] + ['revision' => $this->transaction->revision()]);
            foreach ($draft['courses'] as $old) if (!in_array((int) $old->course_id, array_column($target['courses'], 'course_id'), true)) {
                $this->plans->saveMembership($actor, $id, $versionId, $old->course_id, $this->confirmation(), true);
            }
            foreach ($target['courses'] as $membership) {
                $courseId = $membership['course_id']; unset($membership['course_id']);
                $this->plans->saveMembership($actor, $id, $versionId, $courseId, $membership + ['revision' => $this->transaction->revision()]);
            }
            $approved = $this->plans->approve($actor, $id, $versionId, $this->confirmation());
            $this->plans->setDefault($actor, $id, $versionId, $this->confirmation());
            $program = AcademicProgram::findOrFail($id);
            $nameBefore = $program->program_name;
            $program->update(['program_name' => $report['programs'][$id]['definition']['target_name']]);
            $after = $this->workspace->values($approved);
            DB::table('academic_plan_events')->insert(['academic_program_id' => $id, 'academic_plan_version_id' => $versionId,
                'action' => 'catalog_completion_published', 'actor_user_id' => $actor->getKey(), 'created_at' => now(),
                'context' => json_encode(['manifest_hash' => $manifest->hash, 'operation_id' => $operationId, 'source_version_id' => $source,
                    'source_fingerprint' => $sourceFingerprint, 'before' => $before[$id], 'after' => $after,
                    'program_name_before' => $nameBefore, 'program_name_after' => $program->program_name,
                    'scope' => 'new_default_and_separately_guarded_existing_assignments'], JSON_THROW_ON_ERROR)]);
            if ($sourceFingerprint !== $this->sourceFingerprint($source)) $this->fail('catalog_completion_history_changed', 'The pinned source changed; publication rolled back.');
            $targets[] = ['academic_program_id' => $id, 'academic_plan_version_id' => $versionId, 'changed' => true];
        }
        if ($historyBefore !== $this->academicFingerprint()) $this->fail('catalog_completion_history_changed', 'Academic evidence changed during completion; publication rolled back.');
        return ['program_ids' => $programIds, 'status' => 'PUBLISHED', 'targets' => $targets, 'academic_history_fingerprint' => $historyBefore];
    }

    private function target(ApprovedCatalogManifest $manifest, array $report, int $programId, callable $resolve): array
    {
        $courses = [];
        foreach ($report['rows'] as $row) if ($row['program_id'] === $programId && in_array($row['state'], ['reuse', 'create'], true)) {
            $s = $row['source']; $id = $resolve($row['identity']);
            $placement = ['academic_level_id' => $s['study_year'] === null ? null : $this->orderedId('academic_levels', 'academic_level_id', 'level_order', (int) $s['study_year']),
                'recommended_semester_id' => $s['semester_order'] === null ? null : $this->orderedId('semesters', 'semester_id', 'semester_order', (int) $s['semester_order'])];
            if (isset($s['elective_source'])) {
                $e = $s['elective_source'];
                $placement = ['academic_level_id' => ApprovedCatalogManifest::nullableInt($e['level_id_baseline']), 'recommended_semester_id' => ApprovedCatalogManifest::nullableInt($e['semester_id_baseline'])];
            }
            $member = ['course_id' => $id, 'requirement_scope' => $s['requirement_scope'], 'course_type' => $s['course_type'], 'is_active' => true] + $placement;
            if (isset($courses[$id]) && $courses[$id] !== $member) $this->fail('catalog_completion_membership_ambiguous', 'The same subject has conflicting group or placement decisions.');
            $courses[$id] = $member;
        }
        // The workbook is not permission to discard later valid elective alternatives.
        $sourceId = AcademicPlanContext::forProgram($programId)->versionId;
        $existing = DB::table('program_courses as pc')->join('courses as c', 'c.course_id', '=', 'pc.course_id')
            ->join('program_course_requirement_groups as m', 'm.program_course_id', '=', 'pc.program_course_id')
            ->join('academic_requirement_groups as g', 'g.requirement_group_id', '=', 'm.requirement_group_id')
            ->where('pc.academic_program_id', $programId)->where('pc.academic_plan_version_id', $sourceId)
            ->where('pc.course_type', 'elective')->where('pc.is_active', true)->where('c.is_active', true)->where('g.is_active', true)
            ->get(['pc.course_id', 'pc.academic_level_id', 'pc.recommended_semester_id', 'g.requirement_scope']);
        foreach ($existing as $e) if (!isset($courses[$e->course_id])) $courses[$e->course_id] = ['course_id' => (int) $e->course_id,
            'requirement_scope' => $e->requirement_scope, 'course_type' => 'elective', 'is_active' => true,
            'academic_level_id' => ApprovedCatalogManifest::nullableInt($e->academic_level_id), 'recommended_semester_id' => ApprovedCatalogManifest::nullableInt($e->recommended_semester_id)];
        if (count($courses) > 200) $this->fail('catalog_completion_membership_limit', 'A target exceeds the existing 200-membership save limit.');
        $groups = array_map(fn ($r) => ['requirement_scope' => $r['requirement_scope'], 'requirement_type' => $r['requirement_type'],
            'required_credit_hours' => (int) $r['required_credit_hours'], 'is_active' => (bool) $r['is_active']], $manifest->rows('Requirements', $programId));
        return ['courses' => array_values($courses), 'requirements' => ['total_credit_hours' => (int) $report['programs'][$programId]['definition']['target_total'], 'groups' => $groups]];
    }

    private function sameValues(array $before, array $target): bool
    {
        $normalize = function (array $value): array {
            foreach (['groups', 'courses'] as $key) { foreach ($value[$key] as &$r) ksort($r); unset($r); usort($value[$key], fn ($a, $b) => json_encode($a) <=> json_encode($b)); }
            return $value;
        };
        return $normalize($before) === $normalize(['total_credit_hours' => $target['requirements']['total_credit_hours'], 'groups' => $target['requirements']['groups'], 'courses' => $target['courses']]);
    }

    private function orderedId(string $table, string $key, string $order, int $value): int
    {
        $ids = DB::table($table)->where($order, $value)->pluck($key);
        if ($ids->count() !== 1) $this->fail('catalog_completion_placement_ambiguous', 'Academic placement order is missing or ambiguous.');
        return (int) $ids->sole();
    }

    private function assignStudents(User $actor, ApprovedCatalogManifest $manifest, array $target, string $operationId): array
    {
        $id = $target['academic_program_id']; $version = $target['academic_plan_version_id'];
        $ids = DB::table('students')->where('academic_program_id', $id)->orderBy('student_id')->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        $report = ['already_assigned' => [], 'transferred' => [], 'blocked' => []];
        foreach (array_chunk($ids, 50) as $chunk) {
            try { $preview = $this->plans->previewCatalogCompletion($actor, $id, $version, $chunk, $manifest->hash); }
            catch (AcademicPlanException $e) {
                foreach ($chunk as $studentId) $report['blocked'][] = ['student_id' => $studentId, 'blockers' => [$e->errorCode]];
                break; // Saved stages remain intact; an operator must review the changed context.
            }
            $eligible = [];
            foreach ($preview['students'] as $student) {
                if ($student['from_version_id'] === $version) $report['already_assigned'][] = $student['student_id'];
                elseif ($student['blockers']) $report['blocked'][] = ['student_id' => $student['student_id'], 'blockers' => $student['blockers'],
                    'before_available' => $student['before']['available'], 'after_available' => $student['after']['available']];
                else $eligible[] = $student['student_id'];
            }
            if (!$eligible) continue;
            $request = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $operationId.'/assignment/'.$id.'/'.implode(',', $eligible));
            try {
                $this->plans->transferCatalogCompletion($actor, $id, $version, ['request_id' => $request, 'revision' => $preview['revision'], 'manifest_hash' => $manifest->hash, 'student_ids' => $eligible]);
                array_push($report['transferred'], ...$eligible);
            } catch (AcademicPlanException $e) {
                foreach ($eligible as $studentId) $report['blocked'][] = ['student_id' => $studentId, 'blockers' => [$e->errorCode]];
            }
        }
        return $report;
    }

    /** Connected new/shared origins are published in one bounded transaction. */
    private function groups(array $report): array
    {
        $sets = array_map(fn ($id) => [$id], array_keys($report['programs'])); $owners = [];
        foreach ($report['rows'] as $r) if (($r['source']['action'] ?? null) === 'MATCH_OR_CREATE' && empty($r['source']['identity_candidate_id'])) $owners[$r['source']['target_code']][] = $r['program_id'];
        foreach ($owners as $ids) {
            $merged = []; $rest = [];
            foreach ($sets as $set) { if (array_intersect($set, $ids)) $merged = array_merge($merged, $set); else $rest[] = $set; }
            sort($merged); $sets = [...$rest, $merged];
        }
        usort($sets, fn ($a, $b) => $a[0] <=> $b[0]);
        return array_map(fn ($ids) => ['program_ids' => $ids, 'blockers' => array_values(array_unique(array_merge(...array_map(fn ($id) => $report['programs'][$id]['blockers'], $ids))))], $sets);
    }

    private function authorize(User $actor, ApprovedCatalogManifest $manifest): void
    {
        foreach ([ScientificProgramAccess::PLANS, ScientificProgramAccess::APPROVE, ScientificProgramAccess::ASSIGN, ScientificProgramAccess::MANAGE] as $permission) $this->access->authorize($actor, $permission);
        $this->courses->authorize($actor, true); AcademicPlanContext::assertReady();
        $ids = array_map('intval', array_column($manifest->programs(), 'program_id'));
        abort_unless($this->access->programs($actor)->whereIn('academic_program_id', $ids)->count() === count($ids), 403);
        $courseIds = array_unique(array_filter(array_map('intval', array_column($manifest->rows('Curriculum'), 'identity_candidate_id'))));
        $courseIds = array_unique(array_merge($courseIds, array_map('intval', array_filter(array_column($manifest->rows('Electives'), 'existing_course_id')))));
        abort_unless($this->courses->courses($actor)->whereIn('course_id', $courseIds)->count() === count($courseIds), 403);
    }

    private function find(string $stage, string $id): ?array
    {
        $log = UserActivityLog::where('action_code', 'catalog_completion.'.$stage)->where('description->operation_id', $id)->orderByDesc('activity_log_id')->first();
        if (!$log) return null;
        $receipt = json_decode($log->description, true, flags: JSON_THROW_ON_ERROR);
        if (isset($receipt['chunked'])) {
            $ref = $receipt['chunked'];
            $chunks = UserActivityLog::where('action_code', 'catalog_completion.receipt_chunk')->where('description->receipt_id', $ref['id'])->orderBy('activity_log_id')->get();
            if ($chunks->count() !== $ref['count']) $this->fail('catalog_completion_receipt_invalid', 'The durable receipt is incomplete.');
            $encoded = '';
            foreach ($chunks as $index => $chunk) {
                $value = json_decode($chunk->description, true, flags: JSON_THROW_ON_ERROR);
                if ($value['index'] !== $index) $this->fail('catalog_completion_receipt_invalid', 'Receipt chunks are out of order.');
                $encoded .= $value['value'];
            }
            $json = base64_decode($encoded, true);
            if ($json === false || !hash_equals($ref['sha256'], hash('sha256', $json))) $this->fail('catalog_completion_receipt_invalid', 'The durable receipt fingerprint differs.');
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }
        return $receipt;
    }

    private function receipt(User $actor, ApprovedCatalogManifest $manifest, string $stage, string $id, array $result): void
    {
        $value = ['operation_id' => $id, 'actor_id' => (int) $actor->getKey(), 'manifest_hash' => $manifest->hash, 'result' => $result];
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Existing activity descriptions are TEXT, not LONGTEXT. Never overflow them for large operator reports.
        if (strlen($json) > 48000) {
            $receiptId = (string) Uuid::uuid4(); $chunks = str_split(base64_encode($json), 32000);
            foreach ($chunks as $index => $chunk) UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'academic_structure',
                'action_code' => 'catalog_completion.receipt_chunk', 'description' => json_encode(['receipt_id' => $receiptId, 'index' => $index, 'value' => $chunk], JSON_THROW_ON_ERROR)]);
            $value = array_diff_key($value, ['result' => true]) + ['chunked' => ['id' => $receiptId, 'count' => count($chunks), 'sha256' => hash('sha256', $json)]];
        }
        UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'academic_structure', 'action_code' => 'catalog_completion.'.$stage,
            'description' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    private function checkReceipt(User $actor, ApprovedCatalogManifest $manifest, array $receipt): void
    {
        abort_unless((int) $receipt['actor_id'] === (int) $actor->getKey(), 403);
        if (!hash_equals($receipt['manifest_hash'], $manifest->hash)) $this->fail('catalog_completion_request_mismatch', 'The operation ID belongs to another manifest.');
    }

    private function sourceFingerprint(int $version): string
    {
        return CatalogCompletionPublication::sourceFingerprint($version);
    }

    private function academicFingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach (['student_course_registrations', 'student_course_results', 'student_grade_components', 'grade_approvals', 'grade_part_approvals', 'grade_part_approval_events', 'grade_audit_logs',
            'student_course_incomplete_cases', 'student_course_incomplete_events', 'student_progression_decisions', 'student_graduation_decisions', 'student_academic_terms'] as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table)) continue;
            $columns = DB::getSchemaBuilder()->getColumnListing($table);
            $columns = array_values(array_diff($columns, ['academic_plan_version_id', 'plan_program_course_id']));
            hash_update($hash, $table);
            foreach (DB::table($table)->select($columns)->orderBy($columns[0])->cursor() as $row) hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
        }
        return hash_final($hash);
    }

    private function confirmation(): array { return ['revision' => $this->transaction->revision(), 'confirmed' => true]; }
    private function fail(string $code, string $message): never { throw AcademicPlanException::conflict($code, $message); }
}
