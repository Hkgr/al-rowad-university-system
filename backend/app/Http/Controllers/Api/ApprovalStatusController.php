<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ApprovalStatus\StoreApprovalStatusRequest;
use App\Http\Requests\ApprovalStatus\UpdateApprovalStatusRequest;
use App\Http\Resources\ApprovalStatusResource;
use App\Models\ApprovalStatus;
use App\Support\SupplementaryExamTargetGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ApprovalStatusController extends ApiController
{
    public function update($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::update($id), 3);
    }

    public function destroy($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::destroy($id), 3);
    }

    protected function beforeUpdateMutation(ApprovalStatus $status, array $payload): void
    {
        SupplementaryExamTargetGuard::assertApprovalStatusUpdateMutable((int) $status->getKey(), $payload);
    }

    protected function beforeDestroyMutation(ApprovalStatus $status): void
    {
        SupplementaryExamTargetGuard::assertApprovalStatusDestroyable((int) $status->getKey());
    }

    protected function modelClass(): string
    {
        return ApprovalStatus::class;
    }

    protected function resourceClass(): string
    {
        return ApprovalStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreApprovalStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateApprovalStatusRequest::class;
    }
}
