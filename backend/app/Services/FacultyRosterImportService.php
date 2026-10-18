<?php

namespace App\Services;

use App\Exceptions\AccountAdministrationException;
use App\Models\Employee;
use App\Models\FacultyMember;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountAdministration;
use App\Support\AdministrativeGovernance;
use App\Support\AdministrativeGovernanceException;
use App\Support\CollegeAffiliation;
use App\Support\TemporaryPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Operator-reviewed roster import: teachers (employee + faculty profile + college
 * affiliation + doctor_instructor account) and college deans (same person, plus the
 * dean role, one college scope and a DEAN position). Every write goes through the
 * existing services and their authorization, locks and audit:
 *   - AdministrativeFacultyService  (profile + affiliation, as the administrative VP)
 *   - AccountAdministrationService  (account + doctor_instructor, as an account manager)
 *   - UserIdentityLinkService       (link an existing unlinked account to the employee)
 *   - AdministrativeDeanService     (dean role + college scope + DEAN position)
 * Names alone never merge records: an existing employee is used only when the
 * employee number or the approved email matches AND the name agrees.
 * Nothing here creates course assignments, offerings or academic approvals.
 */
class FacultyRosterImportService
{
    public const EXISTING_LINK = 'EXISTING_LINK';
    public const CREATE = 'CREATE';
    public const UPDATE_AFFILIATION = 'UPDATE_AFFILIATION';
    public const NEEDS_REVIEW = 'NEEDS_REVIEW';
    public const CONFLICT = 'CONFLICT';

    public const COLUMNS = [
        'row_no', 'source_college', 'source_name', 'source_designation', 'source_title', 'equivalence_status_source',
        'is_dean', 'college_code', 'first_name', 'last_name', 'name_split_confirmed',
        'employee_number', 'email', 'username', 'replace_current_dean', 'operator_notes',
    ];

    private const INSTRUCTOR_ROLE = 'doctor_instructor';

    public function __construct(
        private readonly AdministrativeFacultyService $faculty,
        private readonly AdministrativeDeanService $deans,
        private readonly AccountAdministrationService $accounts,
        private readonly UserIdentityLinkService $identityLinks,
        private readonly AdministrativeGovernance $governance,
    ) {}

    // ── input ────────────────────────────────────────────────────────────────

