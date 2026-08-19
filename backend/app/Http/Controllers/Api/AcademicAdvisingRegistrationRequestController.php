<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentRegistrationRequest;
use App\Services\RegistrationRequestService;
use App\Support\AcademicQueuePagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicAdvisingRegistrationRequestController extends Controller
{
    public function __construct(private RegistrationRequestService $requests)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:submitted,returned,approved'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.AcademicQueuePagination::MAX_PER_PAGE],
        ]);

        return $this->successResponse(
            $this->requests->advisorIndex(
                $request->user(),
                $validated['status'] ?? null,
                isset($validated['per_page']) ? (int) $validated['per_page'] : null
            )
        );
    }

    public function show(Request $request, StudentRegistrationRequest $registrationRequest): JsonResponse
    {
        return $this->successResponse(
            $this->requests->advisorShow($request->user(), $registrationRequest)
        );
    }

    public function returnForModification(Request $request, StudentRegistrationRequest $registrationRequest): JsonResponse
    {
        $validated = $request->validate([
            'advisor_notes' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        return $this->successResponse(
            $this->requests->returnForModification(
                $request->user(),
                $registrationRequest,
                $validated['advisor_notes']
            ),
            'أُعيد الطلب إلى الطالب للتعديل.'
        );
    }

    public function approve(Request $request, StudentRegistrationRequest $registrationRequest): JsonResponse
    {
        return $this->successResponse(
            $this->requests->approve($request->user(), $registrationRequest),
            'تم اعتماد طلب التسجيل.'
        );
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
