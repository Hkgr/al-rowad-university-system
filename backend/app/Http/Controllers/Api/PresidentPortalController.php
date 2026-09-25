<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\President\{PresidentReadService, PresidentFollowupService};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PresidentPortalController extends Controller
{
    public const RESOURCES = ['colleges' => 'colleges', 'programs' => 'colleges', 'courses' => 'colleges', 'students' => 'students', 'faculty' => 'staff', 'deans' => 'staff', 'leadership' => 'leadership', 'exams' => 'exams', 'results' => 'exams'];
    public const FILTERS = [
        'colleges' => ['college_id', 'active'], 'programs' => ['college_id', 'program_id', 'active'],
        'students' => ['college_id', 'department_id', 'program_id', 'level_id', 'status', 'enrollment_year_id', 'registered_year_id', 'registered_semester_id', 'graduated_year_id'],
        'faculty' => ['college_id', 'active'], 'deans' => ['college_id', 'state'],
        'courses' => ['college_id', 'department_id', 'program_id', 'active', 'offered_year_id', 'offered_semester_id'],
        'leadership' => [], 'exams' => ['college_id', 'program_id', 'academic_year_id', 'semester_id'], 'results' => ['college_id', 'program_id', 'academic_year_id', 'semester_id'],
    ];

    public function __construct(private PresidentReadService $read, private PresidentFollowupService $followup) {}

    public function filters()
    {
        // Existing reference-data projection only, not a ministry authentication bypass.
        // Every president route has its own guard before this call; ministry routes remain untouched.
        return app(MinistryPortalController::class)->filters();
    }

    public function dashboard(Request $r) { return response()->json(['data' => $this->read->dashboard($this->input($r, ['academic_year_id', 'semester_id', 'college_id', 'program_id']))]); }
    public function index(Request $r) { $resource = $r->route('resource'); return response()->json($this->read->listing($resource, $this->input($r, self::FILTERS[$resource], true))); }
    public function show(Request $r) { return response()->json(['data' => $this->read->detail($r->route('resource'), $r->route('id'))]); }
    public function followup() { return response()->json(['data' => ['inbox' => $this->followup->inbox(), 'workflows' => $this->followup->definitions()]]); }
    public function workflow(Request $r, string $source) { return response()->json($this->followup->records($source, $this->input($r, ['status'], true))); }
    public function workflowRecord(Request $r, string $source, int $id) { return response()->json($this->followup->records($source, $this->input($r, []), $id)); }

    private function input(Request $r, array $fields, bool $paging = false): array
    {
        $rules = [];
        foreach ($fields as $field) $rules[$field] = match ($field) {
            'active' => ['nullable', 'boolean'], 'state' => ['nullable', 'in:current,historical'],
            'status' => ['nullable', 'string', 'max:50'], default => ['nullable', 'integer', 'min:1'],
        };
        if ($paging) $rules += ['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']];
        $unknown = array_diff(array_keys($r->query()), array_keys($rules));
        if ($unknown) throw ValidationException::withMessages(['query' => ['مرشحات غير مدعومة: '.implode(', ', $unknown)]]);
        $f = $r->validate($rules);
        foreach (['semester_id' => 'academic_year_id', 'registered_semester_id' => 'registered_year_id', 'offered_semester_id' => 'offered_year_id'] as $sem => $year) {
            if (! empty($f[$sem]) && empty($f[$year])) throw ValidationException::withMessages([$year => ['اختر السنة قبل الفصل.']]);
        }
        return $f;
    }
}
