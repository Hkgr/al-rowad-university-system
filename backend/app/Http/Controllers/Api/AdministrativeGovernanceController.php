<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\FacultyMember;
use App\Models\User;
use App\Services\AdministrativeDashboardService;
use App\Services\AdministrativeDeanService;
use App\Services\AdministrativeFacultyService;
use App\Support\AdministrativeGovernance;
use App\Support\AdministrativeGovernanceException;
use App\Support\CollegeAffiliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Administrative Vice-Presidency: home indicators, teacher profiles and college deans. */
class AdministrativeGovernanceController extends Controller
{
    private const MESSAGES = [
        'required' => 'هذا الحقل مطلوب.',
        'required_if' => 'هذا الحقل مطلوب في هذا الخيار.',
        'integer' => 'القيمة يجب أن تكون رقمًا صحيحًا.',
        'date' => 'التاريخ غير صالح.',
        'email' => 'صيغة البريد غير صحيحة.',
        'max' => 'القيمة أطول من المسموح.',
        'in' => 'القيمة غير مسموحة.',
        'unique' => 'القيمة مستخدمة مسبقًا.',
        'confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        'prohibited' => 'هذا الحقل يُحدَّد على الخادم ولا يُقبل من الواجهة.',
        'password.min' => 'كلمة المرور يجب ألا تقل عن 10 محارف.',
        'password.letters' => 'كلمة المرور يجب أن تحتوي على حرف.',
        'password.mixed' => 'كلمة المرور يجب أن تحتوي على حرف كبير وصغير.',
        'password.numbers' => 'كلمة المرور يجب أن تحتوي على رقم.',
    ];

    public function __construct(
        private readonly AdministrativeGovernance $access,
        private readonly AdministrativeDashboardService $dashboard,
        private readonly AdministrativeFacultyService $faculty,
        private readonly AdministrativeDeanService $deans,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        if (! $this->access->allowsDashboard($request->user())) {
            throw AdministrativeGovernanceException::forbidden();
        }
        $filters = $request->validate([
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'semester_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'college_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], self::MESSAGES);

        return $this->ok($this->dashboard->payload($request->user(), array_filter($filters, fn ($v) => $v !== null)));
    }

    // ── Teachers ─────────────────────────────────────────────────────────────

