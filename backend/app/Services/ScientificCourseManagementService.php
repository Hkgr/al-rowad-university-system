<?php

namespace App\Services;

use App\Exceptions\{AcademicCatalogException, AcademicRequirementConfigurationException};
use App\Models\{AcademicLevel, AcademicProgram, AcademicRequirementGroup, College, Course, CourseDepartment, CoursePrerequisite, Department, ProgramCourse, ProgramCourseRequirementGroup, ResultStatus, Semester, User, UserActivityLog};
use App\Support\ScientificCourseAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Catalog origins, program membership and requirement budgets are separate resources. */
final class ScientificCourseManagementService
{
    public function __construct(private ScientificCourseAccess $access, private AcademicCatalogTransaction $transaction, private AcademicCatalogHistory $history) {}

    public function listing(User $actor, array $input): array
    {
        $this->access->authorize($actor);
        return $this->transaction->snapshot(fn () => $this->listSnapshot($actor, $input));
    }

    private function listSnapshot(User $actor, array $input): array
    {
        $v = $this->validate($input, [
            'q' => 'sometimes|nullable|string|max:200', 'college_id' => 'sometimes|integer|min:1',
            'department_id' => 'sometimes|integer|min:1', 'academic_program_id' => 'sometimes|integer|min:1',
            'requirement_scope' => 'sometimes|in:university,college,department', 'course_type' => 'sometimes|in:mandatory,elective',
            'is_active' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'sort' => 'sometimes|in:course_code,course_name,credit_hours', 'direction' => 'sometimes|in:asc,desc',
        ]);
        $programs = $this->context($actor, $v);
        $visibleMemberships = AcademicPlanContext::constrainProgramProjection(ProgramCourse::whereIn('academic_program_id', $this->access->programs($actor)->select('academic_program_id')),
            $this->access->programs($actor)->pluck('academic_program_id')->all())->select('program_course_id');
        $q = $this->access->courses($actor);
        if (!empty($v['q'])) $q->where(fn ($q) => $q->where('course_code', 'like', '%'.trim($v['q']).'%')->orWhere('course_name', 'like', '%'.trim($v['q']).'%'));
        if (isset($v['is_active'])) $q->where('is_active', $v['is_active']);
        $membership = function ($p) use ($programs, $v, $visibleMemberships) {
            $p->whereIn('program_course_id', clone $visibleMemberships);
            $p->whereIn('academic_program_id', (clone $programs)->select('academic_program_id'));
            if (isset($v['course_type'])) $p->where('course_type', $v['course_type']);
            if (isset($v['requirement_scope'])) $p->whereHas('requirementMapping.requirementGroup', fn ($g) => $g->where('requirement_scope', $v['requirement_scope'])->where('is_active', true)
                ->whereColumn('academic_requirement_groups.academic_program_id', 'program_courses.academic_program_id')->whereColumn('academic_requirement_groups.requirement_type', 'program_courses.course_type'));
        };
        if (array_intersect(array_keys($v), ['college_id', 'department_id', 'academic_program_id', 'course_type', 'requirement_scope'])) {
            $q->where(function ($q) use ($membership, $actor, $v) {
                $q->whereHas('programCourses', $membership);
                // Unlinked catalog origins remain discoverable in their owning department.
                if (!isset($v['academic_program_id']) && !isset($v['course_type']) && !isset($v['requirement_scope'])) {
                    $q->orWhereHas('courseDepartments.department', function ($d) use ($actor, $v) {
                        $d->whereIn('department_id', $this->access->departments($actor)->select('department_id'));
                        if (isset($v['college_id'])) $d->where('college_id', $v['college_id']);
                        if (isset($v['department_id'])) $d->where('department_id', $v['department_id']);
                    });
                }
            });
        }
        $total = (clone $q)->count();
        // No misleading sum of shared catalog credits across different curricula.
        $groups = DB::table('program_courses as pc')->joinSub((clone $q)->select('courses.course_id'), 'visible', 'visible.course_id', '=', 'pc.course_id')
            ->join('courses as c', 'c.course_id', '=', 'pc.course_id')->leftJoin('program_course_requirement_groups as m', 'm.program_course_id', '=', 'pc.program_course_id')
            ->leftJoin('academic_requirement_groups as g', fn ($g) => $g->on('g.requirement_group_id', '=', 'm.requirement_group_id')
                ->on('g.academic_program_id', '=', 'pc.academic_program_id')->on('g.requirement_type', '=', 'pc.course_type')->where('g.is_active', true))
            ->whereIn('pc.academic_program_id', (clone $programs)->select('academic_program_id'))
            ->whereIn('pc.program_course_id', clone $visibleMemberships);
        if (isset($v['course_type'])) $groups->where('pc.course_type', $v['course_type']);
        if (isset($v['requirement_scope'])) $groups->where('g.requirement_scope', $v['requirement_scope']);
        $summary = $groups->selectRaw('g.requirement_scope, g.requirement_type AS course_type, COUNT(*) AS membership_count, SUM(c.credit_hours) AS available_credit_hours')
            ->groupBy('g.requirement_scope', 'g.requirement_type')->orderBy('g.requirement_scope')->orderBy('g.requirement_type')->get();
        // Filters choose courses; association counts still cover every visible link of each course.
        $rows = $q->with(['courseDepartments' => fn ($d) => $d->whereIn('department_id', $this->access->departments($actor)->select('department_id')), 'courseDepartments.department.college', 'programCourses' => fn ($p) => $p->whereIn('program_course_id', clone $visibleMemberships), 'programCourses.academicProgram.department.college', 'programCourses.academicLevel', 'programCourses.recommendedSemester', 'programCourses.requirementMapping.requirementGroup'])
            ->orderBy($v['sort'] ?? 'course_code', $v['direction'] ?? 'asc')->orderBy('course_id')->paginate($v['per_page'] ?? 20, ['*'], 'page', $v['page'] ?? 1);
        $instructors = $this->instructors(collect($rows->items())->pluck('course_id')->all());
        return ['revision' => $this->transaction->revision(), 'data' => collect($rows->items())->map(fn ($c) => $this->courseProjection($c) + ['instructors' => $instructors->get($c->getKey(), collect())->values()]), 'meta' => $this->meta($rows),
            'summary' => ['catalog_count' => $total, 'groups' => $summary, 'hours_context' => isset($v['academic_program_id']) ? 'program_available_pool' : 'not_a_graduation_total'],
            'can_manage' => $actor->effectivePermissions()->contains(ScientificCourseAccess::MANAGE),
            'can_create' => $actor->effectivePermissions()->contains(ScientificCourseAccess::MANAGE) && $this->access->canCreateOrigin($actor)];
    }

