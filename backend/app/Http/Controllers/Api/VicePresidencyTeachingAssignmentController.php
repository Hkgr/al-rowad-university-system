<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeachingAssignmentRequestResource;
use App\Models\TeachingAssignmentRequest;
use App\Services\TeachingAssignmentWorkflowService;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class VicePresidencyTeachingAssignmentController extends Controller
{
    public function __construct(private TeachingAssignmentWorkflowService $workflow)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'authority' => ['required', Rule::in([
                TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC,
                TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE,
            ])],
            'queue' => ['sometimes', Rule::in(['pending', 'returned', 'approved', 'all'])],
            'action_type' => ['sometimes', Rule::in([
                TeachingAssignmentWorkflow::ACTION_ASSIGN,
                TeachingAssignmentWorkflow::ACTION_REMOVE,
            ])],
            'college_id' => ['sometimes', 'integer', 'min:1'],
            'academic_program_id' => ['sometimes', 'integer', 'min:1'],
            'academic_year_id' => ['sometimes', 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'min:1', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $authority = $validated['authority'];
        $query = $this->workflow->reviewQueueQuery($request->user(), $authority)
            ->with($this->workflow->requestListRelations())
            ->orderByDesc('submitted_at');

        $queue = $validated['queue'] ?? 'pending';
        if (isset($validated['action_type']) && TeachingAssignmentWorkflow::schemaReady()) {
            $query->where('action_type', $validated['action_type']);
        }
        if ($queue === 'pending') {
            $query->whereHas('reviews', fn ($reviews) => $reviews
                ->where('review_authority', $authority)
                ->where('status', TeachingAssignmentWorkflow::REVIEW_PENDING));
        } elseif ($queue === 'returned') {
            $query->where('status', TeachingAssignmentWorkflow::STATUS_RETURNED);
        } elseif ($queue === 'approved') {
            $query->where('status', TeachingAssignmentWorkflow::STATUS_APPROVED);
        }

        if (isset($validated['college_id'])) {
            $collegeId = (int) $validated['college_id'];
            $query->whereHas('courseOffering', function ($offering) use ($collegeId): void {
                $offering->where(function ($inner) use ($collegeId): void {
                    $inner
                        ->whereHas(
                            'academicProgram.department',
                            fn ($department) => $department->where('college_id', $collegeId)
                        )
                        ->orWhereHas(
                            'department',
                            fn ($department) => $department->where('college_id', $collegeId)
                        );
                });
            });
        }
        if (isset($validated['academic_program_id'])) {
            $query->whereHas('courseOffering', fn ($offering) => $offering
                ->where('academic_program_id', (int) $validated['academic_program_id']));
        }
        if (isset($validated['academic_year_id'])) {
            $query->whereHas('courseOffering', fn ($offering) => $offering
                ->where('academic_year_id', (int) $validated['academic_year_id']));
        }
        if (isset($validated['semester_id'])) {
            $query->whereHas('courseOffering', fn ($offering) => $offering
                ->where('semester_id', (int) $validated['semester_id']));
        }
        if (isset($validated['search'])) {
            $like = '%'.addcslashes(trim($validated['search']), '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->whereHas('courseOffering.course', fn ($course) => $course->where(fn ($fields) => $fields
                    ->where('course_name', 'like', $like)->orWhere('course_code', 'like', $like)))
                    ->orWhereHas('facultyMember.employee', fn ($employee) => $employee->where(fn ($fields) => $fields
                        ->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('employee_number', 'like', $like)
                        ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?", [$like])));
            });
        }

        $rows = $query->paginate((int) ($validated['per_page'] ?? 20));
        $payload = TeachingAssignmentRequestResource::collection($rows)
            ->response($request)
            ->getData(true);

        return $this->ok($payload);
    }

    /** Aggregated administrative queue counts. Never load every request into PHP. */
    public function administrativeSummary(Request $request): JsonResponse
    {
        if (! $request->user()->isAdministrativeVicePresident()
            || ! app(\App\Services\DataScopeService::class)->hasActualUniversityScope($request->user())) {
            abort(403);
        }
        $filters = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $this->workflow->reviewQueueQuery($request->user(), TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE)
            ->join('course_offerings as summary_offering', 'summary_offering.course_offering_id', '=', 'teaching_assignment_requests.course_offering_id')
            ->leftJoin('academic_programs as summary_program', 'summary_program.academic_program_id', '=', 'summary_offering.academic_program_id')
            ->leftJoin('departments as summary_department', 'summary_department.department_id', '=', DB::raw('COALESCE(summary_offering.department_id, summary_program.department_id)'))
            ->join('colleges as summary_college', 'summary_college.college_id', '=', 'summary_department.college_id')
            ->leftJoin('teaching_assignment_reviews as summary_review', function ($join): void {
                $join->on('summary_review.teaching_assignment_request_id', '=', 'teaching_assignment_requests.teaching_assignment_request_id')
                    ->where('summary_review.review_authority', '=', TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE);
            });
        foreach (['academic_year_id', 'semester_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('summary_offering.'.$field, (int) $filters[$field]);
            }
        }
        $rows = $query->select('summary_college.college_id', 'summary_college.college_name')
            ->selectRaw("SUM(CASE WHEN summary_review.status = 'pending' THEN 1 ELSE 0 END) AS pending_count")
            ->selectRaw("SUM(CASE WHEN teaching_assignment_requests.status = 'returned' THEN 1 ELSE 0 END) AS returned_count")
            ->selectRaw("SUM(CASE WHEN teaching_assignment_requests.status = 'approved' THEN 1 ELSE 0 END) AS approved_count")
            ->groupBy('summary_college.college_id', 'summary_college.college_name')
            ->orderBy('summary_college.college_name')->get();
        return $this->ok([
            'colleges' => $rows,
            'totals' => [
                'pending' => $rows->sum('pending_count'),
                'returned' => $rows->sum('returned_count'),
                'approved' => $rows->sum('approved_count'),
            ],
        ]);
    }

    public function show(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $this->workflow->assertCanViewRequest($request->user(), $teachingAssignmentRequest);
        $row = TeachingAssignmentRequest::query()
            ->with($this->workflow->requestDisplayRelations())
            ->findOrFail($teachingAssignmentRequest->teaching_assignment_request_id);

        return $this->ok((new TeachingAssignmentRequestResource($row))->resolve($request));
    }

    public function approveScientific(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $updated = $this->workflow->approveScientific($request->user(), $teachingAssignmentRequest);

        return $this->ok(
            (new TeachingAssignmentRequestResource($updated))->resolve($request),
            'تمت الموافقة العلمية على التكليف.'
        );
    }

    public function returnScientific(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $updated = $this->workflow->returnScientific(
            $request->user(),
            $teachingAssignmentRequest,
            $validated['reason']
        );

        return $this->ok(
            (new TeachingAssignmentRequestResource($updated))->resolve($request),
            'أُعيد طلب التكليف إلى العميد.'
        );
    }

    public function approveAdministrative(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $updated = $this->workflow->approveAdministrative($request->user(), $teachingAssignmentRequest);

        return $this->ok(
            (new TeachingAssignmentRequestResource($updated))->resolve($request),
            'تمت الموافقة الإدارية على التكليف.'
        );
    }

    public function returnAdministrative(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $updated = $this->workflow->returnAdministrative(
            $request->user(),
            $teachingAssignmentRequest,
            $validated['reason']
        );

        return $this->ok(
            (new TeachingAssignmentRequestResource($updated))->resolve($request),
            'أُعيد طلب التكليف إلى العميد.'
        );
    }

    private function ok(mixed $data, string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
