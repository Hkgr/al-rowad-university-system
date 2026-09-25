<?php

namespace App\Services\Ministry;

use App\Support\CollegeAffiliation;
use App\Support\MinistryLabels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deans, teaching staff and university leadership, derived only from recorded data:
 *  - dean role + college scope (what the system uses for dean access), and
 *  - employee_positions (DEAN / PRESIDENT / VICE_PRESIDENT …) for units and dates.
 * Nothing is inferred from the organizational chart: an office without a recorded
 * role or position holder is reported as "not recorded". Names only; no usernames,
 * e-mail addresses, phone numbers or employee numbers leave the server.
 */
final class MinistryStaffService
{
    /** Vice-presidency units whose system role exists, matched by the unit's exact official name. */
    public const VP_ROLE_BY_UNIT_NAME = [
        'نائب رئيس الجامعة للشؤون العلمية' => 'vice_president_scientific',
        'نائب رئيس الجامعة للشؤون الإدارية' => 'vice_president_administrative',
    ];

    public const LEGACY_VP_ROLE = 'vice_president';

    // ── deans ──────────────────────────────────────────────────────────────

    /** @return Collection<int, array<string, mixed>> one row per (person, college) */
    public function deanRows(): Collection
    {
        $today = now()->toDateString();
        $collegeByUnit = DB::table('colleges')->whereNotNull('organizational_unit_id')->get(['college_id', 'college_name', 'organizational_unit_id'])->keyBy('organizational_unit_id');
        $collegeNames = DB::table('colleges')->pluck('college_name', 'college_id');
        $rows = [];

        $positions = DB::table('employee_positions as ep')->join('positions as p', 'p.position_id', '=', 'ep.position_id')
            ->join('employees as e', 'e.employee_id', '=', 'ep.employee_id')
            ->where('p.position_code', 'DEAN')
            ->orderBy('ep.start_date')
            ->get(['ep.employee_id', 'ep.organizational_unit_id', 'ep.start_date', 'ep.end_date', 'ep.is_active', DB::raw(MinistryQueries::fullName('e').' as full_name')]);
        foreach ($positions as $p) {
            $college = $collegeByUnit->get($p->organizational_unit_id);
            if ($college === null) {
                continue;
            }
            $key = 'employee-'.$p->employee_id.'|'.$college->college_id;
            $open = (bool) $p->is_active && ($p->end_date === null || $p->end_date >= $today);
            $existing = $rows[$key] ?? null;
            // Keep the open position, else the latest one.
            if ($existing === null || ($open && ! $existing['position_open']) || (! $existing['position_open'] && $p->start_date > $existing['start_date'])) {
                $rows[$key] = [
                    'person' => 'employee-'.$p->employee_id, 'full_name' => $p->full_name, 'college_id' => (int) $college->college_id, 'college_name' => $college->college_name,
                    'start_date' => $p->start_date, 'end_date' => $p->end_date, 'position_open' => $open, 'position_recorded' => true, 'account_current' => false,
                ];
            }
        }

        $accounts = MinistryQueries::currentDeanAssignments()->leftJoin('employees as e', 'e.employee_id', '=', 'u.employee_id')
            ->get(['us.scope_id', 'u.user_id', 'u.employee_id', DB::raw(MinistryQueries::fullName('e').' as full_name')]);
        foreach ($accounts as $a) {
            $person = $a->employee_id ? 'employee-'.$a->employee_id : 'account-'.$a->user_id;
            $key = $person.'|'.$a->scope_id;
            $rows[$key] = ($rows[$key] ?? [
                'person' => $person, 'full_name' => $a->full_name, 'college_id' => (int) $a->scope_id, 'college_name' => $collegeNames[$a->scope_id] ?? null,
                'start_date' => null, 'end_date' => null, 'position_open' => false, 'position_recorded' => false,
            ]);
            $rows[$key]['account_current'] = true;
        }

        return collect($rows)->map(function (array $r) {
            $current = $r['account_current'] || $r['position_open'];
            $notes = [];
            if ($r['account_current'] && ! $r['position_recorded']) {
                $notes[] = 'حساب عميد فعّال دون قيد منصب؛ تاريخ التكليف غير مسجل.';
            } elseif ($r['account_current'] && ! $r['position_open']) {
                $notes[] = 'حساب العميد فعّال لكن قيد المنصب منتهٍ.';
            }
            if ($r['position_open'] && ! $r['account_current']) {
                $notes[] = 'منصب عميد مسجل دون حساب عميد فعّال لهذه الكلية.';
            }

            return [
                'person' => $r['person'],
                'full_name' => trim((string) $r['full_name']) !== '' ? $r['full_name'] : 'حساب غير مرتبط بسجل موظف',
                'college' => ['id' => $r['college_id'], 'name' => $r['college_name']],
                'state' => $current ? 'current' : 'historical',
                'state_label' => $current ? 'حالي' : 'سابق',
                'start_date' => $r['start_date'],
                'end_date' => $current ? null : $r['end_date'],
                'notes' => $notes,
            ];
        })->sortBy(fn ($r) => ($r['state'] === 'current' ? '0' : '1').$r['college']['name'].($r['start_date'] ?? ''))->values();
    }

