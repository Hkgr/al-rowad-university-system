<?php

namespace App\Services\Ministry;

use App\Support\MinistryLabels;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Colleges (with departments/programs) and courses (definition vs plan inclusion vs offering). */
final class MinistryAcademicService
{
    public function __construct(private readonly MinistryStaffService $staff) {}

    // ── colleges ───────────────────────────────────────────────────────────

    public function colleges(array $filters = []): array
    {
        $colleges = MinistryQueries::colleges($filters)->orderBy('college_name')->orderBy('college_id')->get(['college_id', 'college_code', 'college_name', 'is_active']);
        $departments = DB::table('departments')->where('is_active', 1)->groupBy('college_id')->selectRaw('college_id as k, COUNT(*) as n')->pluck('n', 'k');
        $programs = DB::table('academic_programs as ap')->join('departments as d', 'd.department_id', '=', 'ap.department_id')
            ->where('ap.is_active', 1)->whereNull('ap.archived_at')->groupBy('d.college_id')->selectRaw('d.college_id as k, COUNT(*) as n')->pluck('n', 'k');
        $students = MinistryQueries::students()->groupBy('d.college_id')
            ->get([DB::raw('d.college_id as college_id'), DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN ss.status_code = 'active' THEN 1 ELSE 0 END) as active")])
            ->keyBy('college_id');
        $faculty = DB::query()->fromSub(MinistryQueries::facultyMembership(), 'm')
            ->join('faculty_members as fm', 'fm.employee_id', '=', 'm.employee_id')->where('fm.is_active', 1)
            ->groupBy('m.college_id')->selectRaw('m.college_id as k, COUNT(DISTINCT fm.faculty_member_id) as n')->pluck('n', 'k');
        $courses = DB::table('course_departments as cd')->join('departments as d', 'd.department_id', '=', 'cd.department_id')
            ->join('courses as crs', 'crs.course_id', '=', 'cd.course_id')->where('crs.is_active', 1)
            ->groupBy('d.college_id')->selectRaw('d.college_id as k, COUNT(DISTINCT cd.course_id) as n')->pluck('n', 'k');
        $deans = $this->currentDeansByCollege();

        return $colleges->map(fn ($c) => [
            'college_id' => (int) $c->college_id,
            'college_code' => $c->college_code,
            'college_name' => $c->college_name,
            'is_active' => (bool) $c->is_active,
            'departments' => (int) ($departments[$c->college_id] ?? 0),
            'programs' => (int) ($programs[$c->college_id] ?? 0),
            'students' => (int) ($students[$c->college_id]->total ?? 0),
            'active_students' => (int) ($students[$c->college_id]->active ?? 0),
            'faculty' => (int) ($faculty[$c->college_id] ?? 0),
            'courses' => (int) ($courses[$c->college_id] ?? 0),
            'deans' => $deans->get((int) $c->college_id, collect())->values(),
        ])->values()->all();
    }

    public function college(int $collegeId): ?array
    {
        $college = DB::table('colleges as c')->leftJoin('organizational_units as ou', 'ou.organizational_unit_id', '=', 'c.organizational_unit_id')
            ->where('c.college_id', $collegeId)->first(['c.college_id', 'c.college_code', 'c.college_name', 'c.description', 'c.is_active', 'c.organizational_unit_id', 'ou.unit_name']);
        if ($college === null) {
            return null;
        }
        $summary = collect($this->colleges())->firstWhere('college_id', $collegeId);
        $departments = DB::table('departments')->where('college_id', $collegeId)->orderBy('department_name')->get(['department_id', 'department_code', 'department_name', 'is_active']);
        $programs = DB::table('academic_programs as ap')->leftJoin('academic_plan_versions as v', 'v.academic_plan_version_id', '=', 'ap.default_academic_plan_version_id')
            ->whereIn('ap.department_id', $departments->pluck('department_id'))->orderBy('ap.program_name')
            ->get(['ap.academic_program_id', 'ap.department_id', 'ap.program_code', 'ap.program_name', 'ap.degree_level', 'ap.duration_years', 'ap.total_credit_hours',
                'ap.is_active', 'ap.archived_at', 'ap.plan_state', 'v.label as plan_label', 'v.version_number', 'v.status as plan_status']);
        $students = MinistryQueries::students()->where('d.college_id', $collegeId)->groupBy('s.academic_program_id')
            ->get([DB::raw('s.academic_program_id as program_id'), DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN ss.status_code = 'active' THEN 1 ELSE 0 END) as active")])
            ->keyBy('program_id');
        $planCourses = MinistryQueries::effectivePlanRows()->whereIn('pc.academic_program_id', $programs->pluck('academic_program_id'))
            ->groupBy('pc.academic_program_id')->selectRaw('pc.academic_program_id as k, COUNT(DISTINCT pc.course_id) as n')->pluck('n', 'k');
        $versions = DB::table('academic_plan_versions')->whereIn('academic_program_id', $programs->pluck('academic_program_id'))
            ->groupBy('academic_program_id')->selectRaw('academic_program_id as k, COUNT(*) as n')->pluck('n', 'k');

        $programRows = $programs->map(fn ($p) => [
            'program_id' => (int) $p->academic_program_id,
            'department_id' => (int) $p->department_id,
            'program_code' => $p->program_code,
            'program_name' => $p->program_name,
            'degree_level' => $p->degree_level,
            'duration_years' => $p->duration_years !== null ? (int) $p->duration_years : null,
            'total_credit_hours' => $p->total_credit_hours !== null ? (int) $p->total_credit_hours : null,
            'is_active' => (bool) $p->is_active && $p->archived_at === null,
            'plan' => $this->planLabel($p),
            'plan_versions' => (int) ($versions[$p->academic_program_id] ?? 0),
            'plan_courses' => $p->plan_state === 'legacy' || $p->plan_label !== null ? (int) ($planCourses[$p->academic_program_id] ?? 0) : null,
            'students' => (int) ($students[$p->academic_program_id]->total ?? 0),
            'active_students' => (int) ($students[$p->academic_program_id]->active ?? 0),
        ]);

        return [
            'college_id' => (int) $college->college_id,
            'college_code' => $college->college_code,
            'college_name' => $college->college_name,
            'description' => $college->description,
            'is_active' => (bool) $college->is_active,
            'organizational_unit' => $college->organizational_unit_id ? ['id' => (int) $college->organizational_unit_id, 'name' => $college->unit_name] : null,
            'summary' => $summary,
            'departments' => $departments->map(fn ($d) => [
                'department_id' => (int) $d->department_id,
                'department_code' => $d->department_code,
                'department_name' => $d->department_name,
                'is_active' => (bool) $d->is_active,
                'programs' => $programRows->where('department_id', (int) $d->department_id)->values(),
            ])->values(),
        ];
    }

    private function planLabel(object $p): array
    {
        if ($p->plan_state === 'legacy') {
            return ['state' => 'legacy', 'label' => 'خطة سابقة لنظام النسخ (غير مرقّمة)'];
        }
        if ($p->plan_label !== null) {
            return ['state' => 'versioned', 'label' => $p->plan_label.' (النسخة '.$p->version_number.')', 'status' => MinistryLabels::of(MinistryLabels::PLAN_STATUS, $p->plan_status)];
        }

        return ['state' => 'unset', 'label' => 'لا توجد خطة معتمدة افتراضية مسجلة'];
    }

    /** @return Collection<int, Collection> college_id => current deans (same rows as the deans list, name only) */
    public function currentDeansByCollege(): Collection
    {
        return $this->staff->deanRows()->where('state', 'current')
            ->groupBy(fn ($r) => (int) $r['college']['id'])
            ->map(fn ($rows) => $rows->map(fn ($r) => ['person' => $r['person'], 'full_name' => $r['full_name']])->values());
    }

    // ── courses ────────────────────────────────────────────────────────────

    public function courses(array $f): array
    {
        $perPage = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $q = MinistryQueries::courses($f);
        $total = (clone $q)->count();
        $rows = $q->orderBy('crs.course_code')->orderBy('crs.course_id')->forPage($page, $perPage)
            ->get(['crs.course_id', 'crs.course_code', 'crs.course_name', 'crs.credit_hours', 'crs.theoretical_hours', 'crs.practical_hours', 'crs.is_active']);
        $ids = $rows->pluck('course_id');
        $departments = DB::table('course_departments as cd')->join('departments as d', 'd.department_id', '=', 'cd.department_id')
            ->leftJoin('colleges as c', 'c.college_id', '=', 'd.college_id')->whereIn('cd.course_id', $ids)
            ->orderByDesc('cd.is_primary')->get(['cd.course_id', 'd.department_name', 'c.college_id', 'c.college_name'])->groupBy('course_id');
        $programs = MinistryQueries::effectivePlanRows()->whereIn('pc.course_id', $ids)->groupBy('pc.course_id')->selectRaw('pc.course_id as k, COUNT(DISTINCT pc.academic_program_id) as n')->pluck('n', 'k');
        $offerings = DB::table('course_offerings')->whereIn('course_id', $ids)
            ->when(! empty($f['offered_year_id']), fn (Builder $o) => $o->where('academic_year_id', (int) $f['offered_year_id']))
            ->when(! empty($f['offered_semester_id']), fn (Builder $o) => $o->where('semester_id', (int) $f['offered_semester_id']))
            ->groupBy('course_id')->selectRaw('course_id as k, COUNT(*) as n')->pluck('n', 'k');

        return [
            'data' => $rows->map(fn ($r) => [
                'course_id' => (int) $r->course_id,
                'course_code' => $r->course_code,
                'course_name' => $r->course_name,
                'credit_hours' => (int) $r->credit_hours,
                'theoretical_hours' => $r->theoretical_hours !== null ? (int) $r->theoretical_hours : null,
                'practical_hours' => $r->practical_hours !== null ? (int) $r->practical_hours : null,
                'is_active' => (bool) $r->is_active,
                'departments' => $departments->get($r->course_id, collect())->map(fn ($d) => ['name' => $d->department_name, 'college' => $d->college_name])->values(),
                'programs_in_plan' => (int) ($programs[$r->course_id] ?? 0),
                'offerings' => (int) ($offerings[$r->course_id] ?? 0),
            ])->values(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    public function course(int $courseId): ?array
    {
        $course = DB::table('courses')->where('course_id', $courseId)->first(['course_id', 'course_code', 'course_name', 'credit_hours', 'theoretical_hours', 'practical_hours', 'description', 'is_active']);
        if ($course === null) {
            return null;
        }
        $departments = DB::table('course_departments as cd')->join('departments as d', 'd.department_id', '=', 'cd.department_id')
            ->leftJoin('colleges as c', 'c.college_id', '=', 'd.college_id')->where('cd.course_id', $courseId)
            ->orderByDesc('cd.is_primary')->get(['d.department_id', 'd.department_name', 'c.college_id', 'c.college_name', 'cd.is_primary']);

        $effective = MinistryQueries::effectivePlanRows()->where('pc.course_id', $courseId)->pluck('pc.program_course_id')->map(fn ($id) => (int) $id);
        $plans = DB::table('program_courses as pc')
            ->join('academic_programs as ap', 'ap.academic_program_id', '=', 'pc.academic_program_id')
            ->leftJoin('departments as d', 'd.department_id', '=', 'ap.department_id')
            ->leftJoin('colleges as c', 'c.college_id', '=', 'd.college_id')
            ->leftJoin('academic_plan_versions as v', 'v.academic_plan_version_id', '=', 'pc.academic_plan_version_id')
            ->leftJoin('academic_levels as al', 'al.academic_level_id', '=', 'pc.academic_level_id')
            ->leftJoin('semesters as sm', 'sm.semester_id', '=', 'pc.recommended_semester_id')
            ->where('pc.course_id', $courseId)->where('pc.is_active', 1)
            ->orderBy('ap.program_name')->orderBy('v.version_number')
            ->get(['pc.program_course_id', 'ap.academic_program_id', 'ap.program_name', 'c.college_name', 'v.label', 'v.version_number', 'v.status',
                'al.level_order', 'al.level_name', 'sm.semester_code', 'sm.semester_name', 'pc.course_type']);

        $offerings = DB::table('course_offerings as co')
            ->join('academic_years as y', 'y.academic_year_id', '=', 'co.academic_year_id')
            ->join('semesters as sm', 'sm.semester_id', '=', 'co.semester_id')
            ->leftJoin('academic_programs as ap', 'ap.academic_program_id', '=', 'co.academic_program_id')
            ->where('co.course_id', $courseId)->orderByDesc('y.start_date')->orderBy('sm.semester_order')->orderBy('co.course_offering_id')
            ->get(['co.course_offering_id', 'y.year_name', 'sm.semester_code', 'sm.semester_name', 'ap.program_name', 'co.status', 'co.capacity']);
        $offeringIds = $offerings->pluck('course_offering_id');
        $registered = MinistryQueries::countedRegistrations()->whereIn('scr.course_offering_id', $offeringIds)
            ->groupBy('scr.course_offering_id')->selectRaw('scr.course_offering_id as k, COUNT(DISTINCT scr.student_id) as n')->pluck('n', 'k');
        $instructors = DB::table('course_offering_instructors as coi')->join('faculty_members as fm', 'fm.faculty_member_id', '=', 'coi.faculty_member_id')
            ->join('employees as e', 'e.employee_id', '=', 'fm.employee_id')
            ->whereIn('coi.course_offering_id', $offeringIds)->where('coi.is_active', 1)
            ->orderByDesc('coi.is_primary')->get(['coi.course_offering_id', 'fm.faculty_member_id', 'coi.instructor_role', DB::raw(MinistryQueries::fullName('e').' as full_name')])
            ->groupBy('course_offering_id');
        $approved = MinistryQueries::officiallyApprovedOfferings()->whereIn('ga.course_offering_id', $offeringIds)->pluck('ga.course_offering_id')->map(fn ($id) => (int) $id);

        return [
            'course_id' => (int) $course->course_id,
            'course_code' => $course->course_code,
            'course_name' => $course->course_name,
            'credit_hours' => (int) $course->credit_hours,
            'theoretical_hours' => $course->theoretical_hours !== null ? (int) $course->theoretical_hours : null,
            'practical_hours' => $course->practical_hours !== null ? (int) $course->practical_hours : null,
            'description' => $course->description,
            'is_active' => (bool) $course->is_active,
            'departments' => $departments->map(fn ($d) => [
                'department_id' => (int) $d->department_id, 'department_name' => $d->department_name,
                'college' => $d->college_id ? ['id' => (int) $d->college_id, 'name' => $d->college_name] : null, 'is_primary' => (bool) $d->is_primary,
            ])->values(),
            'plan_inclusions' => $plans->map(fn ($p) => [
                'program' => ['id' => (int) $p->academic_program_id, 'name' => $p->program_name, 'college' => $p->college_name],
                'plan' => $p->version_number !== null ? $p->label.' (النسخة '.$p->version_number.' — '.MinistryLabels::of(MinistryLabels::PLAN_STATUS, $p->status).')' : 'خطة سابقة لنظام النسخ',
                'in_effective_plan' => $effective->contains((int) $p->program_course_id),
                'level' => MinistryLabels::level($p->level_order !== null ? (int) $p->level_order : null, $p->level_name),
                'semester' => MinistryLabels::semester($p->semester_code, $p->semester_name),
                'course_type' => MinistryLabels::of(MinistryLabels::COURSE_TYPE, $p->course_type),
            ])->values(),
            'offerings' => $offerings->map(fn ($o) => [
                'offering_id' => (int) $o->course_offering_id,
                'term' => $o->year_name.' — '.MinistryLabels::semester($o->semester_code, $o->semester_name),
                'program' => $o->program_name,
                'status' => MinistryLabels::of(MinistryLabels::OFFERING_STATUS, $o->status),
                'capacity' => (int) $o->capacity,
                'registered_students' => (int) ($registered[$o->course_offering_id] ?? 0),
                'grades_officially_approved' => $approved->contains((int) $o->course_offering_id),
                'instructors' => $instructors->get($o->course_offering_id, collect())->map(fn ($i) => [
                    'faculty_member_id' => (int) $i->faculty_member_id, 'full_name' => $i->full_name, 'role' => MinistryLabels::of(MinistryLabels::INSTRUCTOR_ROLE, $i->instructor_role),
                ])->values(),
            ])->values(),
        ];
    }
}
