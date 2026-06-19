<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RecordAttendanceRequest;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public function sessionStudents(int $id, AttendanceService $service): JsonResponse
    {
        return $this->successResponse($service->getSessionStudents($id));
    }

    public function record(int $id, RecordAttendanceRequest $request, AttendanceService $service): JsonResponse
    {
        $data = $service->recordSessionAttendance($id, $request->validated()['records']);

        return $this->successResponse($data, 'Attendance recorded successfully');
    }
}
