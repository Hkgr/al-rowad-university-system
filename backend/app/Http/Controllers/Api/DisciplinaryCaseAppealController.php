<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\DisciplinaryCase\StoreDisciplinaryCaseAppealRequest;
use App\Http\Requests\DisciplinaryCase\UpdateDisciplinaryCaseAppealRequest;
use App\Http\Resources\DisciplinaryCaseAppealResource;
use App\Models\DisciplinaryCaseAppeal;
use App\Services\DisciplinaryCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisciplinaryCaseAppealController extends ApiController
{
    protected function modelClass(): string
    {
        return DisciplinaryCaseAppeal::class;
    }

    protected function resourceClass(): string
    {
        return DisciplinaryCaseAppealResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreDisciplinaryCaseAppealRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateDisciplinaryCaseAppealRequest::class;
    }

    public function store(): JsonResponse
    {
        /** @var StoreDisciplinaryCaseAppealRequest $request */
        $request = app(StoreDisciplinaryCaseAppealRequest::class);
        $validated = $request->validated();

        $appeal = app(DisciplinaryCaseService::class)->submitAppeal(
            (int) $validated['case_id'],
            $validated
        );

        return $this->successResponse(
            (new DisciplinaryCaseAppealResource($appeal))->resolve(request()),
            'Operation completed successfully',
            201
        );
    }

    public function decide(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status_code' => ['required', 'string', 'in:accepted,rejected'],
            'notes' => ['nullable', 'string'],
        ]);

        $appeal = app(DisciplinaryCaseService::class)->decideAppeal(
            $id,
            $validated['status_code'],
            $validated['notes'] ?? null,
            $request->user()?->user_id
        );

        return $this->successResponse(
            (new DisciplinaryCaseAppealResource($appeal))->resolve(request())
        );
    }
}
