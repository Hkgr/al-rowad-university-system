<?php

namespace App\Services\President;

use App\Services\Ministry\{MinistryAcademicService, MinistryDashboardService, MinistryQueries, MinistryStaffService, MinistryStudentService};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Composition of existing official read projections. Never calls a workflow mutation. */
final class PresidentReadService
{
    public function __construct(private MinistryDashboardService $dashboard, private MinistryAcademicService $academic,
        private MinistryStudentService $students, private MinistryStaffService $staff, private PresidentFollowupService $followup) {}

    public function dashboard(array $f): array
    {
        $d = $this->dashboard->build($f);
        $d = $this->links($d);
        $scope = array_filter(['college_id' => $f['college_id'] ?? null, 'program_id' => $f['program_id'] ?? null]);
        $d['counts']['programs']['link'] = $this->link('programs', $scope + ['active' => 1]);
        if ($d['period']['available']) {
            $period = $scope + ['academic_year_id' => $d['period']['academic_year']['id'], 'semester_id' => $d['period']['semester']['id'] ?? null];
            $d['period_metrics']['official_results']['link'] = $this->link('results', $period);
            $d['period_metrics']['offerings']['link'] = $this->link('exams', $period);
        }
        $d['followup'] = $this->followup->inbox();
        $college = $f['college_id'] ?? null;
        if (empty($f['college_id']) && ! empty($f['program_id'])) {
            $college = DB::table('academic_programs as ap')->join('departments as dp', 'dp.department_id', '=', 'ap.department_id')->where('ap.academic_program_id', $f['program_id'])->value('dp.college_id');
        }
        // Comparison is explicitly organizational/current, not a sum of overlapping faculty groups.
        $d['college_comparison'] = $this->academic->colleges(['college_id' => $college]);
        $d['comparison_note'] = 'مقارنة حالية للكليات؛ لا تتأثر بالسنة والفصل أو بمنهج تاريخي. قد ينتمي المدرس لأكثر من كلية، فلا تجمع أعداد المدرسين بين الكليات.';
        $d['generated_at'] = now()->utc()->toIso8601String();
        return $d;
    }

    public function listing(string $resource, array $f): array
    {
        return match ($resource) {
            'students' => $this->students->list($f),
            'faculty' => $this->staff->faculty($f),
            'deans' => $this->staff->deans($f),
            'courses' => $this->academic->courses($f),
            'colleges' => $this->pageCollection(collect($this->academic->colleges($f))->filter(fn ($r) => empty($f['search']) || mb_stripos($r['college_name'], $f['search']) !== false), $f),
            'programs' => $this->programs($f),
            'results' => $this->results($f),
            'exams' => $this->exams($f),
            'leadership' => $this->units($f),
            default => abort(404),
        };
    }

    public function detail(string $resource, string $id): array
    {
        $data = match ($resource) {
            'students' => $this->students->show((int) $id), 'faculty' => $this->staff->facultyMember((int) $id),
            'deans' => $this->staff->dean($id), 'courses' => $this->academic->course((int) $id),
            'colleges' => $this->academic->college((int) $id), 'leadership' => $this->staff->unit((int) $id),
            'programs' => $this->programs(['id' => (int) $id])['data']->first(),
            'results' => $this->results(['id' => (int) $id])['data']->first(),
            'exams' => $this->examDetail((int) $id), default => null,
        };
        abort_if($data === null, 404);
        $data = (array) $data;
        if ($resource === 'students') {
            unset($data['gender']);
            $data['publication_note'] = 'تُعرض النتائج المعتمدة رسميًا والفصول المعتمدة فقط؛ لا تعرض البوابة العلامات المسودة أو علامات الأجزاء أو ملاحظات المراجعة.';
            $ready = Schema::hasColumns('student_progression_decisions', ['student_progression_decision_id', 'student_id', 'status', 'decision_result', 'approved_at', 'materialized_at', 'superseded_at']);
            $data['progression'] = $ready ? DB::table('student_progression_decisions')->where('student_id', (int) $id)
                ->where('status', 'approved')->whereNotNull('materialized_at')->whereNull('superseded_at')
                ->orderByDesc('student_progression_decision_id')->get(['decision_result', 'approved_at']) : [];
            $data['progression_note'] = $ready ? 'قرارات الترفيع المعتمدة والنافذة فقط.' : 'قرارات الترفيع غير متاحة في المخطط الحالي.';
        }
        return $data;
    }

