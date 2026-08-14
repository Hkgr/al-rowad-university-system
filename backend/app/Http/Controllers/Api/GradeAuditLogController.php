<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradeAuditLog\StoreGradeAuditLogRequest;
use App\Http\Resources\GradeAuditLogResource;
use App\Models\GradeAuditLog;
use App\Models\StudentGradeComponent;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;

class GradeAuditLogController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->assertStaffGrader();
        $logs = $this->scopedQuery()->paginate(request()->integer('per_page', 15));
        return $this->successResponse(GradeAuditLogResource::collection($logs)->response(request())->getData(true));
    }

    public function show($id): JsonResponse
    {
        $this->assertStaffGrader();
        $log = $this->scopedQuery()->findOrFail($id);
        return $this->successResponse((new GradeAuditLogResource($log))->resolve(request()));
    }

    public function store(): JsonResponse
    {
        $this->assertStaffGrader();
        /** @var StoreGradeAuditLogRequest $request */
        $request = app(StoreGradeAuditLogRequest::class);
        $data = $request->validated();
        $component = StudentGradeComponent::query()->findOrFail($data['student_grade_component_id']);
        abort_unless(app(DataScopeService::class)->scopeRegistrationsForStaff(
            $component->studentCourseRegistration()->getQuery(), $request->user()
        )->whereKey($component->student_course_registration_id)->exists(), 403);
        $data['changed_by_user_id'] = $request->user()->user_id;
        $data['changed_at'] ??= now();
        $log = GradeAuditLog::query()->create($data);
        return $this->successResponse((new GradeAuditLogResource($log))->resolve($request), 'Audit log created.', 201);
    }

    public function update($id): JsonResponse { abort(405, 'Grade audit logs are immutable.'); }
    public function destroy($id): JsonResponse { abort(405, 'Grade audit logs are immutable.'); }

    private function scopedQuery()
    {
        return GradeAuditLog::query()->whereHas('studentGradeComponent.studentCourseRegistration', fn ($registration) =>
            app(DataScopeService::class)->scopeRegistrationsForStaff($registration, request()->user()));
    }

    private function assertStaffGrader(): void
    {
        $user = request()->user();
        abort_unless($user !== null
            && ($user->employee_id !== null || $user->effectiveRoles()->contains('super_admin'))
            && $user->hasPermission('grades.manage'), 403);
    }

    protected function modelClass(): string { return GradeAuditLog::class; }
    protected function resourceClass(): string { return GradeAuditLogResource::class; }
    protected function storeRequestClass(): string { return StoreGradeAuditLogRequest::class; }
    protected function updateRequestClass(): string { return ''; }
}
