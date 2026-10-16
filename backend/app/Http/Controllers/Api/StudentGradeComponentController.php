<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentGradeComponent\StoreStudentGradeComponentRequest;
use App\Http\Requests\StudentGradeComponent\UpdateStudentGradeComponentRequest;
use App\Http\Resources\StudentGradeComponentResource;
use App\Models\StudentGradeComponent;

class StudentGradeComponentController extends ApiController
{
    public function store(): \Illuminate\Http\JsonResponse { return \App\Services\AcademicPlanContext::transaction(fn () => parent::store()); }
    public function update($id): \Illuminate\Http\JsonResponse { return \App\Services\AcademicPlanContext::transaction(fn () => parent::update($id)); }
    public function destroy($id): \Illuminate\Http\JsonResponse { return \App\Services\AcademicPlanContext::transaction(fn () => parent::destroy($id)); }
    protected function beforeStoreMutation(array $payload): void { $this->lockIncompleteTargets([(int) $payload['student_course_registration_id']]); }
    protected function beforeUpdateMutation(StudentGradeComponent $component, array $payload): void { $this->lockIncompleteTargets([(int) $component->student_course_registration_id, (int) ($payload['student_course_registration_id'] ?? $component->student_course_registration_id)]); }
    protected function beforeDestroyMutation(StudentGradeComponent $component): void { $this->lockIncompleteTargets([(int) $component->student_course_registration_id]); }
    private function lockIncompleteTargets(array $ids): void
    {
        $ids = array_values(array_unique($ids)); sort($ids);
        $offerings = \App\Models\StudentCourseRegistration::whereIn('student_course_registration_id', $ids)->pluck('course_offering_id')->unique()->sort();
        foreach ($offerings as $id) \App\Services\CourseOfferingLock::lock((int) $id);
        \App\Models\StudentCourseRegistration::whereIn('student_course_registration_id', $ids)->orderBy('student_course_registration_id')->lockForUpdate()->get();
        foreach ($ids as $id) \App\Support\IncompleteOutcomeGuard::assertOrdinaryWrite($id);
    }
    protected function modelClass(): string
    {
        return StudentGradeComponent::class;
    }

    protected function resourceClass(): string
    {
        return StudentGradeComponentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentGradeComponentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentGradeComponentRequest::class;
    }
}
