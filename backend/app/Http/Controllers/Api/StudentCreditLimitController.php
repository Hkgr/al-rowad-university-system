<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentCreditLimit\StoreStudentCreditLimitRequest;
use App\Http\Requests\StudentCreditLimit\UpdateStudentCreditLimitRequest;
use App\Http\Resources\StudentCreditLimitResource;
use App\Models\StudentCreditLimit;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;

class StudentCreditLimitController extends ApiController
{
    public function store(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('registration.manage'), 403);
        $request = app($this->storeRequestClass());
        $data = $request->validated();
        app(DataScopeService::class)->assertPayloadScope($request->user(), $data);
        $data['approved_by_user_id'] = $request->user()->user_id;
        $limit = StudentCreditLimit::query()->create($data);
        return $this->successResponse((new StudentCreditLimitResource($limit))->resolve($request), 'Operation completed successfully', 201);
    }

    public function update($id): JsonResponse
    {
        $request = app($this->updateRequestClass());
        $limit = app(DataScopeService::class)->scopeResourceQuery(StudentCreditLimit::query(), $request->user())->findOrFail($id);
        abort_unless($request->user()->hasPermission('registration.manage'), 403);
        $data = $request->validated();
        app(DataScopeService::class)->assertPayloadScope($request->user(), $data);
        $data['approved_by_user_id'] = $request->user()->user_id;
        $limit->update($data);
        return $this->successResponse((new StudentCreditLimitResource($limit->fresh()))->resolve($request));
    }
    protected function modelClass(): string
    {
        return StudentCreditLimit::class;
    }

    protected function resourceClass(): string
    {
        return StudentCreditLimitResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentCreditLimitRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentCreditLimitRequest::class;
    }
}
