<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RegistrationRequestService;
use App\Support\AcademicQueuePagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovedRegistrationRequestController extends Controller
{
    public function __construct(private RegistrationRequestService $requests)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.AcademicQueuePagination::MAX_PER_PAGE],
        ]);

        return $this->successResponse(
            $this->requests->approvedIndex(
                $request->user(),
                isset($validated['per_page']) ? (int) $validated['per_page'] : null
            )
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