    public function deans(array $f): array
    {
        $rows = $this->deanRows()
            ->when(! empty($f['college_id']), fn ($c) => $c->where('college.id', (int) $f['college_id']))
            ->when(in_array($f['state'] ?? null, ['current', 'historical'], true), fn ($c) => $c->where('state', $f['state']))
            ->when(! empty($f['search']), fn ($c) => $c->filter(fn ($r) => mb_stripos($r['full_name'], trim((string) $f['search'])) !== false || mb_stripos((string) $r['college']['name'], trim((string) $f['search'])) !== false))
            ->values();

        return $this->paginate($rows, $f);
    }

    public function dean(string $person): ?array
    {
        $rows = $this->deanRows()->where('person', $person)->values();
        if ($rows->isEmpty()) {
            return null;
        }
        [$type, $id] = explode('-', $person, 2);
        $employeeId = $type === 'employee' ? (int) $id : null;
        $faculty = $employeeId ? DB::table('faculty_members')->where('employee_id', $employeeId)->first(['faculty_member_id', 'academic_rank', 'specialization']) : null;

        return [
            'person' => $person,
            'full_name' => $rows->first()['full_name'],
            'academic_rank' => $faculty?->academic_rank,
            'specialization' => $faculty?->specialization,
            'faculty_member_id' => $faculty ? (int) $faculty->faculty_member_id : null,
            'dean_assignments' => $rows,
            'positions' => $employeeId ? $this->positionsOf($employeeId) : [],
            'source_note' => 'العمادة الحالية مأخوذة من دور العميد ونطاق الكلية الفعّالين، وتواريخ التكليف من قيود المناصب (employee_positions).',
        ];
    }

    // ── faculty ────────────────────────────────────────────────────────────

