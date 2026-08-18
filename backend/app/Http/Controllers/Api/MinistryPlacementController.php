<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MinistryPlacementException;
use App\Http\Requests\MinistryPlacement\ConvertToApplicantRequest;
use App\Http\Requests\MinistryPlacement\ImportMinistryPlacementRequest;
use App\Http\Requests\MinistryPlacement\MatchProgramRequest;
use App\Http\Resources\MinistryPlacementBatchResource;
use App\Http\Resources\MinistryPlacementRecordResource;
use App\Imports\MinistryPlacementImport;
use App\Models\MinistryPlacementBatch;
use App\Models\MinistryPlacementRecord;
use App\Services\MinistryPlacementService;
use Illuminate\Http\JsonResponse;

class MinistryPlacementController extends ApiController
{
    protected function modelClass(): string
    {
        return MinistryPlacementBatch::class;
    }

    protected function resourceClass(): string
    {
        return MinistryPlacementBatchResource::class;
    }

    protected function storeRequestClass(): string
    {
        return ImportMinistryPlacementRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return '';
    }

    public function store(ImportMinistryPlacementRequest $request, MinistryPlacementService $service): JsonResponse
    {
        $parsedRows = (new MinistryPlacementImport)->parse($request->file('file'));

        $batch = $service->importBatch(
            $request->file('file'),
            $request->validated(),
            $request->user()?->user_id
        );

        $payload = (new MinistryPlacementBatchResource($batch))->resolve(request());
        $payload['first_parsed_row_debug'] = $parsedRows[0] ?? null;

        return $this->successResponse(
            $payload,
            'Operation completed successfully',
            201
        );
    }

    public function index(): JsonResponse
    {
        $batches = MinistryPlacementBatch::query()
            ->with(['academicYear', 'importedBy'])
            ->withCount('records')
            ->orderByDesc('batch_id')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            MinistryPlacementBatchResource::collection($batches)->response(request())->getData(true)
        );
    }

    public function show(int $id): JsonResponse
    {
        $batch = MinistryPlacementBatch::query()
            ->with(['academicYear', 'importedBy', 'records'])
            ->findOrFail($id);

        return $this->successResponse(
            (new MinistryPlacementBatchResource($batch))->resolve(request())
        );
    }

    public function records(int $id): JsonResponse
    {
        MinistryPlacementBatch::query()->findOrFail($id);

        $records = MinistryPlacementRecord::query()
            ->where('batch_id', $id)
            ->with(['matchedAcademicProgram', 'applicant'])
            ->orderBy('row_number')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            MinistryPlacementRecordResource::collection($records)->response(request())->getData(true)
        );
    }

    public function matchProgram(int $id, MatchProgramRequest $request, MinistryPlacementService $service): JsonResponse
    {
        $record = $service->matchProgram(
            $id,
            (int) $request->validated('academic_program_id')
        );

        return $this->successResponse(
            (new MinistryPlacementRecordResource($record))->resolve(request())
        );
    }

    public function convertToApplicant(int $id, ConvertToApplicantRequest $request, MinistryPlacementService $service): JsonResponse
    {
        try {
            $record = $service->convertToApplicant(
                $id,
                $request->user()?->user_id
            );
        } catch (MinistryPlacementException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->errors, $exception->status);
        }

        return $this->successResponse(
            (new MinistryPlacementRecordResource($record))->resolve(request())
        );
    }
}
