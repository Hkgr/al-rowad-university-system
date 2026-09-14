<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, AcademicProgram, Department, User, UserActivityLog};
use App\Support\{ScientificCourseAccess, ScientificProgramAccess};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\{Rule, ValidationException};

final class ScientificProgramManagementService
{
    public function __construct(private ScientificProgramAccess $access, private AcademicCatalogTransaction $transaction, private AcademicCatalogHistory $history) {}

    public function listing(User $actor, array $input): array
    {
        $this->access->authorize($actor); AcademicPlanContext::assertReady();
        $v = $this->validate($input, ['q' => 'sometimes|nullable|string|max:200', 'college_id' => 'sometimes|integer|min:1', 'department_id' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:active,inactive,archived', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'sort' => 'sometimes|in:program_code,program_name,duration_years,total_credit_hours', 'direction' => 'sometimes|in:asc,desc']);
        return $this->transaction->snapshot(function () use ($actor, $v) {
            $q = $this->access->programs($actor)->with('department.college');
            if (!empty($v['q'])) $q->where(fn ($q) => $q->where('program_code', 'like', '%'.trim($v['q']).'%')->orWhere('program_name', 'like', '%'.trim($v['q']).'%'));
            if (isset($v['college_id'])) $q->whereHas('department', fn ($q) => $q->where('college_id', $v['college_id']));
            if (isset($v['department_id'])) $q->where('department_id', $v['department_id']);
            if (($v['status'] ?? null) === 'archived') $q->whereNotNull('archived_at');
            elseif (isset($v['status'])) $q->whereNull('archived_at')->where('is_active', $v['status'] === 'active');
            $sort = ($v['sort'] ?? 'program_code') === 'total_credit_hours'
                ? DB::raw('COALESCE((SELECT apv.total_credit_hours FROM academic_plan_versions apv WHERE apv.academic_plan_version_id=academic_programs.default_academic_plan_version_id), academic_programs.total_credit_hours)')
                : ($v['sort'] ?? 'program_code');
            $page = $q->orderBy($sort, $v['direction'] ?? 'asc')->orderBy('academic_program_id')->paginate($v['per_page'] ?? 20, ['*'], 'page', $v['page'] ?? 1);
            $versions = AcademicPlanVersion::whereIn('academic_program_id', collect($page->items())->pluck('academic_program_id'))->orderBy('version_number')->get()->groupBy('academic_program_id');
            return ['data' => collect($page->items())->map(fn ($p) => $this->projection($p, $versions->get($p->getKey(), collect()))),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
                'capabilities' => $this->access->capabilities($actor), 'revision' => $this->transaction->revision()];
        });
    }

    public function detail(User $actor, int $id): array
    {
        $this->access->authorize($actor); AcademicPlanContext::assertReady();
        return $this->transaction->snapshot(function () use ($actor, $id) {
            $p = $this->access->programs($actor)->with('department.college')->findOrFail($id);
            $versions = AcademicPlanVersion::where('academic_program_id', $id)->orderBy('version_number')->get();
            $used = $this->history->programUsed($id, false) || $versions->whereIn('status', ['approved', 'transitional'])->isNotEmpty();
            return ['program' => $this->projection($p, $versions), 'versions' => $versions, 'capabilities' => $this->access->capabilities($actor) + [
                'edit_academic' => !$used, 'academic_lock_reason' => $used ? 'هوية البرنامج مرتبطة بتاريخ؛ يمكن تصحيح الاسم والوصف، وتعديل الخطة عبر نسخة جديدة.' : null],
                'revision' => $this->transaction->revision()];
        });
    }

    public function options(User $actor, array $input): array
    {
        $this->access->authorize($actor); AcademicPlanContext::assertReady();
        $v = $this->validate($input, ['resource' => 'required|in:colleges,departments,courses,levels,semesters,students',
            'q' => 'sometimes|nullable|string|max:150', 'college_id' => 'sometimes|integer|min:1', 'academic_program_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        return $this->transaction->snapshot(function () use ($actor, $v) {
            $scope = app(ScientificCourseAccess::class);
            [$query, $key, $name] = match ($v['resource']) {
                'colleges' => [\App\Models\College::whereIn('college_id', $scope->departments($actor)->select('college_id')), 'college_id', 'college_name'],
                'departments' => [$scope->departments($actor)->when(isset($v['college_id']), fn ($q) => $q->where('college_id', $v['college_id'])), 'department_id', 'department_name'],
                'courses' => [$scope->courses($actor), 'course_id', 'course_name'],
                'levels' => [\App\Models\AcademicLevel::query(), 'academic_level_id', 'level_name'],
                'semesters' => [\App\Models\Semester::query(), 'semester_id', 'semester_name'],
                'students' => [\App\Models\Student::whereIn('academic_program_id', $this->access->programs($actor)->select('academic_program_id')),
                    'student_id', 'student_number'],
            };
            if ($v['resource'] === 'students') {
                $this->access->authorize($actor, ScientificProgramAccess::ASSIGN);
                if (!isset($v['academic_program_id'])) throw ValidationException::withMessages(['academic_program_id' => 'حدد البرنامج أولًا.']);
                $query->where('academic_program_id', $v['academic_program_id']);
            } else $query->where('is_active', true);
            if (!empty($v['q'])) $query->where(function ($q) use ($v, $name) {
                $q->where($name, 'like', '%'.trim($v['q']).'%');
                if ($v['resource'] === 'courses') $q->orWhere('course_code', 'like', '%'.trim($v['q']).'%');
            });
            $page = $query->orderBy($name)->orderBy($key)->paginate($v['per_page'] ?? 20, ['*'], 'page', $v['page'] ?? 1);
            return ['data' => collect($page->items())->map(fn ($r) => ['id' => (int) $r->$key, 'label' => $r->$name
                .($v['resource'] === 'courses' ? ' ('.$r->course_code.')' : '')]),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
        });
    }

    public function save(User $actor, ?int $id, array $input): array
    {
        $this->access->authorize($actor, ScientificProgramAccess::MANAGE); AcademicPlanContext::assertReady();
        $required = $id ? 'sometimes' : 'required';
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'program_code' => [$required, 'string', 'max:50', Rule::unique('academic_programs', 'program_code')->ignore($id, 'academic_program_id')],
            'program_name' => "$required|string|max:200", 'department_id' => "$required|integer|min:1", 'degree_level' => "$required|string|max:80",
            'duration_years' => "$required|integer|min:1|max:50", 'total_credit_hours' => "$required|integer|min:1|max:2147483647", 'description' => 'sometimes|nullable|string|max:16000']);
        return $this->transaction->run(function () use ($actor, $id, $v) {
            $this->access->authorize($actor, ScientificProgramAccess::MANAGE);
            $program = $id ? $this->access->programs($actor)->lockForUpdate()->findOrFail($id) : new AcademicProgram;
            if (isset($v['department_id'])) {
                $department = app(ScientificCourseAccess::class)->departments($actor)->findOrFail($v['department_id']);
                if (!$department->is_active || !$department->college?->is_active) throw ValidationException::withMessages(['department_id' => 'اختر قسمًا وكلية نشطين.']);
                // A program-only scope cannot create a new sibling program.
                if (!$id && !app(ScientificCourseAccess::class)->canCreateOrigin($actor)) abort(403);
            }
            $attributes = collect($v)->except('revision')->all();
            foreach (['program_code', 'program_name', 'degree_level'] as $name) if (isset($attributes[$name])) {
                $attributes[$name] = trim($attributes[$name]);
                if ($attributes[$name] === '') throw ValidationException::withMessages([$name => 'القيمة مطلوبة.']);
            }
            $copy = clone $program; $copy->fill($attributes);
            if ($id && array_diff(array_keys($copy->getDirty()), ['program_name', 'description']) && ($this->history->programUsed($id)
                || AcademicPlanVersion::where('academic_program_id', $id)->whereIn('status', ['approved', 'transitional'])->exists())) {
                throw AcademicPlanException::conflict('academic_plan_program_identity_locked', 'التصحيح النصي متاح؛ استخدم نسخة خطة جديدة للتعديل الأكاديمي.');
            }
            $program->fill($attributes);
            if (!$id) $program->forceFill(['is_active' => true, 'plan_state' => 'preparing']);
            $program->save();
            if (!$id) AcademicPlanVersion::create(['academic_program_id' => $program->getKey(), 'version_number' => 1, 'label' => 'الخطة الأولى',
                'status' => 'draft', 'calculation_policy' => 'explicit_zero_v1', 'total_credit_hours' => $program->total_credit_hours, 'created_by_user_id' => $actor->getKey()]);
            $this->audit($actor, $program, $id ? 'program.update' : 'program.create');
            return $this->detail($actor, (int) $program->getKey());
        }, $v['revision']);
    }

    public function impact(User $actor, int $id): array
    {
        $this->access->authorize($actor); AcademicPlanContext::assertReady();
        return $this->transaction->snapshot(function () use ($actor, $id) {
            $this->access->programs($actor)->findOrFail($id);
            $counts = [];
            foreach (AcademicCatalogHistory::PROGRAM_REFERENCES as $table => [$key, $foreign]) $counts[$table] = DB::table($table)->where($foreign, $id)->count();
            foreach (['academic_plan_versions', 'program_courses', 'academic_requirement_groups', 'academic_plan_events'] as $table) $counts[$table] = DB::table($table)->where('academic_program_id', $id)->count();
            $emptyDrafts = AcademicPlanVersion::where('academic_program_id', $id)->where('status', 'draft')->whereNull('fixed_at')->count();
            // Only the empty setup draft of a never-used program may be removed.
            // Fixed plans, events, groups, memberships, assignments and academic references remain restrictive.
            return ['counts' => $counts, 'removable_empty_draft_count' => $emptyDrafts,
                'can_delete' => array_sum($counts) - $emptyDrafts === 0, 'revision' => $this->transaction->revision()];
        });
    }

    public function archive(User $actor, int $id, array $input, bool $restore = false): array
    {
        $this->access->authorize($actor, ScientificProgramAccess::ARCHIVE); AcademicPlanContext::assertReady();
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->transaction->run(function () use ($actor, $id, $restore) {
            $this->access->authorize($actor, ScientificProgramAccess::ARCHIVE);
            $p = $this->access->programs($actor)->lockForUpdate()->findOrFail($id);
            // is_active is intentionally untouched: continuing-student operations retain their existing eligibility.
            $p->forceFill(['archived_at' => $restore ? null : now()])->save();
            $this->audit($actor, $p, $restore ? 'program.restore' : 'program.archive');
            return $this->detail($actor, $id);
        }, $v['revision']);
    }

    public function delete(User $actor, int $id, array $input): array
    {
        $this->access->authorize($actor, ScientificProgramAccess::DELETE); AcademicPlanContext::assertReady();
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->transaction->run(function () use ($actor, $id) {
            $this->access->authorize($actor, ScientificProgramAccess::DELETE);
            $p = $this->access->programs($actor)->lockForUpdate()->findOrFail($id);
            if (!$this->impact($actor, $id)['can_delete']) throw AcademicPlanException::conflict('academic_program_delete_blocked', 'توجد ارتباطات تمنع الحذف؛ يمكن أرشفة البرنامج.');
            AcademicPlanVersion::where('academic_program_id', $id)->where('status', 'draft')->whereNull('fixed_at')->delete();
            $this->audit($actor, $p, 'program.delete'); $p->delete();
            return ['deleted' => true, 'revision' => $this->transaction->revision()];
        }, $v['revision']);
    }

    private function projection(AcademicProgram $p, $versions): array
    {
        $default = $versions->firstWhere('academic_plan_version_id', $p->default_academic_plan_version_id);
        return $p->toArray() + ['plan_total_credit_hours' => $default?->total_credit_hours,
            'plan_setup' => $p->plan_state, 'version_count' => $versions->count(), 'default_plan_label' => $default?->label];
    }

    private function audit(User $actor, AcademicProgram $program, string $action): void
    {
        UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'academic_structure', 'action_code' => 'academic_plan.'.$action,
            'description' => json_encode(['academic_program_id' => $program->getKey()], JSON_THROW_ON_ERROR)]);
    }

    private function validate(array $input, array $rules): array
    {
        if (array_diff(array_keys($input), array_keys($rules))) throw ValidationException::withMessages(['input' => 'توجد حقول غير مسموحة.']);
        return Validator::make($input, $rules)->validate();
    }
}
