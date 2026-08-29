<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MinistryPlacement\ReconcileMinistryPlacementRequest;
use App\Services\MinistryPlacementReconciliationService;
use Illuminate\Http\JsonResponse;

class MinistryPlacementReconciliationController extends ApiController
{
    public function index(ReconcileMinistryPlacementRequest $request, MinistryPlacementReconciliationService $service): JsonResponse
    {
        return $this->successResponse($service->globalSummary($request->validated()));
    }

    public function batch(ReconcileMinistryPlacementRequest $request, int $batch, MinistryPlacementReconciliationService $service): JsonResponse
    {
        return $this->successResponse($service->batchSummary($batch, $request->validated()));
    }
}
