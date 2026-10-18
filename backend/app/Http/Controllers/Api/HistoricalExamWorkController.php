<?php

namespace App\Http\Controllers\Api;

use App\Services\HistoricalExams\HistoricalExamWorkService;
use Illuminate\Http\Request;

/** Existing Exam Affairs authorization only. Private source evidence never enters student endpoints. */
final class HistoricalExamWorkController extends ActionApiController
{
    public function index(Request $request, HistoricalExamWorkService $service)
    {
        $input=$request->validate(['kind'=>['sometimes','in:ungraded_followup,aggregate_incomplete_followup,correction_review,final_absence_review'],
            'student_id'=>['sometimes','integer','min:1'],'academic_year_id'=>['sometimes','integer','min:1'],
            'semester_id'=>['sometimes','integer','min:1'],'page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);
        if(array_diff(array_keys($request->query()),['kind','student_id','academic_year_id','semester_id','page','per_page']))throw \Illuminate\Validation\ValidationException::withMessages(['request'=>'Unknown filter.']);
        return $this->successResponse($service->listing($request->user(),$input));
    }
    public function show(Request $request,string $work,HistoricalExamWorkService $service)
    {
        return $this->successResponse($service->details($request->user(),$work));
    }
}