    private function programs(array $f): array
    {
        $q = DB::table('academic_programs as ap')->join('departments as d', 'd.department_id', '=', 'ap.department_id')->join('colleges as c', 'c.college_id', '=', 'd.college_id');
        if (isset($f['active'])) $q->where('ap.is_active', (bool) $f['active'])->when((bool) $f['active'], fn ($q) => $q->whereNull('ap.archived_at'));
        $q->when($f['college_id'] ?? null, fn ($q, $id) => $q->where('d.college_id', $id))
            ->when($f['program_id'] ?? $f['id'] ?? null, fn ($q, $id) => $q->where('ap.academic_program_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('ap.program_name', 'like', MinistryQueries::like($s)));
        return $this->page($q, $f, ['ap.academic_program_id as id', 'ap.program_name', 'ap.degree_level', 'ap.total_credit_hours', 'ap.is_active', 'ap.plan_state', 'c.college_name', 'd.department_name'], 'ap.academic_program_id');
    }

    private function results(array $f): array
    {
        $q = MinistryQueries::officialResults()->join('courses as cr', 'cr.course_id', '=', 'co.course_id');
        // Same student-current-program scope as MinistryDashboardService::officialResultsQuery.
        $q->when($f['college_id'] ?? null, fn ($q, $id) => $q->where('d.college_id', $id))
            ->when($f['program_id'] ?? null, fn ($q, $id) => $q->where('s.academic_program_id', $id));
        $this->period($q, $f);
        $q->when($f['id'] ?? null, fn ($q, $id) => $q->where('r.student_course_result_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($x) => $x->where('s.student_number', 'like', MinistryQueries::like($s))->orWhere('cr.course_code', 'like', MinistryQueries::like($s))));
        return $this->page($q, $f, ['r.student_course_result_id as id', 's.student_id', 's.student_number', 'cr.course_code', 'cr.course_name', 'co.academic_year_id', 'co.semester_id', 'r.final_mark', 'rst.status_code'], 'r.student_course_result_id');
    }

    private function offerings(array $f): Builder
    {
        $q = DB::table('course_offerings as co')->join('courses as cr', 'cr.course_id', '=', 'co.course_id');
        $this->period($q, $f);
        return $q->when($f['program_id'] ?? null, fn ($q, $id) => $q->where('co.academic_program_id', $id))
            ->when(empty($f['program_id']) && ! empty($f['college_id']), fn ($q) => $q->whereRaw(MinistryQueries::offeringCollegeSql().' = ?', [(int) $f['college_id']]))
            ->when($f['id'] ?? null, fn ($q, $id) => $q->where('co.course_offering_id', $id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($x) => $x->where('cr.course_name', 'like', MinistryQueries::like($s))->orWhere('cr.course_code', 'like', MinistryQueries::like($s))));
    }

    private function exams(array $f): array
    {
        $q = $this->offerings($f);
        $latest = DB::table('grade_approvals')->selectRaw('course_offering_id, MAX(grade_approval_id) as id')->groupBy('course_offering_id');
        $q->leftJoinSub($latest, 'latest', 'latest.course_offering_id', '=', 'co.course_offering_id')
            ->leftJoin('grade_approvals as ga', 'ga.grade_approval_id', '=', 'latest.id')->leftJoin('approval_statuses as ast', 'ast.approval_status_id', '=', 'ga.approval_status_id');
        return $this->page($q, $f, ['co.course_offering_id as id', 'cr.course_code', 'cr.course_name', 'co.academic_year_id', 'co.semester_id', 'co.status', DB::raw("COALESCE(ast.status_code, 'draft') as final_approval_status")], 'co.course_offering_id');
    }

    private function examDetail(int $id): ?array
    {
        $row = $this->exams(['id' => $id])['data']->first();
        if (! $row) return null;
        $ready = Schema::hasColumns('grade_components', ['course_offering_id', 'component_type', 'is_required']) && Schema::hasColumns('grade_part_approvals', ['course_offering_id', 'component_type', 'status']);
        $parts = $ready ? DB::table('grade_components as gc')->leftJoin('grade_part_approvals as pa', fn ($j) => $j->on('pa.course_offering_id', '=', 'gc.course_offering_id')->on('pa.component_type', '=', 'gc.component_type'))
            ->where('gc.course_offering_id', $id)->where('gc.is_required', 1)->whereIn('gc.component_type', ['theoretical', 'practical'])
            ->select('gc.component_type')->selectRaw("COALESCE(pa.status, 'draft') as status")->distinct()->orderBy('gc.component_type')->get() : [];
        return (array) $row + ['parts_available' => $ready, 'parts' => $parts, 'notice' => 'حالات الأجزاء للمتابعة وليست نتائج منشورة. النتائج الرسمية تتطلب أن يكون أحدث اعتماد نهائي معتمدًا. لا تعرض هذه الصفحة علامات المكونات.'];
    }

    private function units(array $f): array
    {
        $q = DB::table('organizational_units as u')->join('organizational_unit_types as t', 't.unit_type_id', '=', 'u.unit_type_id')
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('u.unit_name', 'like', MinistryQueries::like($s)));
        return $this->page($q, $f, ['u.organizational_unit_id as id', 'u.unit_name', 'u.unit_code', 'u.is_active', 't.type_name'], 'u.organizational_unit_id');
    }

    private function period(Builder $q, array $f): void
    {
        $q->when($f['academic_year_id'] ?? null, fn ($q, $id) => $q->where('co.academic_year_id', $id))
            ->when($f['semester_id'] ?? null, fn ($q, $id) => $q->where('co.semester_id', $id));
    }

    private function page(Builder $q, array $f, array $columns, string $key): array
    {
        $total = (clone $q)->count(); $size = $f['per_page'] ?? 25; $page = $f['page'] ?? 1;
        return ['data' => $q->orderBy($key)->forPage($page, $size)->get($columns), 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $size, 'last_page' => max(1, (int) ceil($total / $size))]];
    }

    private function pageCollection($rows, array $f): array
    {
        $total = $rows->count(); $size = $f['per_page'] ?? 25; $page = $f['page'] ?? 1;
        return ['data' => $rows->forPage($page, $size)->values(), 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $size, 'last_page' => max(1, (int) ceil($total / $size))]];
    }

    private function link(string $resource, array $f): string { return '/president/'.$resource.'?'.http_build_query(array_filter($f, fn ($v) => $v !== null && $v !== '')); }
    private function links(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) $data[$k] = $this->links($v);
            elseif ($v instanceof \Illuminate\Support\Collection) $data[$k] = $v->map(fn ($r) => is_array($r) ? $this->links($r) : $r);
            elseif ($k === 'link' && is_string($v) && str_starts_with($v, '/ministry/')) $data[$k] = '/president/'.substr($v, strlen('/ministry/'));
        }
        return $data;
    }
}
