<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradeApproval\StoreGradeApprovalRequest;
use App\Http\Requests\GradeApproval\UpdateGradeApprovalRequest;
use App\Http\Resources\GradeApprovalResource;
use App\Models\GradeApproval;
use App\Services\AcademicAuthorizationService;
use Illuminate\Http\JsonResponse;

class GradeApprovalController extends ApiController
{
    public function store(): JsonResponse
    {
        app(AcademicAuthorizationService::class)->assertExaminationCommittee(request()->user());

        return parent::store();
    }

    public function update($id): JsonResponse
    {
        app(AcademicAuthorizationService::class)->assertExaminationCommittee(request()->user());

        return parent::update($id);
    }

    public function destroy($id): JsonResponse
    {
        app(AcademicAuthorizationService::class)->assertExaminationCommittee(request()->user());

        return parent::destroy($id);
    }

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
