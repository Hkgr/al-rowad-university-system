<?php

namespace App\Services;

use App\Exceptions\SupplementaryExamOfferingException;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamOfferingEvent;
use App\Models\SupplementaryExamOfferingSource;
use App\Models\SupplementaryExamPeriod;
use App\Models\User;
use App\Support\SupplementaryExamOfferingGovernance;
use App\Support\SupplementaryExamPeriodGovernance;
use App\Support\SupplementaryExamPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SupplementaryExamOfferingService
{
    public function __construct(
        private DataScopeService $dataScope,
        private TeachingAssignmentService $teachingAssignments,
        private SupplementaryExamOfferingSourceService $sources,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        $this->assertCanView($user);
        $collegeIds = $this->accessibleCollegeIdList($user);

        $departments = Department::query()
            ->whereIn('college_id', $collegeIds === [] ? [0] : $collegeIds)
            ->orderBy('department_name')
            ->get(['department_id', 'department_name', 'college_id']);

        $programs = AcademicProgram::query()
            ->whereHas('department', fn ($department) => $department->whereIn('college_id', $collegeIds === [] ? [0] : $collegeIds))
            ->with('department:department_id,department_name,college_id')
            ->orderBy('program_name')
            ->get(['academic_program_id', 'program_code', 'program_name', 'department_id']);

        $years = AcademicYear::query()
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->get(['academic_year_id', 'year_name', 'is_current', 'is_active']);

        $periods = SupplementaryExamPeriod::query()
            ->with(['academicYear', 'semester'])
            ->orderBy('academic_year_id')
            ->orderBy('semester_id')
            ->get();

        return [
            'academic_years' => $years->map(fn (AcademicYear $year) => [
                'academic_year_id' => $year->academic_year_id,
                'id' => $year->academic_year_id,
                'name' => $year->year_name,
                'year_name' => $year->year_name,
                'is_current' => (bool) $year->is_current,
            ])->values()->all(),
            'departments' => $departments->map(fn (Department $department) => [
                'department_id' => $department->department_id,
                'id' => $department->department_id,
                'name' => $department->department_name,
                'department_name' => $department->department_name,
                'college_id' => $department->college_id,
            ])->values()->all(),
            'programs' => $programs->map(fn (AcademicProgram $program) => [
                'academic_program_id' => $program->academic_program_id,
                'id' => $program->academic_program_id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
                'name' => $program->program_name,
                'department_id' => $program->department_id,
            ])->values()->all(),
            'periods' => $periods->map(fn (SupplementaryExamPeriod $period) => $this->periodPayload($period))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(User $user, int $periodId, int $programId): array
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();

        $collegeIds = $this->accessibleCollegeIdList($user);
        $period = SupplementaryExamPeriod::query()->with(['academicYear', 'semester'])->findOrFail($periodId);
        $program = AcademicProgram::query()->with('department.college')->findOrFail($programId);
        $this->assertProgramInAccessibleCollege($program, $collegeIds);

        $semesterOrder = SupplementaryExamPolicy::periodSemesterOrder($period);
        $allowedOrders = SupplementaryExamPolicy::allowedSourceSemesterOrders($period);
        $studentLimit = SupplementaryExamPolicy::maxCoursesPerStudent($period);

        $eligible = $this->sources->eligibleSourcesForProgram($period, $program, $collegeIds);
        $existing = SupplementaryExamOffering::query()
            ->with(['course', 'sources.courseOffering.semester'])
            ->where('supplementary_exam_period_id', $period->supplementary_exam_period_id)
            ->where('academic_program_id', $program->academic_program_id)
            ->get()
            ->keyBy('course_id');

        $grouped = $this->sources->groupSourcesByCourse($eligible);
        $courseIds = $grouped->keys()->merge($existing->keys())->unique()->sort()->values();

        $available = [];
        foreach ($courseIds as $courseId) {
            $sourceRows = $grouped->get($courseId, collect());
            $offering = $existing->get($courseId);
            $course = $sourceRows->first()?->course ?? $offering?->course;
            if ($course === null) {
                $course = Course::query()->find($courseId);
            }
            if ($course === null) {
                continue;
            }

            $sourcePayload = $sourceRows->isNotEmpty()
                ? $sourceRows->map(fn (CourseOffering $source) => $this->sourcePayload($source))->values()->all()
                : $offering?->sources->map(function (SupplementaryExamOfferingSource $row) {
                    $source = $row->courseOffering;
                    if ($source === null) {
                        return null;
                    }

                    return $this->sourcePayload($source);
                })->filter()->values()->all() ?? [];

            $available[] = [
                'course_id' => (int) $course->course_id,
                'course_code' => $course->course_code,
                'course_name' => $course->course_name,
                'source_offerings' => $sourcePayload,
                'has_current_supplementary_offering' => $offering !== null,
                'supplementary_offering' => $offering === null ? null : [
                    'id' => $offering->supplementary_exam_offering_id,
                    'supplementary_exam_offering_id' => $offering->supplementary_exam_offering_id,
                    'status' => $offering->status,
                    'opened_at' => $offering->opened_at,
                    'closed_at' => $offering->closed_at,
                ],
            ];
        }

        return [
            'period' => $this->periodPayload($period, $allowedOrders, $studentLimit, $semesterOrder),
            'program' => [
                'academic_program_id' => $program->academic_program_id,
                'id' => $program->academic_program_id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
                'name' => $program->program_name,
            ],
            'available_courses' => $available,
            'manageable' => $this->periodIsManageable($period),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listOfferings(User $user, array $filters = [])
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();
        $collegeIds = $this->accessibleCollegeIdList($user);

        $query = SupplementaryExamOffering::query()
            ->with($this->offeringRelations())
            ->whereHas('academicProgram.department', fn ($department) => $department->whereIn(
                'college_id',
                $collegeIds === [] ? [0] : $collegeIds
            ));

        if (isset($filters['supplementary_exam_period_id'])) {
            $query->where('supplementary_exam_period_id', (int) $filters['supplementary_exam_period_id']);
        }
        if (isset($filters['academic_program_id'])) {
            $query->where('academic_program_id', (int) $filters['academic_program_id']);
        }
        if (isset($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->orderBy('supplementary_exam_offering_id')->get();
    }

    public function findOffering(User $user, SupplementaryExamOffering $offering): SupplementaryExamOffering
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();
        $offering->loadMissing($this->offeringRelations());
        $this->assertOfferingInScope($offering, $this->accessibleCollegeIdList($user));

        return $offering;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function open(User $user, array $payload): SupplementaryExamOffering
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();

        if (! DB::transactionLevel()) {
            return DB::transaction(fn () => $this->openInsideTransaction($user, $payload));
        }

        return $this->openInsideTransaction($user, $payload);
    }

    public function close(User $user, SupplementaryExamOffering $offering): SupplementaryExamOffering
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();

        if (! DB::transactionLevel()) {
            return DB::transaction(fn () => $this->closeInsideTransaction($user, $offering));
        }

        return $this->closeInsideTransaction($user, $offering);
    }

    public function reopen(User $user, SupplementaryExamOffering $offering): SupplementaryExamOffering
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();

        if (! DB::transactionLevel()) {
            return DB::transaction(fn () => $this->reopenInsideTransaction($user, $offering));
        }

        return $this->reopenInsideTransaction($user, $offering);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function openInsideTransaction(User $user, array $payload): SupplementaryExamOffering
    {
        if (! DB::transactionLevel()) {
            throw SupplementaryExamOfferingException::transactionRequired();
        }

        $this->assertCanManage($user);
        $collegeIds = $this->accessibleCollegeIdList($user);

        $period = SupplementaryExamPeriod::query()
            ->with('semester')
            ->lockForUpdate()
            ->findOrFail((int) $payload['supplementary_exam_period_id']);
        $this->assertPeriodManageable($period);

        $program = AcademicProgram::query()
            ->with('department.college')
            ->findOrFail((int) $payload['academic_program_id']);
        $this->assertProgramInAccessibleCollege($program, $collegeIds);

        $course = Course::query()->findOrFail((int) $payload['course_id']);
        $qualifying = $this->sources->eligibleSources($period, $program, $course, $collegeIds);
        if ($qualifying->isEmpty()) {
            throw SupplementaryExamOfferingException::noActualSourceOffering();
        }

        $existing = SupplementaryExamOffering::query()
            ->where('supplementary_exam_period_id', $period->supplementary_exam_period_id)
            ->where('academic_program_id', $program->academic_program_id)
            ->where('course_id', $course->course_id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw SupplementaryExamOfferingException::offeringExists([
                'supplementary_exam_offering_id' => $existing->supplementary_exam_offering_id,
                'status' => $existing->status,
                'use_reopen' => $existing->isClosed(),
            ]);
        }

        $offering = new SupplementaryExamOffering;
        $offering->supplementary_exam_period_id = $period->supplementary_exam_period_id;
        $offering->academic_program_id = $program->academic_program_id;
        $offering->course_id = $course->course_id;
        $offering->status = SupplementaryExamOfferingGovernance::STATUS_OPEN;
        $offering->opened_by_user_id = $user->user_id;
        $offering->opened_at = now();
        $offering->closed_by_user_id = null;
        $offering->closed_at = null;

        try {
            $offering->save();
        } catch (QueryException $exception) {
            if ($this->isUniqueIdentityViolation($exception)) {
                throw SupplementaryExamOfferingException::offeringExists();
            }

            throw $exception;
        }

        foreach ($qualifying as $source) {
            SupplementaryExamOfferingSource::query()->create([
                'supplementary_exam_offering_id' => $offering->supplementary_exam_offering_id,
                'course_offering_id' => $source->course_offering_id,
                'created_at' => now(),
            ]);
        }

        SupplementaryExamOfferingEvent::query()->create([
            'supplementary_exam_offering_id' => $offering->supplementary_exam_offering_id,
            'event_type' => SupplementaryExamOfferingGovernance::EVENT_OPENED,
            'from_status' => null,
            'to_status' => SupplementaryExamOfferingGovernance::STATUS_OPEN,
            'actor_user_id' => $user->user_id,
            'notes' => null,
            'created_at' => now(),
        ]);

        return $offering->fresh($this->offeringRelations());
    }

    private function closeInsideTransaction(User $user, SupplementaryExamOffering $offering): SupplementaryExamOffering
    {
        if (! DB::transactionLevel()) {
            throw SupplementaryExamOfferingException::transactionRequired();
        }

        $locked = SupplementaryExamOffering::query()
            ->with(['period.semester', 'academicProgram.department.college'])
            ->lockForUpdate()
            ->findOrFail($offering->supplementary_exam_offering_id);

        $this->assertOfferingInScope($locked, $this->accessibleCollegeIdList($user));
        $this->assertPeriodManageable($locked->period);
        if (! $locked->isOpen()) {
            throw SupplementaryExamOfferingException::offeringNotOpen();
        }

        $locked->status = SupplementaryExamOfferingGovernance::STATUS_CLOSED;
        $locked->closed_by_user_id = $user->user_id;
        $locked->closed_at = now();
        $locked->save();

        SupplementaryExamOfferingEvent::query()->create([
            'supplementary_exam_offering_id' => $locked->supplementary_exam_offering_id,
            'event_type' => SupplementaryExamOfferingGovernance::EVENT_CLOSED,
            'from_status' => SupplementaryExamOfferingGovernance::STATUS_OPEN,
            'to_status' => SupplementaryExamOfferingGovernance::STATUS_CLOSED,
            'actor_user_id' => $user->user_id,
            'notes' => null,
            'created_at' => now(),
        ]);

        return $locked->fresh($this->offeringRelations());
    }

    private function reopenInsideTransaction(User $user, SupplementaryExamOffering $offering): SupplementaryExamOffering
    {
        if (! DB::transactionLevel()) {
            throw SupplementaryExamOfferingException::transactionRequired();
        }

        $locked = SupplementaryExamOffering::query()
            ->with(['period.semester', 'academicProgram.department.college', 'course', 'sources.courseOffering'])
            ->lockForUpdate()
            ->findOrFail($offering->supplementary_exam_offering_id);

        $collegeIds = $this->accessibleCollegeIdList($user);
        $this->assertOfferingInScope($locked, $collegeIds);
        $this->assertPeriodManageable($locked->period);
        if (! $locked->isClosed()) {
            throw SupplementaryExamOfferingException::offeringNotClosed();
        }

        if ($locked->sources->isEmpty()) {
            throw SupplementaryExamOfferingException::sourceStale();
        }

        foreach ($locked->sources as $mapping) {
            $source = $mapping->courseOffering;
            if ($source === null
                || ! $this->sources->sourceStillValid(
                    $locked->period,
                    $locked->academicProgram,
                    $locked->course,
                    $source,
                    $collegeIds
                )) {
                throw SupplementaryExamOfferingException::sourceStale();
            }
        }

        $locked->status = SupplementaryExamOfferingGovernance::STATUS_OPEN;
        $locked->closed_by_user_id = null;
        $locked->closed_at = null;
        $locked->save();

        SupplementaryExamOfferingEvent::query()->create([
            'supplementary_exam_offering_id' => $locked->supplementary_exam_offering_id,
            'event_type' => SupplementaryExamOfferingGovernance::EVENT_REOPENED,
            'from_status' => SupplementaryExamOfferingGovernance::STATUS_CLOSED,
            'to_status' => SupplementaryExamOfferingGovernance::STATUS_OPEN,
            'actor_user_id' => $user->user_id,
            'notes' => null,
            'created_at' => now(),
        ]);

        return $locked->fresh($this->offeringRelations());
    }

    private function holdsAssignedPermission(User $user, string $permission): bool
    {
        return $user->effectivePermissions()->contains($permission);
    }

    private function assertCanManage(User $user): void
    {
        if (! $user->isDean()
            || ! $this->holdsAssignedPermission($user, SupplementaryExamOfferingGovernance::PERMISSION_MANAGE)) {
            throw SupplementaryExamOfferingException::manageForbidden();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->isDean()
            || ! $this->holdsAssignedPermission($user, SupplementaryExamOfferingGovernance::PERMISSION_VIEW)) {
            throw SupplementaryExamOfferingException::viewForbidden();
        }
    }

    private function assertSchemaReady(): void
    {
        if (! SupplementaryExamOfferingGovernance::schemaReady()) {
            throw SupplementaryExamOfferingException::schemaNotReady();
        }
    }

    private function assertPeriodManageable(?SupplementaryExamPeriod $period): void
    {
        if ($period === null || $period->isLegacy()
            || (string) $period->status !== SupplementaryExamPeriodGovernance::STATUS_ANNOUNCED) {
            throw SupplementaryExamOfferingException::periodNotManageable();
        }
    }

    private function periodIsManageable(SupplementaryExamPeriod $period): bool
    {
        return ! $period->isLegacy()
            && (string) $period->status === SupplementaryExamPeriodGovernance::STATUS_ANNOUNCED;
    }

    /**
     * @param  list<int>  $collegeIds
     */
    private function assertProgramInAccessibleCollege(AcademicProgram $program, array $collegeIds): void
    {
        $program->loadMissing('department.college');
        $collegeId = $program->department?->college_id;
        if ($collegeId === null || $program->department?->college === null) {
            throw SupplementaryExamOfferingException::programOutOfScope();
        }
        if (! in_array((int) $collegeId, $collegeIds, true)) {
            throw SupplementaryExamOfferingException::programOutOfScope();
        }
    }

    /**
     * @param  list<int>  $collegeIds
     */
    private function assertOfferingInScope(SupplementaryExamOffering $offering, array $collegeIds): void
    {
        $offering->loadMissing('academicProgram.department.college');
        if ($offering->academicProgram === null) {
            throw SupplementaryExamOfferingException::programOutOfScope();
        }
        $this->assertProgramInAccessibleCollege($offering->academicProgram, $collegeIds);
    }

    /**
     * @return list<int>
     */
    private function accessibleCollegeIdList(User $user): array
    {
        return $this->teachingAssignments->accessibleCollegeIdList($user);
    }

    /**
     * @return list<string>
     */
    private function offeringRelations(): array
    {
        return ['period.academicYear', 'period.semester', 'academicProgram', 'course', 'sources.courseOffering.semester', 'openedBy', 'closedBy'];
    }

    /**
     * @param  list<int>|null  $allowedOrders
     * @return array<string, mixed>
     */
    private function periodPayload(
        SupplementaryExamPeriod $period,
        ?array $allowedOrders = null,
        ?int $studentLimit = null,
        ?int $semesterOrder = null,
    ): array {
        $period->loadMissing(['academicYear', 'semester']);
        $order = $semesterOrder ?? (int) ($period->semester?->semester_order ?? 0);
        if ($allowedOrders === null && in_array($order, [1, 2, 3], true)) {
            $allowedOrders = SupplementaryExamPolicy::allowedSourceSemesterOrdersForOrder($order);
            $studentLimit = SupplementaryExamPolicy::maxCoursesPerStudentForOrder($order);
        }

        return [
            'id' => $period->supplementary_exam_period_id,
            'supplementary_exam_period_id' => $period->supplementary_exam_period_id,
            'name' => $period->period_name,
            'period_name' => $period->period_name,
            'status' => $period->status,
            'semester_order' => $period->semester?->semester_order,
            'source_semester_orders' => $allowedOrders,
            'student_course_limit' => $studentLimit,
            'academic_year' => $period->academicYear === null ? null : [
                'academic_year_id' => $period->academicYear->academic_year_id,
                'id' => $period->academicYear->academic_year_id,
                'name' => $period->academicYear->year_name,
                'year_name' => $period->academicYear->year_name,
            ],
            'semester' => $period->semester === null ? null : [
                'semester_id' => $period->semester->semester_id,
                'id' => $period->semester->semester_id,
                'name' => $period->semester->semester_name,
                'semester_name' => $period->semester->semester_name,
                'semester_order' => $period->semester->semester_order,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePayload(CourseOffering $source): array
    {
        $source->loadMissing('semester');

        return [
            'course_offering_id' => $source->course_offering_id,
            'semester_id' => $source->semester_id,
            'semester_name' => $source->semester?->semester_name,
            'semester_order' => $source->semester?->semester_order,
        ];
    }

    private function isUniqueIdentityViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($sqlState !== '23000' && ! str_contains((string) $exception->getCode(), '23000')) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'uq_seo_period_program_course')
            || str_contains($message, 'supplementary_exam_period_id')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate');
    }
}
