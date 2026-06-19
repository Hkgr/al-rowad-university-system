<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AttendanceSession\StoreAttendanceSessionRequest;
use App\Http\Requests\AttendanceSession\UpdateAttendanceSessionRequest;
use App\Http\Resources\AttendanceSessionResource;
use App\Models\AttendanceSession;

class AttendanceSessionController extends ApiController
{
    protected function modelClass(): string
    {
        return AttendanceSession::class;
    }

    protected function resourceClass(): string
    {
        return AttendanceSessionResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAttendanceSessionRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAttendanceSessionRequest::class;
    }
}
