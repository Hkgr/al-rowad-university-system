<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AttendanceStatus\StoreAttendanceStatusRequest;
use App\Http\Requests\AttendanceStatus\UpdateAttendanceStatusRequest;
use App\Http\Resources\AttendanceStatusResource;
use App\Models\AttendanceStatus;

class AttendanceStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return AttendanceStatus::class;
    }

    protected function resourceClass(): string
    {
        return AttendanceStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAttendanceStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAttendanceStatusRequest::class;
    }
}
