<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ResultStatus\StoreResultStatusRequest;
use App\Http\Requests\ResultStatus\UpdateResultStatusRequest;
use App\Http\Resources\ResultStatusResource;
use App\Models\ResultStatus;
use App\Support\SupplementaryExamTargetGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ResultStatusController extends ApiController
{
    public function update($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::update($id), 3);
    }

    public function destroy($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::destroy($id), 3);
    }

    protected function beforeUpdateMutation(ResultStatus $status, array $payload): void
    {
        SupplementaryExamTargetGuard::assertResultStatusUpdateMutable((int) $status->getKey(), $payload);
    }

    protected function beforeDestroyMutation(ResultStatus $status): void
    {
        SupplementaryExamTargetGuard::assertResultStatusDestroyable((int) $status->getKey());
    }

    protected function modelClass(): string
    {
        return ResultStatus::class;
    }

    protected function resourceClass(): string
    {
        return ResultStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreResultStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateResultStatusRequest::class;
    }
}
