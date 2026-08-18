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
            'college_id' => ['sometimes', 'integer', 'min:1'],
            'academic_program_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $authority = $validated['authority'];
        $query = $this->workflow->reviewQueueQuery($request->user(), $authority)
            ->with($this->workflow->requestListRelations())
            ->orderByDesc('submitted_at');

        $queue = $validated['queue'] ?? 'pending';
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

        $rows = $query->paginate((int) ($validated['per_page'] ?? 20));
        $payload = TeachingAssignmentRequestResource::collection($rows)
            ->response($request)
            ->getData(true);

        return $this->ok($payload);
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
