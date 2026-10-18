<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MinistryPlacement\ConvertMinistryPlacementApplicantRequest;
use App\Http\Requests\MinistryPlacement\ConvertMinistryPlacementBatchRequest;
use App\Services\MinistryPlacementApplicantConversionService;
use App\Support\MinistryPlacementAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinistryPlacementApplicantConversionController extends ActionApiController
{
    public function summary(Request $request, int $batch, MinistryPlacementAccess $access, MinistryPlacementApplicantConversionService $service): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);

        return $this->successResponse($service->summary($batch));
    }

    public function convert(ConvertMinistryPlacementApplicantRequest $request, int $record, MinistryPlacementApplicantConversionService $service): JsonResponse
    {
        return $this->successResponse(
            $service->convert($record, $request->user()),
            'تم إنشاء سجل المتقدم وطلب القبول المعلق بنجاح.',
        );
    }

    public function convertAll(ConvertMinistryPlacementBatchRequest $request, int $batch, MinistryPlacementApplicantConversionService $service): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse(
            $service->convertAll(
                $batch,
                (int) $validated['expected_eligible_count'],
                (string) $validated['expected_snapshot'],
                $request->user(),
            ),
            'تم إنشاء سجلات المتقدمين وطلبات القبول المعلقة بنجاح.',
        );
    }
}
