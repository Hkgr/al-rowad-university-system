<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, AcademicProgram, Course, User, UserActivityLog};
use App\Support\{ScientificCourseAccess, ScientificProgramAccess};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** A coordinated save, not a new academic workflow. The existing workflow owns all transitions. */
final class ScientificPlanChangeService
{
    private const RECEIPT = 'academic_plan.workspace_result';

    public function __construct(private AcademicCatalogTransaction $transaction, private AcademicPlanWorkflow $plans,
        private ScientificProgramAccess $access, private ScientificCourseManagementService $catalog) {}

    public function save(User $actor, array $input): array
    {
        $v = $this->validate($input);
        $this->authorize($actor, $v);
        $digest = hash('sha256', json_encode($this->canonicalPayload($v), JSON_THROW_ON_ERROR));
        // Do NOT pass expected revision to run(): replay detection must happen under its lock FIRST.
        return $this->transaction->run(function () use ($actor, $v, $digest) {
            $this->authorize($actor, $v);
            if ($receipt = $this->receipt($v['request_id'])) {
                $this->authorizeReceipt($actor, $receipt);
                if (!hash_equals($receipt['digest'], $digest)) $this->fail('academic_plan_request_mismatch', 'معرّف الحفظ مستخدم لمحتوى مختلف؛ لم يُحفظ تغيير جديد.');
                return $receipt['result'];
            }
            if (!hash_equals($this->transaction->revision(), $v['revision'])) $this->fail('academic_catalog_stale', 'تغيرت البيانات؛ احتُفظ بإعدادك. راجع الحالة الحالية قبل الحفظ.');
            $bases = [];
            foreach ($v['targets'] as $target) {
                $program = $this->access->programs($actor)->lockForUpdate()->findOrFail($target['academic_program_id']);
                $base = $this->source($actor, $program, $target['source_version_id']);
                $before = $this->values($base);
                $proposed = $this->targetValues($target);
                // The UI always presents six inputs. An unchanged missing group's NULL input
                // is a placeholder, not an instruction to create or repair the legacy group.
                $existingGroups = array_column(array_map(fn ($g) => $g + ['identity' => $g['requirement_scope'].':'.$g['requirement_type']], $before['groups']), null, 'identity');
                $proposed['groups'] = array_values(array_filter($proposed['groups'], fn ($g) => $g['required_credit_hours'] !== null
                    || isset($existingGroups[$g['requirement_scope'].':'.$g['requirement_type']])));
                foreach ($proposed['groups'] as &$group) {
                    $identity = $group['requirement_scope'].':'.$group['requirement_type'];
                    if (isset($existingGroups[$identity])) $group['is_active'] = $existingGroups[$identity]['is_active'];
                } unset($group);
                if ($before !== $proposed) $bases[] = compact('program', 'base', 'before', 'target');
            }
            $outcomes = [];
            // Pin ALL legacy sources before any curriculum completion or catalog-origin creation.
            foreach ($bases as &$entry) {
                if (!$entry['base']['persisted']) {
                    $fixed = $this->plans->fixTransition($actor, $entry['program']->getKey(), $this->confirmation());
                    $entry['source_id'] = $fixed['current_version_id'];
                } else $entry['source_id'] = $entry['base']['version']->getKey();
            }
            unset($entry);
            $new = [];
            if ($bases !== []) foreach ($v['new_courses'] as $course) {
                $key = $course['key']; unset($course['key']);
                $created = $this->catalog->saveCourse($actor, null, $course + ['revision' => $this->transaction->revision()]);
                $new[$key] = (int) $created['data']['course_id'];
            }
            foreach ($bases as $entry) {
                $programId = (int) $entry['program']->getKey();
                $source = AcademicPlanVersion::findOrFail($entry['source_id']);
                $draft = $source->status === 'draft' ? $entry['base'] : $this->plans->copy($actor, $programId, $source->getKey(),
                    ['revision' => $this->transaction->revision(), 'label' => 'خطة الطلاب الجدد']);
                $versionId = (int) $draft['version']->getKey();
                $target = $entry['target'];
                $targetCourses = collect($target['courses'])->map(fn ($c) => array_replace($c, [
                    'course_id' => $c['course_id'] ?? $new[$c['new_course_key']]]))->all();
                $this->plans->saveRequirements($actor, $programId, $versionId,
                    $target['requirements'] + ['revision' => $this->transaction->revision()]);
                $wanted = array_column($targetCourses, 'course_id');
                foreach ($draft['courses'] as $old) if (!in_array((int) $old->course_id, $wanted, true)) {
                    $this->plans->saveMembership($actor, $programId, $versionId, $old->course_id, $this->confirmation(), true);
                }
                foreach ($targetCourses as $membership) {
                    $courseId = $membership['course_id']; unset($membership['course_id'], $membership['new_course_key']);
                    $this->plans->saveMembership($actor, $programId, $versionId, $courseId,
                        $membership + ['revision' => $this->transaction->revision()]);
                }
                $approved = $this->plans->approve($actor, $programId, $versionId, $this->confirmation());
                $this->plans->setDefault($actor, $programId, $versionId, $this->confirmation());
                $after = $this->values($approved);
                $change = ['request_id' => $v['request_id'], 'scope' => 'new_students_only', 'before' => $entry['before'],
                    'after' => $after, 'selected_program_ids' => array_column($v['targets'], 'academic_program_id'),
                    'selected_program_labels' => $this->access->programs($actor)->whereIn('academic_program_id', array_column($v['targets'], 'academic_program_id'))->pluck('program_name', 'academic_program_id')->all(),
                    'course_labels' => Course::whereIn('course_id', array_unique(array_merge(array_column($entry['before']['courses'], 'course_id'), array_column($after['courses'], 'course_id'))))
                        ->get(['course_id', 'course_code', 'course_name', 'credit_hours', 'theoretical_hours', 'practical_hours'])
                        ->keyBy('course_id')->toArray(), 'created_course_definitions' => $v['new_courses']];
                DB::table('academic_plan_events')->insert(['academic_program_id' => $programId, 'academic_plan_version_id' => $versionId,
                    'action' => 'workspace_saved', 'actor_user_id' => $actor->getKey(), 'context' => json_encode($change, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                // Full immutable differences belong in LONGTEXT plan events, not the
                // existing TEXT activity-log receipt (which must support multi-program saves).
                $outcomes[] = ['academic_program_id' => $programId, 'academic_plan_version_id' => $versionId];
            }
            $result = ['request_id' => $v['request_id'], 'changed' => $outcomes !== [], 'scope' => 'new_students_only',
                'targets' => $outcomes, 'created_courses' => $new, 'revision' => $this->transaction->revision()];
            // Operational outcome only. No-op receipts NEVER masquerade as academic changes/events.
            UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'academic_structure', 'action_code' => self::RECEIPT,
                'description' => json_encode(['request_id' => $v['request_id'], 'actor_id' => $actor->getKey(), 'digest' => $digest,
                    'program_ids' => array_column($v['targets'], 'academic_program_id'), 'requires_catalog_create' => $v['new_courses'] !== [],
                    'result' => $result], JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    public function result(User $actor, string $id): array
    {
        $this->authorize($actor);
        Validator::make(['request_id' => $id], ['request_id' => 'required|uuid'])->validate();
        $id = strtolower($id);
        return $this->transaction->snapshot(function () use ($actor, $id) {
            $receipt = $this->receipt($id);
            if (!$receipt) return ['status' => 'not_found', 'request_id' => $id]; // NOT rollback evidence.
            $this->authorizeReceipt($actor, $receipt);
            return ['status' => 'confirmed', 'result' => $receipt['result']];
        });
    }

    private function authorize(User $actor, ?array $v = null): void
    {
        foreach ([ScientificProgramAccess::PLANS, ScientificProgramAccess::APPROVE, ScientificProgramAccess::ASSIGN] as $permission) $this->access->authorize($actor, $permission);
        AcademicPlanContext::assertReady();
        if ($v) {
            $ids = array_column($v['targets'], 'academic_program_id');
            abort_unless($this->access->programs($actor)->whereIn('academic_program_id', $ids)->count() === count($ids), 403);
            if ($v['new_courses'] !== []) app(ScientificCourseAccess::class)->authorize($actor, true);
            $courseIds = collect($v['targets'])->flatMap(fn ($t) => array_column($t['courses'], 'course_id'))->filter()->unique()->values();
            abort_unless(app(ScientificCourseAccess::class)->courses($actor)->whereIn('course_id', $courseIds)->count() === $courseIds->count(), 403);
        }
    }

    private function authorizeReceipt(User $actor, array $receipt): void
    {
        abort_unless((int) $receipt['actor_id'] === (int) $actor->getKey(), 403);
        abort_unless($this->access->programs($actor)->whereIn('academic_program_id', $receipt['program_ids'])->count() === count($receipt['program_ids']), 403);
        if ($receipt['requires_catalog_create']) app(ScientificCourseAccess::class)->authorize($actor, true);
    }

    private function receipt(string $id): ?array
    {
        $rows = UserActivityLog::where('action_code', self::RECEIPT)->where('module_code', 'academic_structure')
            ->whereRaw('JSON_VALID(description) = 1')->where('description->request_id', $id)->limit(2)->get();
        if ($rows->count() > 1) $this->fail('academic_plan_request_ambiguous', 'نتيجة العملية غير محددة؛ يلزم مراجعتها.');
        return $rows->isEmpty() ? null : json_decode($rows->sole()->description, true, flags: JSON_THROW_ON_ERROR);
    }

    public function source(User $actor, AcademicProgram $program, ?int $versionId): array
    {
        if ($versionId !== null) {
            $version = AcademicPlanVersion::where('academic_program_id', $program->getKey())->findOrFail($versionId);
            if (!in_array($version->status, ['approved', 'transitional', 'draft'], true)) $this->fail('academic_plan_context_invalid', 'الخطة المختارة غير صالحة.');
            return $this->plans->versionProjection($actor, $program, $version);
        }
        if (AcademicPlanVersion::where('academic_program_id', $program->getKey())->exists()) $this->fail('academic_plan_context_invalid', 'تغير سياق البرنامج؛ حدّث العرض دون تبديل إعدادك.');
        return $this->plans->currentPlanProjection($actor, $program);
    }

    public function values(array $plan): array
    {
        $version = $plan['version'];
        return $this->normalizeValues(['total_credit_hours' => data_get($version, 'total_credit_hours'),
            'groups' => collect($plan['groups'])->map(fn ($g) => ['requirement_scope' => $g->requirement_scope,
                'requirement_type' => $g->requirement_type, 'required_credit_hours' => $g->required_credit_hours, 'is_active' => (bool) $g->is_active])->all(),
            'courses' => collect($plan['courses'])->map(fn ($c) => ['course_id' => (int) $c->course_id,
                'requirement_scope' => $c->requirementMapping?->requirementGroup?->requirement_scope, 'course_type' => $c->course_type,
                'academic_level_id' => $c->academic_level_id, 'recommended_semester_id' => $c->recommended_semester_id, 'is_active' => (bool) $c->is_active])->all()]);
    }

    private function targetValues(array $target): array
    {
        return $this->normalizeValues(['total_credit_hours' => $target['requirements']['total_credit_hours'],
            'groups' => array_map(fn ($g) => $g + ['is_active' => true], $target['requirements']['groups']),
            'courses' => array_map(function ($c) { if (!$c['course_id']) $c['course_id'] = 'new:'.$c['new_course_key']; unset($c['new_course_key']); return $c; }, $target['courses'])]);
    }

    private function normalizeValues(array $v): array
    {
        $v['total_credit_hours'] = $v['total_credit_hours'] === null ? null : (int) $v['total_credit_hours'];
        foreach ($v['groups'] as &$g) $g['required_credit_hours'] = $g['required_credit_hours'] === null ? null : (int) $g['required_credit_hours']; unset($g);
        foreach ($v['courses'] as &$c) {
            foreach (['academic_level_id', 'recommended_semester_id'] as $k) $c[$k] = $c[$k] === null ? null : (int) $c[$k];
            $c['is_active'] = (bool) $c['is_active']; ksort($c);
        } unset($c);
        foreach ($v['groups'] as &$g) { $g['is_active'] = (bool) $g['is_active']; ksort($g); } unset($g);
        usort($v['groups'], fn ($a, $b) => [$a['requirement_scope'], $a['requirement_type']] <=> [$b['requirement_scope'], $b['requirement_type']]);
        usort($v['courses'], fn ($a, $b) => (string) $a['course_id'] <=> (string) $b['course_id']);
        return $v;
    }

    private function canonicalPayload(array $v): array
    {
        unset($v['request_id'], $v['revision']);
        $normalize = function ($value) use (&$normalize) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($normalize, $value);
        };
        return $normalize($v);
    }

    private function validate(array $input): array
    {
        if (array_diff(array_keys($input), ['request_id', 'revision', 'confirmed', 'new_courses', 'targets'])) $this->invalid('حقول غير مسموحة.');
        $v = Validator::make($input, [
            'request_id' => 'required|uuid', 'revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted',
            'new_courses' => 'present|array|max:50', 'new_courses.*' => 'array:key,course_code,course_name,credit_hours,theoretical_hours,practical_hours,description,is_active,departments,prerequisites',
            'new_courses.*.key' => 'required|string|max:60|distinct', 'new_courses.*.course_code' => 'required|string|max:50',
            'new_courses.*.course_name' => 'required|string|max:200', 'new_courses.*.credit_hours' => 'required|integer|min:1',
            'new_courses.*.theoretical_hours' => 'sometimes|nullable|integer|min:0', 'new_courses.*.practical_hours' => 'sometimes|nullable|integer|min:0',
            'new_courses.*.description' => 'sometimes|nullable|string|max:16000', 'new_courses.*.is_active' => 'required|boolean',
            'new_courses.*.departments' => 'sometimes|array|max:100', 'new_courses.*.departments.*' => 'array:department_id,is_primary',
            'new_courses.*.departments.*.department_id' => 'required|integer|min:1', 'new_courses.*.departments.*.is_primary' => 'required|boolean',
            'new_courses.*.prerequisites' => 'sometimes|array|max:100', 'new_courses.*.prerequisites.*' => 'array:prerequisite_course_id,minimum_result_status_id',
            'new_courses.*.prerequisites.*.prerequisite_course_id' => 'required|integer|min:1', 'new_courses.*.prerequisites.*.minimum_result_status_id' => 'nullable|integer',
            'targets' => 'required|array|min:1|max:50', 'targets.*' => 'array:academic_program_id,source_version_id,courses,requirements',
            'targets.*.academic_program_id' => 'required|integer|min:1|distinct', 'targets.*.source_version_id' => 'present|nullable|integer|min:1',
            'targets.*.courses' => 'present|array|max:200', 'targets.*.courses.*' => 'array:course_id,new_course_key,requirement_scope,course_type,academic_level_id,recommended_semester_id,is_active',
            'targets.*.courses.*.course_id' => 'present|nullable|integer|min:1', 'targets.*.courses.*.new_course_key' => 'sometimes|nullable|string|max:60',
            // Nullable only for unchanged legacy display/no-op. Canonical saveMembership
            // still rejects an unmapped course in any changed plan before approval.
            'targets.*.courses.*.requirement_scope' => 'present|nullable|in:university,college,department', 'targets.*.courses.*.course_type' => 'required|in:mandatory,elective',
            'targets.*.courses.*.academic_level_id' => 'present|nullable|integer|min:1', 'targets.*.courses.*.recommended_semester_id' => 'present|nullable|integer|min:1',
            'targets.*.courses.*.is_active' => 'required|boolean', 'targets.*.requirements' => 'required|array:total_credit_hours,groups',
            'targets.*.requirements.total_credit_hours' => 'present|nullable|integer|min:1', 'targets.*.requirements.groups' => 'required|array|size:6',
            'targets.*.requirements.groups.*' => 'array:requirement_scope,requirement_type,required_credit_hours',
            'targets.*.requirements.groups.*.requirement_scope' => 'required|in:university,college,department',
            'targets.*.requirements.groups.*.requirement_type' => 'required|in:mandatory,elective',
            'targets.*.requirements.groups.*.required_credit_hours' => 'present|nullable|integer|min:0',
        ])->validate();
        $v['request_id'] = strtolower($v['request_id']);
        $usedKeys = [];
        foreach ($v['targets'] as &$t) {
            $t['academic_program_id'] = (int) $t['academic_program_id'];
            $t['source_version_id'] = $t['source_version_id'] === null ? null : (int) $t['source_version_id'];
            $seen = [];
            foreach ($t['courses'] as &$c) {
                $c['course_id'] = $c['course_id'] === null ? null : (int) $c['course_id'];
                if ((bool) $c['course_id'] === (bool) ($c['new_course_key'] ?? null)) $this->invalid('حدد مادة موجودة أو مادة جديدة، وليس الاثنين.');
                $identity = $c['course_id'] ? 'id:'.$c['course_id'] : 'key:'.$c['new_course_key'];
                if (isset($seen[$identity])) $this->invalid('المادة مكررة داخل البرنامج.'); $seen[$identity] = true;
                if (!$c['course_id']) $usedKeys[] = $c['new_course_key'];
            } unset($c);
        } unset($t);
        $keys = array_column($v['new_courses'], 'key');
        if (array_diff($usedKeys, $keys) || array_diff($keys, $usedKeys)) $this->invalid('المواد الجديدة يجب أن ترتبط بالبرامج المحددة فقط.');
        usort($v['targets'], fn ($a, $b) => $a['academic_program_id'] <=> $b['academic_program_id']);
        usort($v['new_courses'], fn ($a, $b) => $a['key'] <=> $b['key']);
        return $v;
    }

    private function confirmation(): array { return ['revision' => $this->transaction->revision(), 'confirmed' => true]; }
    private function invalid(string $message): never { throw ValidationException::withMessages(['plan' => $message]); }
    private function fail(string $code, string $message): never { throw AcademicPlanException::conflict($code, $message); }
}