    /** @return list<array<string, string>> */
    public function readCsv(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Roster file not found or not readable: {$path}");
        }
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            throw new RuntimeException('Roster file is empty.');
        }
        $header = array_map(fn ($cell) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell)), $header);
        $missing = array_diff(self::COLUMNS, $header);
        if ($missing !== []) {
            throw new RuntimeException('Roster file is missing columns: '.implode(', ', $missing));
        }
        $rows = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($cells === [null] || implode('', $cells) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** Both actors must pass the same authorization as the HTTP paths they stand in for. */
    public function assertActors(User $vpActor, User $accountsActor): void
    {
        foreach ([AdministrativeGovernance::FACULTY_MANAGE, AdministrativeGovernance::DEANS_MANAGE] as $permission) {
            if (! $this->governance->allows($vpActor, $permission)) {
                throw new RuntimeException("--vp-actor {$vpActor->username} lacks {$permission} with an actual university scope.");
            }
        }
        if ($accountsActor->accountStatus?->status_code !== 'active' || ! $accountsActor->hasPermission(AccountAdministration::MANAGE)) {
            throw new RuntimeException("--accounts-actor {$accountsActor->username} lacks ".AccountAdministration::MANAGE.'.');
        }
    }

    // ── preview ──────────────────────────────────────────────────────────────

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>} */
    public function preview(array $rows, string $effectiveDate): array
    {
        $date = $this->effectiveDate($effectiveDate);
        $batch = $this->batchDuplicates($rows);
        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->planRow($row, $batch, $date);
        }

        return ['rows' => $results, 'summary' => $this->summary($results)];
    }

    // ── apply ────────────────────────────────────────────────────────────────

    /**
     * Applies every row whose plan is actionable; each row is one transaction.
     * Rows needing review or in conflict are skipped and reported; a row that fails
     * inside the services is rolled back and reported without stopping the others.
     *
     * @param  callable(array<string, string>): void|null  $onCredentialsIssued
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>, credentials: list<array<string, string>>}
     */
    public function apply(array $rows, User $vpActor, User $accountsActor, string $effectiveDate, ?string $ip = null, ?callable $onCredentialsIssued = null): array
    {
        $this->assertActors($vpActor, $accountsActor);
        $date = $this->effectiveDate($effectiveDate);
        $batch = $this->batchDuplicates($rows);
        $taken = [];
        $credentials = [];
        $results = [];

        foreach ($rows as $row) {
            $issued = null;
            try {
                $result = DB::transaction(function () use ($row, $batch, $date, $vpActor, $accountsActor, $ip, &$taken, &$issued): array {
                    $plan = $this->planRow($row, $batch, $date);
                    if (in_array($plan['status'], [self::NEEDS_REVIEW, self::CONFLICT], true)) {
                        return $plan + ['applied' => false];
                    }

                    return $this->applyRow($plan, $vpActor, $accountsActor, $date, $ip, $taken, $issued) + ['applied' => true];
                });
            } catch (Throwable $exception) {
                $result = $this->planRow($row, $batch, $date);
                $result['applied'] = false;
                $result['status'] = self::CONFLICT;
                $result['reasons'][] = 'فشل التطبيق وأُلغيت معاملة هذا الصف: '.$this->exceptionMessage($exception);
                $issued = null;
            }
            if ($issued !== null && $result['applied']) {
                // Handed over right after the row commits, so a later failure cannot lose it.
                $credentials[] = $issued;
                if ($onCredentialsIssued !== null) {
                    $onCredentialsIssued($issued);
                }
            }
            $results[] = $result;
        }

        $summary = $this->summary($results);
        $this->faculty->audit($vpActor, 'faculty_roster.import_applied', $ip, [
            'effective_date' => $date->toDateString(),
            'rows' => count($results),
            'applied' => count(array_filter($results, fn ($r) => $r['applied'])),
            'by_status' => $summary['by_status'],
            'accounts_created' => count($credentials),
        ]);

        return ['rows' => $results, 'summary' => $summary, 'credentials' => $credentials];
    }

    /** @param array<string, true> $taken */
    private function applyRow(array $plan, User $vpActor, User $accountsActor, Carbon $date, ?string $ip, array &$taken, ?array &$issued): array
    {
        $collegeId = $plan['college']['college_id'];
        $start = $date->toDateString();
        $employee = $plan['employee_id'] ? Employee::query()->find($plan['employee_id']) : null;
        $member = $plan['faculty_member_id'] ? FacultyMember::query()->find($plan['faculty_member_id']) : null;

        // 1. Employee + teaching profile + college affiliation (as the administrative VP).
        if ($employee === null) {
            $member = $this->faculty->create($vpActor, [
                'mode' => 'new',
                'employee_number' => $plan['employee_number'],
                'first_name' => $plan['first_name'],
                'last_name' => $plan['last_name'],
                'email' => $plan['email'],
                'college_id' => $collegeId,
                'start_date' => $start,
            ], $ip);
            $employee = $member->employee()->first();
        } elseif ($member === null) {
            // The plan verified number/email + name; hand the service the record's own identity.
            $member = $this->faculty->create($vpActor, array_filter([
                'mode' => 'link',
                'employee_id' => $employee->employee_id,
                'employee_number' => $employee->employee_number,
                'last_name' => $employee->last_name,
                'college_id' => $plan['already_affiliated'] ? null : $collegeId,
                'start_date' => $start,
            ], fn ($value) => $value !== null), $ip);
        } elseif (! $plan['already_affiliated']) {
            $this->faculty->changeAffiliation($vpActor, $member, ['mode' => 'assign', 'college_id' => $collegeId, 'start_date' => $start], $ip);
        }

        // 2. One login account linked to this employee, with doctor_instructor (as an account manager).
        $instructorRoleId = (int) Role::query()->where('role_code', self::INSTRUCTOR_ROLE)->value('role_id');
        $userId = $plan['user_id'];
        if ($userId === null) {
            $password = TemporaryPassword::unique($taken);
            $user = $this->accounts->create($accountsActor, [
                'username' => $plan['username'],
                'email' => $plan['email'],
                'password' => $password,
                'account_status' => 'active',
                'role_ids' => [$instructorRoleId],
                'employee_id' => $employee->employee_id,
            ], $ip);
            $userId = (int) $user->user_id;
            $issued = [
                'row_no' => (string) $plan['row_no'],
                'source_name' => $plan['source_name'],
                'college_code' => $plan['college']['college_code'],
                'username' => $plan['username'],
                'email' => $plan['email'],
                'temporary_password' => $password,
            ];
        } else {
            $user = User::query()->findOrFail($userId);
            if ($user->employee_id === null) {
                $this->identityLinks->link($user, $accountsActor, ['employee_id' => $employee->employee_id], $ip);
            }
            if (! $user->fresh()->effectiveRoles()->contains(self::INSTRUCTOR_ROLE)) {
                $this->accounts->assignRole($accountsActor, $userId, $instructorRoleId, $ip);
            }
        }

        // 3. Deans: the same account also gets dean + one college scope + DEAN position (as the VP).
        if ($plan['is_dean'] && $plan['dean']['action'] !== 'none') {
            $current = $this->currentDeanIds($collegeId);
            $this->deans->appoint($vpActor, [
                'college_id' => $collegeId,
                'expected_current_dean_user_id' => $current[0] ?? null,
                'replace_current' => $plan['dean']['action'] === 'replace',
                'start_date' => $start,
                'employee' => ['mode' => 'existing', 'employee_id' => $employee->employee_id, 'employee_number' => $employee->employee_number, 'last_name' => $employee->last_name],
                'account' => ['mode' => 'existing', 'user_id' => $userId],
            ], $ip);
        }

        $after = $this->snapshot($employee->fresh(), User::query()->find($userId));
        $plan['employee_id'] = (int) $employee->employee_id;
        $plan['faculty_member_id'] = $after['faculty_member_id'];
        $plan['user_id'] = $userId;
        $plan['after'] = $after;

        return $plan;
    }

    // ── planning ─────────────────────────────────────────────────────────────

    private function planRow(array $row, array $batch, Carbon $date): array
    {
        $reviews = [];
        $conflicts = [];
        $changes = [];
        $rowNo = $row['row_no'] ?? '';
        $isDean = in_array(mb_strtolower($row['is_dean'] ?? ''), ['yes', '1', 'true', 'نعم'], true);
        $number = trim($row['employee_number'] ?? '');
        $email = mb_strtolower(trim($row['email'] ?? ''));
        $first = $this->collapse($row['first_name'] ?? '');
        $last = $this->collapse($row['last_name'] ?? '');
        $username = trim($row['username'] ?? '');
        $usernameProposed = false;
        if ($username === '' && $number !== '') {
            $username = $this->proposedUsername($number);
            $usernameProposed = true;
        }

        // College by code (operator-confirmed in the template), never by numeric id.
        $code = mb_strtoupper(trim($row['college_code'] ?? ''));
        $college = $code === '' ? null : DB::table('colleges')->whereRaw('UPPER(college_code) = ?', [$code])->first();
        if ($code === '') {
            $reviews[] = 'رمز الكلية غير محدد في القالب.';
        } elseif ($college === null) {
            $reviews[] = "رمز الكلية {$code} غير موجود في جدول colleges.";
        } elseif (! $college->is_active || $college->organizational_unit_id === null) {
            $conflicts[] = "الكلية {$code} غير مفعّلة أو غير مرتبطة بوحدة تنظيمية.";
        }

        if ($number === '') {
            $reviews[] = 'الرقم الوظيفي (employee_number) غير متوفر.';
        } elseif (mb_strlen($number) > 50) {
            $reviews[] = 'الرقم الوظيفي أطول من 50 محرفًا.';
        }
        if ($email === '') {
            $reviews[] = 'البريد المعتمد غير متوفر.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150) {
            $reviews[] = 'صيغة البريد المعتمد غير صحيحة.';
        }
        if ($first === '' || $last === '') {
            $reviews[] = 'الاسم الأول أو الكنية غير محدد.';
        } elseif (mb_strtolower($row['name_split_confirmed'] ?? '') !== 'yes') {
            $reviews[] = 'تقسيم الاسم إلى اسم أول وكنية مقترح ويحتاج تأكيد المشغّل (name_split_confirmed=yes).';
        }
        if ($username !== '' && (! preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username))) {
            $reviews[] = 'اسم المستخدم يقبل الأحرف اللاتينية والأرقام والنقطة و- و_ (3–80 محرفًا).';
        }
        foreach (['employee_number' => $number, 'email' => $email, 'username' => mb_strtolower($username)] as $field => $value) {
            if ($value !== '' && ($batch[$field][$value] ?? 0) > 1) {
                $conflicts[] = "القيمة {$field} مكررة في أكثر من صف في الملف.";
            }
        }

        // Employee: by number, then by approved email. A name match alone never links.
        $byNumber = $number === '' ? null : Employee::query()->with('employeeStatus')->where('employee_number', $number)->first();
        $byEmail = $email === '' ? collect() : Employee::query()->with('employeeStatus')->whereRaw('LOWER(email) = ?', [$email])->get();
        if ($byEmail->count() > 1) {
            $conflicts[] = 'البريد مسجّل لأكثر من موظف.';
        }
        if ($byNumber !== null && $byEmail->count() === 1 && (int) $byEmail->first()->employee_id !== (int) $byNumber->employee_id) {
            $conflicts[] = "الرقم الوظيفي يعود للموظف #{$byNumber->employee_id} والبريد يعود للموظف #{$byEmail->first()->employee_id}.";
        }
        $employee = $byNumber ?? ($byEmail->count() === 1 ? $byEmail->first() : null);
        $candidates = [];
        if ($employee !== null) {
            if ($first !== '' && $last !== '' && ! $this->sameName("{$first} {$last}", "{$employee->first_name} {$employee->last_name}")) {
                $conflicts[] = "الرقم الوظيفي أو البريد يعود لموظف قائم باسم مختلف (#{$employee->employee_id}: {$employee->first_name} {$employee->last_name}).";
            }
            if ($employee->employeeStatus?->status_code !== 'active') {
                $conflicts[] = "الموظف القائم #{$employee->employee_id} غير نشط.";
            }
        } elseif ($first !== '' && $last !== '') {
            $candidates = $this->nameCandidates("{$first} {$last}");
            if ($candidates !== []) {
                $reviews[] = 'يوجد موظف قائم باسم مشابه دون تطابق الرقم الوظيفي أو البريد؛ راجع قبل الإنشاء: '
                    .implode('، ', array_map(fn ($c) => "#{$c['employee_id']} ({$c['employee_number']})", $candidates)).'.';
            }
        }

        $member = $employee ? FacultyMember::query()->where('employee_id', $employee->employee_id)->first() : null;
        if ($member !== null && ! $member->is_active) {
            $conflicts[] = "الملف التدريسي القائم #{$member->faculty_member_id} غير مفعّل؛ لا يُفعّل تلقائيًا.";
        }
        $colleges = $employee ? (CollegeAffiliation::collegesForEmployees([(int) $employee->employee_id])[(int) $employee->employee_id] ?? []) : [];
        $alreadyAffiliated = $college !== null && collect($colleges)->contains('college_id', (int) $college->college_id);

        // Account: the one linked to the employee, or an unlinked one with the same email.
        $byEmployee = $employee ? User::query()->with('accountStatus')->where('employee_id', $employee->employee_id)->first() : null;
        $byAccountEmail = $email === '' ? null : User::query()->with('accountStatus')->whereRaw('LOWER(email) = ?', [$email])->first();
        $byUsername = $username === '' ? null : User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->first();
        $account = null;
        if ($byEmployee !== null) {
            $account = $byEmployee;
            if ($byAccountEmail !== null && (int) $byAccountEmail->user_id !== (int) $byEmployee->user_id) {
                $conflicts[] = "البريد المعتمد مستخدم للحساب #{$byAccountEmail->user_id} بينما حساب الموظف هو #{$byEmployee->user_id}.";
            } elseif ($email !== '' && mb_strtolower($byEmployee->email) !== $email) {
                $conflicts[] = "حساب الموظف القائم #{$byEmployee->user_id} مسجّل ببريد مختلف ({$byEmployee->email}); لا يُعدَّل البريد تلقائيًا.";
            }
        } elseif ($byAccountEmail !== null) {
            if ($byAccountEmail->employee_id !== null) {
                $conflicts[] = "البريد المعتمد مستخدم لحساب #{$byAccountEmail->user_id} مرتبط بموظف آخر.";
            } else {
                $account = $byAccountEmail;
                $changes[] = "ربط الحساب القائم #{$account->user_id} ({$account->username}) بسجل الموظف.";
            }
        }
        if ($byUsername !== null && ($account === null || (int) $byUsername->user_id !== (int) $account->user_id)) {
            $conflicts[] = "اسم المستخدم {$username} مستخدم لحساب آخر (#{$byUsername->user_id}).";
        }
        $accountRoles = $account ? $account->effectiveRoles()->values()->all() : [];
        if ($account !== null) {
            if (in_array('super_admin', $accountRoles, true)
                || DB::table('user_access_scopes')->where('user_id', $account->user_id)->where('scope_type', 'university')->where('is_active', 1)->exists()) {
                $conflicts[] = "الحساب #{$account->user_id} محمي (مدير نظام أو نطاق جامعة) ولا يُعدَّل من هذا المسار.";
            }
            if ($account->accountStatus?->status_code !== 'active') {
                $conflicts[] = "الحساب #{$account->user_id} غير مفعّل؛ لا يُفعّل تلقائيًا.";
            }
            if ($isDean) {
                $foreign = array_diff($accountRoles, AdministrativeGovernance::DEAN_COMPATIBLE_ROLES);
                if ($foreign !== []) {
                    $conflicts[] = 'حساب العميد يحمل أدوارًا لا يديرها مسار العمداء: '.implode('، ', $foreign).'.';
                }
            }
        }

        // Dean: never replace a current dean unless the operator asked for it explicitly.
        $dean = ['action' => 'none', 'current_dean_user_ids' => []];
        if ($isDean && $college !== null) {
            $current = $this->currentDeanIds((int) $college->college_id);
            $dean['current_dean_user_ids'] = $current;
            $alreadyDean = $account !== null && $current === [(int) $account->user_id];
            if (! $alreadyDean) {
                if ($current !== [] && ! ($account !== null && in_array((int) $account->user_id, $current, true))) {
                    if (mb_strtolower($row['replace_current_dean'] ?? '') === 'yes') {
                        $dean['action'] = 'replace';
                        $changes[] = 'استبدال العميد الحالي ('.$this->usernames($current).') بطلب صريح من المشغّل؛ يُنهى تكليفه ويبقى حسابه.';
                    } else {
                        $conflicts[] = 'للكلية عميد حالي ('.$this->usernames($current).'); لا يُستبدل تلقائيًا (replace_current_dean=yes للاستبدال الصريح).';
                    }
                } else {
                    $dean['action'] = 'appoint';
                }
                if ($account !== null) {
                    $otherColleges = DB::table('user_access_scopes')->where('user_id', $account->user_id)->where('scope_type', 'college')
                        ->where('is_active', 1)->where('scope_id', '!=', (int) $college->college_id)->pluck('scope_id')->all();
                    if ($otherColleges !== [] && in_array('dean', $accountRoles, true)) {
                        $conflicts[] = 'الحساب عميد لكلية أخرى؛ النقل يتم من صفحة عمداء الكليات.';
                    }
                }
                if ($dean['action'] !== 'none') {
                    $changes[] = "تعيين عميدًا للكلية {$code}: دور dean + نطاق الكلية + منصب DEAN من {$date->toDateString()}.";
                }
            }
        }

        if ($employee === null) {
            array_unshift($changes, 'إنشاء سجل موظف أكاديمي وملف تدريسي، وانتماء إلى الكلية من '.$date->toDateString().'.');
        } else {
            if ($member === null) {
                array_unshift($changes, "إنشاء ملف تدريسي للموظف القائم #{$employee->employee_id}.");
            }
            if (! $alreadyAffiliated && $college !== null) {
                $changes[] = "إسناد انتماء إلى الكلية {$code} من {$date->toDateString()}".($colleges !== [] ? ' مع بقاء الانتماءات الحالية.' : '.');
            }
        }
        if ($account === null) {
            $changes[] = "إنشاء حساب دخول {$username} بدور doctor_instructor مرتبط بسجل الموظف (كلمة مرور مؤقتة فريدة).";
        } elseif (! in_array(self::INSTRUCTOR_ROLE, $accountRoles, true)) {
            $changes[] = "إسناد دور doctor_instructor للحساب القائم #{$account->user_id}.";
        }
        if ($usernameProposed && $account === null) {
            $changes[] = "اسم المستخدم مقترح من الرقم الوظيفي: {$username}.";
        }

        $status = match (true) {
            $conflicts !== [] => self::CONFLICT,
            $reviews !== [] => self::NEEDS_REVIEW,
            $employee === null => self::CREATE,
            ! $alreadyAffiliated => self::UPDATE_AFFILIATION,
            default => self::EXISTING_LINK,
        };

        return [
            'row_no' => $rowNo,
            'source_college' => $row['source_college'] ?? '',
            'source_name' => $row['source_name'] ?? '',
            'source_designation' => $row['source_designation'] ?? '',
            'source_title' => $row['source_title'] ?? '',
            'equivalence_status_source' => $row['equivalence_status_source'] ?? '',
            'is_dean' => $isDean,
            'college' => $college === null ? ['college_id' => null, 'college_code' => $code, 'college_name' => null]
                : ['college_id' => (int) $college->college_id, 'college_code' => $college->college_code, 'college_name' => $college->college_name],
            'first_name' => $first,
            'last_name' => $last,
            'employee_number' => $number,
            'email' => $email,
            'username' => $username,
            'status' => $status,
            'reasons' => array_values(array_merge($conflicts, $reviews)),
            'changes' => $status === self::CONFLICT || $status === self::NEEDS_REVIEW ? [] : $changes,
            'name_candidates' => $candidates,
            'employee_id' => $employee ? (int) $employee->employee_id : null,
            'faculty_member_id' => $member ? (int) $member->faculty_member_id : null,
            'user_id' => $account ? (int) $account->user_id : null,
            'already_affiliated' => $alreadyAffiliated,
            'dean' => $dean,
            'before' => $this->snapshot($employee, $account),
            'after' => null,
        ];
    }

    private function snapshot(?Employee $employee, ?User $user): array
    {
        $colleges = $employee ? (CollegeAffiliation::collegesForEmployees([(int) $employee->employee_id])[(int) $employee->employee_id] ?? []) : [];

        return [
            'employee_id' => $employee?->employee_id,
            'faculty_member_id' => $employee ? FacultyMember::query()->where('employee_id', $employee->employee_id)->value('faculty_member_id') : null,
            'colleges' => array_map(fn ($c) => ['college_id' => $c['college_id'], 'start_date' => $c['start_date'] ? substr((string) $c['start_date'], 0, 10) : null, 'source' => $c['source']], $colleges),
            'user_id' => $user?->user_id,
            'roles' => $user ? $user->effectiveRoles()->sort()->values()->all() : [],
            'college_scopes' => $user ? DB::table('user_access_scopes')->where('user_id', $user->user_id)->where('scope_type', 'college')->where('is_active', 1)->pluck('scope_id')->map(fn ($id) => (int) $id)->all() : [],
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function effectiveDate(string $value): Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException('--effective-date must be YYYY-MM-DD.');
        }

        return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
    }

    /** @return array<string, array<string, int>> */
    private function batchDuplicates(array $rows): array
    {
        $counts = ['employee_number' => [], 'email' => [], 'username' => []];
        foreach ($rows as $row) {
            $number = trim($row['employee_number'] ?? '');
            $values = [
                'employee_number' => $number,
                'email' => mb_strtolower(trim($row['email'] ?? '')),
                'username' => mb_strtolower(trim($row['username'] ?? '') ?: ($number === '' ? '' : $this->proposedUsername($number))),
            ];
            foreach ($values as $field => $value) {
                if ($value !== '') {
                    $counts[$field][$value] = ($counts[$field][$value] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /** @return list<int> */
    private function currentDeanIds(int $collegeId): array
    {
        return DB::table('user_access_scopes as s')
            ->join('user_roles as ur', 'ur.user_id', '=', 's.user_id')
            ->join('roles as r', fn ($j) => $j->on('r.role_id', '=', 'ur.role_id')->where('r.role_code', 'dean'))
            ->where('ur.is_active', 1)->where('s.scope_type', 'college')->where('s.scope_id', $collegeId)->where('s.is_active', 1)
            ->orderBy('s.user_id')->distinct()->pluck('s.user_id')->map(fn ($id) => (int) $id)->all();
    }

    private function usernames(array $userIds): string
    {
        return User::query()->whereIn('user_id', $userIds)->pluck('username')->implode('، ');
    }

    private function proposedUsername(string $number): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', $number));

        return 'emp.'.trim($slug, '-.');
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /** Arabic-insensitive comparison for identity checks (hamza/alef, ta marbuta, ya, tatweel, spaces). */
    public static function normalizeName(string $value): string
    {
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value);
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);

        return mb_strtolower(preg_replace('/\s+/u', '', $value));
    }

    private function sameName(string $a, string $b): bool
    {
        return self::normalizeName($a) === self::normalizeName($b);
    }

    /** @return list<array{employee_id:int, employee_number:string}> */
    private function nameCandidates(string $fullName): array
    {
        $target = self::normalizeName($fullName);
        $candidates = [];
        Employee::query()->select(['employee_id', 'employee_number', 'first_name', 'last_name'])->orderBy('employee_id')
            ->chunk(500, function ($employees) use ($target, &$candidates): void {
                foreach ($employees as $employee) {
                    if (self::normalizeName("{$employee->first_name} {$employee->last_name}") === $target) {
                        $candidates[] = ['employee_id' => (int) $employee->employee_id, 'employee_number' => (string) $employee->employee_number];
                    }
                }
            });

        return $candidates;
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->implode(' ');
        }
        if ($exception instanceof AdministrativeGovernanceException || $exception instanceof AccountAdministrationException) {
            return $exception->getMessage().' ('.($exception->errorCode ?? 'error').')';
        }

        return class_basename($exception).': '.$exception->getMessage();
    }

    /** @return array<string, mixed> */
    private function summary(array $results): array
    {
        $byStatus = array_fill_keys([self::EXISTING_LINK, self::CREATE, self::UPDATE_AFFILIATION, self::NEEDS_REVIEW, self::CONFLICT], 0);
        $byCollege = [];
        foreach ($results as $result) {
            $byStatus[$result['status']]++;
            $key = $result['college']['college_code'] ?: ($result['source_college'] ?: '—');
            $byCollege[$key] ??= ['college_name' => $result['college']['college_name'] ?? $result['source_college'], 'rows' => 0, 'deans' => 0, 'ready' => 0, 'blocked' => 0] + array_fill_keys(array_keys($byStatus), 0);
            $byCollege[$key]['rows']++;
            $byCollege[$key]['deans'] += $result['is_dean'] ? 1 : 0;
            $byCollege[$key][$result['status']]++;
            in_array($result['status'], [self::NEEDS_REVIEW, self::CONFLICT], true) ? $byCollege[$key]['blocked']++ : $byCollege[$key]['ready']++;
        }
        $ready = array_filter($results, fn ($r) => ! in_array($r['status'], [self::NEEDS_REVIEW, self::CONFLICT], true));

        return [
            'rows' => count($results),
            'dean_rows' => count(array_filter($results, fn ($r) => $r['is_dean'])),
            'ready' => count($ready),
            'ready_deans' => count(array_filter($ready, fn ($r) => $r['is_dean'])),
            'blocked' => count($results) - count($ready),
            'by_status' => $byStatus,
            'by_college' => $byCollege,
        ];
    }
}
