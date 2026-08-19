<?php

namespace App\Services;

use App\Exceptions\CourseOfferingClosureException;
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

    public const ACTION_CREATED_PENDING_COVERAGE = 'created_pending_coverage';

    public function __construct(
        private DataScopeService $dataScope,
        private TeachingAssignmentService $teachingAssignments,
        private CourseOfferingContextService $offeringContext,
        private CourseOfferingOpeningService $opening,
        private CourseOfferingInstructorCoverageService $coverage,
        private CourseOfferingExceptionWorkflowService $exceptionWorkflow,
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
            $programCourse = $this->lockProgramCourse((int) $payload['program_course_id']);

            $yearId = (int) $payload['academic_year_id'];
            $semesterId = (int) $payload['semester_id'];
            $capacity = (int) ($payload['capacity'] ?? 40);
            if ($capacity < 1) {
                throw ValidationException::withMessages([
                    'capacity' => ['السعة يجب أن تكون 1 على الأقل.'],
                ]);
            }

            // Actual offering identity uses the Dean-selected year/semester.
            // ProgramCourse.recommended_semester_id is advisory metadata only.
            $resolved = $this->findOrCreateClosedOffering(
                $user,
                $programCourse,
                $yearId,
                $semesterId,
                $capacity,
            );
            $offering = $resolved['offering'];
            $collegeIds = $this->accessibleCollegeIdList($user);

            if ($resolved['created']) {
                return [
                    'action' => self::ACTION_CREATED_PENDING_COVERAGE,
                    'program_course_id' => $programCourse->program_course_id,
                    'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
                ];
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

            $offering = $this->opening->normalOpen($offering, $user);

            return [
                'action' => 'reopened',
                'program_course_id' => $programCourse->program_course_id,
                'offering' => $this->offeringPayload($this->hydrateOffering($offering)),
            ];
        });
    }

    /**
     * Prepare missing CourseOfferings as CLOSED for the selected actual term.
     * Does not open offerings, assign instructors, or change existing status.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bulkPrepare(User $user, array $payload): array
    {
        $this->assertCanManage($user);

        $programId = (int) $payload['academic_program_id'];
        $yearId = (int) $payload['academic_year_id'];
        $semesterId = (int) $payload['semester_id'];
        $mode = (string) $payload['mode'];

        $collegeIds = $this->accessibleCollegeIdList($user);
        $program = AcademicProgram::query()
            ->with('department.college')
            ->find($programId);

        if ($program === null) {
            throw (new ModelNotFoundException())->setModel(AcademicProgram::class, [$programId]);
        }

        $this->assertProgramInAccessibleCollege($user, $program, $collegeIds);

        $programCourses = $this->selectProgramCoursesForBulkPrepare(
            $mode,
            $program,
            $semesterId,
            isset($payload['academic_level_id']) ? (int) $payload['academic_level_id'] : null,
            $payload['program_course_ids'] ?? [],
        );

        $items = [];
        foreach ($programCourses as $programCourse) {
            $items[] = $this->prepareOneClosedOffering(
                $user,
                $programCourse,
                $yearId,
                $semesterId,
            );
        }

        $createdCount = 0;
        $existingCount = 0;
        $failedCount = 0;
        foreach ($items as $item) {
            if ($item['result'] === 'created') {
                $createdCount++;
            } elseif ($item['result'] === 'existing') {
                $existingCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'selected_count' => count($items),
            'created_count' => $createdCount,
            'existing_count' => $existingCount,
            'failed_count' => $failedCount,
            'items' => $items,
        ];
    }

    /**
     * Create a missing offering as CLOSED, or return the existing row unchanged.
     *
     * @return array{offering: CourseOffering, created: bool}
     */
    private function findOrCreateClosedOffering(
        User $user,
        ProgramCourse $programCourse,
        int $yearId,
        int $semesterId,
        int $capacity,
    ): array {
        $programCourse->load(['course', 'academicProgram.department.college']);
        $this->assertActiveCurriculumRow($programCourse);

        // Actual offering identity uses the Dean-selected year/semester.
        // ProgramCourse.recommended_semester_id is advisory metadata only.
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

        if ($offering !== null) {
            return [
                'offering' => $offering,
                'created' => false,
            ];
        }

        try {
            $offering = $this->offeringContext->createOffering($context, [
                'faculty_member_id' => null,
                'capacity' => $capacity,
                'available_seats' => $capacity,
                'status' => self::STATUS_CLOSED,
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

            return [
                'offering' => $offering,
                'created' => false,
            ];
        }

        return [
            'offering' => $offering,
            'created' => (bool) $offering->wasRecentlyCreated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareOneClosedOffering(
        User $user,
        ProgramCourse $programCourse,
        int $yearId,
        int $semesterId,
    ): array {
        $base = [
            'program_course_id' => (int) $programCourse->program_course_id,
            'course_id' => $programCourse->course_id === null ? null : (int) $programCourse->course_id,
            'course_code' => $programCourse->course?->course_code,
            'course_name' => $programCourse->course?->course_name,
            'course_offering_id' => null,
            'result' => 'failed',
            'error_code' => null,
        ];

        try {
            $resolved = DB::transaction(function () use ($user, $programCourse, $yearId, $semesterId): array {
                $locked = $this->lockProgramCourse((int) $programCourse->program_course_id);

                return $this->findOrCreateClosedOffering(
                    $user,
                    $locked,
                    $yearId,
                    $semesterId,
                    40,
                );
            });
        } catch (CourseOfferingContextException $exception) {
            $base['error_code'] = $exception->errorCode ?? 'prepare_failed';

            return $base;
        } catch (ValidationException) {
            $base['error_code'] = 'invalid_program_course';

            return $base;
        } catch (ModelNotFoundException) {
            $base['error_code'] = 'not_found';

            return $base;
        } catch (ConflictHttpException) {
            $base['error_code'] = 'conflict';

            return $base;
        }

        $offering = $resolved['offering'];

        return [
            ...$base,
            'course_offering_id' => (int) $offering->course_offering_id,
            'result' => $resolved['created'] ? 'created' : 'existing',
            'error_code' => null,
        ];
    }

    /**
     * @param  list<mixed>  $programCourseIds
     * @return \Illuminate\Support\Collection<int, ProgramCourse>
     */
    private function selectProgramCoursesForBulkPrepare(
        string $mode,
        AcademicProgram $program,
        int $semesterId,
        ?int $academicLevelId,
        array $programCourseIds,
    ) {
        $programId = (int) $program->academic_program_id;

        if ($mode === 'selected') {
            return $this->selectedProgramCoursesForBulkPrepare($programId, $programCourseIds);
        }

        $query = ProgramCourse::query()
            ->with('course')
            ->where('academic_program_id', $programId)
            ->where('is_active', true)
            ->orderBy('program_course_id');

        if ($mode === 'advisory_semester') {
            $query->where('recommended_semester_id', $semesterId);
        } elseif ($mode === 'advisory_level') {
            if ($academicLevelId === null) {
                throw ValidationException::withMessages([
                    'academic_level_id' => ['المستوى الدراسي مطلوب لتجهيز مستوى واحد.'],
                ]);
            }

            $levelInProgram = ProgramCourse::query()
                ->where('academic_program_id', $programId)
                ->where('academic_level_id', $academicLevelId)
                ->exists();

            if (! $levelInProgram) {
                throw ValidationException::withMessages([
                    'academic_level_id' => ['المستوى الدراسي المحدد ليس ضمن خطة هذا البرنامج.'],
                ]);
            }

            $query->where('academic_level_id', $academicLevelId)
                ->where('recommended_semester_id', $semesterId);
        } elseif ($mode !== 'all_curriculum') {
            throw ValidationException::withMessages([
                'mode' => ['وضع التجهيز الجماعي غير معروف.'],
            ]);
        }

        return $query->get();
    }

    /**
     * @param  list<mixed>  $programCourseIds
     * @return \Illuminate\Support\Collection<int, ProgramCourse>
     */
    private function selectedProgramCoursesForBulkPrepare(int $programId, array $programCourseIds)
    {
        $requested = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $programCourseIds
        )));

        if ($requested === []) {
            throw ValidationException::withMessages([
                'program_course_ids' => ['يجب تحديد مادة واحدة على الأقل.'],
            ]);
        }

        $rows = ProgramCourse::query()
            ->with('course')
            ->whereIn('program_course_id', $requested)
            ->get()
            ->keyBy(fn (ProgramCourse $row): int => (int) $row->program_course_id);

        foreach ($requested as $id) {
            $row = $rows->get($id);
            if ($row === null) {
                throw ValidationException::withMessages([
                    'program_course_ids' => ['بعض المواد المحددة غير موجودة.'],
                ]);
            }

            if ((int) $row->academic_program_id !== $programId) {
                throw ValidationException::withMessages([
                    'program_course_ids' => ['لا يمكن تجهيز مادة من برنامج أكاديمي آخر.'],
                ]);
            }

            if (! $row->is_active) {
                throw ValidationException::withMessages([
                    'program_course_ids' => ['بعض المواد المحددة غير نشطة في خطة البرنامج.'],
                ]);
            }
        }

        return $rows
            ->sortBy(fn (ProgramCourse $row): int => (int) $row->program_course_id)
            ->values();
    }

    private function lockProgramCourse(int $programCourseId): ProgramCourse
    {
        $programCourse = ProgramCourse::query()
            ->whereKey($programCourseId)
            ->lockForUpdate()
            ->first();

        if ($programCourse === null) {
            throw (new ModelNotFoundException())->setModel(ProgramCourse::class, [$programCourseId]);
        }

        return $programCourse;
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

            $offering = $this->opening->normalOpen($offering, $user);

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

            throw CourseOfferingClosureException::workflowRequired();
        });
    }

    public function canManage(User $user): bool
    {
        if (! $user->isDean()) {
            return false;
        }

        $permissions = $user->effectivePermissions();

        return $permissions->contains('course_offerings.manage')
            || $permissions->contains('courses.manage');
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
            ->with(['course', 'academicLevel', 'recommendedSemester', 'requirementMapping.requirementGroup'])
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
                            'advisory_plan' => $this->advisoryPlan($row),
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
            ->with(array_merge(
                CourseOfferingInstructorCoverageService::eagerLoadRelations(),
                array_map(
                    static fn (string $relation): string => 'currentExceptionRequest.'.$relation,
                    $this->exceptionWorkflow->deanCardRelations()
                )
            ))
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
        $offering->load(array_merge(
            CourseOfferingInstructorCoverageService::eagerLoadRelations(),
            array_map(
                static fn (string $relation): string => 'currentExceptionRequest.'.$relation,
                $this->exceptionWorkflow->deanCardRelations()
            )
        ));
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
            'instructor_coverage' => $this->coverage->describe($offering),
            'exceptional_opening_request' => $this->exceptionWorkflow->cardSummary(
                $offering->relationLoaded('currentExceptionRequest')
                    ? $offering->currentExceptionRequest
                    : null
            ),
        ];
    }

    /**
     * Presentational ProgramCourse plan metadata. Never used as an offering
     * identity or eligibility filter.
     *
     * @return array{
     *     academic_level_id: int|null,
     *     academic_level_name: string|null,
     *     recommended_semester_id: int|null,
     *     recommended_semester_name: string|null
     * }
     */
    private function advisoryPlan(ProgramCourse $row): array
    {
        $level = $row->relationLoaded('academicLevel') ? $row->academicLevel : null;
        $semester = $row->relationLoaded('recommendedSemester') ? $row->recommendedSemester : null;

        return [
            'academic_level_id' => $row->academic_level_id === null
                ? null
                : (int) $row->academic_level_id,
            'academic_level_name' => $level?->level_name,
            'recommended_semester_id' => $row->recommended_semester_id === null
                ? null
                : (int) $row->recommended_semester_id,
            'recommended_semester_name' => $semester?->semester_name,
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
