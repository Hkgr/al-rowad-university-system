<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicProgram;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamRegistration;
use App\Services\DataScopeService;
use App\Services\SupplementaryExamRegistrationService;
use App\Services\SupplementaryExamRegistrationWindowService;
use App\Support\SupplementaryExamRegistrationGovernance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SupplementaryExamRegistrationOfficeController extends Controller
{
    public function __construct(
        private readonly SupplementaryExamRegistrationService $service,
        private readonly SupplementaryExamRegistrationWindowService $window,
        private readonly DataScopeService $scope,
    ) {}

    public function open(Request $request, int|string $period)
    {
        return response()->json(['data' => $this->window->open($request->user(), (int) $period)]);
    }

    public function close(Request $request, int|string $period)
    {
        return response()->json(['data' => $this->window->close($request->user(), (int) $period)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplementary_exam_offering_id' => 'required|integer',
            'student_course_registration_id' => 'required|integer',
        ]);

        return response()->json([
            'data' => $this->service->registerForStudent(
                $request->user(),
                (int) $data['supplementary_exam_offering_id'],
                (int) $data['student_course_registration_id'],
            ),
        ], 201);
    }

    public function cancel(Request $request, int|string $registration)
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);

        return response()->json([
            'data' => $this->service->cancelForStudent(
                $request->user(),
                (int) $registration,
                $data['reason'],
            ),
        ]);
    }

    public function periods(Request $request)
    {
        $this->service->ready();
        $user = $request->user();
        abort_unless(
            $user->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::VIEW)
                || $user->hasRoleCode('super_admin'),
            403,
        );

        $actualScopes = collect($this->scope->scopes($user));
        $universityScope = $actualScopes->contains(
            fn (array $scope): bool => $scope['type'] === 'university',
        );
        $periods = SupplementaryExamPeriod::query()
            ->with([
                'academicYear',
                'semester',
                'supplementaryExamOfferings.academicProgram.department',
            ])
            ->whereNotIn('status', ['legacy'])
            ->orderByDesc('supplementary_exam_period_id')
            ->get()
            ->filter(fn (SupplementaryExamPeriod $period): bool => $universityScope
                || $period->supplementaryExamOfferings->contains(
                    fn ($offering): bool => $offering->academicProgram !== null
                        && $this->programIsInActualScope($offering->academicProgram, $actualScopes),
                ))
            ->map(fn (SupplementaryExamPeriod $period): array => [
                'supplementary_exam_period_id' => (int) $period->getKey(),
                'period_name' => $period->period_name,
                'academic_year' => $period->academicYear,
                'semester' => $period->semester,
                'status' => (string) $period->status,
                'registration_window_open' => $period->status === 'registration_open',
                'registration_window_closed' => SupplementaryExamRegistrationGovernance::isRosterFixed(
                    (string) $period->status,
                ),
            ])
            ->values();

        return response()->json(['data' => $periods]);
    }

    public function index(Request $request, int|string $period)
    {
        $this->service->ready();
        $user = $request->user();
        abort_unless(
            $user->effectivePermissions()->contains(SupplementaryExamRegistrationGovernance::VIEW)
                || $user->hasRoleCode('super_admin'),
            403,
        );

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'offering_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $periodQuery = SupplementaryExamPeriod::query();
        if (! $this->scope->hasActualUniversityScope($user)) {
            $periodQuery->whereHas('supplementaryExamOfferings', function (Builder $offering) use ($user): void {
                $offering->whereHas('academicProgram', function (Builder $program) use ($user): void {
                    $this->scope->scopeProgramsForMutation($program, $user);
                });
            });
        }
        $periodRecord = $periodQuery->findOrFail((int) $period);
        $query = SupplementaryExamRegistration::query()
            ->with([
                'student',
                'offering.course',
                'offering.academicProgram',
                'originalRegistration.courseOffering.semester',
            ])
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->whereHas('offering', fn (Builder $offering): Builder => $offering
                ->where('supplementary_exam_period_id', $periodRecord->getKey()))
            ->whereHas('student', function (Builder $student) use ($user): void {
                $this->scope->scopeStudents($student, $user);
            })
            ->whereHas('offering.academicProgram', function (Builder $program) use ($user): void {
                $this->scope->scopeProgramsForMutation($program, $user);
            });

        if (isset($filters['offering_id'])) {
            $query->where('supplementary_exam_offering_id', (int) $filters['offering_id']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $this->applySearch($query, $search);
        }

        $status = (string) $periodRecord->status;
        $permissions = $user->effectivePermissions();
        $actualRegistrationOfficer = $user->isRegistrationOfficer();
        $summaryQuery = clone $query;
        $paginator = $query
            ->orderBy('supplementary_exam_registration_id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'period_status' => $status,
            'list_status' => SupplementaryExamRegistrationGovernance::isRosterFixed($status) ? 'fixed' : 'draft',
            'capabilities' => [
                'can_manage_registrations' => $actualRegistrationOfficer
                    && $permissions->contains(SupplementaryExamRegistrationGovernance::MANAGE),
                'can_manage_window' => $actualRegistrationOfficer
                    && $permissions->contains(SupplementaryExamRegistrationGovernance::WINDOW),
            ],
            'summary' => [
                'registered_students' => (clone $summaryQuery)->distinct()->count('student_id'),
                'offerings_with_registrations' => (clone $summaryQuery)->distinct()->count('supplementary_exam_offering_id'),
            ],
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $match) use ($search): void {
            $match->whereHas('student', function (Builder $student) use ($search): void {
                $student->where(function (Builder $fields) use ($search): void {
                    $fields->where('student_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })->orWhereHas('offering.course', function (Builder $course) use ($search): void {
                $course->where(function (Builder $fields) use ($search): void {
                    $fields->where('course_code', 'like', "%{$search}%")
                        ->orWhere('course_name', 'like', "%{$search}%");
                });
            })->orWhereHas('offering.academicProgram', function (Builder $program) use ($search): void {
                $program->where(function (Builder $fields) use ($search): void {
                    $fields->where('program_code', 'like', "%{$search}%")
                        ->orWhere('program_name', 'like', "%{$search}%");
                });
            });
        });
    }

    /** @param Collection<int, array{type: string, id: int}> $scopes */
    private function programIsInActualScope(AcademicProgram $program, Collection $scopes): bool
    {
        return $scopes->contains(fn (array $scope): bool =>
            ($scope['type'] === 'program' && $scope['id'] === (int) $program->getKey())
            || ($scope['type'] === 'department' && $scope['id'] === (int) $program->department_id)
            || ($scope['type'] === 'college' && $scope['id'] === (int) $program->department?->college_id)
        );
    }
}
