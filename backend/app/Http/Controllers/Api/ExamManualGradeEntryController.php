<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradePart\ExamManualGradeEntryRequest;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Services\ExamManualGradeEntryService;
use App\Services\GradePartWorkflowService;
use App\Support\ExamManualGradeEntryAccess;

class ExamManualGradeEntryController extends Controller
{
    public function catalog(Student $student, ExamManualGradeEntryRequest $request, \App\Services\ExamManualGradeContextService $service)
    {
        return $this->success($service->catalog($request->user(), $student, $request->validated()));
    }

    public function componentPreview(Student $student, \App\Models\CourseOffering $offering, ExamManualGradeEntryRequest $request, \App\Services\ExamManualGradeContextService $service)
    {
        return $this->success($service->preview($request->user(), $student, $offering));
    }

    public function prepareComponents(Student $student, \App\Models\CourseOffering $offering, ExamManualGradeEntryRequest $request, \App\Services\ExamManualGradeContextService $service)
    {
        return $this->success($service->prepare($request->user(), $student, $offering, $request->validated()));
    }

    public function prepareRegistration(Student $student, \App\Models\CourseOffering $offering, ExamManualGradeEntryRequest $request, \App\Services\RegistrationService $service)
    {
        $registration = $service->prepareManualGradeRegistration($student, $offering, $request->user(), $request->validated());
        return $this->success(['registration_id' => (int) $registration->getKey()]);
    }

    public function students(ExamManualGradeEntryRequest $request, ExamManualGradeEntryService $service)
    {
        return $this->success($service->students($request->user(), $request->validated()));
    }

    public function registrations(Student $student, ExamManualGradeEntryRequest $request, ExamManualGradeEntryService $service)
    {
        return $this->success($service->registrations($request->user(), $student, $request->validated()));
    }

    public function save(Student $student, StudentCourseRegistration $registration, ExamManualGradeEntryRequest $request, GradePartWorkflowService $service)
    {
        return $this->success($service->saveManualMarks($student, $registration, $request->validated(), $request->user()));
    }

    public function readiness(Student $student, StudentCourseRegistration $registration, string $part, ExamManualGradeEntryRequest $request, GradePartWorkflowService $service, ExamManualGradeEntryAccess $access)
    {
        $access->authorize($request->user(), $student, $registration);
        return $this->success($service->manualSubmissionReadiness($registration, $part));
    }

    public function submit(Student $student, StudentCourseRegistration $registration, string $part, ExamManualGradeEntryRequest $request, GradePartWorkflowService $service)
    {
        return $this->success($service->submitManualPart($student, $registration, $part, $request->validated(), $request->user()));
    }

    private function success(array $data)
    {
        return response()->json(['success' => true, 'data' => $data]);
    }
}