    public function options(User $actor, array $input): array
    {
        $this->access->authorize($actor);
        return $this->transaction->snapshot(fn () => $this->optionsSnapshot($actor, $input));
    }

    private function optionsSnapshot(User $actor, array $input): array
    {
        $v = $this->validate($input, ['resource' => 'required|in:colleges,departments,programs,courses,levels,semesters,result_statuses',
            'q' => 'sometimes|nullable|string|max:200', 'college_id' => 'sometimes|integer|min:1', 'department_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $programs = $this->context($actor, $v);
        [$q, $id, $name] = match ($v['resource']) {
            'colleges' => [College::whereIn('college_id', $this->access->departments($actor)->select('college_id')), 'college_id', 'college_name'],
            'departments' => [$this->access->departments($actor)->when(isset($v['college_id']), fn ($q) => $q->where('college_id', $v['college_id'])), 'department_id', 'department_name'],
            'programs' => [$programs, 'academic_program_id', 'program_name'],
            'courses' => [$this->access->courses($actor), 'course_id', 'course_name'],
            'levels' => [AcademicLevel::query(), 'academic_level_id', 'level_name'],
            'semesters' => [Semester::query(), 'semester_id', 'semester_name'],
            'result_statuses' => [ResultStatus::query(), 'result_status_id', 'status_name'],
        };
        if (!empty($v['q'])) $q->where(fn ($q) => $q->where($name, 'like', '%'.trim($v['q']).'%')->when($v['resource'] === 'courses', fn ($q) => $q->orWhere('course_code', 'like', '%'.trim($v['q']).'%')));
        $isCourse = $v['resource'] === 'courses';
        $page = $q->orderBy($name)->orderBy($id)->paginate($v['per_page'] ?? 25, [$id, $name, ...($isCourse ? ['course_code'] : [])], 'page', $v['page'] ?? 1);
        return ['data' => collect($page->items())->map(fn ($r) => ['id' => $r->$id, 'label' => $isCourse ? $r->$name.' ('.$r->course_code.')' : $r->$name]), 'meta' => $this->meta($page), 'revision' => $this->transaction->revision()];
    }

    public function course(User $actor, int $id): array
    {
        $this->access->authorize($actor);
        return $this->transaction->snapshot(function () use ($actor, $id) {
            $course = $this->access->courses($actor)->findOrFail($id);
            $origin = $this->access->canEditOrigin($actor, $course);
            $used = $this->history->courseUsed($id, false);
            $manage = $actor->effectivePermissions()->contains(ScientificCourseAccess::MANAGE);
            // Do not disclose the names/IDs of programs or prerequisites outside the actor's scope.
            $course->load(['courseDepartments' => fn ($q) => $q->whereIn('department_id', $this->access->departments($actor)->select('department_id')),
                'courseDepartments.department.college', 'coursePrerequisites' => fn ($q) => $q->whereIn('prerequisite_course_id', $this->access->courses($actor)->select('course_id')),
                'coursePrerequisites.prerequisiteCourse', 'programCourses' => fn ($q) => $q->whereIn('academic_program_id', $this->access->programs($actor)->select('academic_program_id')),
                'programCourses.academicProgram.department.college', 'programCourses.academicLevel', 'programCourses.recommendedSemester', 'programCourses.requirementMapping.requirementGroup']);
            $outsideRelationships = $course->coursePrerequisites()->whereNotIn('prerequisite_course_id', $this->access->courses($actor)->select('course_id'))->exists();
            $reasons = $this->history->deleteReasons($id);
            return ['data' => $this->courseProjection($course), 'revision' => $this->transaction->revision(), 'capabilities' => [
                'edit_text' => $manage && $origin, 'edit_academic' => $manage && $origin && !$used,
                'edit_relationships' => $manage && $origin && !$used && !$outsideRelationships,
                'delete' => $manage && $origin && !$reasons,
                'origin_lock_reason' => $origin ? null : 'هذه مادة مشتركة أو لا تقع ملكية أصلها كاملة ضمن نطاقك.',
                'academic_lock_reason' => $used ? 'لا يمكن تغيير الرمز والساعات لمادة مستخدمة؛ يمكنك تصحيح الاسم والوصف.' : null,
                'relationship_lock_reason' => $outsideRelationships ? 'توجد علاقات خارج نطاقك؛ لا يسمح باستبدالها من هذا الحساب.' : null,
                'delete_reasons' => $reasons,
            ], 'impact' => ['shared' => $course->programCourses()->count() > 1, 'linked_program_count' => $course->programCourses()->count()]];
        });
    }

    public function saveCourse(User $actor, ?int $id, array $input): array
    {
        $this->access->authorize($actor, true);
        $v = $this->validate($input, [
            'revision' => 'required|string|regex:/^[0-9]+$/', 'impact_confirmed' => 'sometimes|boolean',
            'course_code' => [($id ? 'sometimes' : 'required'), 'string', 'max:50', Rule::unique('courses', 'course_code')->ignore($id, 'course_id')],
            'course_name' => ($id ? 'sometimes' : 'required').'|string|max:200', 'credit_hours' => ($id ? 'sometimes' : 'required').'|integer|min:1|max:2147483647',
            'theoretical_hours' => 'sometimes|nullable|integer|min:0|max:2147483647', 'practical_hours' => 'sometimes|nullable|integer|min:0|max:2147483647',
            'description' => 'sometimes|nullable|string|max:16000', 'is_active' => ($id ? 'sometimes' : 'required').'|boolean',
            'departments' => 'sometimes|array|max:100', 'departments.*' => 'array:department_id,is_primary',
            'departments.*.department_id' => 'required|integer|min:1|distinct', 'departments.*.is_primary' => 'required|boolean',
            'prerequisites' => 'sometimes|array|max:100', 'prerequisites.*' => 'array:prerequisite_course_id,minimum_result_status_id',
            'prerequisites.*.prerequisite_course_id' => 'required|integer|min:1|distinct', 'prerequisites.*.minimum_result_status_id' => 'nullable|integer|exists:result_statuses,result_status_id',
            ...($id ? [] : ['distribution' => 'sometimes|array:scope,college_id,department_id,course_type,draft_version_ids',
                'distribution_confirmed' => 'exclude_without:distribution|required|accepted',
                'academic_level_id' => 'required_with:distribution|integer|exists:academic_levels,academic_level_id',
                'recommended_semester_id' => 'required_with:distribution|integer|exists:semesters,semester_id']),
        ]);
        return $this->transaction->run(function () use ($actor, $id, $v) {
            $this->access->authorize($actor, true);
            if (!$id) abort_unless($this->access->canCreateOrigin($actor), 403);
            $plan = isset($v['distribution']) ? app(ScientificCourseDistribution::class)->plan($actor, $v['distribution'], true) : null;
            if ($plan && !$plan['can_apply']) $this->invalid('distribution', 'تعذر ربط جميع البرامج؛ راجع معاينة النطاق. لم يُحفظ أي تغيير.');
            $course = $id ? $this->access->courses($actor)->lockForUpdate()->findOrFail($id) : new Course;
            if ($id) abort_unless($this->access->canEditOrigin($actor, $course), 403);
            if ($id && $course->programCourses()->count() > 1 && !($v['impact_confirmed'] ?? false)) $this->invalid('impact_confirmed', 'أكد أثر التصحيح على أصل المادة المشترك قبل الحفظ.');
            $attributes = array_diff_key($v, array_flip(['revision', 'impact_confirmed', 'departments', 'prerequisites', 'distribution', 'distribution_confirmed', 'academic_level_id', 'recommended_semester_id']));
            foreach (['course_code', 'course_name'] as $field) if (isset($attributes[$field])) $attributes[$field] = trim($attributes[$field]);
            if (isset($attributes['course_code']) && $attributes['course_code'] === '') $this->invalid('course_code', 'رمز المادة مطلوب.');
            if (isset($attributes['course_name']) && $attributes['course_name'] === '') $this->invalid('course_name', 'اسم المادة مطلوب.');
            $course->fill($attributes);
            $used = $id && $this->history->courseUsed($id);
            if ($used && array_diff(array_keys($course->getDirty()), ['course_name', 'description']) !== []) $this->locked();
            if ($used && (isset($v['departments']) || isset($v['prerequisites']))) $this->locked();
            if (isset($v['departments'])) {
                if (collect($v['departments'])->where('is_primary', true)->count() > 1) $this->invalid('departments', 'لا يسمح بأكثر من قسم رئيسي واحد.');
                foreach ($v['departments'] as $d) abort_unless($this->access->departments($actor)->whereKey($d['department_id'])->exists(), 403);
            }
            if (!$id && !$this->access->university($actor) && empty($v['departments'])) $this->invalid('departments', 'حدد القسم المالك للمادة داخل نطاقك.');
            if (isset($v['prerequisites'])) foreach ($v['prerequisites'] as $p) abort_unless($this->access->courses($actor)->whereKey($p['prerequisite_course_id'])->exists(), 403);
            $changed = $course->isDirty() || isset($v['departments']) || isset($v['prerequisites']);
            $course->save();
            if (isset($v['departments'])) {
                $course->courseDepartments()->delete();
                foreach ($v['departments'] as $d) CourseDepartment::create(['course_id' => $course->getKey(), ...$d]);
            }
            if (isset($v['prerequisites'])) {
                abort_if($course->coursePrerequisites()->whereNotIn('prerequisite_course_id', $this->access->courses($actor)->select('course_id'))->exists(), 403);
                $course->coursePrerequisites()->delete();
                foreach ($v['prerequisites'] as $p) CoursePrerequisite::create(['course_id' => $course->getKey(), ...$p]);
                $this->transaction->assertAcyclic();
            }
            abort_unless($this->access->canEditOrigin($actor, $course), 403);
            if ($changed) $this->audit($actor, $id ? 'course.update' : 'course.create', ['course_id' => $course->getKey(), 'fields' => array_keys($attributes)]);
            if ($plan) app(ScientificCourseDistribution::class)->apply($actor, $course, $plan, collect($v)->only(['academic_level_id', 'recommended_semester_id'])->all());
            return $this->course($actor, (int) $course->getKey());
        }, $v['revision']);
    }

    public function deleteCourse(User $actor, int $id, array $input): array
    {
        $this->access->authorize($actor, true);
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->transaction->run(function () use ($actor, $id) {
            $c = $this->access->courses($actor)->lockForUpdate()->findOrFail($id);
            $this->access->authorize($actor, true);
            abort_unless($this->access->canEditOrigin($actor, $c), 403);
            if ($this->history->deleteReasons($id)) $this->locked();
            // Only nonhistorical ownership links are explicitly removed. Never cascade student data.
            $c->courseDepartments()->delete(); $c->delete();
            $this->audit($actor, 'course.delete', ['course_id' => $id]);
            return ['deleted' => true, 'revision' => $this->transaction->revision()];
        }, $v['revision']);
    }

    public function program(User $actor, int $id): array
    {
        $this->access->authorize($actor);
        return $this->transaction->snapshot(function () use ($actor, $id) {
            $p = $this->access->programs($actor)->findOrFail($id);
            $versioned = AcademicPlanContext::installed() && $p->plan_state !== 'legacy';
            $locked = $versioned || $this->history->programUsed($id, false);
            $context = AcademicPlanContext::forProgram($id);
            $groups = $context->constrain(AcademicRequirementGroup::where('academic_program_id', $id))->orderBy('requirement_scope')->orderBy('requirement_type')->get();
            $pools = DB::table('program_courses as pc')->join('courses as c', 'c.course_id', '=', 'pc.course_id')
                ->join('program_course_requirement_groups as m', 'm.program_course_id', '=', 'pc.program_course_id')
                ->where('pc.academic_program_id', $id)->where('pc.is_active', true)->where('c.is_active', true)
                ->selectRaw('m.requirement_group_id, COUNT(*) AS course_count, SUM(c.credit_hours) AS available_credit_hours')->groupBy('m.requirement_group_id')->get()->keyBy('requirement_group_id');
            try {
                app(AcademicRequirementService::class)->assertProgramGraduationConfiguration($p);
                $configuration = ['available' => true, 'message' => null];
            } catch (AcademicRequirementConfigurationException $e) {
                $configuration = ['available' => false, 'message' => $e->getMessage()];
            }
            return ['data' => $p, 'groups' => $groups->map(fn ($g) => [...$g->toArray(),
                'available_credit_hours' => (int) ($pools[$g->getKey()]->available_credit_hours ?? 0),
                'available_minus_required_hours' => (int) ($pools[$g->getKey()]->available_credit_hours ?? 0) - (int) $g->required_credit_hours,
                'course_count' => (int) ($pools[$g->getKey()]->course_count ?? 0)]),
                'configuration' => $configuration, 'revision' => $this->transaction->revision(),
                'versioned_plans' => $versioned, 'academic_plan_version_id' => $context->versionId,
                'capabilities' => ['edit_curriculum' => !$locked && $actor->effectivePermissions()->contains(ScientificCourseAccess::MANAGE),
                    'lock_reason' => $versioned ? 'اختر نسخة للتعديل من إدارة البرامج الأكاديمية؛ الخطط الثابتة لا تعدّل من الدليل.' : ($locked ? 'لا يمكن تغيير مواد هذا البرنامج أو متطلبات تخرجه لارتباطه بسجلات أكاديمية قائمة.' : null)]];
        });
    }

    public function saveMembership(User $actor, int $programId, int $courseId, array $input, bool $delete = false): array
    {
        $this->access->authorize($actor, true);
        $v = $this->validate($input, $delete ? ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted'] : [
            'revision' => 'required|string|regex:/^[0-9]+$/', 'academic_level_id' => 'required|integer|exists:academic_levels,academic_level_id',
            'recommended_semester_id' => 'required|integer|exists:semesters,semester_id', 'course_type' => 'required|in:mandatory,elective',
            'requirement_scope' => 'required|in:university,college,department', 'requirement_group_id' => 'sometimes|integer|min:1', 'is_active' => 'required|boolean',
        ]);
        return $this->transaction->run(function () use ($actor, $programId, $courseId, $v, $delete) {
            $this->access->authorize($actor, true);
            $this->access->programs($actor)->lockForUpdate()->findOrFail($programId);
            $this->assertLegacyCurriculum($programId);
            $this->access->courses($actor)->findOrFail($courseId);
            if ($this->history->programUsed($programId)) $this->locked();
            $pc = ProgramCourse::where('academic_program_id', $programId)->where('course_id', $courseId)->lockForUpdate()->first();
            if ($delete) {
                abort_unless($pc, 404);
                $pc->requirementMapping()->delete(); $pc->delete();
            } else {
                $matches = AcademicRequirementGroup::where('academic_program_id', $programId)->where('is_active', true)
                    ->where('requirement_scope', $v['requirement_scope'])->where('requirement_type', $v['course_type'])->lockForUpdate()->get();
                if ($matches->count() !== 1) $this->invalid('requirement_scope', 'إعداد هذا التصنيف غير مكتمل أو متعارض. راجع متطلبات التخرج للبرنامج.');
                $g = $matches->sole();
                // Compatibility for older clients is validation, never authority to choose a group.
                if (isset($v['requirement_group_id']) && (int) $v['requirement_group_id'] !== (int) $g->getKey()) $this->invalid('requirement_group_id', 'التصنيف لا يطابق متطلبات البرنامج.');
                $pc ??= new ProgramCourse(['academic_program_id' => $programId, 'course_id' => $courseId]);
                $pc->fill(collect($v)->only(['academic_level_id', 'recommended_semester_id', 'course_type', 'is_active'])->all())->save();
                ProgramCourseRequirementGroup::updateOrCreate(['program_course_id' => $pc->getKey()], ['requirement_group_id' => $g->getKey()]);
            }
            $this->audit($actor, $delete ? 'program_course.unlink' : 'program_course.save', ['academic_program_id' => $programId, 'course_id' => $courseId]);
            return $this->program($actor, $programId);
        }, $v['revision']);
    }

    public function saveGroups(User $actor, int $id, array $input): array
    {
        $this->access->authorize($actor, true);
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted',
            'total_credit_hours' => 'required|integer|min:1|max:2147483647', 'groups' => 'required|array|min:1|max:6',
            'groups.*' => 'array:requirement_group_id,group_code,group_name,requirement_scope,requirement_type,required_credit_hours,is_active',
            'groups.*.requirement_group_id' => 'nullable|integer|min:1|distinct', 'groups.*.group_code' => 'sometimes|string|max:100|distinct',
            'groups.*.group_name' => 'sometimes|string|max:200', 'groups.*.requirement_scope' => 'required|in:university,college,department',
            'groups.*.requirement_type' => 'required|in:mandatory,elective', 'groups.*.required_credit_hours' => 'required|integer|min:0|max:2147483647',
            'groups.*.is_active' => 'required|boolean']);
        return $this->transaction->run(function () use ($actor, $id, $v) {
            $this->access->authorize($actor, true);
            $p = $this->access->programs($actor)->lockForUpdate()->findOrFail($id);
            $this->assertLegacyCurriculum($id);
            if ($this->history->programUsed($id)) $this->locked();
            $seen = [];
            foreach ($v['groups'] as $i => $row) {
                $key = $row['requirement_scope'].':'.$row['requirement_type'];
                if (isset($seen[$key])) $this->invalid("groups.$i.requirement_type", 'لكل مستوى ونوع مجموعة واحدة فقط.');
                $seen[$key] = true;
                $g = !empty($row['requirement_group_id']) ? AcademicRequirementGroup::where('academic_program_id', $id)->findOrFail($row['requirement_group_id']) : new AcademicRequirementGroup(['academic_program_id' => $id]);
                if ($g->exists && ($g->requirement_scope !== $row['requirement_scope'] || $g->requirement_type !== $row['requirement_type']) && $g->programCourseMappings()->exists()) $this->invalid("groups.$i", 'افصل المواد أولًا قبل تغيير هوية مجموعة مرتبطة.');
                // Internal labels only; hours always come from explicit operator input.
                $row['group_code'] ??= $g->group_code ?: 'SC-'.$id.'-'.$row['requirement_scope'].'-'.$row['requirement_type'];
                $row['group_name'] ??= $g->group_name ?: ['university' => 'متطلبات الجامعة', 'college' => 'متطلبات الكلية', 'department' => 'متطلبات القسم'][$row['requirement_scope']].' '.($row['requirement_type'] === 'mandatory' ? 'الإجبارية' : 'الاختيارية');
                $codeQuery = AcademicRequirementGroup::where('group_code', trim($row['group_code']));
                if ($g->exists) $codeQuery->whereKeyNot($g->getKey());
                if ($codeQuery->exists()) $this->invalid("groups.$i.group_code", 'رمز المجموعة مستخدم بالفعل.');
                unset($row['requirement_group_id']); $g->fill($row)->save();
            }
            $p->update(['total_credit_hours' => $v['total_credit_hours']]);
            $this->audit($actor, 'requirement_groups.save', ['academic_program_id' => $id]);
            // Draft curricula can be incomplete while being prepared. Return the canonical
            // configuration warning; NEVER silently change budgets to fit available courses.
            return $this->program($actor, $id);
        }, $v['revision']);
    }

    private function assertLegacyCurriculum(int $programId): void
    {
        if (AcademicPlanContext::installed() && AcademicProgram::findOrFail($programId)->plan_state !== 'legacy') {
            throw \App\Exceptions\AcademicPlanException::conflict('academic_plan_explicit_version_required', 'اختر نسخة مسودة صريحة من إدارة البرامج الأكاديمية.');
        }
    }

    private function context(User $actor, array $v)
    {
        $p = $this->access->programs($actor);
        if (isset($v['college_id'])) {
            abort_unless($this->access->departments($actor)->where('college_id', $v['college_id'])->exists(), 403);
            $p->whereHas('department', fn ($d) => $d->where('college_id', $v['college_id']));
        }
        if (isset($v['department_id'])) {
            $d = $this->access->departments($actor)->findOrFail($v['department_id']);
            if (isset($v['college_id']) && (int) $d->college_id !== (int) $v['college_id']) $this->invalid('department_id', 'القسم لا يتبع الكلية المحددة.');
            $p->where('department_id', $v['department_id']);
        }
        if (isset($v['academic_program_id'])) {
            abort_unless($this->access->programs($actor)->whereKey($v['academic_program_id'])->exists(), 403);
            if (!(clone $p)->whereKey($v['academic_program_id'])->exists()) $this->invalid('academic_program_id', 'البرنامج لا يتبع السياق المحدد.');
            $p->whereKey($v['academic_program_id']);
        }
        return $p;
    }

    private function validate(array $input, array $rules): array
    {
        foreach (array_diff(array_keys($input), array_filter(array_keys($rules), fn ($k) => !str_contains($k, '.'))) as $unknown) $this->invalid($unknown, 'حقل غير مسموح.');
        return Validator::make($input, $rules, ['course_code.unique' => 'رمز المادة مستخدم بالفعل؛ أدخل رمزًا آخر'])->validate();
    }

    private function courseProjection(Course $course): array
    {
        $data = $course->only(['course_id', 'course_code', 'course_name', 'credit_hours', 'theoretical_hours', 'practical_hours', 'description', 'is_active']);
        $data['course_departments'] = $course->courseDepartments->map(fn ($d) => [...$d->only(['course_department_id', 'department_id', 'is_primary']),
            'department' => ($d->department?->only(['department_id', 'department_name', 'college_id']) ?? []) + ['college' => $d->department?->college?->only(['college_id', 'college_name'])]]);
        $data['program_courses'] = $course->programCourses->map(function ($pc) {
            $group = $pc->requirementMapping?->requirementGroup;
            $sameProgram = $group && (int) $group->academic_program_id === (int) $pc->academic_program_id;
            $classification = \App\Support\CourseRequirementClassification::fromProgramCourse($pc);
            if (!$sameProgram) $classification['requirement_group_id'] = null;
            return [...$pc->only(['program_course_id', 'academic_program_id', 'course_id', 'academic_level_id', 'recommended_semester_id', 'course_type', 'is_active']),
                'academic_program' => $pc->academicProgram ? $pc->academicProgram->only(['academic_program_id', 'program_name']) + [
                    'department' => $pc->academicProgram->department ? $pc->academicProgram->department->only(['department_id', 'department_name', 'college_id']) + [
                        'college' => $pc->academicProgram->department->college?->only(['college_id', 'college_name'])] : null] : null,
                'academic_level' => $pc->academicLevel?->only(['academic_level_id', 'level_name']),
                'recommended_semester' => $pc->recommendedSemester?->only(['semester_id', 'semester_name']),
                'requirement_classification' => $classification,
                'requirement_mapping' => $sameProgram ? ['requirement_group_id' => $group->getKey(), 'requirement_group' => $group->only(['requirement_group_id', 'group_name', 'requirement_scope', 'requirement_type'])] : null];
        });
        if ($course->relationLoaded('coursePrerequisites')) $data['course_prerequisites'] = $course->coursePrerequisites->map(fn ($p) => [...$p->only(['course_prerequisite_id', 'prerequisite_course_id', 'minimum_result_status_id']),
            'prerequisite_course' => $p->prerequisiteCourse?->only(['course_id', 'course_code', 'course_name'])]);
        return $data;
    }
    /** Catalog instructor links, not effective term assignments. Safe name/role fields only. */
    private function instructors(array $courseIds)
    {
        return DB::table('course_instructors as ci')->leftJoin('faculty_members as f', 'f.faculty_member_id', '=', 'ci.faculty_member_id')
            ->leftJoin('employees as e', 'e.employee_id', '=', 'f.employee_id')->whereIn('ci.course_id', $courseIds)
            ->orderBy('ci.course_id')->orderBy('ci.faculty_member_id')->orderBy('ci.course_instructor_id')
            ->get(['ci.course_id', 'ci.faculty_member_id', 'ci.is_primary', 'ci.is_active', 'e.first_name', 'e.last_name'])->groupBy('course_id');
    }
    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
    private function locked(): never { throw new AcademicCatalogException('البيانات مرتبطة بتاريخ أكاديمي أو علاقة مانعة؛ يسمح بالتصحيح النصي فقط.', 'academic_catalog_history_locked'); }
    private function meta($p): array { return ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]; }
    private function audit(User $actor, string $action, array $ids): void
    {
        UserActivityLog::create(['user_id' => $actor->getKey(), 'module_code' => 'courses', 'action_code' => 'scientific_catalog.'.$action,
            'description' => json_encode(['context' => $ids, 'revision' => $this->transaction->revision()], JSON_THROW_ON_ERROR)]);
    }
}
