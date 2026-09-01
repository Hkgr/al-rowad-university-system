<?php

namespace App\Services;

use App\Exceptions\CourseOfferingScheduleException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingScheduleSlot;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationRequestItem;
use App\Models\User;
use App\Support\SemesterOfferingGovernance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CourseOfferingScheduleService
{
    public const COMPONENT_THEORETICAL = 'theoretical';

    public const COMPONENT_PRACTICAL = 'practical';

    public const LOCK_REGISTRATION_STARTED = 'course_registration_started';

    public const LOCK_OFFICIAL_REGISTRATION = 'student_registration_exists';

    public const LOCK_REQUEST_RELIANCE = 'submitted_registration_request_exists';

    public function __construct(
        private CourseOfferingInstructorCoverageService $coverage,
        private AcademicCalendarPolicyService $calendar,
        private DataScopeService $scope,
        private TeachingAssignmentService $teachingAssignments,
    ) {
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('course_offering_schedule_slots')
            && Schema::hasColumns('course_offering_schedule_slots', [
                'course_offering_schedule_slot_id',
                'course_offering_id',
                'component_type',
                'day_of_week',
                'start_time',
                'end_time',
                'location_label',
                'created_by_user_id',
                'created_at',
                'updated_at',
            ]);
    }

    /**
     * Load all schedule rows in one query and describe each Offering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeMany(Collection $offerings, bool $includeEditability = false, ?CarbonInterface $at = null): array
    {
        $offerings = $offerings
            ->filter(fn ($offering): bool => $offering instanceof CourseOffering)
            ->unique('course_offering_id')
            ->values();

        if ($offerings->isEmpty()) {
            return [];
        }

        $offerings = new EloquentCollection($offerings->all());
        $offerings->loadMissing('course');
        if (! $this->schemaReady()) {
            return $offerings->mapWithKeys(function (CourseOffering $offering) use ($includeEditability): array {
                $description = $this->unavailableDescription($offering);
                if ($includeEditability) {
                    $description += [
                        'editable' => false,
                        'locked_reason' => CourseOfferingScheduleException::SCHEMA_NOT_READY,
                    ];
                }

                return [(int) $offering->course_offering_id => $description];
            })->all();
        }

        $offeringIds = $offerings->pluck('course_offering_id')->map(fn ($id): int => (int) $id)->all();
        $slotsByOffering = CourseOfferingScheduleSlot::query()
            ->whereIn('course_offering_id', $offeringIds)
            ->orderBy('course_offering_id')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('course_offering_schedule_slot_id')
            ->get()
            ->groupBy('course_offering_id');

        $editability = $includeEditability
            ? $this->editabilityMany($offerings, $at)
            : [];

        return $offerings->mapWithKeys(function (CourseOffering $offering) use ($slotsByOffering, $editability): array {
            $description = $this->describeLoaded(
                $offering,
                $slotsByOffering->get($offering->course_offering_id, collect()),
            );
            if (isset($editability[(int) $offering->course_offering_id])) {
                $description += $editability[(int) $offering->course_offering_id];
            }

            return [(int) $offering->course_offering_id => $description];
        })->all();
    }

    public function describe(CourseOffering $offering, bool $includeEditability = false): array
    {
        return $this->describeMany(collect([$offering]), $includeEditability)[(int) $offering->course_offering_id];
    }

    /** @param list<array<string, mixed>> $slots */
    public function replace(User $actor, CourseOffering $offering, array $slots): array
    {
        if (! $this->schemaReady()) {
            throw CourseOfferingScheduleException::schemaNotReady();
        }

        return DB::transaction(function () use ($actor, $offering, $slots): array {
            $locked = CourseOffering::query()
                ->with(['course', 'academicProgram.department.college', 'department.college'])
                ->whereKey($offering->course_offering_id)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                throw (new ModelNotFoundException())->setModel(CourseOffering::class, [$offering->course_offering_id]);
            }

            $this->assertDeanCanManage($actor, $locked);
            $editability = $this->editabilityMany(collect([$locked]))[(int) $locked->course_offering_id];
            if (! $editability['editable']) {
                throw CourseOfferingScheduleException::locked((string) $editability['locked_reason']);
            }

            CourseOfferingScheduleSlot::query()
                ->where('course_offering_id', $locked->course_offering_id)
                ->orderBy('course_offering_schedule_slot_id')
                ->lockForUpdate()
                ->get();

            $normalized = $this->validateReplacement($locked, $slots);
            CourseOfferingScheduleSlot::query()
                ->where('course_offering_id', $locked->course_offering_id)
                ->delete();

            foreach ($normalized as $slot) {
                CourseOfferingScheduleSlot::query()->create([
                    ...$slot,
                    'course_offering_id' => (int) $locked->course_offering_id,
                    'created_by_user_id' => (int) $actor->user_id,
                ]);
            }

            return $this->describe($locked, true);
        });
    }

    /**
     * Evaluate complete schedules and conflicts for all candidate Offerings
     * from one batch-loaded graph.
     *
     * @param list<int> $officialOfferingIds
     * @param list<int> $requestOfferingIds
     * @return array<int, array<string, mixed>>
     */
    public function registrationEvaluations(
        Student $student,
        Collection $targets,
        array $officialOfferingIds,
        array $requestOfferingIds,
    ): array {
        if ($student->getKey() !== null) {
            $officialOfferingIds = collect([
                ...$officialOfferingIds,
                ...StudentCourseRegistration::query()
                    ->where('student_id', $student->getKey())
                    ->current()
                    ->pluck('course_offering_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            ])->unique()->values()->all();
        }
        $targetIds = $targets->pluck('course_offering_id')->map(fn ($id): int => (int) $id)->all();
        $allIds = collect([...$targetIds, ...$officialOfferingIds, ...$requestOfferingIds])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($targetIds === []) {
            return [];
        }
        if (! $this->schemaReady()) {
            return collect($targetIds)->mapWithKeys(fn (int $id): array => [$id => [
                'reason' => CourseOfferingScheduleException::SCHEMA_NOT_READY,
                'schedule' => [
                    'schema_ready' => false,
                    'components_defined' => false,
                    'complete' => false,
                    'required_components' => [],
                    'scheduled_components' => [],
                    'missing_components' => [],
                    'invalid_components' => [],
                    'slots' => [],
                ],
                'conflicts' => [],
            ]])->all();
        }

        $offerings = CourseOffering::query()
            ->with('course')
            ->whereIn('course_offering_id', $allIds)
            ->get()
            ->keyBy('course_offering_id');
        $descriptions = $this->describeMany($offerings->values());
        $comparisonIds = collect([...$officialOfferingIds, ...$requestOfferingIds])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $evaluations = [];
        foreach ($targetIds as $targetId) {
            $target = $offerings->get($targetId);
            $schedule = $descriptions[$targetId] ?? null;
            if ($target === null || $schedule === null || $schedule['complete'] !== true) {
                $evaluations[$targetId] = [
                    'reason' => CourseOfferingScheduleException::INCOMPLETE,
                    'schedule' => $schedule ?? $this->unavailableDescription($targets->firstWhere('course_offering_id', $targetId)),
                    'conflicts' => [],
                ];
                continue;
            }

            $conflicts = [];
            foreach ($comparisonIds as $comparisonId) {
                if ($comparisonId === $targetId) {
                    continue;
                }
                $other = $offerings->get($comparisonId);
                $otherSchedule = $descriptions[$comparisonId] ?? null;
                if ($other === null
                    || $otherSchedule === null
                    || (int) $other->academic_year_id !== (int) $target->academic_year_id
                    || (int) $other->semester_id !== (int) $target->semester_id) {
                    continue;
                }
                $conflicts = [...$conflicts, ...$this->conflictsBetween($target, $schedule, $other, $otherSchedule)];
            }

            $evaluations[$targetId] = [
                'reason' => $conflicts === [] ? null : CourseOfferingScheduleException::CONFLICT,
                'schedule' => $schedule,
                'conflicts' => $conflicts,
            ];
        }

        return $evaluations;
    }

    /** @return array<int, array{editable: bool, locked_reason: ?string}> */
    private function editabilityMany(Collection $offerings, ?CarbonInterface $at = null): array
    {
        $evaluatedAt = $at === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($at)->utc();
        $ids = $offerings->pluck('course_offering_id')->map(fn ($id): int => (int) $id)->all();
        $registrationIds = StudentCourseRegistration::query()
            ->whereIn('course_offering_id', $ids)
            ->distinct()
            ->pluck('course_offering_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
        $requestIds = StudentRegistrationRequestItem::query()
            ->join('student_registration_requests as srr', 'srr.student_registration_request_id', '=', 'student_registration_request_items.student_registration_request_id')
            ->whereIn('student_registration_request_items.course_offering_id', $ids)
            ->where(function ($query): void {
                $query->whereNotNull('srr.first_submitted_at')
                    ->orWhereIn('srr.status', [
                        StudentRegistrationRequest::STATUS_SUBMITTED,
                        StudentRegistrationRequest::STATUS_RETURNED,
                        StudentRegistrationRequest::STATUS_APPROVED,
                        StudentRegistrationRequest::STATUS_EXPIRED,
                    ]);
            })
            ->distinct()
            ->pluck('student_registration_request_items.course_offering_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
        $startedByTerm = [];

        $result = [];
        foreach ($offerings as $offering) {
            $id = (int) $offering->course_offering_id;
            $reason = null;
            if ($registrationIds->has($id)) {
                $reason = self::LOCK_OFFICIAL_REGISTRATION;
            } elseif ($requestIds->has($id)) {
                $reason = self::LOCK_REQUEST_RELIANCE;
            } else {
                $term = (int) $offering->academic_year_id.':'.(int) $offering->semester_id;
                if (! array_key_exists($term, $startedByTerm)) {
                    $startedByTerm[$term] = $this->calendar->courseRegistrationHasEverStarted(
                        (int) $offering->academic_year_id,
                        (int) $offering->semester_id,
                        $evaluatedAt,
                    );
                }
                if ($startedByTerm[$term]) {
                    $reason = self::LOCK_REGISTRATION_STARTED;
                }
            }
            $result[$id] = ['editable' => $reason === null, 'locked_reason' => $reason];
        }

        return $result;
    }

    /** @param Collection<int, CourseOfferingScheduleSlot> $slots */
    private function describeLoaded(CourseOffering $offering, Collection $slots): array
    {
        $required = $this->coverage->requiredRoles($offering->course);
        $componentsDefined = $required !== [];
        $validSlots = $slots->filter(fn (CourseOfferingScheduleSlot $slot): bool => in_array($slot->component_type, $required, true));
        $scheduled = $validSlots->pluck('component_type')->unique()->values()->all();
        $missing = array_values(array_diff($required, $scheduled));
        $invalid = $slots->pluck('component_type')->reject(fn ($component): bool => in_array($component, $required, true))->unique()->values()->all();
        $hasInternalOverlap = $this->hasInternalOverlap($validSlots);

        return [
            'schema_ready' => true,
            'components_defined' => $componentsDefined,
            'complete' => $componentsDefined && $missing === [] && $invalid === [] && ! $hasInternalOverlap,
            'required_components' => array_values($required),
            'scheduled_components' => $scheduled,
            'missing_components' => $missing,
            'invalid_components' => $invalid,
            'has_internal_overlap' => $hasInternalOverlap,
            'slots' => $slots->map(fn (CourseOfferingScheduleSlot $slot): array => $this->slotPayload($slot))->values()->all(),
        ];
    }

    private function unavailableDescription(?CourseOffering $offering): array
    {
        $required = $offering === null ? [] : $this->coverage->requiredRoles($offering->course);

        return [
            'schema_ready' => false,
            'components_defined' => $required !== [],
            'complete' => false,
            'required_components' => array_values($required),
            'scheduled_components' => [],
            'missing_components' => array_values($required),
            'invalid_components' => [],
            'has_internal_overlap' => false,
            'slots' => [],
        ];
    }

    /** @param Collection<int, CourseOfferingScheduleSlot> $slots */
    private function hasInternalOverlap(Collection $slots): bool
    {
        $ordered = $slots->values();
        foreach ($ordered as $index => $slot) {
            foreach ($ordered->slice($index + 1) as $other) {
                if ((int) $slot->day_of_week === (int) $other->day_of_week
                    && $this->overlaps($this->slotPayload($slot), $this->slotPayload($other))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $slots */
    private function validateReplacement(CourseOffering $offering, array $slots): array
    {
        $required = $this->coverage->requiredRoles($offering->course);
        if ($required === []) {
            throw CourseOfferingScheduleException::incomplete($this->unavailableDescription($offering));
        }

        $normalized = [];
        foreach ($slots as $slot) {
            $component = (string) $slot['component_type'];
            if (! in_array($component, $required, true)) {
                throw CourseOfferingScheduleException::invalidComponent($component);
            }
            $start = strlen((string) $slot['start_time']) === 5 ? $slot['start_time'].':00' : (string) $slot['start_time'];
            $end = strlen((string) $slot['end_time']) === 5 ? $slot['end_time'].':00' : (string) $slot['end_time'];
            if ($start >= $end) {
                throw new CourseOfferingScheduleException(
                    'The timetable start time must be before its end time.',
                    'offering_schedule_invalid_interval',
                    ['slots' => ['start_time_must_precede_end_time']],
                    status: 422,
                );
            }
            $location = isset($slot['location_label']) ? trim((string) $slot['location_label']) : '';
            $normalized[] = [
                'component_type' => $component,
                'day_of_week' => (int) $slot['day_of_week'],
                'start_time' => $start,
                'end_time' => $end,
                'location_label' => $location === '' ? null : $location,
            ];
        }

        $seen = [];
        foreach ($normalized as $index => $slot) {
            $key = implode('|', [$slot['component_type'], $slot['day_of_week'], $slot['start_time'], $slot['end_time']]);
            if (isset($seen[$key])) {
                throw new CourseOfferingScheduleException('Duplicate timetable slot.', 'offering_schedule_duplicate_slot', ['slots' => ['duplicate_slot']], status: 422);
            }
            $seen[$key] = true;
            foreach (array_slice($normalized, 0, $index) as $other) {
                if ($slot['day_of_week'] === $other['day_of_week'] && $this->overlaps($slot, $other)) {
                    throw new CourseOfferingScheduleException('The course timetable contains overlapping slots.', 'offering_schedule_internal_overlap', ['slots' => ['overlapping_slots']], status: 422);
                }
            }
        }

        $scheduled = collect($normalized)->pluck('component_type')->unique()->all();
        $missing = array_values(array_diff($required, $scheduled));
        if ($missing !== []) {
            throw CourseOfferingScheduleException::incomplete([
                'components_defined' => true,
                'missing_components' => $missing,
            ]);
        }

        return $normalized;
    }

    private function assertDeanCanManage(User $actor, CourseOffering $offering): void
    {
        if (! $actor->isDean()
            || ! $actor->effectivePermissions()->contains(SemesterOfferingGovernance::PERMISSION_MANAGE)) {
            throw new AccessDeniedHttpException('Only an authorized Dean may manage the official timetable.');
        }
        if ($offering->academic_program_id === null
            || ! $this->scope->canMutateProgram($actor, (int) $offering->academic_program_id)
            || ! $this->scope->canAccessOffering($actor, $offering)) {
            throw (new ModelNotFoundException())->setModel(CourseOffering::class, [$offering->course_offering_id]);
        }

        $college = $offering->resolveCollege();
        if ($college === null
            || ! in_array((int) $college->college_id, $this->teachingAssignments->accessibleCollegeIdList($actor), true)) {
            throw (new ModelNotFoundException())->setModel(CourseOffering::class, [$offering->course_offering_id]);
        }
    }

    private function conflictsBetween(CourseOffering $target, array $targetSchedule, CourseOffering $other, array $otherSchedule): array
    {
        $targetRequired = $targetSchedule['required_components'] ?? [];
        $otherRequired = $otherSchedule['required_components'] ?? [];
        $targetSlots = collect($targetSchedule['slots'])->filter(fn (array $slot): bool => in_array($slot['component_type'], $targetRequired, true));
        $otherSlots = collect($otherSchedule['slots'])->filter(fn (array $slot): bool => in_array($slot['component_type'], $otherRequired, true));
        $conflicts = [];

        foreach ($targetSlots as $slot) {
            foreach ($otherSlots as $otherSlot) {
                if ((int) $slot['day_of_week'] !== (int) $otherSlot['day_of_week'] || ! $this->overlaps($slot, $otherSlot)) {
                    continue;
                }
                $conflicts[] = [
                    'course_offering_id' => (int) $target->course_offering_id,
                    'course_id' => (int) $target->course_id,
                    'course_code' => $target->course?->course_code,
                    'course_name' => $target->course?->course_name,
                    ...$this->slotConflictPayload($slot),
                    'conflicting_with' => [
                        'course_offering_id' => (int) $other->course_offering_id,
                        'course_id' => (int) $other->course_id,
                        'course_code' => $other->course?->course_code,
                        'course_name' => $other->course?->course_name,
                        ...$this->slotConflictPayload($otherSlot),
                    ],
                ];
            }
        }

        return $conflicts;
    }

    private function overlaps(array $a, array $b): bool
    {
        return (string) $a['start_time'] < (string) $b['end_time']
            && (string) $b['start_time'] < (string) $a['end_time'];
    }

    private function slotPayload(CourseOfferingScheduleSlot $slot): array
    {
        return [
            'course_offering_schedule_slot_id' => (int) $slot->course_offering_schedule_slot_id,
            'component_type' => (string) $slot->component_type,
            'day_of_week' => (int) $slot->day_of_week,
            'start_time' => (string) $slot->start_time,
            'end_time' => (string) $slot->end_time,
            'location_label' => $slot->location_label,
        ];
    }

    private function slotConflictPayload(array $slot): array
    {
        return [
            'component_type' => (string) $slot['component_type'],
            'day_of_week' => (int) $slot['day_of_week'],
            'start_time' => (string) $slot['start_time'],
            'end_time' => (string) $slot['end_time'],
        ];
    }
}
