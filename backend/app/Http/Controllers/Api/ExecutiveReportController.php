<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecutiveReportFilterRequest;
use App\Http\Requests\ExecutiveReportQueryRequest;
use App\Services\ExecutiveReportFilterService;
use App\Services\ExecutiveReportQueryService;
use App\Support\ExecutiveReportAccess;
use App\Support\ExecutiveReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExecutiveReportController extends Controller
{
    public function __construct(private ExecutiveReportAccess $access, private ExecutiveReportFilterService $filters, private ExecutiveReportQueryService $reports) {}
    public function definitions(Request $request): JsonResponse { $this->access->authorize($request->user()); return $this->ok(['version'=>ExecutiveReportRegistry::VERSION,'subjects'=>ExecutiveReportRegistry::definitions(),'comparisons'=>ExecutiveReportRegistry::COMPARISONS,'limits'=>ExecutiveReportRegistry::LIMITS]); }
    public function filters(ExecutiveReportFilterRequest $request): JsonResponse { return $this->ok($this->filters->options($request->validated())); }
    public function query(ExecutiveReportQueryRequest $request): JsonResponse { return $this->ok($this->reports->run($request->validated())); }
    public function overview(Request $request): JsonResponse { $this->access->authorize($request->user()); return $this->ok($this->reports->overview()); }
    private function ok(mixed $data): JsonResponse { return response()->json(['success'=>true,'message'=>'Operation completed successfully','data'=>$data]); }
}
