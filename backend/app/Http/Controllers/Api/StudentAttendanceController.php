<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentAttendance\StoreStudentAttendanceRequest;
use App\Http\Requests\StudentAttendance\UpdateStudentAttendanceRequest;
use App\Http\Resources\StudentAttendanceResource;
use App\Models\StudentAttendance;

class StudentAttendanceController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentAttendance::class;
    }

    protected function resourceClass(): string
    {
        return StudentAttendanceResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentAttendanceRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentAttendanceRequest::class;
    }
}
