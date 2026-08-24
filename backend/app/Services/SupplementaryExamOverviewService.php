<?php

namespace App\Services;

use App\Models\SupplementaryExamGraderAssignment;
use App\Models\SupplementaryExamGradeSubmission;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamRegistration;
use App\Models\User;
use App\Support\AcademicQueuePagination;
use App\Support\SupplementaryExamGradingGovernance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only, scope-filtered operational projection for the Exam Board. */
class SupplementaryExamOverviewService
{
    private const STAGES = [
        'announcement',
        'registration',
        'roster_fixed',
        'grading',
        'review',
        'publication',
        'materialization',
    ];

    private const STATUS_STAGE = [
        'announced' => 0,
        'registration_open' => 1,
        'registration_closed' => 2,
        'grading_open' => 3,
        'grading_submitted' => 4,
        'results_approved' => 5,
        'results_published' => 6,
        'results_materialized' => 7,
    ];

    public function __construct(
        private readonly DataScopeService $scope,
        private readonly SupplementaryExamGradingService $grading,
        private readonly SupplementaryExamOccurrenceService $occurrence,
    ) {}

    /** @return array<string, mixed> */
    public function overview(
        User $actor,
        ?int $periodId = null,
        ?int $offeringId = null,
        ?string $search = null,
        ?int $perPage = null,
    ): array {
        $periods = $this->accessiblePeriods($actor)->get();
        $selected = $periodId === null
            ? $periods->first()
            : $periods->firstWhere('supplementary_exam_period_id', $periodId);

        if ($periodId !== null && ! $selected) {
            abort(404);
        }

        if (! $selected) {
            return $this->emptyPayload($periods);
        }

        $offerings = $this->accessibleOfferings($actor, (int) $selected->getKey())
            ->with([
                'course',
                'academicProgram.department.college',
                'sources.courseOffering.academicYear',
                'sources.courseOffering.semester',
            ])
            ->orderBy('supplementary_exam_offering_id')
            ->get();
        $offeringIds = $offerings->modelKeys();
        if ($offeringId !== null && ! in_array($offeringId, array_map('intval', $offeringIds), true)) {
            abort(404);
        }

        $latestSubmissions = $this->grading->latestSubmissionsForOfferings(collect($offeringIds));
        $assignments = SupplementaryExamGraderAssignment::query()
            ->with('facultyMember.employee')
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->where('current_slot', 1)
            ->get()
            ->keyBy('supplementary_exam_offering_id');

        $counts = $this->offeringCounts($actor, $offeringIds, $latestSubmissions);
        $rosterQuery = $this->currentRoster($actor, (int) $selected->getKey(), $offeringId, $search)
            ->with(['student.academicProgram', 'offering.course', 'offering.academicProgram'])
            ->orderBy('supplementary_exam_registration_id');
        $paginator = $rosterQuery->paginate(AcademicQueuePagination::perPage($perPage));

        return [
            'periods' => $periods->map(fn (SupplementaryExamPeriod $period): array => $this->periodPayload($period))->all(),
            'selected_period' => $this->periodPayload($selected) + [
                'supplementary_exam_occurrence' => $this->occurrence->snapshotForPeriod($selected)->toPublicArray(),
            ],
            'stage' => $this->stagePayload((string) $selected->status),
            'summary' => $this->summary($actor, (int) $selected->getKey(), $offerings, $assignments, $latestSubmissions),
            'offerings' => $offerings->map(fn (SupplementaryExamOffering $offering): array => $this->offeringPayload(
                $offering,
                $assignments->get($offering->getKey()),
                $latestSubmissions->get($offering->getKey()),
                $counts->get($offering->getKey(), $this->zeroCounts()),
            ))->all(),
            'registrations' => [
                'data' => collect($paginator->items())->map(fn (SupplementaryExamRegistration $registration): array => $this->registrationPayload($registration))->all(),
                'meta' => AcademicQueuePagination::meta($paginator),
            ],
            'capabilities' => [
                'can_access_grades' => $actor->isExamOfficer()
                    && $actor->effectivePermissions()->contains(SupplementaryExamGradingGovernance::REVIEW)
                    && in_array((string) $selected->status, SupplementaryExamGradingGovernance::PERIOD_STATUSES, true),
            ],
        ];
    }

