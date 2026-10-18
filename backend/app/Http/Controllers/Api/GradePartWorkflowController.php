<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradePart\SaveGradePartRequest;
use App\Models\CourseOffering;
use App\Models\StudentCourseRegistration;
use App\Services\AcademicAuthorizationService;
use App\Services\GradePartWorkflowService;
use App\Services\RegularExamOccurrenceService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\RegularExamOccurrenceSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradePartWorkflowController extends Controller
{
    public function show(
        CourseOffering $offering,
        Request $request,
        AcademicAuthorizationService $authorization,
        GradePartWorkflowService $service,
        RegularExamOccurrenceService $occurrence,
    ): JsonResponse
    {
        $authorization->assertCanViewGradeParts($request->user(), $offering->course_offering_id);

        $payload = $service->workflow($offering->course_offering_id, $request->user());
        $payload['regular_exam_occurrence'] = $this->occurrencePayload(
            $occurrence->snapshotForOffering($offering)
        );

        return $this->success($payload);
    }

    public function update(StudentCourseRegistration $registration, string $part, SaveGradePartRequest $request, AcademicAuthorizationService $authorization, GradePartWorkflowService $service): JsonResponse
    {
        $authorization->assertCanManageGradePart($request->user(), $registration->course_offering_id, $part);

        return $this->success($service->savePart($registration, $part, $request->validated(), $request->user()), 'Grade part saved successfully');
    }

    public function submit(CourseOffering $offering, string $part, Request $request, AcademicAuthorizationService $authorization, GradePartWorkflowService $service): JsonResponse
    {
        $authorization->assertCanManageGradePart($request->user(), $offering->course_offering_id, $part);

        return $this->success($service->submit($offering->course_offering_id, $part, $request->user())->toArray(), 'Grade part submitted successfully');
    }

    public function submitMyParts(CourseOffering $offering, Request $request, AcademicAuthorizationService $authorization, GradePartWorkflowService $service): JsonResponse
    {
        $authorization->assertAssignedInstructor($request->user(), $offering->course_offering_id);

        return $this->success(
            $service->submitMyParts($request->user(), $offering->course_offering_id),
            'Assigned grade parts submitted successfully'
        );
    }

    private function success(mixed $data, string $message = 'Operation completed successfully'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    /** @return array<string, mixed> */
    private function occurrencePayload(RegularExamOccurrenceSnapshot $snapshot): array
    {
        return [
            'course_offering_id' => $snapshot->courseOfferingId,
            'academic_year_id' => $snapshot->academicYearId,
            'semester_id' => $snapshot->semesterId,
            'evaluated_at' => $snapshot->evaluatedAt->toIso8601String(),
            'practical' => $this->occurrencePartPayload($snapshot->practical),
            'theoretical' => $this->occurrencePartPayload($snapshot->theoretical),
        ];
    }

    /** @return array{status: string, is_occurring: bool, reason_code: string|null} */
    private function occurrencePartPayload(AcademicCalendarPolicyResult $result): array
    {
        return [
            'status' => $result->status->value,
            'is_occurring' => $result->isOpen(),
            'reason_code' => $result->reasonCode,
        ];
    }
}
