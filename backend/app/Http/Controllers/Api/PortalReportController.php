<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortalReportService;
use App\Support\PortalReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PortalReportController extends Controller
{
    public function __construct(private PortalReportService $reports) {}

    public function definitions(Request $r)
    {
        return response()->json(['data' => $this->reports->options($r->user(), $r->route('portal'))]);
    }

    public function show(Request $r)
    {
        abort_unless(app(PortalReportRegistry::class)->allows($r->user(), $r->route('portal'), $r->route('report')), 403);
        $rules = ['college_id' => 'nullable|integer|min:1', 'program_id' => 'nullable|integer|min:1', 'academic_year_id' => 'nullable|integer|exists:academic_years,academic_year_id', 'semester_id' => 'nullable|integer|exists:semesters,semester_id', 'search' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100'];
        if (array_diff(array_keys($r->query()), array_keys($rules))) {
            throw ValidationException::withMessages(['query' => ['مرشحات غير معروفة.']]);
        }
        $f = $r->validate($rules);
        if (! empty($f['semester_id']) && empty($f['academic_year_id'])) {
            throw ValidationException::withMessages(['academic_year_id' => ['اختر السنة قبل الفصل.']]);
        }

        return response()->json(['data' => $this->reports->run($r->user(), $r->route('portal'), $r->route('report'), $f)]);
    }
}
