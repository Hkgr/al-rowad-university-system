<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RecordAttendanceRequest;
use App\Services\AttendanceService;
use App\Services\AcademicAuthorizationService;
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

    public function sessionStudents(int $id, AttendanceService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('attendance.view'), 403);
        $authorization->assertCanAccessAttendanceSession(request()->user(), $id);
        return $this->successResponse($service->getSessionStudents($id));
    }

    public function record(int $id, RecordAttendanceRequest $request, AttendanceService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        abort_unless($request->user()->hasPermission('attendance.manage'), 403);
        $authorization->assertCanAccessAttendanceSession($request->user(), $id);
        $data = $service->recordSessionAttendance($id, $request->validated()['records']);

        return $this->successResponse($data, 'Attendance recorded successfully');
    }
}
