<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradeApproval\ApproveGradeApprovalRequest;
use App\Http\Requests\GradeApproval\ListGradeApprovalsRequest;
use App\Http\Requests\GradeApproval\ReturnGradeApprovalRequest;
use App\Http\Resources\GradeApprovalResource;
use App\Services\AcademicAuthorizationService;
use App\Services\GradeApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeApprovalController extends Controller
{
    public function index(ListGradeApprovalsRequest $request, AcademicAuthorizationService $authorization, GradeApprovalWorkflowService $service): JsonResponse
    {
        $authorization->assertExaminationCommittee($request->user());
        $paginator = $service->paginate($request->user(), $request->validated());
        $payload = GradeApprovalResource::collection($paginator)->response()->getData(true);

        return $this->success($payload);
    }

    public function show(int $gradeApproval, Request $request, AcademicAuthorizationService $authorization, GradeApprovalWorkflowService $service): JsonResponse
    {
        $authorization->assertExaminationCommittee($request->user());
        $details = $service->details($request->user(), $gradeApproval);
        $details['approval'] = (new GradeApprovalResource($details['approval']))->resolve($request);

        return $this->success($details);
    }

    public function approve(int $gradeApproval, ApproveGradeApprovalRequest $request, AcademicAuthorizationService $authorization, GradeApprovalWorkflowService $service): JsonResponse
    {
        $authorization->assertExaminationCommittee($request->user());
        $approval = $service->approve($request->user(), $gradeApproval, $request->validated('approval_notes'));

        return $this->success((new GradeApprovalResource($approval))->resolve($request), 'Grades approved successfully');
    }

    public function returnForCorrection(int $gradeApproval, ReturnGradeApprovalRequest $request, AcademicAuthorizationService $authorization, GradeApprovalWorkflowService $service): JsonResponse
    {
        $authorization->assertExaminationCommittee($request->user());
        $approval = $service->returnForCorrection($request->user(), $gradeApproval, $request->validated('approval_notes'));

        return $this->success((new GradeApprovalResource($approval))->resolve($request), 'Grades returned for correction');
    }

    private function success(mixed $data, string $message = 'Operation completed successfully'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
