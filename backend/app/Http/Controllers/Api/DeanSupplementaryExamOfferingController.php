<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dean\CatalogSupplementaryExamOfferingRequest;
use App\Http\Requests\Dean\StoreSupplementaryExamOfferingRequest;
use App\Http\Resources\SupplementaryExamOfferingResource;
use App\Models\SupplementaryExamOffering;
use App\Services\SupplementaryExamOfferingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeanSupplementaryExamOfferingController extends Controller
{
    public function __construct(private SupplementaryExamOfferingService $offerings)
    {
    }

    public function context(Request $request): JsonResponse
    {
        return $this->successResponse($this->offerings->context($request->user()));
    }

    public function catalog(CatalogSupplementaryExamOfferingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse(
            $this->offerings->catalog(
                $request->user(),
                (int) $validated['supplementary_exam_period_id'],
                (int) $validated['academic_program_id']
            )
        );
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplementary_exam_period_id' => ['sometimes', 'integer', 'min:1', 'exists:supplementary_exam_periods,supplementary_exam_period_id'],
            'academic_program_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_programs,academic_program_id'],
            'course_id' => ['sometimes', 'integer', 'min:1', 'exists:courses,course_id'],
            'status' => ['sometimes', 'string', 'in:open,closed'],
        ]);

        $rows = $this->offerings->listOfferings($request->user(), $validated);

        return $this->successResponse(
            SupplementaryExamOfferingResource::collection($rows)->resolve($request)
        );
    }

    public function show(Request $request, SupplementaryExamOffering $offering): JsonResponse
    {
        $offering = $this->offerings->findOffering($request->user(), $offering);

        return $this->successResponse(
            (new SupplementaryExamOfferingResource($offering))->resolve($request)
        );
    }

    public function store(StoreSupplementaryExamOfferingRequest $request): JsonResponse
    {
        $offering = $this->offerings->open($request->user(), $request->validated());

        return $this->successResponse(
            (new SupplementaryExamOfferingResource($offering))->resolve($request),
            'تم طرح المادة في الدورة التكميلية.',
            201
        );
    }

    public function close(Request $request, SupplementaryExamOffering $offering): JsonResponse
    {
        $offering = $this->offerings->close($request->user(), $offering);

        return $this->successResponse(
            (new SupplementaryExamOfferingResource($offering))->resolve($request),
            'تم إغلاق الطرح التكميلي.'
        );
    }

    public function reopen(Request $request, SupplementaryExamOffering $offering): JsonResponse
    {
        $offering = $this->offerings->reopen($request->user(), $offering);

        return $this->successResponse(
            (new SupplementaryExamOfferingResource($offering))->resolve($request),
            'تمت إعادة فتح الطرح التكميلي.'
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