    private function accessiblePeriods(User $actor): Builder
    {
        $query = SupplementaryExamPeriod::query()
            ->with(['academicYear', 'semester'])
            ->whereNotNull('status')
            ->where('status', '<>', 'legacy')
            ->orderByDesc('supplementary_exam_period_id');

        if ($this->scope->hasActualUniversityScope($actor)) {
            return $query;
        }

        return $query->whereHas('supplementaryExamOfferings.academicProgram', function (Builder $program) use ($actor): void {
            $this->scope->scopePrograms($program, $actor);
        });
    }

    private function accessibleOfferings(User $actor, int $periodId): Builder
    {
        return SupplementaryExamOffering::query()
            ->where('supplementary_exam_period_id', $periodId)
            ->whereHas('academicProgram', function (Builder $program) use ($actor): void {
                $this->scope->scopePrograms($program, $actor);
            });
    }

    private function currentRoster(
        User $actor,
        int $periodId,
        ?int $offeringId = null,
        ?string $search = null,
    ): Builder {
        $query = SupplementaryExamRegistration::query()
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->whereHas('offering', function (Builder $offering) use ($actor, $periodId): void {
                $offering->where('supplementary_exam_period_id', $periodId)
                    ->whereHas('academicProgram', function (Builder $program) use ($actor): void {
                        $this->scope->scopePrograms($program, $actor);
                    });
            })
            ->whereHas('student', function (Builder $student) use ($actor): void {
                $this->scope->scopeStudents($student, $actor);
            });

        if ($offeringId !== null) {
            $query->where('supplementary_exam_offering_id', $offeringId);
        }
        $term = mb_substr(trim((string) $search), 0, 100);
        if ($term !== '') {
            $query->where(function (Builder $match) use ($term): void {
                $match->whereHas('student', fn (Builder $student): Builder => $student
                    ->where(fn (Builder $identity): Builder => $identity
                        ->where('student_number', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")))
                    ->orWhereHas('offering.course', fn (Builder $course): Builder => $course
                        ->where(fn (Builder $identity): Builder => $identity
                            ->where('course_code', 'like', "%{$term}%")
                            ->orWhere('course_name', 'like', "%{$term}%")))
                    ->orWhereHas('offering.academicProgram', fn (Builder $program): Builder => $program
                        ->where(fn (Builder $identity): Builder => $identity
                            ->where('program_code', 'like', "%{$term}%")
                            ->orWhere('program_name', 'like', "%{$term}%")));
            });
        }

        return $query;
    }

    /** @param list<int> $offeringIds */
    private function offeringCounts(User $actor, array $offeringIds, Collection $latestSubmissions): Collection
    {
        $counts = collect($offeringIds)->mapWithKeys(fn ($id): array => [(int) $id => $this->zeroCounts()]);
        if ($offeringIds === []) {
            return $counts;
        }

        $base = SupplementaryExamRegistration::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->whereHas('student', function (Builder $student) use ($actor): void {
                $this->scope->scopeStudents($student, $actor);
            });
        $registered = (clone $base)->selectRaw('supplementary_exam_offering_id, COUNT(*) AS aggregate')
            ->groupBy('supplementary_exam_offering_id')->pluck('aggregate', 'supplementary_exam_offering_id');
        $graded = (clone $base)->whereHas('gradeResult', fn (Builder $result): Builder => $result->whereNotNull('theoretical_mark'))
            ->selectRaw('supplementary_exam_offering_id, COUNT(*) AS aggregate')
            ->groupBy('supplementary_exam_offering_id')->pluck('aggregate', 'supplementary_exam_offering_id');
        $materialized = (clone $base)->whereHas('materialization')
            ->selectRaw('supplementary_exam_offering_id, COUNT(*) AS aggregate')
            ->groupBy('supplementary_exam_offering_id')->pluck('aggregate', 'supplementary_exam_offering_id');

        $publishedWinners = $latestSubmissions
            ->filter(fn (SupplementaryExamGradeSubmission $submission): bool => $submission->status === 'published')
            ->mapWithKeys(fn (SupplementaryExamGradeSubmission $submission): array => [
                (int) $submission->supplementary_exam_offering_id => (int) $submission->submission_version,
            ]);
        $published = collect();
        if ($publishedWinners->isNotEmpty()) {
            $published = (clone $base)
                ->whereHas('gradeResult', function (Builder $result) use ($publishedWinners): void {
                    $result->where('status', 'published')
                        ->where(function (Builder $winner) use ($publishedWinners): void {
                            foreach ($publishedWinners as $offeringId => $version) {
                                $winner->orWhere(fn (Builder $pair): Builder => $pair
                                    ->where('supplementary_exam_offering_id', $offeringId)
                                    ->where('submission_version', $version));
                            }
                        });
                })
                ->selectRaw('supplementary_exam_offering_id, COUNT(*) AS aggregate')
                ->groupBy('supplementary_exam_offering_id')
                ->pluck('aggregate', 'supplementary_exam_offering_id');
        }

        foreach ($counts->keys() as $offeringId) {
            $counts->put($offeringId, [
                'registered' => (int) ($registered[$offeringId] ?? 0),
                'graded' => (int) ($graded[$offeringId] ?? 0),
                'published' => (int) ($published[$offeringId] ?? 0),
                'materialized' => (int) ($materialized[$offeringId] ?? 0),
            ]);
        }

        return $counts;
    }

    private function summary(
        User $actor,
        int $periodId,
        Collection $offerings,
        Collection $assignments,
        Collection $submissions,
    ): array
    {
        $currentRoster = $this->currentRoster($actor, $periodId);

        return [
            'offerings_count' => $offerings->count(),
            'open_offerings_count' => $offerings->where('status', 'open')->count(),
            'registered_students_count' => (clone $currentRoster)->distinct()->count('student_id'),
            'grader_assigned_offerings_count' => $assignments->count(),
            'graded_students_count' => (clone $currentRoster)
                ->whereHas('gradeResult', fn (Builder $result): Builder => $result->whereNotNull('theoretical_mark'))
                ->distinct()->count('student_id'),
            'submitted_offerings_count' => $submissions->where('status', 'submitted')->count(),
            'approved_offerings_count' => $submissions->where('status', 'approved')->count(),
            'published_offerings_count' => $submissions->where('status', 'published')->count(),
            'materialized_students_count' => (clone $currentRoster)->whereHas('materialization')
                ->distinct()->count('student_id'),
        ];
    }

    private function periodPayload(SupplementaryExamPeriod $period): array
    {
        return [
            'supplementary_exam_period_id' => (int) $period->getKey(),
            'period_name' => $period->period_name,
            'status' => (string) $period->status,
            'is_active' => (bool) $period->is_active,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'decision_note' => $period->decision_note,
            'academic_year' => $period->academicYear ? [
                'academic_year_id' => (int) $period->academicYear->getKey(),
                'year_name' => $period->academicYear->year_name,
            ] : null,
            'semester' => $period->semester ? [
                'semester_id' => (int) $period->semester->getKey(),
                'semester_code' => $period->semester->semester_code,
                'semester_name' => $period->semester->semester_name,
            ] : null,
        ];
    }

    private function offeringPayload(
        SupplementaryExamOffering $offering,
        ?SupplementaryExamGraderAssignment $assignment,
        ?SupplementaryExamGradeSubmission $submission,
        array $counts,
    ): array
    {
        $employee = $assignment?->facultyMember?->employee;

        return [
            'supplementary_exam_offering_id' => (int) $offering->getKey(),
            'status' => (string) $offering->status,
            'course' => $offering->course ? [
                'course_id' => (int) $offering->course->getKey(),
                'course_code' => $offering->course->course_code,
                'course_name' => $offering->course->course_name,
            ] : null,
            'academic_program' => $offering->academicProgram ? [
                'academic_program_id' => (int) $offering->academicProgram->getKey(),
                'program_code' => $offering->academicProgram->program_code,
                'program_name' => $offering->academicProgram->program_name,
                'department_name' => $offering->academicProgram->department?->department_name,
                'college_name' => $offering->academicProgram->department?->college?->college_name,
            ] : null,
            'sources' => $offering->sources->map(fn ($source): array => [
                'supplementary_exam_offering_source_id' => (int) $source->getKey(),
                'course_offering_id' => (int) $source->course_offering_id,
                'academic_year' => $source->courseOffering?->academicYear?->year_name,
                'semester' => $source->courseOffering?->semester?->semester_name,
            ])->all(),
            'current_grader' => $assignment ? [
                'faculty_member_id' => (int) $assignment->faculty_member_id,
                'employee_number' => $employee?->employee_number,
                'full_name' => trim((string) $employee?->first_name.' '.(string) $employee?->last_name),
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ] : null,
            'workflow_status' => $submission?->status ?? ($counts['graded'] > 0 ? 'draft' : 'waiting'),
            'latest_submission' => $submission ? [
                'supplementary_exam_grade_submission_id' => (int) $submission->getKey(),
                'submission_version' => (int) $submission->submission_version,
                'status' => (string) $submission->status,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
                'published_at' => $submission->published_at?->toIso8601String(),
            ] : null,
            'counts' => $counts,
        ];
    }

    private function registrationPayload(SupplementaryExamRegistration $registration): array
    {
        $student = $registration->student;

        return [
            'supplementary_exam_registration_id' => (int) $registration->getKey(),
            'student' => $student ? [
                'student_id' => (int) $student->getKey(),
                'student_number' => $student->student_number,
                'full_name' => trim((string) $student->first_name.' '.(string) $student->last_name),
            ] : null,
            'course' => $registration->offering?->course ? [
                'course_code' => $registration->offering->course->course_code,
                'course_name' => $registration->offering->course->course_name,
            ] : null,
            'academic_program' => $registration->offering?->academicProgram ? [
                'program_code' => $registration->offering->academicProgram->program_code,
                'program_name' => $registration->offering->academicProgram->program_name,
            ] : null,
            'eligibility' => ['status' => 'eligible', 'reason' => $registration->eligibility_reason],
            'registration_channel' => $registration->registration_channel,
            'registered_at' => $registration->registered_at?->toIso8601String(),
            'status' => (string) $registration->status,
        ];
    }

    private function stagePayload(string $status): array
    {
        if (! array_key_exists($status, self::STATUS_STAGE)) {
            return [
                'known' => false,
                'status' => $status,
                'steps' => collect(self::STAGES)->map(fn (string $code): array => ['code' => $code, 'state' => 'unknown'])->all(),
            ];
        }
        $position = self::STATUS_STAGE[$status];

        return [
            'known' => true,
            'status' => $status,
            'steps' => collect(self::STAGES)->map(function (string $code, int $index) use ($position): array {
                $state = $position > $index ? 'completed' : ($position === $index ? 'current' : 'future');

                return ['code' => $code, 'state' => $state];
            })->all(),
        ];
    }

    private function emptyPayload(Collection $periods): array
    {
        return [
            'periods' => $periods->map(fn (SupplementaryExamPeriod $period): array => $this->periodPayload($period))->all(),
            'selected_period' => null,
            'stage' => null,
            'summary' => [
                'offerings_count' => 0, 'open_offerings_count' => 0, 'registered_students_count' => 0,
                'grader_assigned_offerings_count' => 0, 'graded_students_count' => 0,
                'submitted_offerings_count' => 0, 'approved_offerings_count' => 0,
                'published_offerings_count' => 0, 'materialized_students_count' => 0,
            ],
            'offerings' => [],
            'registrations' => ['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]],
            'capabilities' => ['can_access_grades' => false],
        ];
    }

    private function zeroCounts(): array
    {
        return ['registered' => 0, 'graded' => 0, 'published' => 0, 'materialized' => 0];
    }
}
