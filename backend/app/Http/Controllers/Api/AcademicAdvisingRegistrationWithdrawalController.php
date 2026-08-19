<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentRegistrationWithdrawalRequest;
use App\Services\RegistrationWithdrawalService;
use App\Support\AcademicQueuePagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicAdvisingRegistrationWithdrawalController extends Controller
{
    public function __construct(private RegistrationWithdrawalService $withdrawals)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:submitted,returned,approved,superseded'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.AcademicQueuePagination::MAX_PER_PAGE],
        ]);

        return $this->successResponse(
            $this->withdrawals->advisorIndex(
                $request->user(),
                $validated['status'] ?? null,
                isset($validated['per_page']) ? (int) $validated['per_page'] : null
            )
        );
    }

    public function show(Request $request, StudentRegistrationWithdrawalRequest $withdrawalRequest): JsonResponse
    {
        return $this->successResponse(
            $this->withdrawals->advisorShow($request->user(), $withdrawalRequest)
        );
    }

    public function returnForModification(Request $request, StudentRegistrationWithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        return $this->successResponse(
            $this->withdrawals->returnForModification(
                $request->user(),
                $withdrawalRequest,
                $validated['review_notes']
            ),
            'أُعيد طلب الانسحاب إلى الطالب للتعديل.'
        );
    }

    public function approve(Request $request, StudentRegistrationWithdrawalRequest $withdrawalRequest): JsonResponse
    {
        return $this->successResponse(
            $this->withdrawals->approve($request->user(), $withdrawalRequest),
            'تم اعتماد الانسحاب.'
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
