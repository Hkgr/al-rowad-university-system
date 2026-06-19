<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginAuditLog\StoreLoginAuditLogRequest;
use App\Http\Requests\LoginAuditLog\UpdateLoginAuditLogRequest;
use App\Http\Resources\LoginAuditLogResource;
use App\Models\LoginAuditLog;

class LoginAuditLogController extends ApiController
{
    protected function modelClass(): string
    {
        return LoginAuditLog::class;
    }

    protected function resourceClass(): string
    {
        return LoginAuditLogResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreLoginAuditLogRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateLoginAuditLogRequest::class;
    }
}
