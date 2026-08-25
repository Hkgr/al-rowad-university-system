<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplementaryExamGrading\SaveSupplementaryExamGradesRequest;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Services\SupplementaryExamGradingService;
use App\Services\SupplementaryExamMaterializationService;
use App\Services\SupplementaryExamOccurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplementaryExamGradingController extends Controller
{
    public function professorIndex(Request $request, SupplementaryExamGradingService $service): JsonResponse { return response()->json(['success'=>true,'data'=>$service->professorOfferings($request->user())]); }
    public function professorGrades(
        Request $request,
        SupplementaryExamOffering $offering,
        SupplementaryExamGradingService $service,
        SupplementaryExamOccurrenceService $occurrence,
    ): JsonResponse {
        $payload = $service->roster($request->user(), $offering);
        $offering->loadMissing('period');
        $payload['supplementary_exam_occurrence'] = $occurrence
            ->snapshotForPeriod($offering->period)
            ->toPublicArray();

        return response()->json(['success' => true, 'data' => $payload]);
    }
    public function save(SaveSupplementaryExamGradesRequest $request, SupplementaryExamOffering $offering, SupplementaryExamGradingService $service): JsonResponse { $data=$request->validated();return response()->json(['success'=>true,'data'=>$service->saveDrafts($request->user(),$offering,$data['marks'])]); }
    public function submit(Request $request, SupplementaryExamOffering $offering, SupplementaryExamGradingService $service): JsonResponse { return response()->json(['success'=>true,'data'=>$service->submit($request->user(),$offering)]); }
    public function resubmit(Request $request, SupplementaryExamOffering $offering, SupplementaryExamGradingService $service): JsonResponse { return response()->json(['success'=>true,'data'=>$service->submit($request->user(),$offering,true)]); }
    public function queue(
        Request $request,
        SupplementaryExamGradingService $service,
        SupplementaryExamMaterializationService $materializations,
    ): JsonResponse {
        $queue = $service->reviewQueue($request->user());

        return response()->json([
            'success' => true,
            // Keep the Phase-6 data array stable; the catalog is additive.
            'data' => $materializations->decorateReviewQueue($request->user(), $queue),
            'periods' => $service->reviewPeriodCatalog($request->user()),
        ]);
    }
    public function return(Request $request,int $submission,SupplementaryExamGradingService $service):JsonResponse{$data=$request->validate(['reason'=>['required','string','max:2000']]);return response()->json(['success'=>true,'data'=>$service->review($request->user(),$submission,'return',$data['reason'])]);}
    public function approve(Request $request,int $submission,SupplementaryExamGradingService $service):JsonResponse{return response()->json(['success'=>true,'data'=>$service->review($request->user(),$submission,'approve')]);}
    public function publish(Request $request,int $submission,SupplementaryExamGradingService $service):JsonResponse{return response()->json(['success'=>true,'data'=>$service->review($request->user(),$submission,'publish')]);}
    public function graders(Request $request, SupplementaryExamOffering $offering, SupplementaryExamGradingService $service): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return response()->json([
            'success' => true,
            'data' => $service->graderOptions($request->user(), $offering, $data['search'] ?? null),
        ]);
    }
    public function assign(Request $request,SupplementaryExamOffering $offering,SupplementaryExamGradingService $service):JsonResponse{$data=$request->validate(['faculty_member_id'=>['required','integer']]);return response()->json(['success'=>true,'data'=>$service->assign($request->user(),$offering,(int)$data['faculty_member_id'])]);}
    public function open(Request $request,SupplementaryExamPeriod $period,SupplementaryExamGradingService $service):JsonResponse{return response()->json(['success'=>true,'data'=>$service->openGrading($request->user(),$period)]);}
}
