<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradeApproval\StoreGradeApprovalRequest;
use App\Http\Requests\GradeApproval\UpdateGradeApprovalRequest;
use App\Http\Resources\GradeApprovalResource;
use App\Models\GradeApproval;
use App\Services\AcademicAuthorizationService;
use Illuminate\Http\JsonResponse;
use App\Services\DataScopeService;

class GradeApprovalController extends ApiController
{
    public function store(): JsonResponse
    {
        $user = request()->user();
        app(AcademicAuthorizationService::class)->assertExaminationCommittee($user);
        $request = app($this->storeRequestClass());
        $data = $request->validated();
        app(DataScopeService::class)->assertPayloadScope($user, $data);
        $data['submitted_by_user_id'] = $user->user_id;
        $data['submitted_at'] ??= now();
        $approval = GradeApproval::query()->create($data);
        return $this->successResponse((new GradeApprovalResource($approval))->resolve($request), 'Operation completed successfully', 201);
    }

    public function update($id): JsonResponse
    {
        app(AcademicAuthorizationService::class)->assertExaminationCommittee(request()->user());
        $approval = app(DataScopeService::class)->scopeResourceQuery(GradeApproval::query(), request()->user())->findOrFail($id);
        $request = app($this->updateRequestClass());
        $data = $request->validated();
        app(DataScopeService::class)->assertPayloadScope(request()->user(), $data);
        $data['approved_by_user_id'] = request()->user()->user_id;
        $approval->update($data);
        return $this->successResponse((new GradeApprovalResource($approval->fresh()))->resolve($request));
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
