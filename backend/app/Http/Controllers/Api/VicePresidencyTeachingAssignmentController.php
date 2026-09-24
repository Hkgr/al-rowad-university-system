<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeachingAssignmentRequestResource;
use App\Models\TeachingAssignmentRequest;
use App\Services\AdministrativeDashboardService;
use App\Services\TeachingAssignmentWorkflowService;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VicePresidencyTeachingAssignmentController extends Controller
{
    public function __construct(
        private TeachingAssignmentWorkflowService $workflow,
        private AdministrativeDashboardService $filters,
    ) {
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
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'academic_year_id' => ['sometimes', 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'integer', 'min:1'],
            'instructor_role' => ['sometimes', Rule::in(['theoretical', 'practical'])],
            'status' => ['sometimes', Rule::in([
                TeachingAssignmentWorkflow::STATUS_SUBMITTED,
                TeachingAssignmentWorkflow::STATUS_RETURNED,
                TeachingAssignmentWorkflow::STATUS_APPROVED,
            ])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
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
            // Same college attribution as the resource and the administrative dashboard:
            // the offering's own department first, otherwise the program's department.
            $collegeId = (int) $validated['college_id'];
            $query->whereHas('courseOffering', function ($offering) use ($collegeId): void {
                $offering->where(function ($inner) use ($collegeId): void {
                    $inner
                        ->whereHas('department', fn ($department) => $department->where('college_id', $collegeId))
                        ->orWhere(fn ($fallback) => $fallback
                            ->whereNull('department_id')
                            ->whereHas('academicProgram.department', fn ($department) => $department->where('college_id', $collegeId)));
                });
            });
        }
        if (isset($validated['academic_program_id'])) {
            $query->whereHas('courseOffering', fn ($offering) => $offering
                ->where('academic_program_id', (int) $validated['academic_program_id']));
        }

        if (isset($validated['department_id'])) {
            $departmentId = (int) $validated['department_id'];
            $query->whereHas('courseOffering', fn ($offering) => $offering->where(fn ($inner) => $inner
                ->where('department_id', $departmentId)
                ->orWhereHas('academicProgram', fn ($program) => $program->where('department_id', $departmentId))));
        }
        foreach (['academic_year_id', 'semester_id'] as $column) {
            if (isset($validated[$column])) {
                $query->whereHas('courseOffering', fn ($offering) => $offering->where($column, (int) $validated[$column]));
            }
        }
        if (isset($validated['instructor_role'])) {
            $query->where('instructor_role', $validated['instructor_role']);
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($inner) use ($like): void {
                $inner
                    ->whereHas('courseOffering.course', fn ($course) => $course
                        ->where('course_code', 'like', $like)
                        ->orWhere('course_name', 'like', $like))
                    ->orWhereHas('facultyMember.employee', fn ($employee) => $employee
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like))
                    ->orWhereHas('courseOffering.offeringInstructors', fn ($slot) => $slot
                        ->where('is_active', true)
                        ->whereHas('facultyMember.employee', fn ($employee) => $employee
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)))
                    ->orWhereHas('requester', fn ($requester) => $requester->where('username', 'like', $like));
            });
        }

        $rows = $query->paginate((int) ($validated['per_page'] ?? 20));
        // Keep the models: the resource collection replaces the paginator items with resources.
        $models = $rows->items();
        $payload = TeachingAssignmentRequestResource::collection($rows)
            ->response($request)
            ->getData(true);
        $isReviewer = $this->workflow->isReviewerFor($request->user(), $authority);
        foreach ($models as $index => $row) {
            $payload['data'][$index]['viewer_context'] = $this->workflow->viewerContext($request->user(), $row, $authority, $isReviewer);
        }

        $payload['filter_options'] = $this->filters->filterOptions();

        return $this->ok($payload);
    }

    public function show(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $this->workflow->assertCanViewRequest($request->user(), $teachingAssignmentRequest);
        $validated = $request->validate([
            'authority' => ['sometimes', Rule::in([
                TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC,
                TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE,
            ])],
        ]);

        return $this->ok($this->detailPayload($request, (int) $teachingAssignmentRequest->teaching_assignment_request_id, $validated['authority'] ?? null));
    }

    public function approveScientific(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $expected = $this->expectedVersion($request);
        $updated = $this->workflow->approveScientific($request->user(), $teachingAssignmentRequest, $expected);

        return $this->ok(
            $this->detailPayload($request, (int) $updated->teaching_assignment_request_id, TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC),
            'تمت الموافقة العلمية على التكليف.'
        );
    }

    public function returnScientific(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $expected = $this->expectedVersion($request);
        $updated = $this->workflow->returnScientific(
            $request->user(),
            $teachingAssignmentRequest,
            $validated['reason'],
            $expected
        );

        return $this->ok(
            $this->detailPayload($request, (int) $updated->teaching_assignment_request_id, TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC),
            'أُعيد طلب التكليف إلى العميد.'
        );
    }

    public function approveAdministrative(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $expected = $this->expectedVersion($request);
        $updated = $this->workflow->approveAdministrative($request->user(), $teachingAssignmentRequest, $expected);

        return $this->ok(
            $this->detailPayload($request, (int) $updated->teaching_assignment_request_id, TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE),
            'تمت الموافقة الإدارية على التكليف.'
        );
    }

    public function returnAdministrative(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $expected = $this->expectedVersion($request);
        $updated = $this->workflow->returnAdministrative(
            $request->user(),
            $teachingAssignmentRequest,
            $validated['reason'],
            $expected
        );

        return $this->ok(
            $this->detailPayload($request, (int) $updated->teaching_assignment_request_id, TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE),
            'أُعيد طلب التكليف إلى العميد.'
        );
    }

    private function expectedVersion(Request $request): ?int
    {
        $validated = $request->validate([
            'expected_submission_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return isset($validated['expected_submission_version']) ? (int) $validated['expected_submission_version'] : null;
    }

    /** Fresh detail (events, previous cycles) plus what the viewer may do now. */
    private function detailPayload(Request $request, int $requestId, ?string $authority): array
    {
        $row = TeachingAssignmentRequest::query()
            ->with($this->workflow->requestDisplayRelations())
            ->findOrFail($requestId);
        $payload = (new TeachingAssignmentRequestResource($row))->resolve($request);
        if ($authority !== null) {
            $payload['viewer_context'] = $this->workflow->viewerContext($request->user(), $row, $authority);
        }

        return $payload;
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
