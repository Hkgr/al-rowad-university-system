<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MinistryPlacement\EnrollMinistryPlacementBatchRequest;
use App\Http\Requests\MinistryPlacement\EnrollMinistryPlacementStudentRequest;
use App\Services\MinistryPlacementStudentEnrollmentService;
use App\Support\MinistryPlacementAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinistryPlacementStudentEnrollmentController extends ActionApiController
{
    public function academicLevels(Request $request, MinistryPlacementAccess $access, MinistryPlacementStudentEnrollmentService $service): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);

        return $this->successResponse($service->academicLevels());
    }

    public function summary(Request $request, int $batch, MinistryPlacementAccess $access, MinistryPlacementStudentEnrollmentService $service): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);

        return $this->successResponse($service->summary($batch));
    }

    public function enroll(EnrollMinistryPlacementStudentRequest $request, int $record, MinistryPlacementStudentEnrollmentService $service): JsonResponse
    {
        return $this->successResponse(
            $service->enroll($record, $request->validated(), $request->user()),
            'تم اعتماد طلب القبول وإنشاء سجل الطالب بنجاح.',
        );
    }

    public function enrollAll(EnrollMinistryPlacementBatchRequest $request, int $batch, MinistryPlacementStudentEnrollmentService $service): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse(
            $service->enrollAll(
                $batch,
                (int) $validated['expected_eligible_count'],
                (string) $validated['expected_snapshot'],
                $validated['items'],
                $request->user(),
            ),
            'تم اعتماد طلبات القبول وإنشاء سجلات الطلاب بنجاح.',
        );
    }
}
