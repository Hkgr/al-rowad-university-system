<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Semester\StoreSemesterRequest;
use App\Http\Requests\Semester\UpdateSemesterRequest;
use App\Http\Resources\SemesterResource;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;

class SemesterController extends ApiController
{
    protected function modelClass(): string
    {
        return Semester::class;
    }

    protected function resourceClass(): string
    {
        return SemesterResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreSemesterRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateSemesterRequest::class;
    }

    public function active(): JsonResponse
    {
        $semesters = Semester::query()
            ->where('is_active', true)
            ->orderBy('semester_order')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            SemesterResource::collection($semesters)->response(request())->getData(true)
        );
    }
}
