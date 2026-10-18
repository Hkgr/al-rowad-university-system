<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplementaryExamPeriodResource;
use App\Models\SupplementaryExamPeriod;
use App\Services\SupplementaryExamPeriodGovernanceService;
use App\Services\SupplementaryExamOccurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class SupplementaryExamPeriodController extends Controller
{
    public function __construct(private SupplementaryExamPeriodGovernanceService $governance)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
            'status' => ['sometimes', 'string', 'max:32'],
            'per_page' => ['prohibited'],
        ]);

        $periods = $this->governance->listPeriods($request->user(), $validated);

        return $this->successResponse(
            SupplementaryExamPeriodResource::collection($periods)->resolve($request)
        );
    }

    public function show(
        Request $request,
        SupplementaryExamPeriod $period,
        SupplementaryExamOccurrenceService $occurrence,
    ): JsonResponse
    {
        $period = $this->governance->findPeriod($request->user(), $period);
        $payload = (new SupplementaryExamPeriodResource($period))->resolve($request);
        $payload['supplementary_exam_occurrence'] = $occurrence
            ->snapshotForPeriod($period)
            ->toPublicArray();

        return $this->successResponse($payload);
    }

    public function store(): JsonResponse
    {
        throw new MethodNotAllowedHttpException(['GET'], 'Generic supplementary exam period writes are disabled.');
    }

    public function update(): JsonResponse
    {
        throw new MethodNotAllowedHttpException(['GET'], 'Generic supplementary exam period writes are disabled.');
    }

    public function destroy(): JsonResponse
    {
        throw new MethodNotAllowedHttpException(['GET'], 'Generic supplementary exam period writes are disabled.');
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
