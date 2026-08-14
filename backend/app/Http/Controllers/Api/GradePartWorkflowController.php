<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradePart\SaveGradePartRequest;
use App\Models\CourseOffering;
use App\Models\StudentCourseRegistration;
use App\Services\AcademicAuthorizationService;
use App\Services\GradePartWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradePartWorkflowController extends Controller
{
    public function show(CourseOffering $offering, Request $request, AcademicAuthorizationService $authorization, GradePartWorkflowService $service): JsonResponse
    {
        $authorization->assertCanViewGradeParts($request->user(), $offering->course_offering_id);
        return $this->success($service->workflow($offering->course_offering_id));
    }
    public function update(StudentCourseRegistration $registration, string $part, SaveGradePartRequest $request, AcademicAuthorizationService $authorization, GradePartWorkflowService $service): JsonResponse
    {
        $authorization->assertCanManageGradePart($request->user(), $registration->course_offering_id, $part);
        return $this->success($service->savePart($registration, $part, $request->validated(), $request->user()->user_id), 'Grade part saved successfully');
    }
    public function submit(CourseOffering $offering, string $part, Request $request, AcademicAuthorizationService $authorization, GradePartWorkflowService $service): JsonResponse
    {
        $authorization->assertCanManageGradePart($request->user(), $offering->course_offering_id, $part);
        return $this->success($service->submit($offering->course_offering_id, $part, $request->user()->user_id)->toArray(), 'Grade part submitted successfully');
    }
    private function success(mixed $data, string $message = 'Operation completed successfully'): JsonResponse { return response()->json(['success' => true, 'message' => $message, 'data' => $data]); }
}
