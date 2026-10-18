<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeachingAssignmentRequestResource;
use App\Models\CourseOffering;
use App\Models\TeachingAssignmentRequest;
use App\Services\TeachingAssignmentWorkflowService;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeanTeachingAssignmentController extends Controller
{
    public function __construct(private TeachingAssignmentWorkflowService $workflow)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([
                TeachingAssignmentWorkflow::STATUS_SUBMITTED,
                TeachingAssignmentWorkflow::STATUS_RETURNED,
                TeachingAssignmentWorkflow::STATUS_APPROVED,
            ])],
            'action_type' => ['sometimes', Rule::in([
                TeachingAssignmentWorkflow::ACTION_ASSIGN,
                TeachingAssignmentWorkflow::ACTION_REMOVE,
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
        if (isset($validated['action_type']) && TeachingAssignmentWorkflow::schemaReady()) {
            $query->where('action_type', $validated['action_type']);
        }

        $rows = $query->paginate((int) ($validated['per_page'] ?? 20));
        $payload = TeachingAssignmentRequestResource::collection($rows)
            ->response($request)
            ->getData(true);

        return $this->ok($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_offering_id' => ['required', 'integer', 'min:1', 'exists:course_offerings,course_offering_id'],
            'faculty_member_id' => ['required', 'integer', 'min:1', 'exists:faculty_members,faculty_member_id'],
            'instructor_role' => ['required', Rule::in(['theoretical', 'practical'])],
        ]);

        $offering = CourseOffering::query()->findOrFail((int) $validated['course_offering_id']);
        $created = $this->workflow->proposeSlot(
            $request->user(),
            $offering,
            $validated['instructor_role'],
            (int) $validated['faculty_member_id']
        );

        return $this->ok(
            (new TeachingAssignmentRequestResource($created))->resolve($request),
            'تم إرسال طلب التكليف للمراجعة.',
            201
        );
    }

    public function resubmit(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->workflow->resubmit(
            $request->user(),
            $teachingAssignmentRequest,
            $validated['reason'] ?? null
        );

        return $this->ok(
            (new TeachingAssignmentRequestResource($updated))->resolve($request),
            'تم إعادة إرسال طلب التكليف.'
        );
    }

    public function requestRemoval(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_offering_id' => ['required', 'integer', 'min:1', 'exists:course_offerings,course_offering_id'],
            'instructor_role' => ['required', Rule::in(['theoretical', 'practical'])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $offering = CourseOffering::query()->findOrFail((int) $validated['course_offering_id']);
        $created = $this->workflow->requestRemoval(
            $request->user(),
            $offering,
            $validated['instructor_role'],
            $validated['reason']
        );

        return $this->ok(
            (new TeachingAssignmentRequestResource($created))->resolve($request),
            'تم إرسال طلب إزالة المدرس للمراجعة.',
            201
        );
    }

    public function withdrawRemoval(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $updated = $this->workflow->withdrawRemoval($request->user(), $teachingAssignmentRequest);

        return $this->ok(
            (new TeachingAssignmentRequestResource($updated))->resolve($request),
            'تم سحب طلب إزالة المدرس.'
        );
    }

    public function replace(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'faculty_member_id' => ['required', 'integer', 'min:1', 'exists:faculty_members,faculty_member_id'],
        ]);

        $updated = $this->workflow->replace(
            $request->user(),
            $teachingAssignmentRequest,
            (int) $validated['faculty_member_id']
        );

        return $this->ok(
            (new TeachingAssignmentRequestResource($updated))->resolve($request),
            'تم استبدال المدرس وبدء دورة موافقة جديدة.'
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
