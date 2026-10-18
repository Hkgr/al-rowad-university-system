<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GradeException;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Services\AcademicAuthorizationService;
use App\Services\GradeWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeWorkflowController extends Controller
{
    public function show(
        CourseOffering $courseOffering,
        Request $request,
        AcademicAuthorizationService $authorization,
        GradeWorkflowService $service
    ): JsonResponse {
        $authorization->assertCanViewGradeWorkflow($request->user(), $courseOffering->course_offering_id);

        return $this->successResponse($service->getWorkflow($courseOffering->course_offering_id));
    }

    public function submit(
        CourseOffering $courseOffering,
        Request $request,
        AcademicAuthorizationService $authorization,
        GradeWorkflowService $service
    ): JsonResponse {
        $user = $request->user();
        $request->validate([
            'submitted_by_user_id' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'approved_by_user_id' => ['prohibited'],
            'approval_date' => ['prohibited'],
            'approval_status_id' => ['prohibited'],
            'approval_status_code' => ['prohibited'],
        ]);

        if ($user->hasPermission('exams.manage')) {
            $authorization->assertExaminationCommitteeCanAccessOffering($user, $courseOffering);
        } elseif ($user->hasPermission('grades.manage')) {
            throw new GradeException(
                'Grade mutations must use the grade-part workflow.',
                status: 403,
                errorCode: 'grade_part_workflow_required'
            );
        } else {
            abort(403, 'Grade management permission is required.');
        }

        return $this->successResponse(
            $service->submit($courseOffering->course_offering_id, $request->user()->user_id),
            'Grades submitted successfully'
        );
    }

    private function successResponse(array $data, string $message = 'Operation completed successfully'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
