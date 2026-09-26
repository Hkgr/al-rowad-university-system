<?php

namespace App\Services;

use App\Models\AcademicProgram;
use App\Models\College;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\User;
use App\Services\Ministry\MinistryDashboardService;
use App\Services\Ministry\MinistryQueries;
use App\Support\PortalReportRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Paginated read-only projections. All summaries and row pages derive from the same scoped query. */
final class PortalReportService
{
    public function __construct(private DataScopeService $scope, private PortalReportRegistry $registry) {}

    public function options(User $u, string $portal): array
    {
        $reports = $this->registry->definitions($u, $portal);

        return ['reports' => $reports, 'years' => collect($reports)->contains('period',true) ? DB::table('academic_years')->orderByDesc('start_date')->get(['academic_year_id as id', 'year_name as name']) : [],
            'semesters' => collect($reports)->contains('period',true) ? DB::table('semesters')->orderBy('semester_order')->get(['semester_id as id', 'semester_name as name']) : [],
            'scope' => $this->scopeLabel($portal),
            'programs' => !collect($reports)->contains('scoped',true) ? [] : $this->programs($u, $portal)->join('departments as rd', 'rd.department_id', '=', 'academic_programs.department_id')->join('colleges as rc', 'rc.college_id', '=', 'rd.college_id')->orderBy('academic_program_id')->get(['academic_program_id as id', 'program_name as name', 'rc.college_id', 'rc.college_name'])];
    }

    public function run(User $u, string $portal, string $report, array $f): array
    {
        abort_unless($this->registry->allows($u, $portal, $report), 403);
        $d = collect($this->registry->definitions($u, $portal))->firstWhere('id', $report);
        if (! empty($f['college_id']) || ! empty($f['program_id'])) {
            if (! $d['scoped']) {
                throw ValidationException::withMessages(['scope' => ['هذا التقرير لا يدعم تغيير النطاق التنظيمي.']]);
            }
            $allowed = $this->programs($u, $portal);
            if (! empty($f['program_id'])) {
                abort_unless((clone $allowed)->whereKey($f['program_id'])->exists(), 403);
            }
            if (! empty($f['college_id'])) {
                abort_unless((clone $allowed)->whereHas('department', fn ($q) => $q->where('college_id', $f['college_id']))->exists(), 403);
            }
            if (! empty($f['program_id']) && ! empty($f['college_id']) && ! $this->programs($u, $portal, $f)->exists()) {
                throw ValidationException::withMessages(['scope' => ['البرنامج لا يتبع الكلية المختارة.']]);
            }
        }
        ['title' => $title, 'definition' => $definition, 'period' => $period] = $d;
        if (! $period && (! empty($f['academic_year_id']) || ! empty($f['semester_id']))) {
            throw ValidationException::withMessages(['period' => ['هذا التقرير لقطة حالية أو سجل بلا حدود فصلية موثوقة.']]);
        }
        $base = ['id' => $report, 'title' => $title, 'definition' => $definition, 'scope' => $this->scopeLabel($portal),
            'period' => $period ? ['academic_year_id' => $f['academic_year_id'] ?? null, 'semester_id' => $f['semester_id'] ?? null, 'label' => empty($f['academic_year_id']) ? 'جميع الفترات المسجلة' : 'الفترة المختارة'] : ['label' => 'الحالة الحالية / السجل المسجل؛ ليس اتجاهًا تاريخيًا'],
            'generated_at' => now()->utc()->toIso8601String(), 'available' => true];
        if ($report === 'academic') {
            $s = Student::findOrFail($u->student_id);
            $snapshot = app(ExamStudentAcademicRecordService::class)->snapshot($s, $u);

            return $base + ['academic' => $snapshot, 'rows' => [], 'groups' => [], 'total' => null, 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1]];
        }
        if ($report === 'trends') {
            $data = app(MinistryDashboardService::class)->trendReport(array_intersect_key($f, array_flip(['college_id', 'program_id'])));
            $base['period'] = ['label' => 'جميع السنوات المسجلة؛ الالتحاق بالتاريخ والتخرج بالقرار والنتائج بالفصل الفعلي'];

            return $base + ['trends' => $data['trends'], 'unavailable' => $data['unavailable'], 'rows' => [], 'groups' => [], 'total' => null, 'meta' => ['total' => null, 'current_page' => 1, 'last_page' => 1]];
        }
        $projection = $this->projection($u, $portal, $report, $f);
        if ($projection === null) {
            return array_replace($base, ['available' => false, 'reason' => 'مصدر التقرير أو أعمدته المطلوبة غير متاحة في المخطط الحالي.', 'rows' => [], 'groups' => [], 'total' => null, 'meta' => ['total' => null, 'current_page' => 1, 'last_page' => 1]]);
        }
        $q = DB::query()->fromSub($projection, 'report_rows');
        if (! empty($f['search'])) {
            $q->where('label', 'like', MinistryQueries::like($f['search']));
        }
        $groups = (clone $q)->selectRaw('category, COUNT(*) as total')->groupBy('category')->orderBy('category')->get();
        if (isset($f['category']) && $f['category'] !== '') {
            $q->where('category', $f['category']);
        }
        $total = (clone $q)->count();
        $size = $f['per_page'] ?? 25;
        $page = $f['page'] ?? 1;
        $q->orderBy('id');
        if ($report === 'parts') {
            $q->orderBy('component_type');
        }

