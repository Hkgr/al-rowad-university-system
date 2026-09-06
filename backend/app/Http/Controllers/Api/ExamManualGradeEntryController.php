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
