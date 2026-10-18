<?php

namespace App\Services;

use App\Models\{AcademicPlanVersion, User};
use App\Support\ScientificProgramAccess;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** Read facade; no setup, assignment, lock or academic mutation during GET. */
final class ScientificProgramWorkspaceService
{
    public function __construct(private ScientificProgramAccess $access,
        private AcademicCatalogTransaction $transaction, private ScientificPlanChangeService $changes) {}

    public function read(User $actor, array $input): array
    {
        $this->access->authorize($actor); AcademicPlanContext::assertReady();
        $v = $this->validate($input, ['academic_program_id' => 'required|integer|min:1', 'version_id' => 'sometimes|integer|min:1',
            'q' => 'sometimes|nullable|string|max:200', 'academic_level_id' => 'sometimes|integer|min:1',
            'requirement_scope' => 'sometimes|in:university,college,department', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        return $this->transaction->snapshot(function () use ($actor, $v) {
            $program = $this->access->programs($actor)->with('department.college')->findOrFail($v['academic_program_id']);
            $versionId = $v['version_id'] ?? $program->default_academic_plan_version_id;
            if (!$versionId && AcademicPlanVersion::where('academic_program_id', $program->getKey())->exists()) {
                $fixed = AcademicPlanVersion::where('academic_program_id', $program->getKey())->where('status', 'transitional')->get();
                if ($fixed->isEmpty()) {
                    // A newly created preparing program can already have an empty draft.
                    // Show authorized choices, but never silently pick an arbitrary draft.
                    return ['program' => $program, 'requires_source_selection' => true,
                        'source_choices' => AcademicPlanVersion::where('academic_program_id', $program->getKey())->whereIn('status', ['draft', 'approved'])
                            ->orderBy('version_number')->get(['academic_plan_version_id', 'label']),
                        'can_save' => false, 'revision' => $this->transaction->revision(),
                        'lock_reason' => 'اختر الخطة المحفوظة التي تريد استكمالها؛ لم تُبدّل خطة أو إسنادات تلقائيًا.'];
                }
                if ($fixed->count() !== 1) throw \App\Exceptions\AcademicPlanException::conflict('academic_plan_context_invalid', 'لا توجد خطة حالية محددة؛ راجع إعداد البرنامج.');
                $versionId = $fixed->sole()->getKey();
            }
            $plan = $this->changes->source($actor, $program, $versionId === null ? null : (int) $versionId);
            $rows = collect($plan['courses']);
            if (!empty($v['q'])) $rows = $rows->filter(fn ($c) => str_contains(mb_strtolower($c->course?->course_name ?? ''), mb_strtolower(trim($v['q'])))
                || str_contains(mb_strtolower($c->course?->course_code ?? ''), mb_strtolower(trim($v['q']))));
            if (isset($v['academic_level_id'])) $rows = $rows->where('academic_level_id', $v['academic_level_id']);
            if (isset($v['requirement_scope'])) $rows = $rows->filter(fn ($c) => $c->requirementMapping?->requirementGroup?->requirement_scope === $v['requirement_scope']);
            $rows = $rows->sortBy(fn ($c) => [data_get($c, 'academicLevel.level_order', $c->academic_level_id),
                data_get($c, 'recommendedSemester.semester_order', $c->recommended_semester_id), $c->course?->course_code, $c->course_id])->values();
            $perPage = $v['per_page'] ?? 25; $page = $v['page'] ?? 1; $count = $rows->count();
            $caps = $this->access->capabilities($actor);
            $historical = isset($v['version_id']) && (int) $v['version_id'] !== (int) ($program->default_academic_plan_version_id ?? $versionId)
                && data_get($plan['version'], 'status') !== 'draft';
            return ['program' => $program, 'plan' => $plan, 'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
                'values' => $this->changes->values($plan), 'revision' => $this->transaction->revision(), 'capabilities' => $caps,
                'can_save' => !$historical && $caps['plans'] && $caps['approve'] && $caps['assign'],
                'can_create_course' => app(\App\Support\ScientificCourseAccess::class)->canCreateOrigin($actor)
                    && ($actor->isSuperAdmin() || collect([\App\Support\ScientificCourseAccess::VIEW, \App\Support\ScientificCourseAccess::MANAGE])->diff($actor->effectivePermissions())->isEmpty()),
                'lock_reason' => $historical ? 'هذه خطة محفوظة للقراءة؛ افتح خطة الطلاب الجدد لإجراء تعديل.'
                    : (!($caps['plans'] && $caps['approve'] && $caps['assign']) ? 'الحفظ يتطلب صلاحيات إدارة الخطط واعتمادها وتعيينها للطلاب الجدد.' : null),
                'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($count / $perPage)), 'per_page' => $perPage, 'total' => $count]];
        });
    }

    public function history(User $actor, int $programId, array $input): array
    {
        $this->access->authorize($actor); AcademicPlanContext::assertReady();
        $v = $this->validate($input, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        return $this->transaction->snapshot(function () use ($actor, $programId, $v) {
            $this->access->programs($actor)->findOrFail($programId);
            $events = DB::table('academic_plan_events')->where('academic_program_id', $programId)
                ->selectRaw("academic_plan_event_id AS history_id, 'plan' AS source, action, actor_user_id, context, created_at");
            $catalog = DB::table('user_activity_logs')->where('module_code', 'courses')->where('action_code', 'like', 'scientific_catalog.%')
                ->whereRaw('JSON_VALID(description) = 1')->where(fn ($q) => $q->where('description->program_id', $programId)
                    ->orWhere('description->context->program_id', $programId)
                    ->orWhereJsonContains('description->academic_program_ids', $programId)
                    ->orWhereIn('description->course_id', DB::table('program_courses')->where('academic_program_id', $programId)->select('course_id'))
                    ->orWhereIn('description->context->course_id', DB::table('program_courses')->where('academic_program_id', $programId)->select('course_id')))
                ->selectRaw("activity_log_id AS history_id, 'catalog' AS source, action_code AS action, user_id AS actor_user_id, description AS context, created_at");
            $page = DB::query()->fromSub($events->unionAll($catalog), 'history')->orderByDesc('created_at')->orderByDesc('source')
                ->orderByDesc('history_id')->paginate($v['per_page'] ?? 20, ['*'], 'page', $v['page'] ?? 1);
            $actors = \App\Models\User::whereIn('user_id', collect($page->items())->pluck('actor_user_id'))->get()->keyBy('user_id');
            $contexts = collect($page->items())->map(fn ($e) => json_decode($e->context, true, flags: JSON_THROW_ON_ERROR));
            $visiblePrograms = $this->access->programs($actor)->whereIn('academic_program_id', $contexts->flatMap(fn ($c) => $c['selected_program_ids'] ?? [$programId])->unique())->pluck('academic_program_id')->all();
            $rows = collect($page->items())->map(function ($event) use ($actors, $programId, $visiblePrograms) {
                $context = json_decode($event->context, true, flags: JSON_THROW_ON_ERROR);
                if ($event->source === 'catalog' && isset($context['context']) && is_array($context['context'])) $context = $context['context'];
                $selected = array_values(array_intersect($context['selected_program_ids'] ?? [$programId], $visiblePrograms));
                return ['id' => $event->source.':'.$event->history_id, 'action' => $event->action, 'created_at' => $event->created_at,
                    'actor' => $actors->get($event->actor_user_id)?->username ?? 'مستخدم غير متاح',
                    'before' => $context['before'] ?? null, 'after' => $context['after'] ?? null,
                    'selected_program_ids' => $selected,
                    'selected_program_labels' => array_intersect_key($context['selected_program_labels'] ?? [], array_flip($selected)),
                    'course_labels' => $context['course_labels'] ?? [],
                    'scope' => $context['scope'] ?? null, 'details_available' => array_key_exists('before', $context) && array_key_exists('after', $context)];
            });
            return ['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]];
        });
    }

    private function validate(array $input, array $rules): array
    {
        if (array_diff(array_keys($input), array_keys($rules))) throw ValidationException::withMessages(['input' => 'حقول غير مسموحة.']);
        return Validator::make($input, $rules)->validate();
    }
}
