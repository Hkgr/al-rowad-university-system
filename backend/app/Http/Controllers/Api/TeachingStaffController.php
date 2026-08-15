<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeachingStaffResource;
use App\Models\College;
use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TeachingStaffController extends Controller
{
    public function __construct(private DataScopeService $dataScope)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertCanViewTeachingStaff($request);
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'min:1', 'max:150'],
            'academic_rank' => ['sometimes', 'string', 'min:1', 'max:100'],
            'assignment_type' => ['sometimes', Rule::in(['theoretical', 'practical', 'both', 'unassigned'])],
        ]);

        $user = $request->user();
        $collegeIds = $this->accessibleCollegeIdList($user);

        $query = FacultyMember::query()
            ->with([
                'employee.employeeStatus',
                'employee.employeeUnitAssignments' => fn ($assignments) => $assignments->where('is_active', true),
            ])
            ->where('is_active', true)
            ->whereHas('employee', fn ($employee) => $employee
                ->whereHas('employeeStatus', fn ($status) => $status
                    ->where('status_code', 'active')
                    ->where('is_active', true)));

        $this->dataScope->scopeFacultyMembers($query, $user);
        $query->select('faculty_members.*');
        $this->applyAssignmentSummaries($query, $collegeIds);
        $this->applyTeachingStaffFilters($query, $validated, $collegeIds);

        $staff = $query
            ->orderBy('faculty_member_id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        $this->hydrateColleges(collect($staff->items()), $user);

        $payload = TeachingStaffResource::collection($staff)
            ->response($request)
            ->getData(true);

        return $this->successResponse($payload);
    }

    public function show(Request $request, FacultyMember $facultyMember): JsonResponse
    {
        $this->assertCanViewTeachingStaff($request);

        $user = $request->user();
        $collegeIds = $this->accessibleCollegeIdList($user);

        $query = FacultyMember::query()
            ->whereKey($facultyMember->faculty_member_id);

        $this->dataScope->scopeFacultyMembers($query, $user);
        $query->select('faculty_members.*');
        $this->applyAssignmentSummaries($query, $collegeIds);

        $facultyMember = $query->firstOrFail();

        $facultyMember->load([
            'employee.employeeStatus',
            'employee.employeeUnitAssignments' => fn ($assignments) => $assignments->where('is_active', true),
        ]);
        $this->hydrateColleges(collect([$facultyMember]), $user);

        return $this->successResponse(
            (new TeachingStaffResource($facultyMember))->resolve($request)
        );
    }

    private function applyTeachingStaffFilters(Builder $query, array $validated, array $collegeIds): void
    {
        if (isset($validated['academic_rank'])) {
            $query->where('academic_rank', $validated['academic_rank']);
        }

        if (isset($validated['search'])) {
            $pattern = $this->likeContains($validated['search']);
            $query->where(function (Builder $search) use ($pattern): void {
                $search
                    ->whereHas('employee', function (Builder $employee) use ($pattern): void {
                        $employee->where(function (Builder $fields) use ($pattern): void {
                            $fields->where('first_name', 'like', $pattern)
                                ->orWhere('last_name', 'like', $pattern)
                                ->orWhere('employee_number', 'like', $pattern)
                                ->orWhere('email', 'like', $pattern)
                                ->orWhereRaw(
                                    "CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?",
                                    [$pattern]
                                );
                        });
                    })
                    ->orWhere('specialization', 'like', $pattern);
            });
        }

        if (! isset($validated['assignment_type'])) {
            return;
        }

        $constrain = fn (Builder $assignments) => $this->constrainActiveScopedAssignments($assignments, $collegeIds);

        match ($validated['assignment_type']) {
            'theoretical' => $query->whereHas(
                'offeringInstructors',
                function (Builder $assignments) use ($constrain): void {
                    $constrain($assignments);
                    $assignments->where('instructor_role', 'theoretical');
                }
            ),
            'practical' => $query->whereHas(
                'offeringInstructors',
                function (Builder $assignments) use ($constrain): void {
                    $constrain($assignments);
                    $assignments->where('instructor_role', 'practical');
                }
            ),
            'both' => $query
                ->whereHas(
                    'offeringInstructors',
                    function (Builder $assignments) use ($constrain): void {
                        $constrain($assignments);
                        $assignments->where('instructor_role', 'theoretical');
                    }
                )
                ->whereHas(
                    'offeringInstructors',
                    function (Builder $assignments) use ($constrain): void {
                        $constrain($assignments);
                        $assignments->where('instructor_role', 'practical');
                    }
                ),
            'unassigned' => $query->whereDoesntHave('offeringInstructors', $constrain),
        };
    }

    private function applyAssignmentSummaries(Builder $query, array $collegeIds): void
    {
        $constrain = fn (Builder $assignments) => $this->constrainActiveScopedAssignments($assignments, $collegeIds);

        $query->withCount([
            'offeringInstructors as active_assignment_count' => $constrain,
            'offeringInstructors as theoretical_assignment_count' => function (Builder $assignments) use ($constrain): void {
                $constrain($assignments);
                $assignments->where('instructor_role', 'theoretical');
            },
            'offeringInstructors as practical_assignment_count' => function (Builder $assignments) use ($constrain): void {
                $constrain($assignments);
                $assignments->where('instructor_role', 'practical');
            },
        ]);

        $query->addSelect([
            'active_course_count' => CourseOfferingInstructor::query()
                ->selectRaw('COUNT(DISTINCT course_offerings.course_id)')
                ->join(
                    'course_offerings',
                    'course_offerings.course_offering_id',
                    '=',
                    'course_offering_instructors.course_offering_id'
                )
                ->whereColumn(
                    'course_offering_instructors.faculty_member_id',
                    'faculty_members.faculty_member_id'
                )
                ->where('course_offering_instructors.is_active', true)
                ->whereIn(
                    'course_offering_instructors.course_offering_id',
                    $this->offeringsInAccessibleCollegesQuery($collegeIds)
                ),
        ]);
    }

    private function constrainActiveScopedAssignments(Builder $assignments, array $collegeIds): void
    {
        $assignments
            ->where('is_active', true)
            ->whereIn('course_offering_id', $this->offeringsInAccessibleCollegesQuery($collegeIds));
    }

    private function offeringsInAccessibleCollegesQuery(array $collegeIds)
    {
        $query = DB::table('course_offerings as accessible_offerings')
            ->leftJoin(
                'departments as offering_departments',
                'offering_departments.department_id',
                '=',
                'accessible_offerings.department_id'
            )
            ->leftJoin(
                'academic_programs as offering_programs',
                'offering_programs.academic_program_id',
                '=',
                'accessible_offerings.academic_program_id'
            )
            ->leftJoin(
                'departments as program_departments',
                'program_departments.department_id',
                '=',
                'offering_programs.department_id'
            )
            ->select('accessible_offerings.course_offering_id');

        if ($collegeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            DB::raw('CASE
                WHEN offering_departments.college_id IS NOT NULL
                 AND program_departments.college_id IS NOT NULL
                 AND offering_departments.college_id <> program_departments.college_id THEN NULL
                ELSE COALESCE(offering_departments.college_id, program_departments.college_id)
            END'),
            $collegeIds
        );
    }

    private function accessibleCollegeIdList(User $user): array
    {
        return array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $this->dataScope->accessibleCollegeIds($user)
        )));
    }

    private function likeContains(string $term): string
    {
        return '%'.addcslashes($term, "%_\\").'%';
    }

    private function assertCanViewTeachingStaff(Request $request): void
    {
        $user = $request->user();
        if ($user === null
            || (! $user->hasPermission('teaching_staff.view')
                && ! $user->hasPermission('teaching_staff.manage'))) {
            throw new AccessDeniedHttpException('You are not authorized to view teaching staff.');
        }
    }

    private function hydrateColleges(Collection $facultyMembers, User $user): void
    {
        $accessibleUnitIds = collect($this->dataScope->accessibleCollegeOrganizationalUnitIds($user))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $unitIds = $facultyMembers
            ->flatMap(function (FacultyMember $facultyMember) use ($accessibleUnitIds): array {
                $employee = $facultyMember->employee;
                if ($employee === null) {
                    return [];
                }

                $ids = $employee->employeeUnitAssignments
                    ->where('is_active', true)
                    ->pluck('organizational_unit_id')
                    ->all();

                if ($employee->organizational_unit_id !== null) {
                    $ids[] = $employee->organizational_unit_id;
                }

                return collect($ids)
                    ->map(fn ($id) => (int) $id)
                    ->intersect($accessibleUnitIds)
                    ->values()
                    ->all();
            })
            ->unique()
            ->values();

        $collegesByUnit = $unitIds->isEmpty()
            ? collect()
            : College::query()
                ->whereIn('organizational_unit_id', $unitIds)
                ->whereIn('organizational_unit_id', $accessibleUnitIds)
                ->get(['college_id', 'college_code', 'college_name', 'organizational_unit_id'])
                ->keyBy(fn (College $college) => (int) $college->organizational_unit_id);

        foreach ($facultyMembers as $facultyMember) {
            $employee = $facultyMember->employee;
            $memberUnitIds = collect($employee?->employeeUnitAssignments)
                ->where('is_active', true)
                ->pluck('organizational_unit_id')
                ->push($employee?->organizational_unit_id)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->intersect($accessibleUnitIds)
                ->unique();

            $facultyMember->setRelation(
                'colleges',
                $collegesByUnit->only($memberUnitIds->all())->values()
            );
        }
    }

    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
