<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradeAuditLog\StoreGradeAuditLogRequest;
use App\Http\Requests\GradeAuditLog\UpdateGradeAuditLogRequest;
use App\Http\Resources\GradeAuditLogResource;
use App\Models\GradeAuditLog;

class GradeAuditLogController extends ApiController
{
    protected function modelClass(): string
    {
        return GradeAuditLog::class;
    }

    protected function resourceClass(): string
    {
        return GradeAuditLogResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreGradeAuditLogRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateGradeAuditLogRequest::class;
    }
}