        return $base + ['source' => $this->source($report), 'total' => $total, 'groups' => $groups,
            'rows' => $q->forPage($page, $size)->get(),
            'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $size, 'last_page' => max(1, (int) ceil($total / $size))]];
    }

    private function students(User $u, string $p, array $f = [])
    {
        $q = Student::query()->when($f['program_id'] ?? null, fn ($q, $id) => $q->where('academic_program_id', $id))->when($f['college_id'] ?? null, fn ($q, $id) => $q->whereHas('academicProgram.department', fn ($d) => $d->where('college_id', $id)));
        if ($p === 'student') {
            return $q->whereKey($u->student_id);
        }
        if ($p === 'professor') {
            return $q->whereHas('studentCourseRegistrations', fn ($r) => $r->whereIn('course_offering_id', $this->offerings($u, $p, $f)->select('course_offering_id')));
        }
        if (in_array($p, ['president', 'ministry'])) {
            return $q;
        }
        if ($p === 'dean') {
            return $q->whereHas('academicProgram.department', fn ($d) => $d->whereIn('college_id', $this->collegeIds($u)));
        }

        return $this->scope->scopeManualGradeStudents($q, $u);
    }

    private function offerings(User $u, string $p, array $f)
    {
        $q = CourseOffering::query()->when($f['program_id'] ?? null, fn ($q, $id) => $q->where('academic_program_id', $id))->when($f['college_id'] ?? null, fn ($q, $id) => $q->whereIn('course_offering_id', CourseOffering::idsResolvedToColleges([(int) $id])));
        if ($p === 'student') {
            $q->whereHas('studentCourseRegistrations', fn ($r) => $r->where('student_id', $u->student_id));
        } elseif ($p === 'professor') {
            // Canonical service owns effective/legacy assignment semantics, not a broad faculty DataScope.
            $ids = collect(app(ProfessorGradeAssignmentService::class)->offeringsForProfessor($u))->pluck('course_offering_id');
            $q->whereIn('course_offering_id', $ids);
        } elseif ($p === 'dean') {
            $q->whereIn('course_offering_id', CourseOffering::idsResolvedToColleges($this->collegeIds($u)));
        } elseif (! in_array($p, ['president', 'ministry'])) {
            $this->scope->scopeManualGradeOfferings($q, $u);
        }

        return $q->when($f['academic_year_id'] ?? null, fn ($q, $id) => $q->where('academic_year_id', $id))->when($f['semester_id'] ?? null, fn ($q, $id) => $q->where('semester_id', $id));
    }

    private function collegeIds(User $u): array
    {
        return collect($this->scope->scopes($u))->where('type', 'college')->pluck('id')->all();
    }

