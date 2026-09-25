<?php

namespace App\Services\Ministry;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single place where the ministry portal defines its populations.
 * Dashboard indicators and the detailed lists call the same builders, so a
 * number on the dashboard and the total of the list it links to cannot drift.
 *
 * Definitions:
 *  - Student population: students rows that are not soft-deleted (deleted_at IS NULL),
 *    current snapshot; college = the college of the student's current program.
 *  - Counted registration: student_course_registrations with status registered or completed
 *    (dropped / withdrawn / cancelled are excluded).
 *  - Official result: a student_course_results row of a counted registration whose offering's
 *    LATEST grade_approvals row is `approved` (same rule as GradeService::scopeOfficialApprovedResults).
 *    Draft, pending, returned or rejected grade approvals never reach the portal.
 *  - Graduate: student_graduation_decisions with status approved, materialized_at set and not superseded.
 *  - Faculty college affiliation: the employee's home unit or an open, active employee_unit_assignments
 *    row pointing at a college unit (App\Support\CollegeAffiliation). A member may belong to several colleges.
 *  - Effective study plan of a program: legacy programs use program_courses rows without a plan version;
 *    other programs use the rows of academic_programs.default_academic_plan_version_id only. Draft or
 *    older plan versions are never added on top, so a course is never counted twice.
 */
final class MinistryQueries
{
    public const COUNTED_REGISTRATION_STATUSES = ['registered', 'completed'];

    public static function fullName(string $alias, bool $withFather = false): string
    {
        $parts = $withFather ? ['first_name', 'father_name', 'last_name'] : ['first_name', 'last_name'];
        if (DB::connection()->getDriverName() === 'sqlite') {
            return 'TRIM('.implode(" || ' ' || ", array_map(fn ($p) => "COALESCE({$alias}.{$p}, '')", $parts)).')';
        }

        return 'TRIM(CONCAT_WS(\' \', '.implode(', ', array_map(fn ($p) => "NULLIF({$alias}.{$p}, '')", $parts)).'))';
    }

