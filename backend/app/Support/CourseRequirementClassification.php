<?php

namespace App\Support;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\ProgramCourse;
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
    public static function empty(?int $academicProgramId, string $status): array
    {
        return [
            'status' => $status,
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
     * @return array<string, mixed>
     */
    public static function fromProgramCourse(?ProgramCourse $programCourse, ?int $academicProgramId = null): array
    {
        $programId = $programCourse?->academic_program_id !== null
            ? (int) $programCourse->academic_program_id
            : $academicProgramId;

        if ($programCourse === null) {
            return self::empty($programId, self::STATUS_NOT_LINKED_TO_PROGRAM);
        }

        $mappingLoaded = $programCourse->relationLoaded('requirementMapping');
        $mapping = $mappingLoaded ? $programCourse->requirementMapping : null;
        $group = null;
        if ($mapping !== null && $mapping->relationLoaded('requirementGroup')) {
            $group = $mapping->requirementGroup;
        } elseif ($programCourse->relationLoaded('requirementGroup')) {
            $group = $programCourse->requirementGroup;
        }

        if ($group === null) {
            return array_merge(self::empty($programId, self::STATUS_REQUIREMENT_MAPPING_MISSING), [
                'program_course_id' => $programCourse->program_course_id,
            ]);
        }

        $groupType = strtolower((string) $group->requirement_type);
        $courseType = strtolower((string) $programCourse->course_type);
        $normalizedCourseType = $courseType === 'required' ? 'mandatory' : $courseType;
        $status = self::STATUS_MAPPED;
        if ($normalizedCourseType !== '' && $groupType !== '' && $normalizedCourseType !== $groupType) {
            $status = self::STATUS_REQUIREMENT_CONFIGURATION_INVALID;
        }

        return [
            'status' => $status,
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

        $ids = collect($courseIds)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return collect();
        }

        return ProgramCourse::query()
            ->where('is_active', true)
            ->where('academic_program_id', $programId)
            ->whereIn('course_id', $ids)
            ->with(['requirementMapping.requirementGroup', 'academicProgram'])
            ->get()
            ->keyBy(fn (ProgramCourse $programCourse): int => (int) $programCourse->course_id);
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

        $unloaded->load(['requirementMapping.requirementGroup', 'academicProgram']);
    }

    public static function hydrateCourses(iterable $courses): void
    {
        $collection = collect($courses)->filter(fn ($item): bool => $item instanceof Course);
        $unloaded = $collection->filter(
            fn (Course $course): bool => ! $course->relationLoaded('programCourses')
        );
        if ($unloaded->isNotEmpty()) {
            $unloaded->load([
                'programCourses' => fn ($query) => $query->where('is_active', true),
                'programCourses.requirementMapping.requirementGroup',
                'programCourses.academicProgram',
            ]);
        }

        self::hydrateProgramCourses(
            $collection
                ->filter(fn (Course $course): bool => $course->relationLoaded('programCourses'))
                ->flatMap(fn (Course $course) => $course->programCourses)
        );
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
