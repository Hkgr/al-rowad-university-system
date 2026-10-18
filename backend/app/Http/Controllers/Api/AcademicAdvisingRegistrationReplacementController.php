<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SemesterRegistrationPhase6Exception;
use App\Http\Controllers\Controller;
use App\Models\StudentRegistrationReplacementRequest;
use App\Services\RegistrationReplacementService;
use App\Support\SemesterRegistrationPhase6;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicAdvisingRegistrationReplacementController extends Controller
{
    public function __construct(private RegistrationReplacementService $service) {}
    public function index(Request $r):JsonResponse{$v=$r->validate(['status'=>['sometimes','string'],'per_page'=>['sometimes','integer','min:1','max:100']]);return $this->ok($this->service->advisorIndex($r->user(),$v['status']??null,$v['per_page']??20));}
    public function show(Request $r,int $replacement):JsonResponse{$this->service->assertAdvisorViewAccess($r->user());return $this->ok($this->service->advisorShow($r->user(),$this->request($replacement)));}
    public function returnForModification(Request $r,int $replacement):JsonResponse{$v=$r->validate(['advisor_notes'=>['required','string','min:8','max:2000']]);$this->service->assertAdvisorReviewAccess($r->user());return $this->ok($this->service->returnForModification($r->user(),$this->request($replacement),$v['advisor_notes']));}
    public function approve(Request $r,int $replacement):JsonResponse{$this->service->assertAdvisorReviewAccess($r->user());return $this->ok($this->service->approve($r->user(),$this->request($replacement)));}
    private function request(int $id):StudentRegistrationReplacementRequest{if(!SemesterRegistrationPhase6::schemaReady())throw SemesterRegistrationPhase6Exception::replacementSchema();return StudentRegistrationReplacementRequest::query()->findOrFail($id);}
    private function ok($data):JsonResponse{return response()->json(['success'=>true,'data'=>$data]);}
}