    public static function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value)).'%';
    }

    // ── students ──────────────────────────────────────────────────────────

    /** Live students with program → department → college, status and level joined (aliases s, ap, d, c, ss, al). */
    public static function students(): Builder
    {
        return DB::table('students as s')
            ->whereNull('s.deleted_at')
            ->leftJoin('academic_programs as ap', 'ap.academic_program_id', '=', 's.academic_program_id')
            ->leftJoin('departments as d', 'd.department_id', '=', 'ap.department_id')
            ->leftJoin('colleges as c', 'c.college_id', '=', 'd.college_id')
            ->leftJoin('student_statuses as ss', 'ss.student_status_id', '=', 's.student_status_id')
            ->leftJoin('academic_levels as al', 'al.academic_level_id', '=', 's.current_academic_level_id');
    }

    /**
     * Filters shared by the dashboard and the student list.
     * Keys: college_id, department_id, program_id, status, level_id, search,
     * enrollment_year_id, registered_year_id (+ registered_semester_id), graduated_year_id.
     */
    public static function filterStudents(Builder $q, array $f): Builder
    {
        if (! empty($f['college_id'])) {
            $q->where('d.college_id', (int) $f['college_id']);
        }
        if (! empty($f['department_id'])) {
            $q->where('ap.department_id', (int) $f['department_id']);
        }
        if (! empty($f['program_id'])) {
            $q->where('s.academic_program_id', (int) $f['program_id']);
        }
        if (! empty($f['status'])) {
            $q->where('ss.status_code', (string) $f['status']);
        }
        if (! empty($f['level_id'])) {
            $q->where('s.current_academic_level_id', (int) $f['level_id']);
        }
        if (! empty($f['search'])) {
            $like = self::like((string) $f['search']);
            $q->where(fn (Builder $w) => $w->where('s.student_number', 'like', $like)
                ->orWhereRaw(self::fullName('s', true).' like ?', [$like])
                ->orWhereRaw(self::fullName('s').' like ?', [$like]));
        }
        if (! empty($f['enrollment_year_id'])) {
            $year = self::year((int) $f['enrollment_year_id']);
            $q->whereBetween('s.enrollment_date', [$year->start_date, $year->end_date]);
        }
        if (! empty($f['registered_year_id'])) {
            $sub = self::countedRegistrations()->where('co.academic_year_id', (int) $f['registered_year_id'])
                ->when(! empty($f['registered_semester_id']), fn (Builder $r) => $r->where('co.semester_id', (int) $f['registered_semester_id']))
                ->select('scr.student_id');
            $q->whereIn('s.student_id', $sub);
        }
        if (! empty($f['graduated_year_id'])) {
            $year = self::year((int) $f['graduated_year_id']);
            $q->whereIn('s.student_id', self::graduations()
                ->whereBetween('g.approved_at', [$year->start_date.' 00:00:00', $year->end_date.' 23:59:59'])
                ->select('g.student_id'));
        }

        return $q;
    }

    /** Counted registrations of live students (aliases scr, rs, co, rst_s). */
    public static function countedRegistrations(): Builder
    {
        return DB::table('student_course_registrations as scr')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->join('course_offerings as co', 'co.course_offering_id', '=', 'scr.course_offering_id')
            ->join('students as rst_s', 'rst_s.student_id', '=', 'scr.student_id')
            ->whereNull('rst_s.deleted_at')
            ->whereIn('rs.status_code', self::COUNTED_REGISTRATION_STATUSES);
    }

    /** Offerings whose latest grade approval is approved (the published grade set). */
    public static function officiallyApprovedOfferings(): Builder
    {
        return DB::table('grade_approvals as ga')
            ->join('approval_statuses as ast', 'ast.approval_status_id', '=', 'ga.approval_status_id')
            ->where('ast.status_code', 'approved')
            ->whereRaw('ga.grade_approval_id = (SELECT MAX(lga.grade_approval_id) FROM grade_approvals lga WHERE lga.course_offering_id = ga.course_offering_id)')
            ->select('ga.course_offering_id');
    }

    /** Official results (aliases r, scr, rs, co, rst, s, ap, d). */
    public static function officialResults(): Builder
    {
        return DB::table('student_course_results as r')
            ->join('student_course_registrations as scr', 'scr.student_course_registration_id', '=', 'r.student_course_registration_id')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->join('course_offerings as co', 'co.course_offering_id', '=', 'scr.course_offering_id')
            ->join('result_statuses as rst', 'rst.result_status_id', '=', 'r.result_status_id')
            ->join('students as s', 's.student_id', '=', 'scr.student_id')
            ->leftJoin('academic_programs as ap', 'ap.academic_program_id', '=', 's.academic_program_id')
            ->leftJoin('departments as d', 'd.department_id', '=', 'ap.department_id')
            ->whereNull('s.deleted_at')
            ->whereIn('rs.status_code', self::COUNTED_REGISTRATION_STATUSES)
            ->whereIn('co.course_offering_id', self::officiallyApprovedOfferings());
    }

    /** Final graduation decisions (alias g). */
    public static function graduations(): Builder
    {
        return DB::table('student_graduation_decisions as g')
            ->where('g.status', 'approved')
            ->whereNotNull('g.materialized_at')
            ->whereNull('g.superseded_at');
    }

    public static function year(int $id): object
    {
        $year = DB::table('academic_years')->where('academic_year_id', $id)->first(['academic_year_id', 'year_name', 'start_date', 'end_date']);
        if ($year === null) {
            throw ValidationException::withMessages(['academic_year_id' => ['السنة الأكاديمية غير موجودة.']]);
        }

        return $year;
    }

    // ── faculty ───────────────────────────────────────────────────────────

    /** employee_id ↔ college_id pairs (home unit or open unit assignment). */
    public static function facultyMembership(): Builder
    {
        $today = now()->toDateString();
        $home = DB::table('employees as me')->join('colleges as mc', 'mc.organizational_unit_id', '=', 'me.organizational_unit_id')
            ->select('me.employee_id', 'mc.college_id');
        $assigned = DB::table('employee_unit_assignments as mua')->join('colleges as muc', 'muc.organizational_unit_id', '=', 'mua.organizational_unit_id')
            ->where('mua.is_active', 1)->where(fn (Builder $x) => $x->whereNull('mua.end_date')->orWhere('mua.end_date', '>=', $today))
            ->select('mua.employee_id', 'muc.college_id');

        return $home->union($assigned);
    }

    /** Faculty members (aliases fm, e, es). Keys: college_id, active, search. */
    public static function faculty(array $f = []): Builder
    {
        $q = DB::table('faculty_members as fm')
            ->join('employees as e', 'e.employee_id', '=', 'fm.employee_id')
            ->leftJoin('employee_statuses as es', 'es.employee_status_id', '=', 'e.employee_status_id');
        if (! empty($f['college_id'])) {
            $q->whereIn('fm.employee_id', DB::query()->fromSub(self::facultyMembership(), 'm')->where('m.college_id', (int) $f['college_id'])->select('m.employee_id'));
        }
        if (isset($f['active']) && $f['active'] !== '' && $f['active'] !== null) {
            $q->where('fm.is_active', (int) (bool) $f['active']);
        }
        if (! empty($f['search'])) {
            $like = self::like((string) $f['search']);
            $q->where(fn (Builder $w) => $w->whereRaw(self::fullName('e').' like ?', [$like])
                ->orWhere('fm.specialization', 'like', $like)->orWhere('fm.academic_rank', 'like', $like));
        }

        return $q;
    }

    // ── courses and plans ─────────────────────────────────────────────────

    /** Rows of each program's effective study plan only (aliases pc, pap). */
    public static function effectivePlanRows(): Builder
    {
        return DB::table('program_courses as pc')
            ->join('academic_programs as pap', 'pap.academic_program_id', '=', 'pc.academic_program_id')
            ->where('pc.is_active', 1)
            ->where(fn (Builder $w) => $w
                ->where(fn (Builder $legacy) => $legacy->where('pap.plan_state', 'legacy')->whereNull('pc.academic_plan_version_id'))
                ->orWhere(fn (Builder $fixed) => $fixed->where('pap.plan_state', '<>', 'legacy')
                    ->whereNotNull('pap.default_academic_plan_version_id')
                    ->whereColumn('pc.academic_plan_version_id', 'pap.default_academic_plan_version_id')));
    }

    /** Courses (alias crs). Keys: college_id, department_id, program_id, active, search, offered_year_id (+ offered_semester_id). */
    public static function courses(array $f = []): Builder
    {
        $q = DB::table('courses as crs');
        if (! empty($f['college_id'])) {
            $q->whereIn('crs.course_id', DB::table('course_departments as cd')->join('departments as cdd', 'cdd.department_id', '=', 'cd.department_id')
                ->where('cdd.college_id', (int) $f['college_id'])->select('cd.course_id'));
        }
        if (! empty($f['department_id'])) {
            $q->whereIn('crs.course_id', DB::table('course_departments')->where('department_id', (int) $f['department_id'])->select('course_id'));
        }
        if (! empty($f['program_id'])) {
            $q->whereIn('crs.course_id', self::effectivePlanRows()->where('pc.academic_program_id', (int) $f['program_id'])->select('pc.course_id'));
        }
        if (isset($f['active']) && $f['active'] !== '' && $f['active'] !== null) {
            $q->where('crs.is_active', (int) (bool) $f['active']);
        }
        if (! empty($f['search'])) {
            $like = self::like((string) $f['search']);
            $q->where(fn (Builder $w) => $w->where('crs.course_code', 'like', $like)->orWhere('crs.course_name', 'like', $like));
        }
        if (! empty($f['offered_year_id'])) {
            $q->whereIn('crs.course_id', DB::table('course_offerings')->where('academic_year_id', (int) $f['offered_year_id'])
                ->when(! empty($f['offered_semester_id']), fn (Builder $o) => $o->where('semester_id', (int) $f['offered_semester_id']))
                ->select('course_id'));
        }

        return $q;
    }

    /** SQL for the college of an offering: its program's college, else its department's college. */
    public static function offeringCollegeSql(): string
    {
        return 'COALESCE((SELECT od1.college_id FROM academic_programs op1 JOIN departments od1 ON od1.department_id = op1.department_id WHERE op1.academic_program_id = co.academic_program_id), '
            .'(SELECT od2.college_id FROM departments od2 WHERE od2.department_id = co.department_id))';
    }

    // ── deans ─────────────────────────────────────────────────────────────

    /** Current dean assignments: active `dean` role + active college scope (the rule the system itself uses for dean access). */
    public static function currentDeanAssignments(): Builder
    {
        return DB::table('user_access_scopes as us')
            ->join('user_roles as ur', 'ur.user_id', '=', 'us.user_id')
            ->join('roles as r', fn ($j) => $j->on('r.role_id', '=', 'ur.role_id')->where('r.role_code', 'dean'))
            ->join('users as u', 'u.user_id', '=', 'us.user_id')
            ->where('ur.is_active', 1)->where('r.is_active', 1)
            ->where('us.scope_type', 'college')->where('us.is_active', 1);
    }
}
