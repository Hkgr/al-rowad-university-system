<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ministry\MinistryAcademicService;
use App\Services\Ministry\MinistryDashboardService;
use App\Services\Ministry\MinistryStaffService;
use App\Services\Ministry\MinistryStudentService;
use App\Support\MinistryLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only endpoints of the Ministry of Education portal. Every route is GET and
 * guarded by RequireMinistryPortal with its own permission (see routes/api.php).
 */
class MinistryPortalController extends Controller
{
    private const ID = ['nullable', 'integer', 'min:1'];

    private const PAGING = ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'search' => ['nullable', 'string', 'max:100']];

    public function __construct(
        private readonly MinistryDashboardService $dashboard,
        private readonly MinistryStudentService $students,
        private readonly MinistryAcademicService $academic,
        private readonly MinistryStaffService $staff,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $input = $request->validate(['academic_year_id' => self::ID, 'semester_id' => self::ID, 'college_id' => self::ID, 'program_id' => self::ID]);

        return response()->json(['data' => $this->dashboard->build($input)]);
    }

    /** Filter options shared by all pages (reference data only). */
    public function filters(): JsonResponse
    {
        return response()->json(['data' => [
            'academic_years' => DB::table('academic_years')->orderByDesc('start_date')->get(['academic_year_id', 'year_name', 'is_current'])
                ->map(fn ($y) => ['id' => (int) $y->academic_year_id, 'name' => $y->year_name, 'is_current' => (bool) $y->is_current])->values(),
            'semesters' => DB::table('semesters')->orderBy('semester_order')->get(['semester_id', 'semester_code', 'semester_name'])
                ->map(fn ($s) => ['id' => (int) $s->semester_id, 'name' => MinistryLabels::semester($s->semester_code, $s->semester_name)])->values(),
            'colleges' => DB::table('colleges')->orderBy('college_name')->get(['college_id', 'college_name', 'is_active'])
                ->map(fn ($c) => ['id' => (int) $c->college_id, 'name' => $c->college_name, 'is_active' => (bool) $c->is_active])->values(),
            'departments' => DB::table('departments')->orderBy('department_name')->get(['department_id', 'department_name', 'college_id'])
                ->map(fn ($d) => ['id' => (int) $d->department_id, 'name' => $d->department_name, 'college_id' => (int) $d->college_id])->values(),
            'programs' => DB::table('academic_programs as ap')->join('departments as d', 'd.department_id', '=', 'ap.department_id')->orderBy('ap.program_name')
                ->get(['ap.academic_program_id', 'ap.program_name', 'ap.department_id', 'd.college_id'])
                ->map(fn ($p) => ['id' => (int) $p->academic_program_id, 'name' => $p->program_name, 'department_id' => (int) $p->department_id, 'college_id' => (int) $p->college_id])->values(),
            'student_statuses' => DB::table('student_statuses')->orderBy('student_status_id')->get(['status_code', 'status_name'])
                ->map(fn ($s) => ['code' => $s->status_code, 'name' => MinistryLabels::studentStatus($s->status_code, $s->status_name)])->values(),
            'levels' => DB::table('academic_levels')->orderBy('level_order')->get(['academic_level_id', 'level_order', 'level_name'])
                ->map(fn ($l) => ['id' => (int) $l->academic_level_id, 'name' => MinistryLabels::level((int) $l->level_order, $l->level_name)])->values(),
        ]]);
    }

    public function students(Request $request): JsonResponse
    {
        $input = $request->validate(self::PAGING + [
            'college_id' => self::ID, 'department_id' => self::ID, 'program_id' => self::ID, 'level_id' => self::ID,
            'status' => ['nullable', 'string', 'max:50', 'exists:student_statuses,status_code'],
            'enrollment_year_id' => self::ID, 'registered_year_id' => self::ID, 'registered_semester_id' => self::ID, 'graduated_year_id' => self::ID,
        ]);
        if (! empty($input['registered_semester_id']) && empty($input['registered_year_id'])) {
            return $this->invalid('registered_year_id', 'اختر السنة الأكاديمية قبل الفصل.');
        }

        return response()->json($this->students->list($input));
    }

    public function student(int $student): JsonResponse
    {
        return $this->found($this->students->show($student), 'الطالب غير موجود.');
    }

    public function colleges(Request $request): JsonResponse
    {
        $rows = $this->academic->colleges($request->validate(['college_id' => self::ID, 'active' => ['nullable', 'boolean']]));
        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function college(int $college): JsonResponse
    {
        return $this->found($this->academic->college($college), 'الكلية غير موجودة.');
    }

    public function courses(Request $request): JsonResponse
    {
        $input = $request->validate(self::PAGING + [
            'college_id' => self::ID, 'department_id' => self::ID, 'program_id' => self::ID, 'active' => ['nullable', 'boolean'],
            'offered_year_id' => self::ID, 'offered_semester_id' => self::ID,
        ]);
        if (! empty($input['offered_semester_id']) && empty($input['offered_year_id'])) {
            return $this->invalid('offered_year_id', 'اختر السنة الأكاديمية قبل الفصل.');
        }

        return response()->json($this->academic->courses($input));
    }

    public function course(int $course): JsonResponse
    {
        return $this->found($this->academic->course($course), 'المقرر غير موجود.');
    }

    public function faculty(Request $request): JsonResponse
    {
        return response()->json($this->staff->faculty($request->validate(self::PAGING + ['college_id' => self::ID, 'active' => ['nullable', 'boolean']])));
    }

    public function facultyMember(int $facultyMember): JsonResponse
    {
        return $this->found($this->staff->facultyMember($facultyMember), 'عضو الهيئة التدريسية غير موجود.');
    }

    public function deans(Request $request): JsonResponse
    {
        return response()->json($this->staff->deans($request->validate(self::PAGING + ['college_id' => self::ID, 'state' => ['nullable', 'in:current,historical']])));
    }

    public function dean(string $person): JsonResponse
    {
        return $this->found($this->staff->dean($person), 'لا يوجد عميد مسجل بهذا المعرّف.');
    }

    public function leadership(): JsonResponse
    {
        return response()->json(['data' => $this->staff->leadership()]);
    }

    public function unit(int $unit): JsonResponse
    {
        return $this->found($this->staff->unit($unit), 'الوحدة غير موجودة.');
    }

    private function found(?array $data, string $message): JsonResponse
    {
        return $data === null ? response()->json(['message' => $message, 'error_code' => 'not_found'], 404) : response()->json(['data' => $data]);
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