    public function faculty(array $f): array
    {
        $perPage = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $q = MinistryQueries::faculty($f);
        $total = (clone $q)->count();
        $rows = $q->orderByRaw(MinistryQueries::fullName('e'))->orderBy('fm.faculty_member_id')->forPage($page, $perPage)
            ->get(['fm.faculty_member_id', 'fm.employee_id', 'fm.academic_rank', 'fm.specialization', 'fm.is_active', 'es.status_code', DB::raw(MinistryQueries::fullName('e').' as full_name')]);
        $colleges = CollegeAffiliation::collegesForEmployees($rows->pluck('employee_id')->map(fn ($id) => (int) $id)->all());
        $assignments = DB::table('course_offering_instructors')->whereIn('faculty_member_id', $rows->pluck('faculty_member_id'))->where('is_active', 1)
            ->groupBy('faculty_member_id')->selectRaw('faculty_member_id as k, COUNT(DISTINCT course_offering_id) as n')->pluck('n', 'k');

        return [
            'data' => $rows->map(fn ($r) => [
                'faculty_member_id' => (int) $r->faculty_member_id,
                'full_name' => $r->full_name,
                'academic_rank' => $r->academic_rank,
                'specialization' => $r->specialization,
                'is_active' => (bool) $r->is_active,
                'employee_status' => MinistryLabels::of(MinistryLabels::EMPLOYEE_STATUS, $r->status_code),
                'colleges' => collect($colleges[(int) $r->employee_id] ?? [])->map(fn ($c) => ['id' => $c['college_id'], 'name' => $c['college_name']])->values(),
                'active_offerings' => (int) ($assignments[$r->faculty_member_id] ?? 0),
            ])->values(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    public function facultyMember(int $facultyMemberId): ?array
    {
        $r = MinistryQueries::faculty()->where('fm.faculty_member_id', $facultyMemberId)
            ->first(['fm.faculty_member_id', 'fm.employee_id', 'fm.academic_rank', 'fm.specialization', 'fm.is_active', 'es.status_code', DB::raw(MinistryQueries::fullName('e').' as full_name')]);
        if ($r === null) {
            return null;
        }
        $colleges = CollegeAffiliation::collegesForEmployees([(int) $r->employee_id])[(int) $r->employee_id] ?? [];
        $teaching = DB::table('course_offering_instructors as coi')
            ->join('course_offerings as co', 'co.course_offering_id', '=', 'coi.course_offering_id')
            ->join('courses as crs', 'crs.course_id', '=', 'co.course_id')
            ->join('academic_years as y', 'y.academic_year_id', '=', 'co.academic_year_id')
            ->join('semesters as sm', 'sm.semester_id', '=', 'co.semester_id')
            ->leftJoin('academic_programs as ap', 'ap.academic_program_id', '=', 'co.academic_program_id')
            ->where('coi.faculty_member_id', $facultyMemberId)->where('coi.is_active', 1)
            ->orderByDesc('y.start_date')->orderBy('sm.semester_order')->orderBy('crs.course_code')
            ->get(['crs.course_id', 'crs.course_code', 'crs.course_name', 'y.year_name', 'sm.semester_code', 'sm.semester_name', 'ap.program_name', 'coi.instructor_role', 'coi.is_primary']);
        $qualified = DB::table('course_instructors as ci')->join('courses as crs', 'crs.course_id', '=', 'ci.course_id')
            ->where('ci.faculty_member_id', $facultyMemberId)->where('ci.is_active', 1)->orderBy('crs.course_code')
            ->get(['crs.course_id', 'crs.course_code', 'crs.course_name']);

        return [
            'faculty_member_id' => (int) $r->faculty_member_id,
            'full_name' => $r->full_name,
            'academic_rank' => $r->academic_rank,
            'specialization' => $r->specialization,
            'is_active' => (bool) $r->is_active,
            'employee_status' => MinistryLabels::of(MinistryLabels::EMPLOYEE_STATUS, $r->status_code),
            'colleges' => collect($colleges)->map(fn ($c) => [
                'id' => $c['college_id'], 'name' => $c['college_name'],
                'source' => $c['source'] === 'home_unit' ? 'الوحدة الأساسية للموظف' : 'تكليف بوحدة الكلية',
                'start_date' => $c['start_date'],
            ])->values(),
            'teaching' => $teaching->map(fn ($t) => [
                'course_id' => (int) $t->course_id, 'course_code' => $t->course_code, 'course_name' => $t->course_name,
                'term' => $t->year_name.' — '.MinistryLabels::semester($t->semester_code, $t->semester_name),
                'program' => $t->program_name, 'role' => MinistryLabels::of(MinistryLabels::INSTRUCTOR_ROLE, $t->instructor_role), 'is_primary' => (bool) $t->is_primary,
            ])->values(),
            'qualified_courses' => $qualified->map(fn ($c) => ['course_id' => (int) $c->course_id, 'course_code' => $c->course_code, 'course_name' => $c->course_name])->values(),
            'positions' => $this->positionsOf((int) $r->employee_id),
            'source_note' => 'الانتماء للكلية من الوحدة الأساسية للموظف أو تكليف فعّال بوحدة الكلية؛ التدريس من تكليفات الطروحات الفعّالة. الانتماء ليس تكليفًا بالتدريس.',
        ];
    }

    // ── leadership ─────────────────────────────────────────────────────────

    public function leadership(): array
    {
        $units = $this->units();
        $presidency = $units->first(fn ($u) => $u->type_code === 'presidency' && $u->parent_unit_id === null) ?? $units->firstWhere('type_code', 'presidency');
        $vpUnits = $units->where('type_code', 'vice_presidency')
            ->when($presidency, fn ($c) => $c->where('parent_unit_id', $presidency->organizational_unit_id))->values();

        $legacyHolders = $this->roleHolders(self::LEGACY_VP_ROLE);

        return [
            'presidency' => $presidency ? $this->unitSummary($presidency, $units) + [
                'role' => $this->roleInfo('university_president'),
                'position_code' => 'PRESIDENT',
            ] : null,
            'vice_presidencies' => $vpUnits->map(function ($u) use ($units) {
                $roleCode = self::VP_ROLE_BY_UNIT_NAME[$u->unit_name] ?? null;

                return $this->unitSummary($u, $units) + [
                    'role' => $roleCode ? $this->roleInfo($roleCode) : ['code' => null, 'exists' => false, 'holders' => [],
                        'note' => 'لا يوجد في النظام دور مخصص لهذا المنصب؛ لا حساب ولا صلاحيات مرتبطة به.'],
                ];
            })->values(),
            'legacy_vice_president_role' => ['exists' => $legacyHolders !== null, 'holders' => $legacyHolders ?? [],
                'note' => 'الدور القديم vice_president لا يحدد شأن النيابة؛ يُعرض حاملوه دون نسبتهم لوحدة.'],
            'source_note' => 'الوحدات من الهيكل التنظيمي المسجل (organizational_units). الشاغلون من قيود المناصب (employee_positions) وأدوار الحسابات الفعّالة. لا يُستنتج شاغل من الرسم التنظيمي.',
        ];
    }

    public function unit(int $unitId): ?array
    {
        $units = $this->units();
        $unit = $units->firstWhere('organizational_unit_id', $unitId);
        if ($unit === null) {
            return null;
        }
        $ancestors = [];
        for ($p = $units->firstWhere('organizational_unit_id', $unit->parent_unit_id); $p !== null; $p = $units->firstWhere('organizational_unit_id', $p->parent_unit_id)) {
            array_unshift($ancestors, ['id' => (int) $p->organizational_unit_id, 'name' => $p->unit_name, 'type' => MinistryLabels::of(MinistryLabels::UNIT_TYPE, $p->type_code)]);
            if (count($ancestors) > 20) {
                break;
            }
        }
        $roleCode = $unit->type_code === 'vice_presidency' ? (self::VP_ROLE_BY_UNIT_NAME[$unit->unit_name] ?? null) : null;
        $colleges = DB::table('colleges')->whereNotNull('organizational_unit_id')->pluck('college_id', 'organizational_unit_id');

        return $this->unitSummary($unit, $units) + [
            'ancestors' => $ancestors,
            'college_id' => isset($colleges[$unit->organizational_unit_id]) ? (int) $colleges[$unit->organizational_unit_id] : null,
            'tree' => $this->tree($unit, $units, $colleges, 0),
            'role' => $unit->type_code === 'vice_presidency'
                ? ($roleCode ? $this->roleInfo($roleCode) : ['code' => null, 'exists' => false, 'holders' => [], 'note' => 'لا يوجد في النظام دور مخصص لهذا المنصب.'])
                : ($unit->type_code === 'presidency' ? $this->roleInfo('university_president') : null),
        ];
    }

    private function units(): Collection
    {
        return DB::table('organizational_units as ou')->join('organizational_unit_types as t', 't.unit_type_id', '=', 'ou.unit_type_id')
            ->orderBy('ou.unit_code')->orderBy('ou.organizational_unit_id')
            ->get(['ou.organizational_unit_id', 'ou.unit_code', 'ou.unit_name', 'ou.parent_unit_id', 'ou.is_active', 't.type_code']);
    }

    private function unitSummary(object $u, Collection $units): array
    {
        $children = $units->where('parent_unit_id', $u->organizational_unit_id);

        return [
            'unit_id' => (int) $u->organizational_unit_id,
            'unit_code' => $u->unit_code,
            'unit_name' => $u->unit_name,
            'unit_type' => MinistryLabels::of(MinistryLabels::UNIT_TYPE, $u->type_code),
            'is_active' => (bool) $u->is_active,
            'direct_units' => $children->count(),
            'all_units' => $this->descendantCount((int) $u->organizational_unit_id, $units),
            'holders' => $this->unitHolders((int) $u->organizational_unit_id),
        ];
    }

    private function descendantCount(int $unitId, Collection $units, int $depth = 0): int
    {
        if ($depth > 20) {
            return 0;
        }
        $children = $units->where('parent_unit_id', $unitId);

        return $children->count() + $children->sum(fn ($c) => $this->descendantCount((int) $c->organizational_unit_id, $units, $depth + 1));
    }

    private function tree(object $u, Collection $units, Collection $colleges, int $depth): array
    {
        return $units->where('parent_unit_id', $u->organizational_unit_id)->values()->map(fn ($c) => [
            'unit_id' => (int) $c->organizational_unit_id,
            'unit_name' => $c->unit_name,
            'unit_type' => MinistryLabels::of(MinistryLabels::UNIT_TYPE, $c->type_code),
            'is_active' => (bool) $c->is_active,
            'college_id' => isset($colleges[$c->organizational_unit_id]) ? (int) $colleges[$c->organizational_unit_id] : null,
            'children' => $depth < 6 ? $this->tree($c, $units, $colleges, $depth + 1) : [],
        ])->all();
    }

    /** Position holders recorded at a unit (current first, then historical). */
    private function unitHolders(int $unitId): array
    {
        $today = now()->toDateString();

        return DB::table('employee_positions as ep')->join('positions as p', 'p.position_id', '=', 'ep.position_id')
            ->join('employees as e', 'e.employee_id', '=', 'ep.employee_id')
            ->where('ep.organizational_unit_id', $unitId)
            ->orderByDesc('ep.start_date')
            ->get(['ep.employee_id', 'p.position_code', 'p.position_title', 'ep.start_date', 'ep.end_date', 'ep.is_active', DB::raw(MinistryQueries::fullName('e').' as full_name')])
            ->map(function ($h) use ($today) {
                $current = (bool) $h->is_active && ($h->end_date === null || $h->end_date >= $today);

                return [
                    'full_name' => $h->full_name,
                    'position' => MinistryLabels::POSITION[$h->position_code] ?? $h->position_title,
                    'start_date' => $h->start_date,
                    'end_date' => $h->end_date,
                    'state' => $current ? 'current' : 'historical',
                    'state_label' => $current ? 'حالي' : 'سابق',
                ];
            })->sortBy(fn ($h) => $h['state'] === 'current' ? 0 : 1)->values()->all();
    }

    private function positionsOf(int $employeeId): array
    {
        $today = now()->toDateString();

        return DB::table('employee_positions as ep')->join('positions as p', 'p.position_id', '=', 'ep.position_id')
            ->leftJoin('organizational_units as ou', 'ou.organizational_unit_id', '=', 'ep.organizational_unit_id')
            ->where('ep.employee_id', $employeeId)->orderByDesc('ep.start_date')
            ->get(['p.position_code', 'p.position_title', 'ou.organizational_unit_id', 'ou.unit_name', 'ep.start_date', 'ep.end_date', 'ep.is_active', 'ep.is_primary'])
            ->map(function ($p) use ($today) {
                $current = (bool) $p->is_active && ($p->end_date === null || $p->end_date >= $today);

                return [
                    'position' => MinistryLabels::POSITION[$p->position_code] ?? $p->position_title,
                    'unit' => $p->organizational_unit_id ? ['id' => (int) $p->organizational_unit_id, 'name' => $p->unit_name] : null,
                    'start_date' => $p->start_date,
                    'end_date' => $p->end_date,
                    'is_primary' => (bool) $p->is_primary,
                    'state' => $current ? 'current' : 'historical',
                    'state_label' => $current ? 'حالي' : 'سابق',
                ];
            })->values()->all();
    }

    /** @return array<string, mixed> */
    private function roleInfo(string $roleCode): array
    {
        $holders = $this->roleHolders($roleCode);

        return [
            'code' => $roleCode,
            'exists' => $holders !== null,
            'holders' => $holders ?? [],
            'note' => $holders === null ? 'الدور غير موجود أو غير مفعّل في النظام.' : ($holders === [] ? 'الدور موجود في النظام ولا يحمله أي حساب فعّال حاليًا.' : null),
        ];
    }

    /** Active accounts holding an active role; null when the role itself is missing/inactive. */
    private function roleHolders(string $roleCode): ?array
    {
        $role = DB::table('roles')->where('role_code', $roleCode)->where('is_active', 1)->first(['role_id']);
        if ($role === null) {
            return null;
        }

        return DB::table('user_roles as ur')->join('users as u', 'u.user_id', '=', 'ur.user_id')
            ->join('account_statuses as ast', 'ast.account_status_id', '=', 'u.account_status_id')
            ->leftJoin('employees as e', 'e.employee_id', '=', 'u.employee_id')
            ->where('ur.role_id', $role->role_id)->where('ur.is_active', 1)->where('ast.status_code', 'active')
            ->orderBy('ur.assigned_at')->orderBy('u.user_id')
            ->get(['u.employee_id', 'ur.assigned_at', DB::raw(MinistryQueries::fullName('e').' as full_name')])
            ->map(fn ($h) => [
                'full_name' => trim((string) $h->full_name) !== '' ? $h->full_name : 'حساب غير مرتبط بسجل موظف',
                'linked_to_employee' => $h->employee_id !== null,
                'role_assigned_on' => $h->assigned_at ? substr((string) $h->assigned_at, 0, 10) : null,
            ])->values()->all();
    }

    private function paginate(Collection $rows, array $f): array
    {
        $perPage = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $total = $rows->count();

        return [
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }
}
