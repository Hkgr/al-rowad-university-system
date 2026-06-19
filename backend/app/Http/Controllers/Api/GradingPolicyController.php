<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradingPolicy\StoreGradingPolicyRequest;
use App\Http\Requests\GradingPolicy\UpdateGradingPolicyRequest;
use App\Http\Resources\GradingPolicyResource;
use App\Models\GradingPolicy;

class GradingPolicyController extends ApiController
{
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
