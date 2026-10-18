<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentAcademicTerm\StoreStudentAcademicTermRequest;
use App\Http\Requests\StudentAcademicTerm\UpdateStudentAcademicTermRequest;
use App\Http\Resources\StudentAcademicTermResource;
use App\Models\StudentAcademicTerm;
use Illuminate\Http\JsonResponse;

class StudentAcademicTermController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentAcademicTerm::class;
    }

    protected function resourceClass(): string
    {
        return StudentAcademicTermResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentAcademicTermRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentAcademicTermRequest::class;
    }

    public function store(): JsonResponse
    {
        app(\App\Services\AcademicTermSnapshotService::class)->rejectGenericMutation();
    }

    public function update($id): JsonResponse
    {
        $term = StudentAcademicTerm::query()->findOrFail($id);
        app(\App\Services\AcademicTermSnapshotService::class)->rejectGenericMutation($term);
    }

    public function destroy($id): JsonResponse
    {
        $term = StudentAcademicTerm::query()->findOrFail($id);
        app(\App\Services\AcademicTermSnapshotService::class)->rejectGenericMutation($term);
    }
}
