<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\GradePart\ListGradePartApprovalsRequest;
use App\Http\Requests\GradePart\ReviewGradePartRequest;
use App\Http\Requests\GradePart\ReturnGradePartRequest;
use App\Http\Resources\GradePartApprovalResource;
use App\Services\AcademicAuthorizationService;
use App\Services\GradePartWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class GradePartApprovalController extends Controller
{
    public function index(ListGradePartApprovalsRequest $request, GradePartWorkflowService $service): JsonResponse { return $this->success(GradePartApprovalResource::collection($service->paginate($request->user(), $request->validated()))->response()->getData(true)); }
    public function show(int $approval, Request $request, AcademicAuthorizationService $auth, GradePartWorkflowService $service): JsonResponse
    {
        $auth->assertExaminationCommittee($request->user());
        $record = $service->find($request->user(), $approval);
        return $this->success([
            'approval' => (new GradePartApprovalResource($record))->resolve($request),
            'workflow' => $service->workflow($record->course_offering_id, $request->user()),
        ]);
    }
    public function approve(int $approval, ReviewGradePartRequest $request, AcademicAuthorizationService $auth, GradePartWorkflowService $service): JsonResponse { $auth->assertExaminationCommittee($request->user()); return $this->success((new GradePartApprovalResource($service->review($request->user(), $approval, 'approve', $request->validated('review_notes'))))->resolve($request), 'Grade part approved successfully'); }
    public function returnForCorrection(int $approval, ReturnGradePartRequest $request, AcademicAuthorizationService $auth, GradePartWorkflowService $service): JsonResponse { $auth->assertExaminationCommittee($request->user()); return $this->success((new GradePartApprovalResource($service->review($request->user(), $approval, 'return', $request->validated('review_notes'))))->resolve($request), 'Grade part returned for correction'); }
    private function success(mixed $data, string $message = 'Operation completed successfully'): JsonResponse { return response()->json(['success' => true, 'message' => $message, 'data' => $data]); }
}
