<?php

namespace App\Support;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\ProgramCourse;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;

class CourseRequirementClassification
{
    public const STATUS_MAPPED = 'mapped';

    public const STATUS_REQUIREMENT_MAPPING_MISSING = 'requirement_mapping_missing';

    public const STATUS_REQUIREMENT_CONFIGURATION_INVALID = 'requirement_configuration_invalid';

    public const STATUS_OUTSIDE_CURRENT_CURRICULUM = 'outside_current_curriculum';

    public const STATUS_NOT_LINKED_TO_PROGRAM = 'not_linked_to_program';

    /**
     * @return array<string, mixed>
     */
    public static function empty(?int $academicProgramId, string $status, ?string $reason = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'academic_program_id' => $academicProgramId,
            'program_course_id' => null,
            'requirement_group_id' => null,
            'group_code' => null,
            'group_name' => null,
            'requirement_type' => null,
            'requirement_scope' => null,
        ];
    }

    /**
     * Mirror AcademicRequirementService::classifyProgramCourse() validity rules.
     *
     * @return array<string, mixed>
     */
    public static function fromProgramCourse(?ProgramCourse $programCourse, ?int $academicProgramId = null): array
    {
        $programId = $academicProgramId !== null
            ? $academicProgramId
            : ($programCourse?->academic_program_id === null ? null : (int) $programCourse->academic_program_id);

        if ($programCourse === null) {
            return self::empty($programId, self::STATUS_NOT_LINKED_TO_PROGRAM);
        }

        self::hydrateProgramCourses([$programCourse]);

        $mapping = $programCourse->requirementMapping;
        if ($mapping === null) {
            return array_merge(self::empty($programId, self::STATUS_REQUIREMENT_MAPPING_MISSING, 'requirement_mapping_missing'), [
                'program_course_id' => $programCourse->program_course_id,
            ]);
        }

        $group = $mapping->requirementGroup;
        if ($group === null) {
            return array_merge(self::empty($programId, self::STATUS_REQUIREMENT_CONFIGURATION_INVALID, 'requirement_group_missing'), [
                'program_course_id' => $programCourse->program_course_id,
            ]);
        }

        if ((int) $group->academic_program_id !== (int) $programId) {
            return array_merge(self::empty($programId, self::STATUS_REQUIREMENT_CONFIGURATION_INVALID, 'requirement_group_program_mismatch'), [
                'program_course_id' => $programCourse->program_course_id,
                'requirement_group_id' => $group->requirement_group_id,
            ]);
        }

        if (! $group->is_active) {
            return array_merge(self::empty($programId, self::STATUS_REQUIREMENT_CONFIGURATION_INVALID, 'requirement_group_inactive'), [
                'program_course_id' => $programCourse->program_course_id,
                'requirement_group_id' => $group->requirement_group_id,
            ]);
        }

        if (strtolower((string) $programCourse->course_type) !== strtolower((string) $group->requirement_type)) {
            return array_merge(self::empty($programId, self::STATUS_REQUIREMENT_CONFIGURATION_INVALID, 'course_type_requirement_type_mismatch'), [
                'program_course_id' => $programCourse->program_course_id,
                'requirement_group_id' => $group->requirement_group_id,
            ]);
        }

        return [
            'status' => self::STATUS_MAPPED,
            'reason' => null,
            'academic_program_id' => $programId,
            'program_course_id' => $programCourse->program_course_id,
            'requirement_group_id' => $group->requirement_group_id,
            'group_code' => $group->group_code,
            'group_name' => $group->group_name,
            'requirement_type' => $group->requirement_type,
            'requirement_scope' => $group->requirement_scope,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forStudent(?int $studentProgramId, ?int $courseId, ?ProgramCourse $programCourse): array
    {
        if ($studentProgramId === null) {
            return self::empty(null, self::STATUS_NOT_LINKED_TO_PROGRAM);
        }

        if ($courseId === null || $programCourse === null) {
            return self::empty($studentProgramId, self::STATUS_OUTSIDE_CURRENT_CURRICULUM);
        }

        return self::fromProgramCourse($programCourse, $studentProgramId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOffering(CourseOffering $offering): array
    {
        $programId = $offering->academic_program_id === null ? null : (int) $offering->academic_program_id;
        if ($programId === null) {
            return self::empty(null, self::STATUS_NOT_LINKED_TO_PROGRAM);
        }

        if (! $offering->relationLoaded('programCourse')) {
            self::hydrateOfferings(collect([$offering]));
        }

        return self::fromProgramCourse($offering->getRelation('programCourse'), $programId);
    }

    public static function hydrateOfferings(iterable $offerings): void
    {
        $collection = collect($offerings)->filter(fn ($offering): bool => $offering instanceof CourseOffering);
        $needed = $collection->filter(
            fn (CourseOffering $offering): bool => ! $offering->relationLoaded('programCourse')
        );
        if ($needed->isEmpty()) {
            return;
        }

        $programIds = $needed
            ->map(fn (CourseOffering $offering): ?int => $offering->academic_program_id === null ? null : (int) $offering->academic_program_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $courseIds = $needed
            ->map(fn (CourseOffering $offering): ?int => $offering->course_id === null ? null : (int) $offering->course_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $indexed = ($programIds === [] || $courseIds === [])
            ? collect()
            : ProgramCourse::query()
                ->where('is_active', true)
                ->whereIn('academic_program_id', $programIds)
                ->whereIn('course_id', $courseIds)
                ->with(['requirementMapping.requirementGroup', 'academicProgram'])
                ->get()
                ->keyBy(fn (ProgramCourse $programCourse): string => (int) $programCourse->academic_program_id.':'.(int) $programCourse->course_id);

        foreach ($needed as $offering) {
            $key = (int) $offering->academic_program_id.':'.(int) $offering->course_id;
            $offering->setRelation('programCourse', $indexed->get($key));
        }
    }

    /**
     * @param  iterable<int|null>  $courseIds
     * @return Collection<int, ProgramCourse>
     */
    public static function indexActiveForProgram(?int $programId, iterable $courseIds): Collection
    {
        if ($programId === null) {
            return collect();
        }

        return self::indexActiveForPrograms([$programId], $courseIds)
            ->mapWithKeys(fn (ProgramCourse $programCourse): array => [
                (int) $programCourse->course_id => $programCourse,
            ]);
    }

    /**
     * @param  iterable<int|null>  $academicProgramIds
     * @param  iterable<int|null>  $courseIds
     * @return Collection<string, ProgramCourse>
     */
    public static function indexActiveForPrograms(iterable $academicProgramIds, iterable $courseIds): Collection
    {
        $programIds = collect($academicProgramIds)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $ids = collect($courseIds)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($programIds === [] || $ids === []) {
            return collect();
        }

        return ProgramCourse::query()
            ->where('is_active', true)
            ->whereIn('academic_program_id', $programIds)
            ->whereIn('course_id', $ids)
            ->with([
                'requirementMapping.requirementGroup',
                'academicProgram',
                'academicLevel',
                'recommendedSemester',
            ])
            ->get()
            ->keyBy(fn (ProgramCourse $programCourse): string => (int) $programCourse->academic_program_id.':'.(int) $programCourse->course_id);
    }

    public static function hydrateProgramCourses(iterable $programCourses): void
    {
        $collection = collect($programCourses)->filter(fn ($item): bool => $item instanceof ProgramCourse);
        $unloaded = $collection->filter(
            fn (ProgramCourse $programCourse): bool => ! $programCourse->relationLoaded('requirementMapping')
        );
        if ($unloaded->isEmpty()) {
            return;
        }

        (new EloquentCollection($unloaded->values()->all()))->load([
            'requirementMapping.requirementGroup',
            'academicProgram',
            'academicLevel',
            'recommendedSemester',
        ]);
    }

    public static function hydrateCourses(iterable $courses, ?User $user = null): void
    {
        if ($user !== null) {
            self::hydrateCoursesForUser($courses, $user);

            return;
        }

        self::loadProgramCourses($courses, null);
    }

    /**
     * Batch-load active ProgramCourse rows visible to the user via DataScopeService.
     *
     * Used by generic CourseResource so program_requirement_classifications[]
     * never includes programs outside the current user's scope.
     */
    public static function hydrateCoursesForUser(iterable $courses, User $user): void
    {
        self::loadProgramCourses($courses, $user);
    }

    /**
     * @param  iterable<mixed>  $courses
     */
    private static function loadProgramCourses(iterable $courses, ?User $user): void
    {
        $collection = collect($courses)->filter(fn ($item): bool => $item instanceof Course);
        if ($collection->isEmpty()) {
            return;
        }

        $userId = $user?->getAuthIdentifier();
        $needsLoad = $collection->filter(function (Course $course) use ($user, $userId): bool {
            if (! $course->relationLoaded('programCourses')) {
                return true;
            }

            if ($user === null) {
                return false;
            }

            return $course->getAttribute('_program_courses_scoped_for_user_id') !== $userId;
        });

        if ($needsLoad->isNotEmpty()) {
            (new EloquentCollection($needsLoad->values()->all()))->load(self::programCourseEagerLoad($user));
            if ($user !== null) {
                foreach ($needsLoad as $course) {
                    $course->setAttribute('_program_courses_scoped_for_user_id', $userId);
                }
            }
        }

        self::hydrateProgramCourses(
            $collection
                ->filter(fn (Course $course): bool => $course->relationLoaded('programCourses'))
                ->flatMap(fn (Course $course) => $course->programCourses)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function programCourseEagerLoad(?User $user): array
    {
        return [
            'programCourses' => function ($query) use ($user): void {
                $query->where('is_active', true);
                if ($user !== null) {
                    app(DataScopeService::class)->scopeResourceQuery($query, $user);
                }
            },
            'programCourses.requirementMapping.requirementGroup',
            'programCourses.academicProgram',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function programClassificationsForCourse(Course $course): array
    {
        if (! $course->relationLoaded('programCourses')) {
            return [];
        }

        self::hydrateProgramCourses($course->programCourses);

        return $course->programCourses
            ->map(function (ProgramCourse $programCourse): array {
                $program = $programCourse->relationLoaded('academicProgram') ? $programCourse->academicProgram : null;

                return [
                    'academic_program_id' => $programCourse->academic_program_id,
                    'program_code' => $program?->program_code,
                    'program_name' => $program?->program_name,
                    'program_course_id' => $programCourse->program_course_id,
                    'requirement_classification' => self::fromProgramCourse(
                        $programCourse,
                        (int) $programCourse->academic_program_id
                    ),
                ];
            })
            ->values()
            ->all();
    }

    public static function attachStudentProgramCourses(iterable $registrations): void
    {
        $models = collect($registrations)->filter();
        $grouped = $models->groupBy(function ($registration): string {
            $programId = $registration->student?->academic_program_id ?? null;

            return $programId === null ? '' : (string) (int) $programId;
        });

        foreach ($grouped as $programId => $group) {
            if ($programId === '') {
                foreach ($group as $registration) {
                    if (! $registration->relationLoaded('studentProgramCourse')) {
                        $registration->setRelation('studentProgramCourse', null);
                    }
                }
                continue;
            }

            $courseIds = $group->map(function ($registration): ?int {
                $courseId = $registration->courseOffering?->course_id
                    ?? $registration->courseOffering?->course?->course_id;

                return $courseId === null ? null : (int) $courseId;
            });
            $map = self::indexActiveForProgram((int) $programId, $courseIds);
            foreach ($group as $registration) {
                $courseId = $registration->courseOffering?->course_id
                    ?? $registration->courseOffering?->course?->course_id;
                $registration->setRelation(
                    'studentProgramCourse',
                    $courseId === null ? null : $map->get((int) $courseId)
                );
            }
        }
    }

    /**
     * @param  Collection<int, ProgramCourse>  $map
     * @return array<string, mixed>
     */
    public static function forStudentFromMap(?int $programId, ?int $courseId, Collection $map): array
    {
        $programCourse = ($courseId === null) ? null : $map->get($courseId);

        return self::forStudent($programId, $courseId, $programCourse);
    }

    /**
     * @param  Collection<string, ProgramCourse>  $map
     * @return array<string, mixed>
     */
    public static function forStudentFromPairMap(?int $programId, ?int $courseId, Collection $map): array
    {
        $programCourse = ($programId === null || $courseId === null)
            ? null
            : $map->get($programId.':'.$courseId);

        return self::forStudent($programId, $courseId, $programCourse);
    }

    /**
     * Attach the student's program-course mapping onto offerings so student-facing
     * lists resolve classification against student.academic_program_id.
     */
    public static function classifyStudentOfferings(?int $studentProgramId, iterable $offerings): void
    {
        $collection = collect($offerings)->filter(fn ($offering): bool => $offering instanceof CourseOffering);
        $map = self::indexActiveForProgram(
            $studentProgramId,
            $collection->map(fn (CourseOffering $offering): ?int => $offering->course_id === null ? null : (int) $offering->course_id)
        );

        foreach ($collection as $offering) {
            $courseId = $offering->course_id === null ? null : (int) $offering->course_id;
            $offering->setRelation('studentProgramCourse', $courseId === null ? null : $map->get($courseId));
            $offering->setAttribute('_student_academic_program_id', $studentProgramId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function forStudentOffering(CourseOffering $offering): array
    {
        if ($offering->relationLoaded('studentProgramCourse')) {
            $programId = $offering->getAttribute('_student_academic_program_id');
            $programId = $programId === null ? null : (int) $programId;
            $courseId = $offering->course_id === null ? null : (int) $offering->course_id;

            return self::forStudent($programId, $courseId, $offering->getRelation('studentProgramCourse'));
        }

        return self::forOffering($offering);
    }

    public static function modelsFromResource(mixed $resource): Collection
    {
        if ($resource instanceof AbstractPaginator || $resource instanceof AbstractCursorPaginator || $resource instanceof Paginator) {
            return collect($resource->getCollection());
        }

        if ($resource instanceof EloquentCollection || $resource instanceof Collection) {
            return collect($resource);
        }

        return collect($resource);
    }
}
