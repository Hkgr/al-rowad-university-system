<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegistrationStatus\StoreRegistrationStatusRequest;
use App\Http\Requests\RegistrationStatus\UpdateRegistrationStatusRequest;
use App\Http\Resources\RegistrationStatusResource;
use App\Models\RegistrationStatus;
use App\Support\SupplementaryExamTargetGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RegistrationStatusController extends ApiController
{
    public function update($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::update($id), 3);
    }

    public function destroy($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::destroy($id), 3);
    }

    protected function beforeUpdateMutation(RegistrationStatus $status, array $payload): void
    {
        SupplementaryExamTargetGuard::assertRegistrationStatusUpdateMutable((int) $status->getKey(), $payload);
    }

    protected function beforeDestroyMutation(RegistrationStatus $status): void
    {
        SupplementaryExamTargetGuard::assertRegistrationStatusDestroyable((int) $status->getKey());
    }

    protected function modelClass(): string
    {
        return RegistrationStatus::class;
    }

    protected function resourceClass(): string
    {
        return RegistrationStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreRegistrationStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateRegistrationStatusRequest::class;
    }
}
