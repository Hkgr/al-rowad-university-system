<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentRegistrationModificationRequest;
use App\Services\RegistrationModificationService;
use App\Support\AcademicQueuePagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicAdvisingRegistrationModificationController extends Controller
{
    public function __construct(private RegistrationModificationService $modifications)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:submitted,returned,approved,expired,superseded'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.AcademicQueuePagination::MAX_PER_PAGE],
        ]);

        return $this->successResponse($this->modifications->advisorIndex(
            $request->user(),
            $validated['status'] ?? null,
            isset($validated['per_page']) ? (int) $validated['per_page'] : null,
        ));
    }

    public function show(Request $request, StudentRegistrationModificationRequest $modification): JsonResponse
    {
        return $this->successResponse($this->modifications->advisorShow($request->user(), $modification));
    }

    public function returnForModification(Request $request, StudentRegistrationModificationRequest $modification): JsonResponse
    {
        $validated = $request->validate(['advisor_notes' => ['required', 'string', 'min:8', 'max:2000']]);

        return $this->successResponse(
            $this->modifications->returnForModification($request->user(), $modification, $validated['advisor_notes']),
            'أُعيد طلب تعديل التسجيل إلى الطالب.',
        );
    }

    public function approve(Request $request, StudentRegistrationModificationRequest $modification): JsonResponse
    {
        return $this->successResponse(
            $this->modifications->approve($request->user(), $modification),
            'تم اعتماد تعديل التسجيل وتثبيته.',
        );
    }

    private function successResponse(mixed $data, string $message = 'Operation completed successfully'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
