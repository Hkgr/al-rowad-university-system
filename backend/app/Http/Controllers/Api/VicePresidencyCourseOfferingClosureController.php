<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseOfferingClosureRequestResource;
use App\Models\CourseOfferingClosureRequest;
use App\Services\CourseOfferingClosureWorkflowService;
use App\Support\CourseOfferingClosureWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VicePresidencyCourseOfferingClosureController extends Controller
{
    public function __construct(private CourseOfferingClosureWorkflowService $workflow)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'authority' => ['required', Rule::in([
                CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC,
                CourseOfferingClosureWorkflow::AUTHORITY_ADMINISTRATIVE,
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
            $query->whereHas('reviews', function ($reviews) use ($authority): void {
                $reviews
                    ->where('review_authority', $authority)
                    ->where('status', CourseOfferingClosureWorkflow::REVIEW_PENDING)
                    ->whereColumn(
                        'course_offering_closure_reviews.submission_version',
                        'course_offering_closure_requests.submission_version'
                    );
            });
        } elseif ($queue === 'returned') {
            $query->where('status', CourseOfferingClosureWorkflow::STATUS_RETURNED);
        } elseif ($queue === 'approved') {
            $query->where('status', CourseOfferingClosureWorkflow::STATUS_APPROVED);
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
        $payload = CourseOfferingClosureRequestResource::collection($rows)
            ->response($request)
            ->getData(true);

        return $this->ok($payload);
    }

    public function show(Request $request, CourseOfferingClosureRequest $courseOfferingClosureRequest): JsonResponse
    {
        $this->workflow->assertCanViewRequest($request->user(), $courseOfferingClosureRequest);
        $row = CourseOfferingClosureRequest::query()
            ->with($this->workflow->requestDisplayRelations())
            ->findOrFail($courseOfferingClosureRequest->course_offering_closure_request_id);

        return $this->ok((new CourseOfferingClosureRequestResource($row))->resolve($request));
    }

    public function approveScientific(Request $request, CourseOfferingClosureRequest $courseOfferingClosureRequest): JsonResponse
    {
        $updated = $this->workflow->approveScientific($request->user(), $courseOfferingClosureRequest);

        return $this->ok(
            (new CourseOfferingClosureRequestResource($updated))->resolve($request),
            'تمت الموافقة العلمية على إغلاق طرح المادة.'
        );
    }

    public function returnScientific(Request $request, CourseOfferingClosureRequest $courseOfferingClosureRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $updated = $this->workflow->returnScientific(
            $request->user(),
            $courseOfferingClosureRequest,
            $validated['reason']
        );

        return $this->ok(
            (new CourseOfferingClosureRequestResource($updated))->resolve($request),
            'أُعيد طلب إغلاق طرح المادة إلى العميد.'
        );
    }

    public function approveAdministrative(Request $request, CourseOfferingClosureRequest $courseOfferingClosureRequest): JsonResponse
    {
        $updated = $this->workflow->approveAdministrative($request->user(), $courseOfferingClosureRequest);

        return $this->ok(
            (new CourseOfferingClosureRequestResource($updated))->resolve($request),
            'تمت الموافقة الإدارية على إغلاق طرح المادة.'
        );
    }

    public function returnAdministrative(Request $request, CourseOfferingClosureRequest $courseOfferingClosureRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $updated = $this->workflow->returnAdministrative(
            $request->user(),
            $courseOfferingClosureRequest,
            $validated['reason']
        );

        return $this->ok(
            (new CourseOfferingClosureRequestResource($updated))->resolve($request),
            'أُعيد طلب إغلاق طرح المادة إلى العميد.'
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
