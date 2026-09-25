<?php

namespace App\Services\Ministry;

use App\Support\MinistryLabels;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * University-wide indicators for the ministry home page. Every count reuses the
 * MinistryQueries builders that the linked list uses, so "click the number → list
 * total" holds by construction (and is asserted in the feature tests).
 */
final class MinistryDashboardService
{
    public function __construct(private readonly MinistryStaffService $staff) {}

    public const VICE_PRESIDENT_ROLES = ['vice_president_scientific', 'vice_president_administrative', 'vice_president'];

    /** @param array{academic_year_id?:int|null, semester_id?:int|null, college_id?:int|null, program_id?:int|null} $input */
    public function build(array $input): array
    {
        [$scope, $applied] = $this->resolveScope($input);
        $period = $this->resolvePeriod($input);

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'filters_applied' => $applied + ['period' => $period['label'] ?? null],
            'period' => $period,
            'counts' => $this->counts($scope),
            'period_metrics' => $period['available'] ? $this->periodMetrics($scope, $period) : null,
            'distributions' => $this->distributions($scope),
            'trends' => $this->trends($scope),
            'unavailable' => self::UNAVAILABLE,
        ];
    }

    /** Indicators the current data cannot support with confidence; shown as such, never as numbers. */
    public const UNAVAILABLE = [
        ['code' => 'dropout_rate', 'label' => 'نسبة التسرب', 'reason' => 'لا يحفظ النظام سجلًا تاريخيًا لتغيّرات حالة الطالب؛ تتوفر الحالة الحالية فقط.'],
        ['code' => 'status_history', 'label' => 'توزيع الحالات في سنوات سابقة', 'reason' => 'الحالة لقطة حالية، ولا يمكن إعادة بنائها لتاريخ سابق.'],
        ['code' => 'faculty_by_program', 'label' => 'المدرسون حسب البرنامج', 'reason' => 'انتماء المدرس مسجل على مستوى الكلية لا البرنامج.'],
        ['code' => 'graduation_rate', 'label' => 'نسبة التخرج في المدة النظامية', 'reason' => 'تتطلب دفعات قبول مكتملة وقرارات تخرج لكل دفعة؛ البيانات الحالية لا تكفي لحسابها بثقة.'],
    ];

    // ── scope & period ─────────────────────────────────────────────────────

    private function resolveScope(array $input): array
    {
        $collegeId = ! empty($input['college_id']) ? (int) $input['college_id'] : null;
        $programId = ! empty($input['program_id']) ? (int) $input['program_id'] : null;
        $applied = ['college' => null, 'program' => null];
        if ($collegeId !== null) {
            $college = DB::table('colleges')->where('college_id', $collegeId)->first(['college_id', 'college_name']);
            if ($college === null) {
                throw ValidationException::withMessages(['college_id' => ['الكلية غير موجودة.']]);
            }
            $applied['college'] = ['id' => $collegeId, 'name' => $college->college_name];
        }
        $programCollegeId = null;
        if ($programId !== null) {
            $program = DB::table('academic_programs as ap')->leftJoin('departments as d', 'd.department_id', '=', 'ap.department_id')
                ->where('ap.academic_program_id', $programId)->first(['ap.academic_program_id', 'ap.program_name', 'd.college_id']);
            if ($program === null) {
                throw ValidationException::withMessages(['program_id' => ['البرنامج غير موجود.']]);
            }
            if ($collegeId !== null && (int) $program->college_id !== $collegeId) {
                throw ValidationException::withMessages(['program_id' => ['البرنامج المختار لا يتبع الكلية المختارة.']]);
            }
            $programCollegeId = $program->college_id !== null ? (int) $program->college_id : null;
            $applied['program'] = ['id' => $programId, 'name' => $program->program_name];
        }

        return [['college_id' => $collegeId, 'program_id' => $programId, 'program_college_id' => $programCollegeId], $applied];
    }

    private function resolvePeriod(array $input): array
    {
        $yearId = ! empty($input['academic_year_id']) ? (int) $input['academic_year_id'] : null;
        $semesterId = ! empty($input['semester_id']) ? (int) $input['semester_id'] : null;
        $source = 'selected';
        if ($yearId === null) {
            $current = DB::table('academic_years')->where('is_current', 1)->where('is_active', 1)->pluck('academic_year_id');
            if ($current->count() !== 1) {
                if ($semesterId !== null) {
                    throw ValidationException::withMessages(['academic_year_id' => ['اختر السنة الأكاديمية قبل الفصل.']]);
                }

                return ['available' => false, 'reason' => 'لا توجد سنة أكاديمية حالية واحدة محددة في النظام؛ اختر سنة لعرض مؤشرات الفترة.'];
            }
            $yearId = (int) $current->first();
            $source = 'current';
        }
        $year = MinistryQueries::year($yearId);
        $semester = null;
        if ($semesterId !== null) {
            $semester = DB::table('semesters')->where('semester_id', $semesterId)->first(['semester_id', 'semester_code', 'semester_name']);
            if ($semester === null) {
                throw ValidationException::withMessages(['semester_id' => ['الفصل غير موجود.']]);
            }
        }
        $semesterLabel = $semester ? MinistryLabels::semester($semester->semester_code, $semester->semester_name) : null;

        return [
            'available' => true,
            'source' => $source,
            'academic_year' => ['id' => (int) $year->academic_year_id, 'name' => $year->year_name, 'start_date' => $year->start_date, 'end_date' => $year->end_date],
            'semester' => $semester ? ['id' => (int) $semester->semester_id, 'name' => $semesterLabel] : null,
            'label' => $year->year_name.($semesterLabel ? ' — '.$semesterLabel : ' — كل الفصول').($source === 'current' ? ' (السنة الحالية)' : ''),
        ];
    }

    // ── counts ─────────────────────────────────────────────────────────────

    private function counts(array $scope): array
    {
        $studentFilters = $this->studentScope($scope);
        $facultyCollege = $scope['college_id'] ?? $scope['program_college_id'];
        $courseFilters = ['college_id' => $scope['college_id'], 'program_id' => $scope['program_id'], 'active' => 1];

        $colleges = MinistryQueries::colleges(['college_id' => $facultyCollege]);
        $departments = DB::table('departments')->where('is_active', 1)->when($facultyCollege, fn (Builder $q) => $q->where('college_id', $facultyCollege));
        $programs = DB::table('academic_programs as ap')->join('departments as d', 'd.department_id', '=', 'ap.department_id')
            ->where('ap.is_active', 1)->whereNull('ap.archived_at')
            ->when($scope['college_id'], fn (Builder $q) => $q->where('d.college_id', $scope['college_id']))
            ->when($scope['program_id'], fn (Builder $q) => $q->where('ap.academic_program_id', $scope['program_id']));

        $currentDeans = $this->staff->deanRows()->where('state', 'current')
            ->when($facultyCollege, fn ($rows) => $rows->where('college.id', $facultyCollege));
        $activeColleges = (clone $colleges)->where('is_active', 1)->pluck('college_id');
        $collegesWithDean = $currentDeans->pluck('college.id')->unique();

        $vicePresidents = DB::table('user_roles as ur')->join('roles as r', 'r.role_id', '=', 'ur.role_id')
            ->join('users as u', 'u.user_id', '=', 'ur.user_id')
            ->join('account_statuses as ast', 'ast.account_status_id', '=', 'u.account_status_id')
            ->whereIn('r.role_code', self::VICE_PRESIDENT_ROLES)->where('r.is_active', 1)->where('ur.is_active', 1)->where('ast.status_code', 'active');

        $query = $this->query($scope);

        return [
            'colleges' => ['value' => $activeColleges->count(), 'inactive' => (clone $colleges)->where('is_active', 0)->count(), 'link' => $this->link('colleges', ['college_id' => $facultyCollege, 'active' => 1])],
            'departments' => ['value' => $departments->count(), 'link' => null],
            'programs' => ['value' => $programs->count(), 'link' => null],
            'students' => ['value' => MinistryQueries::filterStudents(MinistryQueries::students(), $studentFilters)->count(), 'link' => $this->link('students', $query)],
            'active_students' => [
                'value' => MinistryQueries::filterStudents(MinistryQueries::students(), $studentFilters + ['status' => 'active'])->count(),
                'link' => $this->link('students', $query + ['status' => 'active']),
            ],
            'faculty' => [
                'value' => MinistryQueries::faculty(['college_id' => $facultyCollege, 'active' => 1])->count(),
                'link' => $this->link('faculty', ['college_id' => $facultyCollege, 'active' => 1]),
                'note' => $scope['program_id'] ? 'انتماء المدرس مسجل على مستوى الكلية؛ يُعرض عدد مدرسي كلية البرنامج.' : null,
            ],
            'deans' => [
                'value' => $currentDeans->count(),
                'people' => $currentDeans->pluck('person')->unique()->count(),
                'colleges_without_dean' => $activeColleges->reject(fn ($id) => $collegesWithDean->contains((int) $id))->count(),
                'link' => $this->link('deans', ['college_id' => $facultyCollege, 'state' => 'current']),
            ],
            'vice_presidents' => ['value' => (clone $vicePresidents)->distinct()->count('ur.user_id'), 'link' => null],
            'courses' => ['value' => MinistryQueries::courses($courseFilters)->count(), 'link' => $this->link('courses', $query + ['active' => 1])],
        ];
    }

    private function periodMetrics(array $scope, array $period): array
    {
        $yearId = $period['academic_year']['id'];
        $semesterId = $period['semester']['id'] ?? null;
        $query = $this->query($scope);

        $registered = MinistryQueries::filterStudents(MinistryQueries::students(), $this->studentScope($scope) + ['registered_year_id' => $yearId, 'registered_semester_id' => $semesterId])->count();
        $offerings = DB::table('course_offerings as co')->where('co.academic_year_id', $yearId)
            ->when($semesterId, fn (Builder $q) => $q->where('co.semester_id', $semesterId))
            ->when($scope['program_id'], fn (Builder $q) => $q->where('co.academic_program_id', $scope['program_id']))
            ->when(! $scope['program_id'] && $scope['college_id'], fn (Builder $q) => $q->whereRaw(MinistryQueries::offeringCollegeSql().' = ?', [$scope['college_id']]))
            ->count();
        $offeredCourses = MinistryQueries::courses(['college_id' => $scope['college_id'], 'program_id' => $scope['program_id'], 'offered_year_id' => $yearId, 'offered_semester_id' => $semesterId])->count();

        $results = $this->officialResultsQuery($scope)->where('co.academic_year_id', $yearId)
            ->when($semesterId, fn (Builder $q) => $q->where('co.semester_id', $semesterId))
            ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN rst.status_code = 'passed' THEN 1 ELSE 0 END) AS passed, SUM(CASE WHEN rst.status_code = 'failed' THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN rst.status_code = 'deprived' THEN 1 ELSE 0 END) AS deprived, SUM(CASE WHEN rst.status_code NOT IN ('passed','failed','deprived') THEN 1 ELSE 0 END) AS other, COUNT(DISTINCT scr.student_id) AS students")
            ->first();
        $total = (int) ($results->total ?? 0);

        $graduates = $semesterId === null
            ? MinistryQueries::filterStudents(MinistryQueries::students(), $this->studentScope($scope) + ['graduated_year_id' => $yearId])->count()
            : null;

        return [
            'registered_students' => [
                'value' => $registered,
                'link' => $this->link('students', $query + ['registered_year_id' => $yearId, 'registered_semester_id' => $semesterId]),
            ],
            'offerings' => ['value' => $offerings],
            'offered_courses' => [
                'value' => $offeredCourses,
                'link' => $this->link('courses', array_intersect_key($query, ['college_id' => 1, 'program_id' => 1]) + ['offered_year_id' => $yearId, 'offered_semester_id' => $semesterId]),
            ],
            'official_results' => [
                'value' => $total,
                'students' => (int) ($results->students ?? 0),
                'passed' => (int) ($results->passed ?? 0),
                'failed' => (int) ($results->failed ?? 0),
                'deprived' => (int) ($results->deprived ?? 0),
                'other' => (int) ($results->other ?? 0),
                'pass_rate' => $total > 0 ? round(100 * (int) $results->passed / $total, 1) : null,
            ],
            'graduates' => $graduates === null
                ? ['value' => null, 'reason' => 'قرارات التخرج تُنسب إلى السنة الأكاديمية لا إلى الفصل؛ أزل مرشح الفصل لعرضها.']
                : ['value' => $graduates, 'link' => $this->link('students', $query + ['graduated_year_id' => $yearId])],
        ];
    }

    // ── distributions (current snapshot) ───────────────────────────────────

    private function distributions(array $scope): array
    {
        $base = fn () => MinistryQueries::filterStudents(MinistryQueries::students(), $this->studentScope($scope));
        $query = $this->query($scope);

        $byCollege = $base()->groupBy('c.college_id', 'c.college_name')->orderByDesc(DB::raw('COUNT(*)'))
            ->get([DB::raw('c.college_id as id'), DB::raw('c.college_name as name'), DB::raw('COUNT(*) as total')])
            ->map(fn ($r) => ['key' => $r->id === null ? 'none' : (string) $r->id, 'label' => $r->name ?? 'غير محدد', 'total' => (int) $r->total,
                'link' => $r->id === null ? null : $this->link('students', $query + ['college_id' => (int) $r->id])])->values();
        $byProgram = $base()->groupBy('ap.academic_program_id', 'ap.program_name', 'c.college_name')->orderByDesc(DB::raw('COUNT(*)'))
            ->get([DB::raw('ap.academic_program_id as id'), DB::raw('ap.program_name as name'), DB::raw('c.college_name as college'), DB::raw('COUNT(*) as total')])
            ->map(fn ($r) => ['key' => (string) $r->id, 'label' => $r->name ?? 'غير محدد', 'college' => $r->college, 'total' => (int) $r->total,
                'link' => $this->link('students', $query + ['program_id' => (int) $r->id])])->values();
        $byStatus = $base()->groupBy('ss.status_code', 'ss.status_name')->orderByDesc(DB::raw('COUNT(*)'))
            ->get([DB::raw('ss.status_code as code'), DB::raw('ss.status_name as name'), DB::raw('COUNT(*) as total')])
            ->map(fn ($r) => ['key' => (string) $r->code, 'label' => MinistryLabels::studentStatus($r->code, $r->name), 'total' => (int) $r->total,
                'link' => $this->link('students', $query + ['status' => $r->code])])->values();
        $byLevel = $base()->groupBy('al.academic_level_id', 'al.level_order', 'al.level_name')->orderBy('al.level_order')
            ->get([DB::raw('al.academic_level_id as id'), DB::raw('al.level_order as level_order'), DB::raw('al.level_name as name'), DB::raw('COUNT(*) as total')])
            ->map(fn ($r) => ['key' => (string) $r->id, 'label' => MinistryLabels::level($r->level_order !== null ? (int) $r->level_order : null, $r->name), 'total' => (int) $r->total,
                'link' => $this->link('students', $query + ['level_id' => (int) $r->id])])->values();

        return ['by_college' => $byCollege, 'by_program' => $byProgram, 'by_status' => $byStatus, 'by_level' => $byLevel];
    }

    // ── trends (all academic years) ────────────────────────────────────────

    private function trends(array $scope): array
    {
        $years = DB::table('academic_years')->orderBy('start_date')->get(['academic_year_id', 'year_name', 'start_date', 'end_date']);
        $semesters = DB::table('semesters')->orderBy('semester_order')->get(['semester_id', 'semester_code', 'semester_name']);
        $semesterLabel = $semesters->mapWithKeys(fn ($s) => [(int) $s->semester_id => MinistryLabels::semester($s->semester_code, $s->semester_name)]);
        $studentScope = $this->studentScope($scope);

        $intake = MinistryQueries::filterStudents(MinistryQueries::students(), $studentScope)
            ->join('academic_years as ty', fn ($j) => $j->on('s.enrollment_date', '>=', 'ty.start_date')->on('s.enrollment_date', '<=', 'ty.end_date'))
            ->groupBy('ty.academic_year_id')->selectRaw('ty.academic_year_id as k, COUNT(*) as n')->pluck('n', 'k');
        $graduates = MinistryQueries::filterStudents(MinistryQueries::students(), $studentScope)
            ->join('student_graduation_decisions as g', 'g.student_id', '=', 's.student_id')
            ->where('g.status', 'approved')->whereNotNull('g.materialized_at')->whereNull('g.superseded_at')
            ->join('academic_years as gy', fn ($j) => $j->whereRaw('g.approved_at >= gy.start_date')->whereRaw('g.approved_at < '.$this->dayAfter('gy.end_date')))
            ->groupBy('gy.academic_year_id')->selectRaw('gy.academic_year_id as k, COUNT(DISTINCT s.student_id) as n')->pluck('n', 'k');

        $registrations = MinistryQueries::filterStudents(MinistryQueries::students(), $studentScope)
            ->join('student_course_registrations as scr', 'scr.student_id', '=', 's.student_id')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->join('course_offerings as co', 'co.course_offering_id', '=', 'scr.course_offering_id')
            ->whereIn('rs.status_code', MinistryQueries::COUNTED_REGISTRATION_STATUSES)
            ->groupBy('co.academic_year_id', 'co.semester_id')
            ->get([DB::raw('co.academic_year_id as year_id'), DB::raw('co.semester_id as semester_id'), DB::raw('COUNT(DISTINCT s.student_id) as students')]);
        $results = $this->officialResultsQuery($scope)->groupBy('co.academic_year_id', 'co.semester_id')
            ->get([DB::raw('co.academic_year_id as year_id'), DB::raw('co.semester_id as semester_id'), DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN rst.status_code = 'passed' THEN 1 ELSE 0 END) as passed")]);

        $yearName = $years->mapWithKeys(fn ($y) => [(int) $y->academic_year_id => $y->year_name]);
        $yearOrder = $years->pluck('academic_year_id')->map(fn ($id) => (int) $id)->flip();
        $termRow = fn ($r) => ['year_id' => (int) $r->year_id, 'semester_id' => (int) $r->semester_id,
            'label' => ($yearName[(int) $r->year_id] ?? '—').' — '.($semesterLabel[(int) $r->semester_id] ?? '—')];
        $sortTerms = fn ($rows) => $rows->sortBy(fn ($r) => sprintf('%05d-%05d', $yearOrder[$r['year_id']] ?? 99999, $semesters->search(fn ($s) => (int) $s->semester_id === $r['semester_id'])))->values();

        return [
            'intake_by_year' => $years->map(fn ($y) => ['key' => (string) $y->academic_year_id, 'label' => $y->year_name, 'total' => (int) ($intake[$y->academic_year_id] ?? 0)])->values(),
            'graduates_by_year' => $years->map(fn ($y) => ['key' => (string) $y->academic_year_id, 'label' => $y->year_name, 'total' => (int) ($graduates[$y->academic_year_id] ?? 0)])->values(),
            'registrations_by_term' => $sortTerms($registrations->map(fn ($r) => $termRow($r) + ['total' => (int) $r->students])),
            'official_results_by_term' => $sortTerms($results->map(fn ($r) => $termRow($r) + [
                'total' => (int) $r->total, 'passed' => (int) $r->passed,
                'pass_rate' => (int) $r->total > 0 ? round(100 * (int) $r->passed / (int) $r->total, 1) : null,
            ])),
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function officialResultsQuery(array $scope): Builder
    {
        return MinistryQueries::officialResults()
            ->when($scope['college_id'], fn (Builder $q) => $q->where('d.college_id', $scope['college_id']))
            ->when($scope['program_id'], fn (Builder $q) => $q->where('s.academic_program_id', $scope['program_id']));
    }

    private function studentScope(array $scope): array
    {
        return ['college_id' => $scope['college_id'], 'program_id' => $scope['program_id']];
    }

    private function query(array $scope): array
    {
        return array_filter(['college_id' => $scope['college_id'], 'program_id' => $scope['program_id']]);
    }

    private function link(string $page, array $query): string
    {
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');

        return '/ministry/'.$page.($query === [] ? '' : '?'.http_build_query($query));
    }

    private function dayAfter(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "date({$column}, '+1 day')" : "DATE_ADD({$column}, INTERVAL 1 DAY)";
    }
}
