<?php

namespace App\Services\Ministry;

use App\Support\MinistryLabels;
use Illuminate\Support\Facades\DB;

/**
 * Student list and academic detail for the ministry. Only identity fields needed to
 * identify a student academically leave the server: no contact data, date of birth,
 * address, national/parent data, internal notes or deregistration reasons. Results are
 * limited to officially approved (published) grades; component marks are never exposed.
 */
final class MinistryStudentService
{
    public function list(array $f): array
    {
        $perPage = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $q = MinistryQueries::filterStudents(MinistryQueries::students(), $f);
        $total = (clone $q)->count();
        $rows = $q->orderBy('s.student_number')->orderBy('s.student_id')->forPage($page, $perPage)->get([
            's.student_id', 's.student_number', DB::raw(MinistryQueries::fullName('s', true).' as full_name'), 's.gender', 's.enrollment_date',
            'ap.academic_program_id', 'ap.program_name', 'd.department_id', 'd.department_name', 'c.college_id', 'c.college_name',
            'ss.status_code', 'ss.status_name', 'al.level_order', 'al.level_name',
        ]);

        return [
            'data' => $rows->map(fn ($r) => [
                'student_id' => (int) $r->student_id,
                'student_number' => $r->student_number,
                'full_name' => $r->full_name,
                'program' => $r->academic_program_id ? ['id' => (int) $r->academic_program_id, 'name' => $r->program_name] : null,
                'department' => $r->department_id ? ['id' => (int) $r->department_id, 'name' => $r->department_name] : null,
                'college' => $r->college_id ? ['id' => (int) $r->college_id, 'name' => $r->college_name] : null,
                'status' => ['code' => $r->status_code, 'label' => MinistryLabels::studentStatus($r->status_code, $r->status_name)],
                'level' => MinistryLabels::level($r->level_order !== null ? (int) $r->level_order : null, $r->level_name),
                'enrollment_date' => $r->enrollment_date,
            ])->values(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    public function show(int $studentId): ?array
    {
        $r = MinistryQueries::students()->where('s.student_id', $studentId)->first([
            's.student_id', 's.student_number', DB::raw(MinistryQueries::fullName('s', true).' as full_name'), 's.gender', 's.enrollment_date',
            'ap.academic_program_id', 'ap.program_name', 'ap.degree_level', 'ap.total_credit_hours', 'ap.plan_state',
            'd.department_id', 'd.department_name', 'c.college_id', 'c.college_name',
            'ss.status_code', 'ss.status_name', 'al.level_order', 'al.level_name',
        ]);
        if ($r === null) {
            return null;
        }

        $plan = DB::table('student_academic_plan_assignments as spa')
            ->join('academic_plan_versions as v', 'v.academic_plan_version_id', '=', 'spa.academic_plan_version_id')
            ->where('spa.student_id', $studentId)->whereNull('spa.ended_at')
            ->orderByDesc('spa.assigned_at')->first(['v.version_number', 'v.label', 'v.status']);

        $terms = DB::table('student_academic_terms as t')
            ->join('academic_years as y', 'y.academic_year_id', '=', 't.academic_year_id')
            ->join('semesters as sm', 'sm.semester_id', '=', 't.semester_id')
            ->where('t.student_id', $studentId)->where('t.is_finalized', 1)
            ->orderBy('y.start_date')->orderBy('sm.semester_order')
            ->get(['y.year_name', 'sm.semester_code', 'sm.semester_name', 't.term_gpa', 't.cumulative_gpa', 't.attempted_hours', 't.earned_hours']);

        $results = MinistryQueries::officialResults()
            ->join('courses as crs', 'crs.course_id', '=', 'co.course_id')
            ->join('academic_years as y', 'y.academic_year_id', '=', 'co.academic_year_id')
            ->join('semesters as sm', 'sm.semester_id', '=', 'co.semester_id')
            ->where('scr.student_id', $studentId)
            ->orderBy('y.start_date')->orderBy('sm.semester_order')->orderBy('crs.course_code')
            ->get(['crs.course_id', 'crs.course_code', 'crs.course_name', 'crs.credit_hours', 'y.year_name', 'sm.semester_code', 'sm.semester_name', 'r.final_mark', 'rst.status_code']);

        $graduation = MinistryQueries::graduations()->where('g.student_id', $studentId)->orderByDesc('g.approved_at')
            ->first(['g.approved_at', 'g.cumulative_gpa_snapshot', 'g.earned_hours_snapshot']);

        return [
            'student_id' => (int) $r->student_id,
            'student_number' => $r->student_number,
            'full_name' => $r->full_name,
            'gender' => $r->gender,
            'enrollment_date' => $r->enrollment_date,
            'status' => ['code' => $r->status_code, 'label' => MinistryLabels::studentStatus($r->status_code, $r->status_name)],
            'level' => MinistryLabels::level($r->level_order !== null ? (int) $r->level_order : null, $r->level_name),
            'college' => $r->college_id ? ['id' => (int) $r->college_id, 'name' => $r->college_name] : null,
            'department' => $r->department_id ? ['id' => (int) $r->department_id, 'name' => $r->department_name] : null,
            'program' => $r->academic_program_id ? [
                'id' => (int) $r->academic_program_id, 'name' => $r->program_name, 'degree_level' => $r->degree_level,
                'total_credit_hours' => $r->total_credit_hours !== null ? (int) $r->total_credit_hours : null,
            ] : null,
            'plan' => $plan
                ? ['label' => $plan->label, 'version_number' => (int) $plan->version_number, 'status' => MinistryLabels::of(MinistryLabels::PLAN_STATUS, $plan->status)]
                : ['label' => $r->plan_state === 'legacy' ? 'الخطة الدراسية السابقة لنظام النسخ (غير مرقّمة)' : null, 'version_number' => null, 'status' => null],
            'terms' => $terms->map(fn ($t) => [
                'term' => $t->year_name.' — '.MinistryLabels::semester($t->semester_code, $t->semester_name),
                'term_gpa' => $t->term_gpa !== null ? (float) $t->term_gpa : null,
                'cumulative_gpa' => $t->cumulative_gpa !== null ? (float) $t->cumulative_gpa : null,
                'attempted_hours' => (int) $t->attempted_hours,
                'earned_hours' => (int) $t->earned_hours,
            ])->values(),
            'official_results' => $results->map(fn ($x) => [
                'course_id' => (int) $x->course_id,
                'course_code' => $x->course_code,
                'course_name' => $x->course_name,
                'credit_hours' => (int) $x->credit_hours,
                'term' => $x->year_name.' — '.MinistryLabels::semester($x->semester_code, $x->semester_name),
                'final_mark' => $x->final_mark !== null ? (float) $x->final_mark : null,
                'result' => MinistryLabels::of(MinistryLabels::RESULT_STATUS, $x->status_code),
                'result_code' => $x->status_code,
            ])->values(),
            'graduation' => $graduation ? [
                'approved_at' => substr((string) $graduation->approved_at, 0, 10),
                'cumulative_gpa' => $graduation->cumulative_gpa_snapshot !== null ? (float) $graduation->cumulative_gpa_snapshot : null,
                'earned_hours' => (int) $graduation->earned_hours_snapshot,
            ] : null,
            'publication_note' => 'تُعرض النتائج المعتمدة رسميًا فقط، والفصول المُقفلة (المعتمدة) فقط. العلامات قيد الإدخال أو المراجعة وعلامات الأجزاء والملاحظات الداخلية غير متاحة للوزارة.',
        ];
    }
}
