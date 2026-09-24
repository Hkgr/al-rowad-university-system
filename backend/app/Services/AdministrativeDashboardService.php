<?php

namespace App\Services;

use App\Models\User;
use App\Support\AdministrativeGovernanceException;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administrative VP home indicators. Every figure is a grouped COUNT query; nothing
 * loads a full list to count it. A missing permission, table or period yields
 * {available:false, reason} instead of a misleading zero.
 */
class AdministrativeDashboardService
{
    public function __construct(private readonly TeachingAssignmentWorkflowService $workflow) {}

    /** @param array{academic_year_id?:int, semester_id?:int, college_id?:int} $filters */
    public function payload(User $user, array $filters): array
    {
        $options = $this->filterOptions();
        $collegeId = isset($filters['college_id']) ? (int) $filters['college_id'] : null;
        if ($collegeId !== null && ! collect($options['colleges'])->contains('id', $collegeId)) {
            throw AdministrativeGovernanceException::invalid('dashboard_college_unknown', 'الكلية المختارة غير موجودة.');
        }
        if (isset($filters['semester_id']) && ! isset($filters['academic_year_id'])) {
            throw AdministrativeGovernanceException::invalid('dashboard_semester_requires_year', 'اختر السنة الدراسية قبل الفصل.');
        }

        return [
            'filters' => [
                'academic_year_id' => $filters['academic_year_id'] ?? null,
                'semester_id' => $filters['semester_id'] ?? null,
                'college_id' => $collegeId,
            ],
            'filter_options' => $options,
            'students' => $this->students($collegeId),
            'faculty' => $this->faculty($collegeId),
            'teaching_assignments' => $this->teachingAssignments($user, $filters, $collegeId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Small reference lists shared by the home filters and the review queue filters. */
    public function filterOptions(): array
    {
        return [
            'academic_years' => DB::table('academic_years')->orderByDesc('start_date')
                ->get(['academic_year_id', 'year_name', 'is_current'])
                ->map(fn ($y) => ['id' => (int) $y->academic_year_id, 'label' => $y->year_name, 'is_current' => (bool) $y->is_current])->all(),
            'semesters' => DB::table('semesters')->where('is_active', 1)->orderBy('semester_order')
                ->get(['semester_id', 'semester_name'])
                ->map(fn ($s) => ['id' => (int) $s->semester_id, 'label' => $s->semester_name])->all(),
            'colleges' => DB::table('colleges')->where('is_active', 1)->orderBy('college_name')
                ->get(['college_id', 'college_name'])
                ->map(fn ($c) => ['id' => (int) $c->college_id, 'label' => $c->college_name])->all(),
        ];
    }

    /** Current snapshot of active students (status code «active»), by the program's college. */
    private function students(?int $collegeId): array
    {
        $base = DB::table('students as s')
            ->join('student_statuses as ss', 'ss.student_status_id', '=', 's.student_status_id')
            ->leftJoin('academic_programs as ap', 'ap.academic_program_id', '=', 's.academic_program_id')
            ->leftJoin('departments as d', 'd.department_id', '=', 'ap.department_id')
            ->whereNull('s.deleted_at')
            ->where('ss.status_code', 'active');
        if ($collegeId !== null) {
            $base->where('d.college_id', $collegeId);
        }
        $rows = (clone $base)->groupBy('d.college_id')->selectRaw('d.college_id, COUNT(DISTINCT s.student_id) as total')->get();

        return [
            'available' => true,
            'basis' => 'current_snapshot',
            'university_total' => $collegeId === null ? (int) (clone $base)->count(DB::raw('DISTINCT s.student_id')) : null,
            'by_college' => $this->distribution($rows),
        ];
    }

    /**
     * Active faculty (profile active + employee status «active») by college affiliation
     * (home unit or open unit assignment). A teacher affiliated with two colleges is
     * counted in both groups but once in the university total.
     */
    private function faculty(?int $collegeId): array
    {
        $today = now()->toDateString();
        $home = DB::table('employees as he')->join('colleges as hc', 'hc.organizational_unit_id', '=', 'he.organizational_unit_id')
            ->select('he.employee_id', 'hc.college_id');
        $assigned = DB::table('employee_unit_assignments as ua')->join('colleges as uc', 'uc.organizational_unit_id', '=', 'ua.organizational_unit_id')
            ->where('ua.is_active', 1)->where(fn ($q) => $q->whereNull('ua.end_date')->orWhere('ua.end_date', '>=', $today))
            ->select('ua.employee_id', 'uc.college_id');
        $active = DB::table('faculty_members as fm')
            ->join('employees as e', 'e.employee_id', '=', 'fm.employee_id')
            ->join('employee_statuses as es', 'es.employee_status_id', '=', 'e.employee_status_id')
            ->where('fm.is_active', 1)
            ->where('es.status_code', 'active');
        $grouped = (clone $active)->joinSub($home->union($assigned), 'm', 'm.employee_id', '=', 'e.employee_id');
        if ($collegeId !== null) {
            $grouped->where('m.college_id', $collegeId);
        }
        $rows = $grouped->groupBy('m.college_id')->selectRaw('m.college_id, COUNT(DISTINCT fm.faculty_member_id) as total')->get();

        return [
            'available' => true,
            'basis' => 'current_snapshot',
            'groups_may_overlap' => true,
            'university_total' => $collegeId === null ? (int) (clone $active)->count(DB::raw('DISTINCT fm.faculty_member_id')) : null,
            'without_college' => $collegeId === null
                ? (int) (clone $active)->whereNotIn('e.employee_id', DB::query()->fromSub($home->union($assigned), 'x')->select('x.employee_id'))->count(DB::raw('DISTINCT fm.faculty_member_id'))
                : null,
            'by_college' => $this->distribution($rows),
        ];
    }

    /**
     * Administrative review queue counts, with the same definitions as the queue page:
     * pending = administrative review pending; returned/approved = request status.
     */
    private function teachingAssignments(User $user, array $filters, ?int $collegeId): array
    {
        if (! Schema::hasTable('teaching_assignment_requests') || ! Schema::hasTable('teaching_assignment_reviews')) {
            return ['available' => false, 'reason' => 'workflow_schema_missing'];
        }
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            return ['available' => false, 'reason' => 'review_permission_missing'];
        }

        // Same population as the review queue (current slot + the viewer's offering scope),
        // taken as a sub-select so the joins below cannot make its columns ambiguous.
        $queueIds = $this->workflow->reviewQueueQuery($user, TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE)
            ->select('teaching_assignment_requests.teaching_assignment_request_id')
            ->toBase();
        $query = DB::table('teaching_assignment_requests')
            ->whereIn('teaching_assignment_requests.teaching_assignment_request_id', $queueIds)
            ->join('teaching_assignment_reviews as v', function ($join): void {
                $join->on('v.teaching_assignment_request_id', '=', 'teaching_assignment_requests.teaching_assignment_request_id')
                    ->where('v.review_authority', TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE);
            })
            ->join('course_offerings as o', 'o.course_offering_id', '=', 'teaching_assignment_requests.course_offering_id')
            ->leftJoin('departments as od', 'od.department_id', '=', 'o.department_id')
            ->leftJoin('academic_programs as ap', 'ap.academic_program_id', '=', 'o.academic_program_id')
            ->leftJoin('departments as pd', 'pd.department_id', '=', 'ap.department_id');
        foreach (['academic_year_id', 'semester_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where('o.'.$column, (int) $filters[$column]);
            }
        }
        if ($collegeId !== null) {
            $query->whereRaw('COALESCE(od.college_id, pd.college_id) = ?', [$collegeId]);
        }

        $rows = $query
            ->selectRaw('COALESCE(od.college_id, pd.college_id) as college_id')
            ->selectRaw("SUM(CASE WHEN v.status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN teaching_assignment_requests.status = 'returned' THEN 1 ELSE 0 END) as returned")
            ->selectRaw("SUM(CASE WHEN teaching_assignment_requests.status = 'approved' THEN 1 ELSE 0 END) as approved")
            ->groupByRaw('COALESCE(od.college_id, pd.college_id)')
            ->get();

        $totals = ['pending' => 0, 'returned' => 0, 'approved' => 0];
        $byCollege = [];
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) $row->{$key};
            }
            $byCollege[] = [
                'college_id' => $row->college_id !== null ? (int) $row->college_id : null,
                'pending' => (int) $row->pending,
                'returned' => (int) $row->returned,
                'approved' => (int) $row->approved,
            ];
        }

        return ['available' => true, 'totals' => $totals, 'by_college' => $byCollege];
    }

    private function distribution($rows): array
    {
        return $rows->map(fn ($row) => [
            'college_id' => $row->college_id !== null ? (int) $row->college_id : null,
            'total' => (int) $row->total,
        ])->values()->all();
    }
}
