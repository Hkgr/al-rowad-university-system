<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradingPolicy\StoreGradingPolicyRequest;
use App\Http\Requests\GradingPolicy\UpdateGradingPolicyRequest;
use App\Http\Resources\GradingPolicyResource;
use App\Models\GradingPolicy;
use App\Support\SupplementaryExamTargetGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class GradingPolicyController extends ApiController
{
    public function store(): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::store(), 3);
    }

    public function update($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::update($id), 3);
    }

    public function destroy($id): JsonResponse
    {
        return DB::transaction(fn (): JsonResponse => parent::destroy($id), 3);
    }

    protected function beforeStoreMutation(array $payload): void
    {
        SupplementaryExamTargetGuard::assertGradingPolicyCreationMutable($payload);
    }

    protected function beforeUpdateMutation(GradingPolicy $policy, array $payload): void
    {
        SupplementaryExamTargetGuard::assertGradingPolicyUpdateMutable((int) $policy->getKey(), $payload);
    }

    protected function beforeDestroyMutation(GradingPolicy $policy): void
    {
        SupplementaryExamTargetGuard::assertGradingPolicyMutable((int) $policy->getKey());
    }

    protected function modelClass(): string
    {
        return GradingPolicy::class;
    }

    protected function resourceClass(): string
    {
        return GradingPolicyResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreGradingPolicyRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateGradingPolicyRequest::class;
    }
}
