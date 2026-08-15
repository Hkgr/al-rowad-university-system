<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeachingStaffResource;
use App\Models\College;
use App\Models\FacultyMember;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        ]);

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

        $this->dataScope->scopeFacultyMembers($query, $request->user());

        $staff = $query
            ->orderBy('faculty_member_id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        $this->hydrateColleges(collect($staff->items()), $request->user());

        $payload = TeachingStaffResource::collection($staff)
            ->response($request)
            ->getData(true);

        return $this->successResponse($payload);
    }

    public function show(Request $request, FacultyMember $facultyMember): JsonResponse
    {
        $this->assertCanViewTeachingStaff($request);

        $facultyMember = $this->dataScope
            ->scopeFacultyMembers(FacultyMember::query(), $request->user())
            ->whereKey($facultyMember->faculty_member_id)
            ->firstOrFail();

        $facultyMember->load([
            'employee.employeeStatus',
            'employee.employeeUnitAssignments' => fn ($assignments) => $assignments->where('is_active', true),
        ]);
        $this->hydrateColleges(collect([$facultyMember]), $request->user());

        return $this->successResponse(
            (new TeachingStaffResource($facultyMember))->resolve($request)
        );
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
