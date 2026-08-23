<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplementaryExamPeriod;
use App\Services\SupplementaryExamReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplementaryExamReconciliationController extends Controller
{
    public function show(
        Request $request,
        SupplementaryExamPeriod $period,
        SupplementaryExamReconciliationService $service,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $service->reconcile($request->user(), $period),
        ]);
    }
}
