<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradeApproval\StoreGradeApprovalRequest;
use App\Http\Requests\GradeApproval\UpdateGradeApprovalRequest;
use App\Http\Resources\GradeApprovalResource;
use App\Models\GradeApproval;

class GradeApprovalController extends ApiController
{
    protected function modelClass(): string
    {
        return GradeApproval::class;
    }

    protected function resourceClass(): string
    {
        return GradeApprovalResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreGradeApprovalRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateGradeApprovalRequest::class;
    }
}
