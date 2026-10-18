<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AcademicCalendarException;
use App\Http\Requests\AcademicYear\StoreAcademicYearRequest;
use App\Http\Requests\AcademicYear\UpdateAcademicYearRequest;
use App\Http\Resources\AcademicYearResource;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;

class AcademicYearController extends ApiController
{
    protected function modelClass(): string
    {
        return AcademicYear::class;
    }

    protected function resourceClass(): string
    {
        return AcademicYearResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAcademicYearRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAcademicYearRequest::class;
    }

    public function current(): JsonResponse
    {
        $academicYear = AcademicYear::query()
            ->where('is_current', true)
            ->first();

        if ($academicYear === null) {
            return $this->errorResponse('No current academic year found', [], 404);
        }

        return $this->successResponse(
            (new AcademicYearResource($academicYear))->resolve(request())
        );
    }

    protected function beforeUpdateMutation(AcademicYear $academicYear, array $payload): void
    {
        if (($academicYear->is_current || $academicYear->calendar_lifecycle_status === 'active')
            && array_key_exists('is_active', $payload) && ! $payload['is_active']) {
            throw AcademicCalendarException::conflict('لا يمكن تعطيل السنة الحالية أو النشطة تقويمياً.');
        }
    }

    protected function beforeDestroyMutation(AcademicYear $academicYear): void
    {
        if ($academicYear->is_current || $academicYear->calendar_lifecycle_status === 'active') {
            throw AcademicCalendarException::conflict('لا يمكن حذف السنة الحالية أو النشطة تقويمياً.');
        }
    }
}
