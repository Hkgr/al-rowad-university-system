<?php

namespace App\Services;

use App\Exceptions\CourseOfferingContextException;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\ProgramCourse;
use App\Models\Semester;
use App\Models\User;
use App\Support\CourseRequirementClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DeanRegistrationOfferingService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public function __construct(
        private DataScopeService $dataScope,
        private TeachingAssignmentService $teachingAssignments,
        private CourseOfferingContextService $offeringContext,
    ) {
    }

    public function catalog(User $user, array $filters): array
    {
        $collegeIds = $this->accessibleCollegeIdList($user);
        $colleges = $this->accessibleColleges($collegeIds);
        $departmentId = isset($filters['department_id']) ? (int) $filters['department_id'] : null;
        $programId = isset($filters['academic_program_id']) ? (int) $filters['academic_program_id'] : null;
        $yearId = isset($filters['academic_year_id']) ? (int) $filters['academic_year_id'] : null;
        $semesterId = isset($filters['semester_id']) ? (int) $filters['semester_id'] : null;

        $departments = $this->scopedDepartments($user, $collegeIds);
        $programs = $this->scopedPrograms($user, $collegeIds, $departmentId);

        $selectedProgram = null;
        if ($programId !== null) {
            $selectedProgram = $programs->firstWhere('academic_program_id', $programId);
            if ($selectedProgram === null) {
                throw (new ModelNotFoundException())->setModel(AcademicProgram::class, [$programId]);
            }
            $this->assertProgramInAccessibleCollege($user, $selectedProgram, $collegeIds);
        }

        $college = $selectedProgram?->department?->college
            ?? ($colleges->count() === 1 ? $colleges->first() : null);

        $levels = [];
        $summary = [
            'total_courses' => 0,
            'open_count' => 0,
            'closed_count' => 0,
            'missing_count' => 0,
        ];

        if ($selectedProgram !== null && $yearId !== null && $semesterId !== null) {
            [$levels, $summary] = $this->curriculumLevels(
                $user,
                $selectedProgram,
                $yearId,
                $semesterId,
                $collegeIds,
                $filters['search'] ?? null
            );
        }

        $year = $yearId === null ? null : AcademicYear::query()->find($yearId);
        $semester = $semesterId === null ? null : Semester::query()->find($semesterId);

        return [
            'academic_year' => $year === null ? null : [
                'academic_year_id' => $year->academic_year_id,
                'year_name' => $year->year_name,
                'is_current' => (bool) $year->is_current,
            ],
            'semester' => $semester === null ? null : [
                'semester_id' => $semester->semester_id,
                'semester_name' => $semester->semester_name,
                'semester_order' => $semester->semester_order,
            ],
            'college' => $college === null ? null : [
                'college_id' => $college->college_id,
                'college_name' => $college->college_name,
            ],
            'filter_options' => [
                'academic_years' => AcademicYear::query()
                    ->orderByDesc('start_date')
                    ->get(['academic_year_id', 'year_name', 'is_current', 'is_active'])
                    ->all(),
                'semesters' => Semester::query()
                    ->orderBy('semester_order')
                    ->get(['semester_id', 'semester_name', 'semester_order', 'is_active'])
                    ->all(),
                'departments' => $departments
                    ->map(fn (Department $department) => [
                        'department_id' => $department->department_id,
                        'department_name' => $department->department_name,
                        'college_id' => $department->college_id,
                    ])
                    ->values()
                    ->all(),
                'academic_programs' => $this->scopedPrograms($user, $collegeIds, null)
                    ->map(fn (AcademicProgram $program) => [
                        'academic_program_id' => $program->academic_program_id,
                        'program_name' => $program->program_name,
                        'department_id' => $program->department_id,
                    ])
                    ->values()
                    ->all(),
            ],
            'summary' => $summary,
            'levels' => $levels,
            'can_manage' => $this->canManage($user),
        ];
    }

    public function openFromProgramCourse(User $user, array $payload): array
    {
        $this->assertCanManage($user);

        return DB::transaction(function () use ($user, $payload): array {
            $programCourse = ProgramCourse::query()
                ->whereKey((int) $payload['program_course_id'])
                ->lockForUpdate()
                ->first();

            if ($programCourse === null) {
                throw (new ModelNotFoundException())->setModel(ProgramCourse::class, [(int) $payload['program_course_id']]);
            }

            $programCourse->load(['course', 'academicProgram.department.college']);
            $this->assertActiveCurriculumRow($programCourse);

            $yearId = (int) $payload['academic_year_id'];
            $semesterId = (int) $payload['semester_id'];
            $context = $this->offeringContext->resolveFromProgramCourse(
                $programCourse,
                $yearId,
                $semesterId,
                $user,
                false,
            );

            $program = $context->academicProgram;
            $collegeIds = $this->accessibleCollegeIdList($user);
            $this->assertProgramInAccessibleCollege($user, $program, $collegeIds);

            $identity = $context->offeringAttributes();
            $courseId = $identity['course_id'];
            $programId = $identity['academic_program_id'];

            $offering = CourseOffering::query()
                ->where('course_id', $courseId)
                ->where('academic_year_id', $yearId)
                ->where('semester_id', $semesterId)
                ->where('academic_program_id', $programId)
                ->lockForUpdate()
                ->first();

            if ($offering === null) {
                $capacity = (int) ($payload['capacity'] ?? 40);
                if ($capacity < 1) {
                    throw ValidationException::withMessages([
                        'capacity' => ['السعة يجب أن تكون 1 على الأقل.'],
                    ]);
                }

                try {
                    $offering = $this->offeringContext->createOffering($context, [
                        'faculty_member_id' => null,
                        'capacity' => $capacity,
                        'available_seats' => $capacity,
                        'status' => self::STATUS_OPEN,
                    ]);
                } catch (CourseOfferingContextException $exception) {
                    if ($exception->errorCode !== CourseOfferingContextException::DUPLICATE_OFFERING) {
                        throw $exception;
                    }

                    $offering = CourseOffering::query()
                        ->where('course_id', $courseId)
                        ->where('academic_year_id', $yearId)
                        ->where('semester_id', $semesterId)
                        ->where('academic_program_id', $programId)
                        ->lockForUpdate()
                        ->first();

                    if ($offering === null) {
                        throw $exception;
                    }
                }

                if ($offering->wasRecentlyCreated) {
                    return [
                        'action' => 'created',
                        'program_course_id' => $programCourse->program_course_id,
                        'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
                    ];
                }
            }

            $this->assertProgramSpecificOffering($offering);
            $this->assertOfferingInAccessibleCollege($offering, $collegeIds);

            if ($offering->status === self::STATUS_OPEN) {
                return [
                    'action' => 'unchanged',
                    'program_course_id' => $programCourse->program_course_id,
                    'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
                ];
            }

            if ($offering->status !== self::STATUS_CLOSED) {
                throw new ConflictHttpException('تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.');
            }

            $offering->status = self::STATUS_OPEN;
            $offering->save();

            return [
                'action' => 'reopened',
                'program_course_id' => $programCourse->program_course_id,
                'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
            ];
        });
    }

    public function reopenOffering(User $user, CourseOffering $courseOffering): array
    {
        $this->assertCanManage($user);

        return DB::transaction(function () use ($user, $courseOffering): array {
            $offering = $this->lockAccessibleProgramOffering($user, $courseOffering);

            if ($offering->status === self::STATUS_OPEN) {
                return [
                    'action' => 'unchanged',
                    'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
                ];
            }

            if ($offering->status !== self::STATUS_CLOSED) {
                throw new ConflictHttpException('تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.');
            }

            $offering->status = self::STATUS_OPEN;
            $offering->save();

            return [
                'action' => 'reopened',
                'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
            ];
        });
    }

    public function closeOffering(User $user, CourseOffering $courseOffering): array
    {
        $this->assertCanManage($user);

        return DB::transaction(function () use ($user, $courseOffering): array {
            $offering = $this->lockAccessibleProgramOffering($user, $courseOffering);

            if ($offering->status === self::STATUS_CLOSED) {
                return [
                    'action' => 'unchanged',
                    'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
                ];
            }

            if ($offering->status !== self::STATUS_OPEN) {
                throw new ConflictHttpException('تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.');
            }

            $offering->status = self::STATUS_CLOSED;
            $offering->save();

            return [
                'action' => 'closed',
                'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
            ];
        });
    }

    public function canManage(User $user): bool
    {
        return $user->hasPermission('course_offerings.manage')
            || $user->hasPermission('courses.manage');
    }

    private function curriculumLevels(
        User $user,
        AcademicProgram $program,
        int $yearId,
        int $semesterId,
        array $collegeIds,
        ?string $search
    ): array {
        $rows = ProgramCourse::query()
            ->where('academic_program_id', $program->academic_program_id)
            ->where('is_active', true)
            ->with(['course', 'academicLevel', 'requirementMapping.requirementGroup'])
            ->orderBy('academic_level_id')
            ->get()
            ->filter(fn (ProgramCourse $row) => $row->course !== null);

        if ($search !== null && trim($search) !== '') {
            $pattern = mb_strtolower(trim($search));
            $rows = $rows->filter(function (ProgramCourse $row) use ($pattern): bool {
                $code = mb_strtolower((string) $row->course?->course_code);
                $name = mb_strtolower((string) $row->course?->course_name);

                return str_contains($code, $pattern) || str_contains($name, $pattern);
            });
        }

        $courseIds = $rows->pluck('course_id')->unique()->values();
        $offerings = $this->matchingOfferings($user, $program, $yearId, $semesterId, $courseIds, $collegeIds);

        $summary = [
            'total_courses' => $rows->count(),
            'open_count' => 0,
            'closed_count' => 0,
            'missing_count' => 0,
        ];

        $grouped = $rows
            ->groupBy(fn (ProgramCourse $row) => (string) ($row->academic_level_id ?? 'none'))
            ->map(function ($levelRows) use ($offerings, &$summary): array {
                $level = $levelRows->first()?->academicLevel;
                $courses = $levelRows
                    ->sortBy(fn (ProgramCourse $row) => mb_strtolower((string) $row->course?->course_code))
                    ->map(function (ProgramCourse $row) use ($offerings, &$summary): array {
                        $offering = $offerings->get((int) $row->course_id);
                        if ($offering === null) {
                            $summary['missing_count']++;
                        } elseif ($offering->status === self::STATUS_OPEN) {
                            $summary['open_count']++;
                        } elseif ($offering->status === self::STATUS_CLOSED) {
                            $summary['closed_count']++;
                        }

                        return [
                            'program_course_id' => $row->program_course_id,
                            'course_type' => $row->course_type,
                            'academic_level_id' => $row->academic_level_id,
                            'requirement_classification' => CourseRequirementClassification::fromProgramCourse($row),
                            'course' => [
                                'course_id' => $row->course->course_id,
                                'course_code' => $row->course->course_code,
                                'course_name' => $row->course->course_name,
                                'credit_hours' => $row->course->credit_hours,
                                'theoretical_hours' => $row->course->theoretical_hours,
                                'practical_hours' => $row->course->practical_hours,
                            ],
                            'offering' => $offering === null ? null : $this->offeringPayload($offering),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'academic_level_id' => $level?->academic_level_id,
                    'level_name' => $level?->level_name ?? 'بدون مستوى دراسي',
                    'level_order' => $level?->level_order ?? 999,
                    'courses' => $courses,
                ];
            })
            ->sortBy('level_order')
            ->values()
            ->all();

        return [$grouped, $summary];
    }

    private function matchingOfferings(
        User $user,
        AcademicProgram $program,
        int $yearId,
        int $semesterId,
        $courseIds,
        array $collegeIds
    ) {
        if ($courseIds->isEmpty()) {
            return collect();
        }

        $scopedIds = $this->scopedProgramOfferingIdsQuery($user, $collegeIds);

        return CourseOffering::query()
            ->whereIn('course_offerings.course_offering_id', $scopedIds)
            ->where('course_offerings.academic_year_id', $yearId)
            ->where('course_offerings.semester_id', $semesterId)
            ->where('course_offerings.academic_program_id', $program->academic_program_id)
            ->whereNotNull('course_offerings.academic_program_id')
            ->whereIn('course_offerings.course_id', $courseIds)
            ->withCount([
                'studentCourseRegistrations as registered_students_count' => fn (Builder $registrations) => $registrations->current(),
            ])
            ->orderBy('course_offerings.course_offering_id')
            ->get()
            ->unique('course_id')
            ->keyBy(fn (CourseOffering $offering) => (int) $offering->course_id);
    }

    private function lockAccessibleProgramOffering(User $user, CourseOffering $courseOffering): CourseOffering
    {
        $offering = CourseOffering::query()
            ->whereKey($courseOffering->course_offering_id)
            ->lockForUpdate()
            ->first();

        if ($offering === null) {
            throw (new ModelNotFoundException())->setModel(CourseOffering::class, [$courseOffering->course_offering_id]);
        }

        $this->assertProgramSpecificOffering($offering);
        $collegeIds = $this->accessibleCollegeIdList($user);
        $this->assertOfferingInAccessibleCollege($offering, $collegeIds);

        $offering->loadMissing('academicProgram.department.college');
        if ($offering->academicProgram !== null) {
            $this->assertProgramInAccessibleCollege($user, $offering->academicProgram, $collegeIds);
        }

        return $offering;
    }

    private function hydrateOffering(CourseOffering $offering): CourseOffering
    {
        $offering->loadCount([
            'studentCourseRegistrations as registered_students_count' => fn (Builder $registrations) => $registrations->current(),
        ]);

        return $offering;
    }

    private function offeringPayload(CourseOffering $offering): array
    {
        return [
            'course_offering_id' => $offering->course_offering_id,
            'status' => $offering->status,
            'capacity' => $offering->capacity,
            'available_seats' => $offering->available_seats,
            'registered_students_count' => (int) ($offering->registered_students_count ?? 0),
        ];
    }

    private function scopedDepartments(User $user, array $collegeIds)
    {
        if ($collegeIds === []) {
            return collect();
        }

        $query = Department::query()->orderBy('department_name');
        $this->dataScope->scopeDepartments($query, $user);
        $query->whereIn('college_id', $collegeIds);

        return $query->get(['department_id', 'department_name', 'college_id']);
    }

    private function scopedPrograms(User $user, array $collegeIds, ?int $departmentId)
    {
        if ($collegeIds === []) {
            return collect();
        }

        $query = AcademicProgram::query()
            ->with('department.college')
            ->orderBy('program_name');
        $this->dataScope->scopePrograms($query, $user);
        $query->whereHas(
            'department',
            fn (Builder $department) => $department->whereIn('college_id', $collegeIds)
        );

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        return $query->get();
    }

    private function scopedProgramOfferingIdsQuery(User $user, array $collegeIds): Builder
    {
        $query = CourseOffering::query()
            ->select('course_offerings.course_offering_id')
            ->whereNotNull('course_offerings.academic_program_id');
        $this->dataScope->scopeOfferings($query, $user);

        return $query->whereIn(
            'course_offerings.course_offering_id',
            CourseOffering::idsResolvedToColleges($collegeIds)
        );
    }

    private function accessibleColleges(array $collegeIds)
    {
        if ($collegeIds === []) {
            return collect();
        }

        return College::query()
            ->whereIn('college_id', $collegeIds)
            ->orderBy('college_name')
            ->get(['college_id', 'college_name']);
    }

    private function accessibleCollegeIdList(User $user): array
    {
        return $this->teachingAssignments->accessibleCollegeIdList($user);
    }

    private function assertProgramInAccessibleCollege(User $user, AcademicProgram $program, array $collegeIds): void
    {
        $program->loadMissing('department.college');
        $collegeId = $program->department?->college_id;
        if ($collegeId === null || ! in_array((int) $collegeId, $collegeIds, true)) {
            throw (new ModelNotFoundException())->setModel(AcademicProgram::class, [$program->academic_program_id]);
        }

        if (! $this->dataScope->canAccessProgram($user, (int) $program->academic_program_id)) {
            throw (new ModelNotFoundException())->setModel(AcademicProgram::class, [$program->academic_program_id]);
        }
    }

    private function assertOfferingInAccessibleCollege(CourseOffering $offering, array $collegeIds): void
    {
        $exists = CourseOffering::query()
            ->whereKey($offering->course_offering_id)
            ->whereNotNull('academic_program_id')
            ->whereIn('course_offering_id', CourseOffering::idsResolvedToColleges($collegeIds))
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException())->setModel(CourseOffering::class, [$offering->course_offering_id]);
        }
    }

    private function assertProgramSpecificOffering(CourseOffering $offering): void
    {
        if ($offering->academic_program_id === null) {
            throw (new ModelNotFoundException())->setModel(CourseOffering::class, [$offering->course_offering_id]);
        }
    }

    private function assertActiveCurriculumRow(ProgramCourse $programCourse): void
    {
        if (! $programCourse->is_active) {
            throw ValidationException::withMessages([
                'program_course_id' => ['هذه المادة غير نشطة في خطة البرنامج.'],
            ]);
        }

        if ($programCourse->course_id === null || $programCourse->academic_program_id === null) {
            throw ValidationException::withMessages([
                'program_course_id' => ['تعذّر تحديد المادة أو البرنامج من خطة الدراسة.'],
            ]);
        }
    }

    private function assertCanManage(User $user): void
    {
        if (! $this->canManage($user)) {
            throw new AccessDeniedHttpException('ليس لديك صلاحية لإدارة إتاحة هذه المادة.');
        }
    }
}