    private function programs(User $u, string $p, array $f = [])
    {
        $q = AcademicProgram::query()->when($f['program_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->when($f['college_id'] ?? null, fn ($q, $id) => $q->whereHas('department', fn ($d) => $d->where('college_id', $id)));
        if ($p === 'dean') {
            return $q->whereHas('department', fn ($d) => $d->whereIn('college_id', $this->collegeIds($u)));
        }
        if (in_array($p, ['president', 'ministry'])) {
            return $q;
        }

        // scopeProgramsForMutation intentionally omits the virtual super-admin bypass.
        return $this->scope->scopeProgramsForMutation($q, $u);
    }

    private function projection(User $u, string $p, string $r, array $f)
    {
        if ($r === 'students') {
            return DB::table('students as s')->join('student_statuses as st', 'st.student_status_id', '=', 's.student_status_id')->whereIn('s.student_id', $this->students($u, $p, $f)->select('student_id'))
                ->selectRaw('s.student_id as id, s.student_number as label, st.status_code as category');
        }
        if ($r === 'programs') {
            return DB::table('academic_programs as ap')->whereIn('ap.academic_program_id', $this->programs($u, $p, $f)->select('academic_program_id'))
                ->selectRaw("ap.academic_program_id as id, ap.program_name as label, CASE WHEN ap.archived_at IS NOT NULL THEN 'archived' WHEN ap.is_active=1 THEN 'active' ELSE 'inactive' END as category");
        }
        if ($r === 'faculty') {
            return DB::table('faculty_members as fm')->when($p === 'dean', fn ($q) => $q->whereIn('fm.employee_id', DB::query()->fromSub(MinistryQueries::facultyMembership(), 'membership')->whereIn('membership.college_id', $this->collegeIds($u))->select('membership.employee_id')))
                ->selectRaw("fm.faculty_member_id as id, fm.academic_rank as label, CASE WHEN fm.is_active=1 THEN 'active' ELSE 'inactive' END as category");
        }
        if ($r === 'employees') {
            if (! Schema::hasColumns('employees', ['employee_id', 'organizational_unit_id', 'employee_status_id']) || ! Schema::hasColumns('employee_statuses', ['employee_status_id', 'status_name'])) {
                return null;
            }
            $q = DB::table('employees as e')->leftJoin('employee_statuses as st', 'st.employee_status_id', '=', 'e.employee_status_id')->leftJoin('organizational_units as ou', 'ou.organizational_unit_id', '=', 'e.organizational_unit_id');
            if (! $this->scope->hasActualUniversityScope($u)) {
                $q->whereIn('e.organizational_unit_id', College::query()->whereIn('college_id', $this->collegeIds($u))->select('organizational_unit_id'));
            }

            return $q->selectRaw("e.employee_id as id, COALESCE(ou.unit_name,'غير محدد') as label, COALESCE(st.status_name,'غير محدد') as category");
        }
        if ($r === 'accounts') {
            return DB::table('users as u')->join('account_statuses as st', 'st.account_status_id', '=', 'u.account_status_id')->selectRaw('u.user_id as id, st.status_name as label, st.status_code as category');
        }
        if ($r === 'activity') {
            if (! Schema::hasColumns('user_activity_logs', ['activity_log_id', 'module_code', 'action_code', 'created_at'])) {
                return null;
            }
            $q = DB::table('user_activity_logs');
            $modules = app(SystemActivityService::class)->visibleModules($u);
            if ($modules !== null) {
                $q->whereIn('module_code', $modules);
            }

            return $q->selectRaw('activity_log_id as id, action_code as label, module_code as category, created_at as occurred_at');
        }
        if (in_array($r, ['requests', 'progression', 'graduation', 'admissions'])) {
            [$table,$key] = match ($r) {
                'requests' => ['student_registration_requests', 'student_registration_request_id'], 'progression' => ['student_progression_decisions', 'student_progression_decision_id'], 'graduation' => ['student_graduation_decisions', 'student_graduation_decision_id'], default => ['admission_applications', 'admission_application_id']
            };
            $status = $r === 'admissions' ? 'decision_status' : 'status';
            if (! Schema::hasColumns($table, [$key, $status, $r === 'admissions' ? 'academic_program_id' : 'student_id'])) {
                return null;
            }
            $q = DB::table($table.' as t');
            if ($r === 'admissions') {
                $q->whereIn('t.academic_program_id', $this->programs($u, $p, $f)->select('academic_program_id'));
            } else {
                $q->whereIn('t.student_id', $this->students($u, $p, $f)->select('student_id'));
            }
            if ($r === 'requests') {
                foreach (['academic_year_id', 'semester_id'] as $k) {
                    if (! empty($f[$k])) {
                        $q->where('t.'.$k, $f[$k]);
                    }
                }
            }

            return $q->selectRaw("t.$key as id, t.$status as label, t.$status as category");
        }
        $offers = $this->offerings($u, $p, $f)->select('course_offering_id');
        if ($r === 'supplementary') {
            if (! Schema::hasColumns('supplementary_exam_registrations', ['supplementary_exam_registration_id', 'student_id', 'student_course_registration_id', 'status'])) {
                return null;
            }

            return DB::table('supplementary_exam_registrations as sr')->join('student_course_registrations as original', 'original.student_course_registration_id', '=', 'sr.student_course_registration_id')
                ->whereIn('original.course_offering_id', $offers)->whereIn('sr.student_id', $this->students($u, $p, $f)->select('student_id'))
                ->selectRaw('sr.supplementary_exam_registration_id as id, sr.status as label, sr.status as category');
        }
        if ($r === 'offerings') {
            return DB::table('course_offerings as co')->join('courses as c', 'c.course_id', '=', 'co.course_id')->whereIn('co.course_offering_id', $offers)->selectRaw('co.course_offering_id as id, c.course_code as label, co.status as category');
        }
        if ($r === 'parts') {
            if (! Schema::hasColumns('grade_components', ['course_offering_id', 'component_type', 'is_required']) || ! Schema::hasColumns('grade_part_approvals', ['course_offering_id', 'component_type', 'status'])) {
                return null;
            }

            return DB::table('grade_components as gc')->join('course_offerings as co', 'co.course_offering_id', '=', 'gc.course_offering_id')->join('courses as c', 'c.course_id', '=', 'co.course_id')
                ->leftJoin('grade_part_approvals as pa', fn ($j) => $j->on('pa.course_offering_id', '=', 'gc.course_offering_id')->on('pa.component_type', '=', 'gc.component_type'))
                ->whereIn('gc.course_offering_id', $offers)->where('gc.is_required', 1)->whereIn('gc.component_type', ['theoretical', 'practical'])
                ->selectRaw("gc.course_offering_id as id, c.course_code as label, gc.component_type, COALESCE(pa.status,'draft') as category")->distinct();
        }
        if ($r === 'sessions') {
            if (! Schema::hasColumns('attendance_sessions', ['attendance_session_id', 'course_offering_id', 'session_type', 'session_date'])) {
                return null;
            }

            return DB::table('attendance_sessions')->whereIn('course_offering_id', $offers)->selectRaw('attendance_session_id as id, session_type as label, session_type as category, session_date as occurred_at');
        }
        if ($r === 'attendance') {
            if (! Schema::hasColumns('student_attendance', ['student_attendance_id', 'student_id', 'attendance_session_id', 'attendance_status_id'])) {
                return null;
            }

            return DB::table('student_attendance as a')->join('attendance_sessions as s', 's.attendance_session_id', '=', 'a.attendance_session_id')->join('attendance_statuses as st', 'st.attendance_status_id', '=', 'a.attendance_status_id')
                ->when($p === 'student', fn ($q) => $q->where('a.student_id', $u->student_id))->whereIn('s.course_offering_id', $offers)->selectRaw('a.student_attendance_id as id, st.status_name as label, st.status_code as category, s.session_date as occurred_at');
        }
        if ($r === 'results') {
            return MinistryQueries::officialResults()->whereIn('co.course_offering_id', $offers)->whereIn('s.student_id', $this->students($u, $p, $f)->select('student_id'))
                ->join('courses as c', 'c.course_id', '=', 'co.course_id')->selectRaw('r.student_course_result_id as id, c.course_code as label, rst.status_code as category, r.final_mark as value');
        }
        $q = DB::table('student_course_registrations as scr')->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->join('course_offerings as co', 'co.course_offering_id', '=', 'scr.course_offering_id')->join('courses as c', 'c.course_id', '=', 'co.course_id')
            ->whereIn('scr.course_offering_id', $offers)->whereIn('scr.student_id', $this->students($u, $p, $f)->select('student_id'))->where('rs.status_code', 'registered');
        if ($r === 'deprivation') {
            $q->join('result_statuses as rst', 'rst.result_status_id', '=', 'scr.result_status_id')->where('rst.status_code', 'deprived');
        }

        return $q->selectRaw('scr.student_course_registration_id as id, c.course_code as label, rs.status_code as category');
    }

    private function scopeLabel(string $p): string
    {
        return match ($p) {
            'student' => 'سجل الطالب المرتبط بالحساب فقط', 'professor' => 'الطروحات المفتوحة المسندة فعليًا للحساب فقط', 'dean' => 'الكليات المسندة فعليًا للعميد فقط', 'ministry','president' => 'الجامعة — صلاحية قراءة البوابة', 'technical' => 'نطاق الحسابات ووحدات النشاط المسموح بها', default => 'النطاق الفعلي المعين للحساب؛ لا تجاوز مدير نظام ضمني'
        };
    }

    private function source(string $r): string
    {
        return match ($r) {
            'results' => 'MinistryQueries::officialResults / latest GradeApproval', 'parts' => 'grade_components + grade_part_approvals', 'activity' => 'user_activity_logs / SystemActivityService::visibleModules', 'students' => 'students + student_statuses', 'offerings' => 'course_offerings', 'registrations','deprivation' => 'student_course_registrations', 'programs' => 'academic_programs', 'faculty' => 'faculty_members / MinistryQueries::facultyMembership', 'employees' => 'employees + organizational_units + employee_statuses', 'accounts' => 'users + account_statuses', 'requests' => 'student_registration_requests', 'progression' => 'student_progression_decisions', 'graduation' => 'student_graduation_decisions', 'admissions' => 'admission_applications', 'supplementary' => 'supplementary_exam_registrations', 'sessions' => 'attendance_sessions', 'attendance' => 'student_attendance + attendance_sessions', default => $r
        };
    }
}
