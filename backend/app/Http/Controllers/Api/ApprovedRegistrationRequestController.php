<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RegistrationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovedRegistrationRequestController extends Controller
{
    public function __construct(private RegistrationRequestService $requests)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->requests->approvedIndex($request->user())
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
