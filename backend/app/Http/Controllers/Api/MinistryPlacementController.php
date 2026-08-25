<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MinistryPlacement\ImportMinistryPlacementRequest;
use App\Http\Requests\MinistryPlacement\ApplyMinistryPlacementProgramGroupRequest;
use App\Http\Requests\MinistryPlacement\MatchMinistryPlacementProgramRequest;
use App\Http\Requests\MinistryPlacement\PreviewMinistryPlacementRequest;
use App\Http\Resources\MinistryPlacementBatchResource;
use App\Http\Resources\MinistryPlacementRecordResource;
use App\Models\MinistryPlacementBatch;
use App\Models\MinistryPlacementRecord;
use App\Services\MinistryPlacementService;
use App\Services\MinistryPlacementProgramMatchingService;
use App\Support\AcademicQueuePagination;
use App\Support\MinistryPlacementAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MinistryPlacementController extends ApiController
{
    public function preview(PreviewMinistryPlacementRequest $request, MinistryPlacementService $service): JsonResponse
    {
        return $this->successResponse($service->preview($request->file('file')));
    }

    public function import(ImportMinistryPlacementRequest $request, MinistryPlacementService $service): JsonResponse
    {
        $batch = $service->import($request->file('file'), $request->validated(), $request->user());

        return $this->successResponse((new MinistryPlacementBatchResource($batch))->resolve($request), 'تم استيراد الدفعة بنجاح.', 201);
    }

    public function index(Request $request, MinistryPlacementAccess $access): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);
        $batches = MinistryPlacementBatch::query()
            ->with(['academicYear', 'importedBy'])
            ->withCount('records')
            ->orderByDesc('batch_id')
            ->paginate(AcademicQueuePagination::perPage($request->integer('per_page') ?: null, 15));

        return $this->successResponse(MinistryPlacementBatchResource::collection($batches)->response($request)->getData(true));
    }

    public function show(Request $request, int $batch, MinistryPlacementAccess $access): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);
        $model = MinistryPlacementBatch::query()
            ->with(['academicYear', 'importedBy'])
            ->withCount('records')
            ->findOrFail($batch);

        return $this->successResponse((new MinistryPlacementBatchResource($model))->resolve($request));
    }

    public function records(Request $request, int $batch, MinistryPlacementAccess $access): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);
        MinistryPlacementBatch::query()->findOrFail($batch);
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:150'],
            'processing_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = MinistryPlacementRecord::query()
            ->where('batch_id', $batch)
            ->with('matchedAcademicProgram.department.college');
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($candidate) use ($escaped): void {
                foreach (['national_civil_id', 'subscription_number', 'first_name', 'last_name', 'accepted_preference_text'] as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $candidate->{$method}($column, 'like', '%'.$escaped.'%');
                }
            });
        }
        if (($validated['processing_status'] ?? null) !== null) {
            $query->where('processing_status', $validated['processing_status']);
        }
        $records = $query->orderBy('row_number')->orderBy('placement_record_id')
            ->paginate(AcademicQueuePagination::perPage(isset($validated['per_page']) ? (int) $validated['per_page'] : null, 15));

        return $this->successResponse(MinistryPlacementRecordResource::collection($records)->response($request)->getData(true));
    }

    public function programs(Request $request, MinistryPlacementAccess $access, MinistryPlacementProgramMatchingService $service): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:150'],
            'college_id' => ['sometimes', 'nullable', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $programs = $service->programs($validated);

        return $this->successResponse(JsonResource::collection($programs)->response($request)->getData(true));
    }

    public function programMatching(Request $request, int $batch, MinistryPlacementAccess $access, MinistryPlacementProgramMatchingService $service): JsonResponse
    {
        abort_unless($access->canView($request->user()), 403);

        return $this->successResponse($service->summary($batch));
    }

    public function matchProgram(MatchMinistryPlacementProgramRequest $request, int $record, MinistryPlacementProgramMatchingService $service): JsonResponse
    {
        $model = $service->match($record, (int) $request->validated('academic_program_id'), $request->user());

        return $this->successResponse((new MinistryPlacementRecordResource($model))->resolve($request), 'تمت مطابقة البرنامج بنجاح.');
    }

    public function unmatchProgram(Request $request, int $record, MinistryPlacementAccess $access, MinistryPlacementProgramMatchingService $service): JsonResponse
    {
        abort_unless($access->canManage($request->user()), 403);
        $model = $service->unmatch($record, $request->user());

        return $this->successResponse((new MinistryPlacementRecordResource($model))->resolve($request), 'تمت إزالة مطابقة البرنامج.');
    }

    public function applyProgramGroup(ApplyMinistryPlacementProgramGroupRequest $request, int $batch, MinistryPlacementProgramMatchingService $service): JsonResponse
    {
        $validated = $request->validated();
        $result = $service->applyGroup(
            $batch,
            $validated['preference_key'],
            (int) $validated['academic_program_id'],
            (int) $validated['expected_eligible_count'],
            $request->user(),
        );

        return $this->successResponse($result, 'تم تطبيق مطابقة المجموعة بنجاح.');
    }
}
