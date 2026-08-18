<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseOfferingExceptionRequestResource;
use App\Models\CourseOffering;
use App\Models\CourseOfferingExceptionRequest;
use App\Services\CourseOfferingExceptionWorkflowService;
use App\Support\ExceptionalOpeningWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeanCourseOfferingExceptionController extends Controller
{
    public function __construct(private CourseOfferingExceptionWorkflowService $workflow)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([
                ExceptionalOpeningWorkflow::STATUS_SUBMITTED,
                ExceptionalOpeningWorkflow::STATUS_RETURNED,
                ExceptionalOpeningWorkflow::STATUS_APPROVED,
            ])],
            'course_offering_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->workflow->deanRequestsQuery($request->user())
            ->with($this->workflow->requestListRelations())
            ->orderByDesc('submitted_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['course_offering_id'])) {
            $query->where('course_offering_id', (int) $validated['course_offering_id']);
        }

        $rows = $query->paginate((int) ($validated['per_page'] ?? 20));
        $payload = CourseOfferingExceptionRequestResource::collection($rows)
            ->response($request)
            ->getData(true);

        return $this->ok($payload);
    }

    public function show(Request $request, CourseOfferingExceptionRequest $courseOfferingExceptionRequest): JsonResponse
    {
        $this->workflow->assertCanViewRequest($request->user(), $courseOfferingExceptionRequest);
        $row = CourseOfferingExceptionRequest::query()
            ->with($this->workflow->requestDisplayRelations())
            ->findOrFail($courseOfferingExceptionRequest->course_offering_exception_request_id);

        return $this->ok((new CourseOfferingExceptionRequestResource($row))->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_offering_id' => ['required', 'integer', 'min:1', 'exists:course_offerings,course_offering_id'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $offering = CourseOffering::query()->findOrFail((int) $validated['course_offering_id']);
        $created = $this->workflow->submit($request->user(), $offering, $validated['reason']);

        return $this->ok(
            (new CourseOfferingExceptionRequestResource($created))->resolve($request),
            'تم إرسال طلب الفتح الاستثنائي للمراجعة.',
            201
        );
    }

    public function resubmit(Request $request, CourseOfferingExceptionRequest $courseOfferingExceptionRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->workflow->resubmit(
            $request->user(),
            $courseOfferingExceptionRequest,
            $validated['reason'] ?? null
        );

        return $this->ok(
            (new CourseOfferingExceptionRequestResource($updated))->resolve($request),
            'تم إعادة إرسال طلب الفتح الاستثنائي.'
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
