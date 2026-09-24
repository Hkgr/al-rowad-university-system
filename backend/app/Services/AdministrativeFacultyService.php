<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeUnitAssignment;
use App\Models\FacultyMember;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Support\AcademicQueuePagination;
use App\Support\AdministrativeGovernance;
use App\Support\AdministrativeGovernanceException;
use App\Support\CollegeAffiliation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teacher profiles (faculty_members) and their college affiliation, managed by the
 * administrative VP. Affiliation uses the existing model (employee home unit +
 * employee_unit_assignments to the college's organizational unit) and keeps history
 * by closing rows instead of deleting them. Nothing here creates or changes a
 * teaching assignment (course_offering_instructors) or a login account.
 */
class AdministrativeFacultyService
{
    public const NOTE_TAG = '[vp-admin-affiliation]';

    /** @return array<string, mixed> */
    public function list(array $filters): array
    {
        $query = FacultyMember::query()
            ->with(['employee.employeeStatus', 'employee.organizationalUnit'])
            ->withCount(['offeringInstructors as effective_assignments_count' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('faculty_member_id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn (Builder $q) => $q
                ->where('specialization', 'like', $like)
                ->orWhere('academic_rank', 'like', $like)
                ->orWhereHas('employee', fn (Builder $e) => $e
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_number', 'like', $like)));
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (! empty($filters['employee_status'])) {
            $query->whereHas('employee.employeeStatus', fn (Builder $s) => $s->where('status_code', $filters['employee_status']));
        }
        if (! empty($filters['college_id'])) {
            $unitId = CollegeAffiliation::collegeUnits()[(int) $filters['college_id']]['unit_id'] ?? null;
            if ($unitId === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('employee', fn (Builder $e) => $e->where(fn (Builder $m) => $m
                    ->where('organizational_unit_id', $unitId)
                    ->orWhereHas('employeeUnitAssignments', fn (Builder $a) => $a
                        ->where('organizational_unit_id', $unitId)
                        ->where(fn ($open) => CollegeAffiliation::openAssignment($open)))));
            }
        } elseif (($filters['college_id'] ?? null) === 0 || ($filters['without_college'] ?? false)) {
            $unitIds = collect(CollegeAffiliation::collegeUnits())->pluck('unit_id')->all();
            $query->whereHas('employee', fn (Builder $e) => $e
                ->where(fn ($home) => $home->whereNull('organizational_unit_id')->orWhereNotIn('organizational_unit_id', $unitIds))
                ->whereDoesntHave('employeeUnitAssignments', fn (Builder $a) => $a
                    ->whereIn('organizational_unit_id', $unitIds)
                    ->where(fn ($open) => CollegeAffiliation::openAssignment($open))));
        }

        $page = $query->paginate(AcademicQueuePagination::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15));
        $colleges = CollegeAffiliation::collegesForEmployees(
            collect($page->items())->pluck('employee_id')->map(fn ($id) => (int) $id)->all()
        );

        return [
            'data' => collect($page->items())->map(fn (FacultyMember $member) => $this->summary($member, $colleges[(int) $member->employee_id] ?? []))->values()->all(),
            'meta' => AcademicQueuePagination::meta($page),
        ];
    }

    /** @return array<string, mixed> */
    public function show(FacultyMember $member): array
    {
        $member->load(['employee.employeeStatus', 'employee.organizationalUnit'])
            ->loadCount(['offeringInstructors as effective_assignments_count' => fn ($q) => $q->where('is_active', true)]);
        $units = collect(CollegeAffiliation::collegeUnits());
        $byUnit = $units->keyBy('unit_id');
        $history = EmployeeUnitAssignment::query()
            ->where('employee_id', $member->employee_id)
            ->whereIn('organizational_unit_id', $units->pluck('unit_id')->all())
            ->orderByDesc('start_date')->orderByDesc('assignment_id')
            ->get()
            ->map(fn (EmployeeUnitAssignment $row) => [
                'assignment_id' => $row->assignment_id,
                'college_id' => $byUnit[(int) $row->organizational_unit_id]['college_id'] ?? null,
                'college_name' => $byUnit[(int) $row->organizational_unit_id]['college_name'] ?? null,
                'start_date' => $row->start_date?->toDateString(),
                'end_date' => $row->end_date?->toDateString(),
                'is_active' => (bool) $row->is_active,
                'notes' => $row->assignment_notes,
            ])->values()->all();
        $colleges = CollegeAffiliation::collegesForEmployees([(int) $member->employee_id])[(int) $member->employee_id] ?? [];

        return $this->summary($member, $colleges) + [
            'affiliation_history' => $history,
            'effective_assignments' => $member->offeringInstructors()
                ->where('is_active', true)
                ->with(['courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester'])
                ->limit(50)->get()
                ->map(fn ($slot) => [
                    'course_offering_id' => $slot->course_offering_id,
                    'course_code' => $slot->courseOffering?->course?->course_code,
                    'course_name' => $slot->courseOffering?->course?->course_name,
                    'year_name' => $slot->courseOffering?->academicYear?->year_name,
                    'semester_name' => $slot->courseOffering?->semester?->semester_name,
                    'instructor_role' => $slot->instructor_role,
                ])->values()->all(),
        ];
    }

    public function create(User $actor, array $data, ?string $ip): FacultyMember
    {
        try {
            return DB::transaction(function () use ($actor, $data, $ip): FacultyMember {
                if (($data['mode'] ?? 'new') === 'link') {
                    $employee = Employee::query()->lockForUpdate()->find((int) $data['employee_id']);
                    $this->assertEmployeeIdentity($employee, $data);
                    $existing = FacultyMember::query()->where('employee_id', $employee->employee_id)->lockForUpdate()->first();
                    if ($existing !== null) {
                        throw new AdministrativeGovernanceException('لهذا الموظف ملف تدريسي بالفعل؛ لا يُنشأ سجل مكرر.', 409, 'faculty_profile_exists', ['faculty_member_id' => $existing->faculty_member_id]);
                    }
                } else {
                    $employee = Employee::query()->create([
                        'employee_number' => $data['employee_number'],
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'father_name' => $data['father_name'] ?? null,
                        'mother_name' => $data['mother_name'] ?? null,
                        'phone_number' => $data['phone_number'] ?? null,
                        'email' => $data['email'] ?? null,
                        'hire_date' => $data['hire_date'] ?? null,
                        'employee_type_id' => $this->lookupId('employee_types', 'employee_type_id', 'type_code', 'academic'),
                        'employee_status_id' => $this->lookupId('employee_statuses', 'employee_status_id', 'status_code', 'active'),
                    ]);
                }

                $member = FacultyMember::query()->create([
                    'employee_id' => $employee->employee_id,
                    'academic_rank' => $data['academic_rank'] ?? null,
                    'specialization' => $data['specialization'] ?? null,
                    'office_location' => $data['office_location'] ?? null,
                    'is_active' => true,
                ]);
                $this->audit($actor, 'faculty.profile_created', $ip, [
                    'faculty_member_id' => $member->faculty_member_id,
                    'employee_id' => $employee->employee_id,
                    'mode' => $data['mode'] ?? 'new',
                ]);

                if (! empty($data['college_id'])) {
                    $this->changeAffiliationLocked($actor, $member, $employee, [
                        'mode' => 'assign',
                        'college_id' => (int) $data['college_id'],
                        'start_date' => $data['start_date'] ?? null,
                    ], $ip);
                }

                return $member;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23000' || str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['employee_number' => ['رقم الموظف أو البريد مستخدم لموظف آخر.']]);
            }
            throw $exception;
        }
    }

    public function update(User $actor, FacultyMember $member, array $data, ?string $ip): FacultyMember
    {
        return DB::transaction(function () use ($actor, $member, $data, $ip): FacultyMember {
            $member = FacultyMember::query()->lockForUpdate()->findOrFail($member->faculty_member_id);
            $employee = Employee::query()->lockForUpdate()->findOrFail($member->employee_id);
            $before = [
                'profile' => $member->only(['academic_rank', 'specialization', 'office_location', 'is_active']),
                'contact' => $employee->only(['phone_number', 'email']),
            ];
            $member->fill(array_intersect_key($data, array_flip(['academic_rank', 'specialization', 'office_location', 'is_active'])))->save();
            $contact = array_intersect_key($data, array_flip(['phone_number', 'email']));
            if ($contact !== []) {
                if (! empty($contact['email']) && Employee::query()->where('email', $contact['email'])->whereKeyNot($employee->employee_id)->exists()) {
                    throw ValidationException::withMessages(['email' => ['البريد مستخدم لموظف آخر.']]);
                }
                $employee->fill($contact)->save();
            }
            $this->audit($actor, 'faculty.profile_updated', $ip, [
                'faculty_member_id' => $member->faculty_member_id,
                'before' => $before,
                'after' => [
                    'profile' => $member->only(['academic_rank', 'specialization', 'office_location', 'is_active']),
                    'contact' => $employee->only(['phone_number', 'email']),
                ],
            ]);

            return $member;
        });
    }

    /** assign | transfer | end; history is kept by closing rows, never deleting them. */
    public function changeAffiliation(User $actor, FacultyMember $member, array $data, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $member, $data, $ip): void {
            $member = FacultyMember::query()->lockForUpdate()->findOrFail($member->faculty_member_id);
            $employee = Employee::query()->lockForUpdate()->findOrFail($member->employee_id);
            $this->changeAffiliationLocked($actor, $member, $employee, $data, $ip);
        });
    }

    private function changeAffiliationLocked(User $actor, FacultyMember $member, Employee $employee, array $data, ?string $ip): void
    {
        $mode = $data['mode'];
        $units = CollegeAffiliation::collegeUnits();
        $start = Carbon::parse($data['start_date'] ?? now()->toDateString())->startOfDay();
        $current = collect(CollegeAffiliation::collegesForEmployees([(int) $employee->employee_id], $units)[(int) $employee->employee_id] ?? []);

        if ($mode !== 'end') {
            $target = $units[(int) ($data['college_id'] ?? 0)] ?? null;
            if ($target === null || ! $target['is_active']) {
                throw AdministrativeGovernanceException::invalid('college_invalid', 'الكلية غير موجودة أو غير مفعّلة أو غير مرتبطة بوحدة تنظيمية.');
            }
            $employee->loadMissing('employeeStatus');
            if ($employee->employeeStatus?->status_code !== 'active') {
                throw AdministrativeGovernanceException::invalid('employee_inactive', 'لا يمكن ربط موظف غير نشط بكلية.');
            }
            if (! $member->is_active) {
                throw AdministrativeGovernanceException::invalid('faculty_profile_inactive', 'الملف التدريسي غير مفعّل.');
            }
            if ($current->contains('college_id', $target['college_id'])) {
                throw AdministrativeGovernanceException::conflict('already_affiliated', 'المدرس منتسب إلى هذه الكلية بالفعل.');
            }
        }

        $source = null;
        if ($mode !== 'assign') {
            $source = $current->firstWhere('college_id', (int) ($data['from_college_id'] ?? 0));
            $expected = $data['expected_assignment_id'] ?? null;
            if ($source === null || (int) ($source['assignment_id'] ?? 0) !== (int) ($expected ?? 0)) {
                throw AdministrativeGovernanceException::conflict('affiliation_stale', 'تغيّر انتماء المدرس منذ فتح الصفحة. أعد التحميل وراجع الانتماء الحالي.');
            }
            if ($source['assignment_id'] !== null) {
                $row = EmployeeUnitAssignment::query()->lockForUpdate()->findOrFail($source['assignment_id']);
                $row->forceFill([
                    'end_date' => $start->copy()->subDay()->max(Carbon::parse($row->start_date))->toDateString(),
                    'is_active' => false,
                    'assignment_notes' => trim(($row->assignment_notes ?? '').' '.self::NOTE_TAG.' closed:'.$mode),
                ])->save();
            }
            if ($employee->organizational_unit_id !== null && (int) $employee->organizational_unit_id === $units[$source['college_id']]['unit_id']) {
                $employee->forceFill(['organizational_unit_id' => $mode === 'transfer' ? $units[(int) $data['college_id']]['unit_id'] : null])->save();
            }
        }

        $created = null;
        if ($mode !== 'end') {
            $created = EmployeeUnitAssignment::query()->create([
                'employee_id' => $employee->employee_id,
                'organizational_unit_id' => $units[(int) $data['college_id']]['unit_id'],
                'start_date' => $start->toDateString(),
                'end_date' => null,
                'assignment_notes' => self::NOTE_TAG.' '.($mode === 'transfer' ? 'transfer' : 'assign'),
                'is_active' => true,
            ]);
        }

        $this->audit($actor, 'faculty.affiliation_'.$mode, $ip, [
            'faculty_member_id' => $member->faculty_member_id,
            'employee_id' => $employee->employee_id,
            'from' => $source === null ? null : ['college_id' => $source['college_id'], 'assignment_id' => $source['assignment_id'], 'source' => $source['source']],
            'to' => $created === null ? null : ['college_id' => (int) $data['college_id'], 'assignment_id' => $created->assignment_id, 'start_date' => $start->toDateString()],
        ]);
    }

    private function assertEmployeeIdentity(?Employee $employee, array $data): void
    {
        if ($employee === null
            || trim((string) $employee->employee_number) !== trim((string) ($data['employee_number'] ?? ''))
            || mb_strtolower(trim((string) $employee->last_name)) !== mb_strtolower(trim((string) ($data['last_name'] ?? '')))) {
            throw AdministrativeGovernanceException::invalid('employee_identity_mismatch', 'بيانات التحقق (رقم الموظف والكنية) لا تطابق سجل الموظف.', ['employee_id' => ['تعذّر التحقق من هوية الموظف.']]);
        }
        $employee->loadMissing('employeeStatus');
        if ($employee->employeeStatus?->status_code !== 'active') {
            throw AdministrativeGovernanceException::invalid('employee_inactive', 'لا يمكن إنشاء ملف تدريسي لموظف غير نشط.');
        }
    }

    private function lookupId(string $table, string $key, string $codeColumn, string $code): int
    {
        $id = DB::table($table)->where($codeColumn, $code)->value($key);
        if ($id === null) {
            throw AdministrativeGovernanceException::invalid('reference_missing', "القيمة المرجعية {$code} غير معرّفة في قاعدة البيانات.");
        }

        return (int) $id;
    }

    private function summary(FacultyMember $member, array $colleges): array
    {
        $employee = $member->employee;

        return [
            'faculty_member_id' => $member->faculty_member_id,
            'employee_id' => $member->employee_id,
            'employee_number' => $employee?->employee_number,
            'full_name' => trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')),
            'first_name' => $employee?->first_name,
            'last_name' => $employee?->last_name,
            'email' => $employee?->email,
            'phone_number' => $employee?->phone_number,
            'employee_status' => $employee?->employeeStatus ? ['code' => $employee->employeeStatus->status_code, 'name' => $employee->employeeStatus->status_name] : null,
            'home_unit' => $employee?->organizationalUnit ? ['unit_id' => $employee->organizationalUnit->organizational_unit_id, 'unit_name' => $employee->organizationalUnit->unit_name] : null,
            'academic_rank' => $member->academic_rank,
            'specialization' => $member->specialization,
            'office_location' => $member->office_location,
            'is_active' => (bool) $member->is_active,
            'colleges' => $colleges,
            'effective_assignments_count' => (int) ($member->effective_assignments_count ?? 0),
        ];
    }

    public function audit(User $actor, string $action, ?string $ip, array $details): void
    {
        UserActivityLog::query()->create([
            'user_id' => $actor->user_id,
            'module_code' => AdministrativeGovernance::MODULE_CODE,
            'action_code' => $action,
            'description' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
