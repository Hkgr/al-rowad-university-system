<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeachingStaff\SyncOfferingAssignmentSlotsRequest;
use App\Http\Resources\TeachingStaffAssignmentOfferingResource;
use App\Models\CourseOffering;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\TeachingAssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TeachingStaffAssignmentOfferingController extends Controller
{
    public function __construct(
        private DataScopeService $dataScope,
        private TeachingAssignmentService $teachingAssignments
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertCanViewTeachingStaff($request);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'min:1', 'max:150'],
            'academic_year_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,department_id'],
            'academic_program_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_programs,academic_program_id'],
            'status' => ['sometimes', 'string', 'min:1', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $query = CourseOffering::query()
            ->whereIn('course_offerings.course_offering_id', $this->scopedOfferingIdsQuery($user))
            ->with($this->offeringDisplayRelations())
            ->join(
                'courses',
                'courses.course_id',
                '=',
                'course_offerings.course_id'
            )
            ->leftJoin(
                'academic_years',
                'academic_years.academic_year_id',
                '=',
                'course_offerings.academic_year_id'
            )
            ->leftJoin(
                'semesters',
                'semesters.semester_id',
                '=',
                'course_offerings.semester_id'
            )
            ->leftJoin(
                'departments',
                'departments.department_id',
                '=',
                'course_offerings.department_id'
            )
            ->leftJoin(
                'academic_programs',
                'academic_programs.academic_program_id',
                '=',
                'course_offerings.academic_program_id'
            )
            ->select('course_offerings.*');

        if (isset($validated['search'])) {
            $pattern = $this->likeContains($validated['search']);
            $query->where(function (Builder $search) use ($pattern): void {
                $search
                    ->where('courses.course_code', 'like', $pattern)
                    ->orWhere('courses.course_name', 'like', $pattern)
                    ->orWhere('departments.department_name', 'like', $pattern)
                    ->orWhere('academic_programs.program_name', 'like', $pattern);
            });
        }

        if (isset($validated['academic_year_id'])) {
            $query->where('course_offerings.academic_year_id', (int) $validated['academic_year_id']);
        }

        if (isset($validated['semester_id'])) {
            $query->where('course_offerings.semester_id', (int) $validated['semester_id']);
        }

        if (isset($validated['department_id'])) {
            $query->where('course_offerings.department_id', (int) $validated['department_id']);
        }

        if (isset($validated['academic_program_id'])) {
            $query->where('course_offerings.academic_program_id', (int) $validated['academic_program_id']);
        }

        if (isset($validated['status'])) {
            $query->where('course_offerings.status', $validated['status']);
        }

        $offerings = $query
            ->orderByDesc('academic_years.start_date')
            ->orderByDesc('semesters.semester_order')
            ->orderBy('courses.course_code')
            ->orderBy('courses.course_name')
            ->paginate((int) ($validated['per_page'] ?? 15));

        $payload = TeachingStaffAssignmentOfferingResource::collection($offerings)
            ->response($request)
            ->getData(true);

        return $this->successResponse($payload);
    }

    public function show(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $this->assertCanViewTeachingStaff($request);

        $offering = CourseOffering::query()
            ->whereIn('course_offerings.course_offering_id', $this->scopedOfferingIdsQuery($request->user()))
            ->whereKey($courseOffering->course_offering_id)
            ->with($this->offeringDisplayRelations())
            ->firstOrFail();

        return $this->successResponse(
            (new TeachingStaffAssignmentOfferingResource($offering))->resolve($request)
        );
    }

    public function updateSlots(
        SyncOfferingAssignmentSlotsRequest $request,
        CourseOffering $courseOffering
    ): JsonResponse {
        $validated = $request->validated();
        $offering = $this->teachingAssignments->syncOfferingAssignmentSlots(
            $request->user(),
            $courseOffering,
            array_key_exists('theoretical_faculty_member_id', $validated)
                ? ($validated['theoretical_faculty_member_id'] === null ? null : (int) $validated['theoretical_faculty_member_id'])
                : null,
            array_key_exists('practical_faculty_member_id', $validated)
                ? ($validated['practical_faculty_member_id'] === null ? null : (int) $validated['practical_faculty_member_id'])
                : null
        );

        return $this->successResponse(
            (new TeachingStaffAssignmentOfferingResource($offering))->resolve($request),
            'تم تحديث التكليف التدريسي بنجاح.'
        );
    }

    private function scopedOfferingIdsQuery(User $user): Builder
    {
        $query = CourseOffering::query()->select('course_offerings.course_offering_id');
        $this->dataScope->scopeOfferings($query, $user);

        return $query->whereIn(
            'course_offerings.course_offering_id',
            $this->teachingAssignments->offeringsInAccessibleCollegesQuery(
                $this->teachingAssignments->accessibleCollegeIdList($user)
            )
        );
    }

    private function offeringDisplayRelations(): array
    {
        return [
            'course',
            'academicYear',
            'semester',
            'department',
            'academicProgram',
            'offeringInstructors.facultyMember.employee',
        ];
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

    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