    public function facultyIndex(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::FACULTY_VIEW);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'college_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'employee_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], self::MESSAGES);
        if (array_key_exists('college_id', $filters) && $filters['college_id'] !== null) {
            $filters['college_id'] = (int) $filters['college_id'];
        }

        return $this->ok($this->faculty->list($filters) + [
            'capabilities' => $this->capabilities($request->user()),
            // Only active colleges linked to an organizational unit can receive teachers.
            'college_options' => collect(CollegeAffiliation::collegeUnits())
                ->filter(fn (array $college) => $college['is_active'])
                ->map(fn (array $college) => ['id' => $college['college_id'], 'label' => $college['college_name']])
                ->sortBy('label')->values()->all(),
        ]);
    }

    public function facultyShow(Request $request, FacultyMember $facultyMember): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::FACULTY_VIEW);

        return $this->ok($this->faculty->show($facultyMember));
    }

    public function employeeLookup(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::FACULTY_MANAGE);
        $data = $request->validate(['employee_number' => ['required', 'string', 'max:50']], self::MESSAGES);
        $employee = Employee::query()->with(['employeeStatus', 'facultyMember'])->where('employee_number', trim($data['employee_number']))->first();

        return $this->ok($employee === null ? null : [
            'employee_id' => $employee->employee_id,
            'employee_number' => $employee->employee_number,
            'full_name' => trim($employee->first_name.' '.$employee->last_name),
            'employee_status' => $employee->employeeStatus?->status_code,
            'faculty_member_id' => $employee->facultyMember?->faculty_member_id,
            'has_account' => User::query()->where('employee_id', $employee->employee_id)->exists(),
        ]);
    }

    public function facultyStore(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::FACULTY_MANAGE);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['new', 'link'])],
            'employee_id' => ['required_if:mode,link', 'nullable', 'integer'],
            'employee_number' => array_filter(['required', 'string', 'max:50', $request->input('mode') === 'new' ? Rule::unique('employees', 'employee_number') : null]),
            'first_name' => ['required_if:mode,new', 'nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'father_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mother_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('employees', 'email')],
            'hire_date' => ['sometimes', 'nullable', 'date'],
            'academic_rank' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:150'],
            'office_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'college_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'employee_type_id' => ['prohibited'],
            'employee_status_id' => ['prohibited'],
            'organizational_unit_id' => ['prohibited'],
        ], self::MESSAGES);
        if ($data['mode'] === 'link') {
            unset($data['email']);
        }
        $member = $this->faculty->create($request->user(), $data, $request->ip());

        return $this->ok($this->faculty->show($member->fresh()), 'تم إنشاء الملف التدريسي.', 201);
    }

    public function facultyUpdate(Request $request, FacultyMember $facultyMember): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::FACULTY_MANAGE);
        $data = $request->validate([
            'academic_rank' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:150'],
            'office_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'employee_id' => ['prohibited'],
            'employee_status_id' => ['prohibited'],
            'employee_type_id' => ['prohibited'],
            'organizational_unit_id' => ['prohibited'],
        ], self::MESSAGES);
        $this->faculty->update($request->user(), $facultyMember, $data, $request->ip());

        return $this->ok($this->faculty->show($facultyMember->fresh()), 'تم حفظ بيانات المدرس.');
    }

    public function facultyAffiliation(Request $request, FacultyMember $facultyMember): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::FACULTY_MANAGE);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['assign', 'transfer', 'end'])],
            'college_id' => ['required_unless:mode,end', 'nullable', 'integer', 'min:1'],
            'from_college_id' => ['required_unless:mode,assign', 'nullable', 'integer', 'min:1'],
            'expected_assignment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'nullable', 'date'],
        ], self::MESSAGES + ['required_unless' => 'هذا الحقل مطلوب في هذا الخيار.']);
        $this->faculty->changeAffiliation($request->user(), $facultyMember, $data, $request->ip());
        $messages = ['assign' => 'تم إسناد الانتماء إلى الكلية.', 'transfer' => 'تم نقل انتماء المدرس.', 'end' => 'تم إنهاء انتماء المدرس للكلية.'];

        return $this->ok($this->faculty->show($facultyMember->fresh()), $messages[$data['mode']]);
    }

    // ── Deans ────────────────────────────────────────────────────────────────

    public function deansIndex(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::DEANS_VIEW);

        return $this->ok($this->deans->list() + ['capabilities' => $this->capabilities($request->user())]);
    }

    public function accountLookup(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::DEANS_MANAGE);
        $data = $request->validate(['username' => ['required', 'string', 'max:80']], self::MESSAGES);
        $user = User::query()->with('employee')->whereRaw('LOWER(username) = ?', [mb_strtolower(trim($data['username']))])->first();
        if ($user === null) {
            return $this->ok(null);
        }
        $roles = $user->effectiveRoles();
        $foreign = $roles->reject(fn ($code) => in_array($code, AdministrativeGovernance::DEAN_COMPATIBLE_ROLES, true))->values();

        return $this->ok([
            'user_id' => $user->user_id,
            'username' => $user->username,
            'roles' => $roles->values()->all(),
            'employee_id' => $user->employee_id,
            'employee_number' => $user->employee?->employee_number,
            'eligible' => $foreign->isEmpty() && (int) $user->user_id !== (int) $request->user()->user_id
                && ! $user->accessScopes()->where('scope_type', 'university')->where('is_active', true)->exists(),
        ]);
    }

    public function deansAppoint(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::DEANS_MANAGE);
        $data = $request->validate([
            'college_id' => ['required', 'integer', 'min:1'],
            'expected_current_dean_user_id' => ['present', 'nullable', 'integer'],
            'replace_current' => ['sometimes', 'boolean'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'employee.mode' => ['required', Rule::in(['new', 'existing'])],
            'employee.employee_id' => ['required_if:employee.mode,existing', 'nullable', 'integer'],
            'employee.employee_number' => array_filter(['required', 'string', 'max:50', $request->input('employee.mode') === 'new' ? Rule::unique('employees', 'employee_number') : null]),
            'employee.first_name' => ['required_if:employee.mode,new', 'nullable', 'string', 'max:100'],
            'employee.last_name' => ['required', 'string', 'max:100'],
            'employee.father_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'employee.phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'employee.email' => array_filter(['sometimes', 'nullable', 'email', 'max:150', $request->input('employee.mode') === 'new' ? Rule::unique('employees', 'email') : null]),
            'account.mode' => ['required', Rule::in(['new', 'existing'])],
            'account.user_id' => ['required_if:account.mode,existing', 'nullable', 'integer'],
            'account.username' => ['required_if:account.mode,new', 'nullable', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'account.email' => ['required_if:account.mode,new', 'nullable', 'email', 'max:150'],
            'account.password' => ['required_if:account.mode,new', 'nullable', 'string', 'max:255', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
            'account.password_hash' => ['prohibited'],
            'account.role_ids' => ['prohibited'],
            'account.scopes' => ['prohibited'],
            'scope_type' => ['prohibited'],
            'role_id' => ['prohibited'],
        ], self::MESSAGES + [
            'account.password.min' => 'كلمة المرور يجب ألا تقل عن 10 محارف.',
            'account.password.mixed' => 'كلمة المرور يجب أن تحتوي على حرف كبير وصغير.',
            'account.password.numbers' => 'كلمة المرور يجب أن تحتوي على رقم.',
            'account.password.letters' => 'كلمة المرور يجب أن تحتوي على حرف.',
            'account.username.regex' => 'اسم المستخدم يقبل الأحرف اللاتينية والأرقام والنقطة و- و_ فقط.',
        ]);
        $result = $this->deans->appoint($request->user(), $data, $request->ip());

        return $this->ok($result + $this->deans->list(), $result['changed'] ? 'تم تعيين العميد وربط حسابه بالكلية.' : 'الحساب معيّن عميدًا لهذه الكلية بالفعل؛ لم يتغير شيء.');
    }

    public function deansTransfer(Request $request, int $college): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::DEANS_MANAGE);
        $data = $request->validate([
            'dean_user_id' => ['required', 'integer'],
            'to_college_id' => ['required', 'integer', 'min:1'],
            'expected_target_dean_user_id' => ['present', 'nullable', 'integer'],
            'replace_current' => ['sometimes', 'boolean'],
            'start_date' => ['sometimes', 'nullable', 'date'],
        ], self::MESSAGES);
        $result = $this->deans->transfer($request->user(), $college, $data, $request->ip());

        return $this->ok($result + $this->deans->list(), 'تم نقل العميد وسحب نطاق الكلية السابقة.');
    }

    public function deansEnd(Request $request, int $college): JsonResponse
    {
        $this->access->authorize($request->user(), AdministrativeGovernance::DEANS_MANAGE);
        $data = $request->validate([
            'dean_user_id' => ['required', 'integer'],
            'end_date' => ['sometimes', 'nullable', 'date'],
        ], self::MESSAGES);
        $result = $this->deans->end($request->user(), $college, $data, $request->ip());

        return $this->ok($result + $this->deans->list(), 'تم إنهاء تكليف العميد وسحب نطاق الكلية؛ بقي الحساب وسجلّه.');
    }

    private function capabilities(?User $user): array
    {
        return [
            'faculty_manage' => $this->access->allows($user, AdministrativeGovernance::FACULTY_MANAGE),
            'deans_manage' => $this->access->allows($user, AdministrativeGovernance::DEANS_MANAGE),
        ];
    }

    private function ok(mixed $data, string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
