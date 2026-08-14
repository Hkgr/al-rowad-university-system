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
    public function index(): JsonResponse
    {
        $this->assertStaffRegistrar();
        $limits = $this->scopedQuery()->paginate(request()->integer('per_page', 15));
        return $this->successResponse(StudentCreditLimitResource::collection($limits)->response(request())->getData(true));
    }

    public function show($id): JsonResponse
    {
        $this->assertStaffRegistrar();
        $limit = $this->scopedQuery()->findOrFail($id);
        return $this->successResponse((new StudentCreditLimitResource($limit))->resolve(request()));
    }

    public function store(): JsonResponse
    {
        $this->assertStaffRegistrar();
        $request = app($this->storeRequestClass());
        $data = $request->validated();
        $this->assertStaffStudentScope($data['student_id']);
        $data['approved_by_user_id'] = $request->user()->user_id;
        $limit = StudentCreditLimit::query()->create($data);
        return $this->successResponse((new StudentCreditLimitResource($limit))->resolve($request), 'Operation completed successfully', 201);
    }

    public function update($id): JsonResponse
    {
        $this->assertStaffRegistrar();
        $request = app($this->updateRequestClass());
        $limit = $this->scopedQuery()->findOrFail($id);
        $data = $request->validated();
        if (array_key_exists('student_id', $data) && $data['student_id'] !== null) {
            $this->assertStaffStudentScope((int) $data['student_id']);
        }
        $data['approved_by_user_id'] = $request->user()->user_id;
        $limit->update($data);
        return $this->successResponse((new StudentCreditLimitResource($limit->fresh()))->resolve($request));
    }

    public function destroy($id): JsonResponse
    {
        $this->assertStaffRegistrar();
        $limit = $this->scopedQuery()->findOrFail($id);
        $limit->delete();
        return $this->successResponse([]);
    }

    private function assertStaffRegistrar(): void
    {
        $user = request()->user();
        abort_unless($user !== null
            && ($user->employee_id !== null || $user->effectiveRoles()->contains('super_admin'))
            && $user->hasPermission('registration.manage'), 403);
    }

    private function scopedQuery()
    {
        return StudentCreditLimit::query()->whereHas('student', fn ($student) =>
            app(DataScopeService::class)->scopeStudentsForStaff($student, request()->user()));
    }

    private function assertStaffStudentScope(int $studentId): void
    {
        $student = \App\Models\Student::query()->findOrFail($studentId);
        abort_unless(app(DataScopeService::class)->canStaffAccessStudent(request()->user(), $student), 403);
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
