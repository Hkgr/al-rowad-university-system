<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\{Course, CourseOffering, GradeComponent, GradePartApproval, Student, StudentCourseRegistration, User, UserActivityLog};
use App\Support\{CourseRequirementClassification, ExamManualGradeEntryAccess, SupplementaryExamTargetGuard};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Catalog/preparation only. Marks, submission and results remain canonical. */
final class ExamManualGradeContextService
{
    public function __construct(private readonly DataScopeService $scope,
        private readonly ExamManualGradeEntryAccess $access, private readonly GradeService $grades,
        private readonly GradePartWorkflowService $workflow, private readonly CourseOfferingInstructorCoverageService $coverage) {}

    public function catalog(User $actor, Student $student, array $filters): array
    {
        $this->access->authorize($actor, $student);
        $student->loadMissing('academicProgram.department.college');
        $collegeId = $student->academicProgram?->department?->college_id;
        $programId = $student->academic_program_id;
        $query = $this->scope->scopeManualGradeCourses(Course::query(), $actor)
            ->where(function ($q) use ($collegeId, $programId, $student, $actor) {
                $q->whereHas('departments', fn ($d) => $d->where('college_id', $collegeId ?? -1))
                    ->orWhereHas('academicPrograms.department', fn ($d) => $d->where('college_id', $collegeId ?? -1))
                    // Includes the student's applicable university/shared requirement rows.
                    ->orWhereHas('programCourses', fn ($p) => $p->where('academic_program_id', $programId ?? -1)->where('is_active', true))
                    ->orWhereHas('courseOfferings', fn ($o) => $this->scope->scopeManualGradeOfferings($o, $actor)
                        ->whereHas('studentCourseRegistrations', fn ($r) => $r->where('student_id', $student->getKey())));
            });
        if (trim($filters['q'] ?? '') !== '') $query->where(fn ($q) => $q
            ->where('course_code', 'like', '%'.$filters['q'].'%')->orWhere('course_name', 'like', '%'.$filters['q'].'%'));
        $page = $query->withExists([
            'departments as in_college_departments' => fn ($d) => $d->where('college_id', $collegeId ?? -1),
            'academicPrograms as in_college_programs' => fn ($p) => $p->whereHas('department', fn ($d) => $d->where('college_id', $collegeId ?? -1)),
        ])->orderBy('course_code')->orderBy('course_id')->paginate($filters['per_page'] ?? 15);
        $ids = $page->getCollection()->modelKeys();
        $offeringsQuery = $this->scope->scopeManualGradeOfferings(CourseOffering::query(), $actor);
        $terms = $this->periods($actor, $student)['terms'];
        foreach (['academic_year_id', 'semester_id'] as $key) if (isset($filters[$key])) $offeringsQuery->where($key, $filters[$key]);
        $offerings = $offeringsQuery->whereIn('course_id', $ids)->with(['course', 'academicYear', 'semester', 'academicProgram', 'gradeComponents'])
            ->orderBy('course_offering_id')->limit(501)->get();
        if ($offerings->count() > 500) throw ValidationException::withMessages(['academic_year_id' => 'Narrow the academic year/semester to resolve at most 500 offering contexts.']);
        $registrations = StudentCourseRegistration::query()->where('student_id', $student->getKey())
            ->whereIn('course_offering_id', $offerings->modelKeys())->orderBy('student_course_registration_id')->limit(1001)->get();
        if ($registrations->count() > 1000) throw ValidationException::withMessages(['academic_year_id' => 'Narrow the selected context.']);
        $snapshots = $registrations->isEmpty() ? collect() : $this->workflow->manualSnapshots($registrations)->groupBy('course_offering_id');
        $classifications = CourseRequirementClassification::indexActiveForProgram($programId, $ids);
        return ['student' => ['student_id' => (int) $student->getKey(), 'name' => trim($student->first_name.' '.$student->last_name),
            'student_number' => $student->student_number, 'college' => $student->academicProgram?->department?->college?->college_name,
            'program' => $student->academicProgram?->program_name], 'terms' => $terms,
            'courses' => $page->getCollection()->map(fn ($c) => ['course_id' => (int) $c->getKey(), 'course_code' => $c->course_code,
                'course_name' => $c->course_name, 'credit_hours' => $c->credit_hours,
                'own_program' => $classifications->has($c->getKey()),
                'catalog_source' => $c->in_college_departments || $c->in_college_programs ? 'college_catalog'
                    : ($classifications->has($c->getKey()) ? 'program_requirement' : 'existing_registration'),
                'requirement_classification' => CourseRequirementClassification::forStudentFromMap($programId, (int) $c->getKey(), $classifications),
                'offerings' => $offerings->where('course_id', $c->getKey())->map(fn ($o) => [
                    'course_offering_id' => (int) $o->getKey(), 'course_id' => (int) $o->course_id,
                    'academic_year_id' => (int) $o->academic_year_id, 'semester_id' => (int) $o->semester_id,
                    'academic_year' => $o->academicYear?->year_name, 'semester' => $o->semester?->semester_name,
                    'program' => $o->academicProgram?->program_name, 'status' => $o->status,
                    'required_parts' => $this->coverage->requiredRoles($o->course),
                    'configuration_missing' => $o->gradeComponents->isEmpty()
                        || array_diff($this->coverage->requiredRoles($o->course), $o->gradeComponents->where('is_required', true)->pluck('component_type')->all()) !== [],
                    'registrations' => $snapshots->get($o->getKey(), collect())->values()->all(),
                ])->values()->all()])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    /** Independent authorized lookup, including when catalog contexts exceed the limit. */
    public function periods(User $actor, Student $student): array
    {
        $this->access->authorize($actor, $student);
        $terms = $this->scope->scopeManualGradeOfferings(CourseOffering::query(), $actor)
            ->join('academic_years as y', 'y.academic_year_id', '=', 'course_offerings.academic_year_id')
            ->join('semesters as s', 's.semester_id', '=', 'course_offerings.semester_id')
            ->select('course_offerings.academic_year_id', 'year_name', 'course_offerings.semester_id', 'semester_name')
            ->distinct()->orderByDesc('course_offerings.academic_year_id')->orderBy('course_offerings.semester_id')->get()->toArray();
        return ['terms' => $terms,
            // Reference choices do not depend on a pre-existing offering (including historical terms).
            'academic_years' => \App\Models\AcademicYear::query()->orderByDesc('academic_year_id')->get(['academic_year_id', 'year_name'])->toArray(),
            'semesters' => \App\Models\Semester::query()->orderBy('semester_order')->orderBy('semester_id')->get(['semester_id', 'semester_name'])->toArray()];
    }

    public function preview(User $actor, Student $student, CourseOffering $offering): array
    {
        $this->access->authorizeOffering($actor, $student, $offering);
        // Existing GradeComponent creation permission; actual scope enforced above, no virtual grant.
        app(ResourceAuthorizationService::class)->authorize($actor, GradeComponent::class, true);
        $offering->load(['course', 'gradeComponents', 'gradePartApprovals', 'gradeApprovals.approvalStatus']);
        if ($this->grades->isOfficiallyApprovedOffering($offering)
            || $offering->gradePartApprovals->contains(fn ($p) => !in_array($p->status, ['draft', 'returned'], true))) {
            throw new GradeException('Offering grading is locked.', status: 409, errorCode: 'official_result_locked');
        }
        $roles = $this->coverage->requiredRoles($offering->course);
        if ($roles === []) throw new GradeException('Delivery components are undefined; correct the course configuration first.', status: 409, errorCode: 'manual_components_undefined');
        $limits = $this->grades->gradingPolicyLimits();
        foreach ($roles as $part) {
            if (!is_finite($limits[$part.'_max_mark']) || $limits[$part.'_max_mark'] <= 0) {
                throw new GradeException('The policy must define a positive maximum for every required part.', status: 409, errorCode: 'grading_policy_incompatible');
            }
        }
        $this->grades->assertRequiredPartsPolicyCompatible(in_array('theoretical', $roles, true), in_array('practical', $roles, true),
            $limits['theoretical_max_mark'], $limits['practical_max_mark']);
        $proposal = collect($roles)->map(fn ($part) => ['component_type' => $part,
            'component_name' => $part === 'theoretical' ? 'الامتحان النظري' : 'الامتحان العملي',
            'max_mark' => $limits[$part.'_max_mark'], 'is_required' => true, 'status' => 'active'])->all();
        if ($offering->gradeComponents->isNotEmpty()) {
            // Partial, inactive, optional, extra and differently weighted definitions need explicit existing configuration work.
            foreach ($proposal as $p) {
                $existing = $offering->gradeComponents->where('component_type', $p['component_type']);
                if ($existing->count() !== 1 || !$existing->first()->is_required || $existing->first()->status !== 'active'
                    || abs((float) $existing->first()->max_mark - $p['max_mark']) > 0.001
                    || $existing->first()->weight_percentage !== null) $this->incompatible();
            }
            if ($offering->gradeComponents->count() !== count($proposal)) $this->incompatible();
        }
        return ['course_offering_id' => (int) $offering->getKey(), 'components' => $proposal,
            'already_prepared' => $offering->gradeComponents->isNotEmpty(),
            'revision' => hash('sha256', json_encode([$offering->only(['course_id', 'academic_program_id', 'academic_year_id', 'semester_id']),
                $offering->course->getAttributes(), $limits, $proposal], JSON_THROW_ON_ERROR))];
    }

    public function prepare(User $actor, Student $student, CourseOffering $offering, array $data): array
    {
        $this->access->authorizeOffering($actor, $student, $offering);
        if (($data['confirmed'] ?? false) !== true) throw ValidationException::withMessages(['confirmed' => 'Confirmation required.']);
        return DB::transaction(function () use ($actor, $student, $offering, $data) {
            $offering = CourseOffering::query()->whereKey($offering->getKey())->lockForUpdate()->firstOrFail();
            GradePartApproval::query()->where('course_offering_id', $offering->getKey())->orderBy('component_type')->lockForUpdate()->get();
            SupplementaryExamTargetGuard::assertCourseOfferingConfigurationsMutable([$offering->getKey()]);
            GradeComponent::query()->where('course_offering_id', $offering->getKey())->orderBy('grade_component_id')->lockForUpdate()->get();
            $this->grades->lockDefaultGradingPolicy();
            $preview = $this->preview($actor, $student, $offering);
            if (!hash_equals($preview['revision'], $data['revision'])) throw new GradeException('Configuration changed; preview again.', status: 409, errorCode: 'manual_grade_entry_stale');
            if (!$preview['already_prepared']) {
                foreach ($preview['components'] as $component) GradeComponent::query()->create(['course_offering_id' => $offering->getKey()] + $component);
                UserActivityLog::query()->create(['user_id' => $actor->getKey(), 'module_code' => 'grades',
                    'action_code' => 'manual_grade_entry.components', 'description' => json_encode([
                        'course_offering_id' => $offering->getKey(), 'components' => $preview['components'],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            }
            return $this->preview($actor, $student, $offering);
        }, 3);
    }

    private function incompatible(): never
    {
        throw new GradeException('Existing configuration is partial or incompatible; no components were changed.', status: 409, errorCode: 'manual_components_incompatible');
    }

    /** Read-only plan. Existing multi-component definitions are reused, never rewritten. */
    public function recordingComponentPlan(CourseOffering $offering): array
    {
        $offering->loadMissing('course');
        $roles = $this->coverage->requiredRoles($offering->course);
        if ($roles === []) throw new GradeException('مكونات تدريس المقرر غير محددة.', status: 409, errorCode: 'manual_components_undefined');
        $limits = $this->grades->gradingPolicyLimits();
        $existing = $offering->exists ? $offering->gradeComponents()->orderBy('grade_component_id')->get() : collect();
        $components = $existing->isEmpty() ? collect($roles)->map(fn ($part) => [
            'key' => 'new:'.$part, 'component_type' => $part,
            'component_name' => $part === 'theoretical' ? 'الامتحان النظري' : 'الامتحان العملي',
            'max_mark' => $limits[$part.'_max_mark'], 'is_required' => true, 'status' => 'active',
        ]) : $existing->map(function ($component) use ($roles) {
            if (!$component->is_required || $component->status !== 'active'
                || !in_array($component->component_type, $roles, true)) $this->incompatible();
            return ['key' => (string) $component->getKey()] + $component->getAttributes();
        });
        foreach ($roles as $part) {
            if ($components->where('component_type', $part)->isEmpty()) $this->incompatible();
        }
        foreach ($components as $component) {
            if (!is_numeric($component['max_mark']) || !is_finite((float) $component['max_mark']) || $component['max_mark'] <= 0) $this->incompatible();
        }
        $this->grades->assertRequiredPartsPolicyCompatible(in_array('theoretical', $roles, true), in_array('practical', $roles, true),
            (float) $components->where('component_type', 'theoretical')->sum('max_mark'),
            (float) $components->where('component_type', 'practical')->sum('max_mark'));
        return ['create' => $existing->isEmpty(), 'components' => $components->values()->all(), 'policy' => $limits];
    }
}
