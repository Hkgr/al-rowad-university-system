<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Http\Controllers\Controller;
use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Services\MinimumEnrollmentReviewService;
use App\Support\SemesterRegistrationPhase6;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScientificMinimumEnrollmentController extends Controller
{
    public function __construct(private MinimumEnrollmentReviewService $service) {}
    public function index(Request $request): JsonResponse
    {
        $data=$request->validate(['academic_year_id'=>['required','integer','min:1'],'semester_id'=>['required','integer','min:1'],'status'=>['sometimes','string'],'per_page'=>['sometimes','integer','min:1','max:100']]);
        $page=$this->service->query($request->user(),$data['academic_year_id'],$data['semester_id'],true)->when($data['status']??null,fn($query,$status)=>$query->where('status',$status))->orderBy('course_offering_id')->paginate($data['per_page']??20);
        $page->getCollection()->transform(fn($review)=>$this->service->payload($review));
        return response()->json(['success'=>true,'data'=>$page]);
    }
    public function show(Request $request,int $review):JsonResponse{$this->service->assertScientificAccess($request->user());$row=$this->review($review);return response()->json(['success'=>true,'data'=>$this->service->payload($row)]);}
    public function decide(Request $request,int $review):JsonResponse{$data=$request->validate(['decision'=>['required',Rule::in(['continue','cancel'])],'notes'=>['required','string','min:8','max:2000']]);$this->service->assertScientificAccess($request->user());return response()->json(['success'=>true,'data'=>$this->service->decide($request->user(),$this->review($review),$data['decision'],$data['notes'])]);}
    private function review(int $id):CourseOfferingMinimumEnrollmentReview{if(!SemesterRegistrationPhase6::schemaReady())throw SemesterRegistrationPhase6Exception::minimumSchema();return CourseOfferingMinimumEnrollmentReview::query()->findOrFail($id);}
}
