<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\Payroll\PayrollBody;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEntry;
use App\Support\PayrollMoney;
use App\Support\PayrollWorkplace;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Owner payroll working sheet (phase 1): one current sheet, no periods.
 *
 * Everything here operates on the three isolated payroll tables only. Money is integer cents; the net
 * payable is always computed here from stored values and is never accepted from a client.
 */
class PayrollSheetService
{
    public const AMOUNT_FIELDS = ['fixed_salary', 'deduction', 'compensation'];

    public const MAX_BATCH = 1000;

    public const SORTS = ['employee_number', 'full_name', 'job_title', 'body', 'workplace', 'academic_level', 'fixed_salary', 'deduction', 'compensation', 'payable'];

    public const BLANK_LEVEL = '__blank__';

    private const PAYABLE_SQL = 'CASE WHEN n.fixed_salary_cents IS NULL THEN NULL ELSE n.fixed_salary_cents - COALESCE(n.deduction_cents, 0) + COALESCE(n.compensation_cents, 0) END';

    // ── filters and reads ─────────────────────────────────────────────────

    /** Normalise the sheet query string (also used by exports, so grid and files agree). */
    public function filters(array $input): array
    {
        $sort = in_array($input['sort'] ?? null, self::SORTS, true) ? $input['sort'] : 'employee_number';
        $search = PayrollText::clean($input['search'] ?? null);

        return [
            'search' => $search === null ? null : mb_substr($search, 0, 100),
            'body_id' => isset($input['body_id']) && $input['body_id'] !== '' ? (int) $input['body_id'] : null,
            'workplace' => in_array($input['workplace'] ?? null, PayrollWorkplace::codes(), true) ? $input['workplace'] : null,
            'academic_level' => isset($input['academic_level']) && $input['academic_level'] !== '' ? (string) $input['academic_level'] : null,
            'sort' => $sort,
            'direction' => ($input['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        ];
    }

    public function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'body_id' => ['nullable', 'integer', 'min:1'],
            'workplace' => ['nullable', 'string', 'in:'.implode(',', PayrollWorkplace::codes())],
            'academic_level' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    private function base(): Builder
    {
        return DB::table('payroll_employees as e')
            ->join('payroll_bodies as b', 'b.id', '=', 'e.payroll_body_id')
            ->join('payroll_entries as n', 'n.payroll_employee_id', '=', 'e.id');
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['body_id'] !== null) {
            $query->where('e.payroll_body_id', $filters['body_id']);
        }
        if ($filters['workplace'] !== null) {
            $query->where('e.workplace', $filters['workplace']);
        }
        if ($filters['academic_level'] !== null) {
            $filters['academic_level'] === self::BLANK_LEVEL
                ? $query->whereNull('e.academic_level')
                : $query->where('e.academic_level', $filters['academic_level']);
        }
        if ($filters['search'] !== null) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $codes = array_keys(array_filter(PayrollWorkplace::LABELS, fn ($label) => mb_stripos($label, $filters['search']) !== false));
            $query->where(function (Builder $q) use ($like, $codes): void {
                foreach (['e.employee_number', 'e.full_name', 'e.job_title', 'b.name', 'e.academic_level', 'e.workplace_other'] as $column) {
                    $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
                }
                if ($codes !== []) {
                    $q->orWhereIn('e.workplace', $codes);
                }
            });
        }

        return $query;
    }

    private function applySort(Builder $query, array $filters): Builder
    {
        $direction = $filters['direction'] === 'desc' ? 'DESC' : 'ASC';
        $workplace = 'CASE e.workplace '.collect(PayrollWorkplace::LABELS)
            ->except(PayrollWorkplace::OTHER)
            ->map(fn ($label, $code) => "WHEN '{$code}' THEN '{$label}'")->implode(' ')
            ." ELSE COALESCE(e.workplace_other, '".PayrollWorkplace::LABELS[PayrollWorkplace::OTHER]."') END";
        $expression = match ($filters['sort']) {
            'employee_number' => 'e.employee_number',
            'full_name' => 'e.full_name',
            'job_title' => 'e.job_title',
            'body' => 'b.name',
            'workplace' => $workplace,
            'academic_level' => 'e.academic_level',
            'fixed_salary' => 'n.fixed_salary_cents',
            'deduction' => 'n.deduction_cents',
            'compensation' => 'n.compensation_cents',
            'payable' => self::PAYABLE_SQL,
        };
        // Blanks always sort last, whatever the direction; ties break on the employee number, then id.
        $query->orderByRaw("({$expression}) IS NULL ASC")
            ->orderByRaw("({$expression}) {$direction}");
        if ($filters['sort'] !== 'employee_number') {
            $query->orderBy('e.employee_number');
        }

        return $query->orderBy('e.id');
    }

    private const COLUMNS = [
        'e.id', 'e.employee_number', 'e.full_name', 'e.job_title', 'e.payroll_body_id', 'b.name as body_name', 'b.is_active as body_is_active',
        'e.workplace', 'e.workplace_other', 'e.academic_level', 'e.revision as employee_revision', 'e.updated_at as employee_updated_at',
        'n.fixed_salary_cents', 'n.deduction_cents', 'n.compensation_cents', 'n.revision as entry_revision', 'n.updated_at as entry_updated_at',
    ];

    /** API projection of a joined row. Money is a signed two-decimal string or null (blank). */
    public function present(object $row): array
    {
        $salary = $row->fixed_salary_cents === null ? null : (int) $row->fixed_salary_cents;
        $deduction = $row->deduction_cents === null ? null : (int) $row->deduction_cents;
        $compensation = $row->compensation_cents === null ? null : (int) $row->compensation_cents;

        return [
            'id' => (int) $row->id,
            'employee_number' => (string) $row->employee_number,
            'full_name' => $row->full_name,
            'job_title' => $row->job_title,
            'body_id' => (int) $row->payroll_body_id,
            'body_name' => $row->body_name,
            'body_is_active' => (bool) $row->body_is_active,
            'workplace' => $row->workplace,
            'workplace_other' => $row->workplace_other,
            'workplace_label' => PayrollWorkplace::display($row->workplace, $row->workplace_other),
            'academic_level' => $row->academic_level,
            'fixed_salary' => PayrollMoney::format($salary),
            'deduction' => PayrollMoney::format($deduction),
            'compensation' => PayrollMoney::format($compensation),
            'payable' => PayrollMoney::format(PayrollMoney::payable($salary, $deduction, $compensation)),
            'employee_revision' => (int) $row->employee_revision,
            'entry_revision' => (int) $row->entry_revision,
            'updated_at' => max((string) $row->employee_updated_at, (string) $row->entry_updated_at) ?: null,
        ];
    }

    /** One row by employee id (same projection as the grid), or null. */
    public function row(int $employeeId): ?array
    {
        $row = $this->base()->where('e.id', $employeeId)->first(self::COLUMNS);

        return $row === null ? null : $this->present($row);
    }

    /**
     * Consistent read of everything matching the filters, in the requested order: rows and totals come from the
     * same transaction and the totals are summed from those very rows (the grid, Home and exports can never disagree).
     */
    public function snapshot(array $filters): array
    {
        return DB::transaction(function () use ($filters): array {
            $rows = $this->applySort($this->applyFilters($this->base(), $filters), $filters)->get(self::COLUMNS)
                ->map(fn ($row) => $this->present($row))->values();

            return ['rows' => $rows, 'totals' => $this->totals($rows), 'generated_at' => now()->toIso8601String(), 'filters' => $filters];
        });
    }

    /** Sum of the displayed rows. Payable sums only rows that have a fixed salary (blank salary => blank payable). */
    public function totals(Collection $rows): array
    {
        $sum = fn (string $key) => $rows->reduce(fn (int $carry, array $row) => $carry + ($row[$key] === null ? 0 : (int) round((float) $row[$key] * 100)), 0);

        return [
            'employees' => $rows->count(),
            'fixed_salary' => PayrollMoney::format($sum('fixed_salary')),
            'deduction' => PayrollMoney::format($sum('deduction')),
            'compensation' => PayrollMoney::format($sum('compensation')),
            'payable' => PayrollMoney::format($sum('payable')),
        ];
    }

    /** Home summary: the same totals computed by the database over the whole sheet. */
    public function home(): array
    {
        return DB::transaction(function (): array {
            $row = $this->base()->selectRaw(
                'COUNT(*) AS employees, SUM(n.fixed_salary_cents) AS fixed, SUM(n.deduction_cents) AS deduction, SUM(n.compensation_cents) AS compensation, SUM('.self::PAYABLE_SQL.') AS payable'
            )->first();

            return [
                'employees' => (int) $row->employees,
                'fixed_salary' => PayrollMoney::format((int) $row->fixed),
                'deduction' => PayrollMoney::format((int) $row->deduction),
                'compensation' => PayrollMoney::format((int) $row->compensation),
                'payable' => PayrollMoney::format((int) $row->payable),
                'bodies' => (int) DB::table('payroll_bodies')->count(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    public function options(): array
    {
        return [
            'bodies' => $this->bodies(),
            'workplaces' => collect(PayrollWorkplace::LABELS)->map(fn ($label, $code) => ['value' => $code, 'label' => $label])->values(),
            'academic_levels' => DB::table('payroll_employees')->whereNotNull('academic_level')->distinct()->orderBy('academic_level')->pluck('academic_level')->values(),
        ];
    }

    /** Human-readable description of the active filters, for the on-screen scope label and the exports. */
    public function scopeLabels(array $filters): array
    {
        $labels = [];
        if ($filters['search'] !== null) {
            $labels[] = 'بحث: '.$filters['search'];
        }
        if ($filters['body_id'] !== null) {
            $labels[] = 'الهيئة: '.(DB::table('payroll_bodies')->where('id', $filters['body_id'])->value('name') ?? 'غير موجودة');
        }
        if ($filters['workplace'] !== null) {
            $labels[] = 'مكان العمل: '.PayrollWorkplace::LABELS[$filters['workplace']];
        }
        if ($filters['academic_level'] !== null) {
            $labels[] = 'المستوى الأكاديمي: '.($filters['academic_level'] === self::BLANK_LEVEL ? 'غير محدد' : $filters['academic_level']);
        }

        return $labels;
    }

    // ── payroll bodies ────────────────────────────────────────────────────

    public function bodies(): Collection
    {
        return DB::table('payroll_bodies as b')
            ->leftJoin('payroll_employees as e', 'e.payroll_body_id', '=', 'b.id')
            ->groupBy('b.id', 'b.name', 'b.is_active', 'b.revision')
            ->orderBy('b.name')
            ->get(['b.id', 'b.name', 'b.is_active', 'b.revision', DB::raw('COUNT(e.id) AS employee_count')])
            ->map(fn ($b) => $this->presentBody($b))->values();
    }

    private function presentBody(object $b): array
    {
        return ['id' => (int) $b->id, 'name' => $b->name, 'is_active' => (bool) $b->is_active, 'revision' => (int) $b->revision, 'employee_count' => (int) $b->employee_count];
    }

    private function bodyPresented(int $id): array
    {
        return $this->bodies()->firstWhere('id', $id);
    }

    private function validatedBodyName(mixed $name, ?int $ignoreId): string
    {
        $name = PayrollText::clean(is_string($name) ? $name : null);
        $error = null;
        if ($name === null) {
            $error = 'اسم الهيئة مطلوب.';
        } elseif (mb_strlen($name) > 150) {
            $error = 'اسم الهيئة طويل (الحد الأقصى 150 حرفًا).';
        } elseif (PayrollText::hasControlCharacters($name)) {
            $error = 'اسم الهيئة يحتوي على محارف غير مسموحة.';
        } elseif (DB::table('payroll_bodies')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $error = 'توجد هيئة بهذا الاسم.';
        }
        if ($error !== null) {
            throw new PayrollException($error, 'payroll_validation', 422, ['name' => [$error]]);
        }

        return $name;
    }

    public function createBody(mixed $name, int $userId): array
    {
        $name = $this->validatedBodyName($name, null);
        try {
            $body = PayrollBody::create(['name' => $name, 'is_active' => true, 'revision' => 1, 'created_by_user_id' => $userId, 'updated_by_user_id' => $userId]);
        } catch (UniqueConstraintViolationException) {
            throw new PayrollException('توجد هيئة بهذا الاسم.', 'payroll_validation', 422, ['name' => ['توجد هيئة بهذا الاسم.']]);
        }

        return $this->bodyPresented($body->id);
    }

    public function renameBody(int $id, mixed $name, int $expectedRevision, int $userId): array
    {
        return DB::transaction(function () use ($id, $name, $expectedRevision, $userId): array {
            $body = $this->lockedBody($id, $expectedRevision);
            $name = $this->validatedBodyName($name, $id);
            if ($name !== $body->name) {
                try {
                    $body->update(['name' => $name, 'revision' => $body->revision + 1, 'updated_by_user_id' => $userId]);
                } catch (UniqueConstraintViolationException) {
                    throw new PayrollException('توجد هيئة بهذا الاسم.', 'payroll_validation', 422, ['name' => ['توجد هيئة بهذا الاسم.']]);
                }
            }

            return $this->bodyPresented($id);
        });
    }

    public function setBodyActive(int $id, bool $active, int $expectedRevision, int $userId): array
    {
        return DB::transaction(function () use ($id, $active, $expectedRevision, $userId): array {
            $body = $this->lockedBody($id, $expectedRevision);
            if ($body->is_active !== $active) {
                $body->update(['is_active' => $active, 'revision' => $body->revision + 1, 'updated_by_user_id' => $userId]);
            }

            return $this->bodyPresented($id);
        });
    }

    public function deleteBody(int $id, int $expectedRevision): void
    {
        DB::transaction(function () use ($id, $expectedRevision): void {
            $body = $this->lockedBody($id, $expectedRevision);
            if (PayrollEmployee::where('payroll_body_id', $id)->exists()) {
                throw new PayrollException('لا يمكن حذف هيئة مرتبطة بموظفين؛ عطّلها بدلًا من حذفها.', 'payroll_body_in_use', 409);
            }
            $body->delete();
        });
    }

    private function lockedBody(int $id, int $expectedRevision): PayrollBody
    {
        $body = PayrollBody::query()->lockForUpdate()->find($id);
        if ($body === null) {
            throw new PayrollException('الهيئة غير موجودة.', 'not_found', 404);
        }
        if ($body->revision !== $expectedRevision) {
            throw new PayrollException('عُدّلت هذه الهيئة من جهة أخرى؛ حدّث القائمة وأعد المحاولة.', 'payroll_conflict', 409, [], ['current' => $this->bodyPresented($id)]);
        }

        return $body;
    }

    // ── payroll employees ─────────────────────────────────────────────────

    public function createEmployee(array $input, int $userId): array
    {
        $data = $this->validatedEmployee($input, null);
        try {
            $id = DB::transaction(function () use ($data, $userId): int {
                $employee = PayrollEmployee::create($data + ['revision' => 1, 'created_by_user_id' => $userId, 'updated_by_user_id' => $userId]);
                // Blank financial inputs: all NULL, never prefilled with zero.
                PayrollEntry::create(['payroll_employee_id' => $employee->id, 'revision' => 1, 'updated_by_user_id' => $userId]);

                return $employee->id;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateNumber();
        }

        return $this->row($id);
    }

    public function updateEmployee(int $id, array $input, int $expectedRevision, int $userId): array
    {
        try {
            DB::transaction(function () use ($id, $input, $expectedRevision, $userId): void {
                $employee = PayrollEmployee::query()->lockForUpdate()->find($id);
                if ($employee === null) {
                    throw new PayrollException('الموظف غير موجود.', 'not_found', 404);
                }
                if ($employee->revision !== $expectedRevision) {
                    throw new PayrollException('عُدّلت بيانات هذا الموظف من جهة أخرى؛ حدّث الصف وأعد المحاولة.', 'payroll_conflict', 409, [], ['current' => $this->row($id)]);
                }
                $data = $this->validatedEmployee($input, $employee);
                $employee->fill($data);
                if ($employee->isDirty()) {
                    $employee->fill(['revision' => $employee->revision + 1, 'updated_by_user_id' => $userId])->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateNumber();
        }

        return $this->row($id);
    }

    private function duplicateNumber(): PayrollException
    {
        return new PayrollException('رقم الموظف مستخدم لموظف آخر.', 'payroll_validation', 422, ['employee_number' => ['رقم الموظف مستخدم لموظف آخر.']]);
    }

    /** @return array{employee_number:string, full_name:string, job_title:string, payroll_body_id:int, workplace:string, workplace_other:?string, academic_level:?string} */
    private function validatedEmployee(array $input, ?PayrollEmployee $existing): array
    {
        $number = PayrollText::clean($input['employee_number'] ?? null);
        $name = PayrollText::clean($input['full_name'] ?? null);
        $title = PayrollText::clean($input['job_title'] ?? null);
        $level = PayrollText::clean($input['academic_level'] ?? null);
        $workplace = $input['workplace'] ?? null;
        $other = PayrollText::clean($input['workplace_other'] ?? null);
        $bodyId = $input['payroll_body_id'] ?? ($input['body_id'] ?? null);

        $errors = [];
        if ($number === null) {
            $errors['employee_number'][] = 'رقم الموظف مطلوب.';
        } elseif (mb_strlen($number) > 64 || ! preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $number)) {
            $errors['employee_number'][] = 'رقم الموظف يقبل الحروف والأرقام و - _ . / فقط (حتى 64 محرفًا).';
        } elseif (DB::table('payroll_employees')->whereRaw('LOWER(employee_number) = ?', [mb_strtolower($number)])->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            $errors['employee_number'][] = 'رقم الموظف مستخدم لموظف آخر.';
        }
        foreach ([['full_name', $name, 'الاسم الكامل', 255, true], ['job_title', $title, 'الصفة الوظيفية', 255, true], ['academic_level', $level, 'المستوى الأكاديمي', 255, false]] as [$key, $value, $label, $max, $required]) {
            if ($value === null) {
                if ($required) {
                    $errors[$key][] = "{$label} مطلوب.";
                }
            } elseif (mb_strlen($value) > $max || PayrollText::hasControlCharacters($value)) {
                $errors[$key][] = "{$label} غير صالح (حتى {$max} حرفًا دون محارف تحكم).";
            }
        }
        if (! is_string($workplace) || ! in_array($workplace, PayrollWorkplace::codes(), true)) {
            $errors['workplace'][] = 'مكان العمل مطلوب ويجب أن يكون أحد الخيارات المعروضة.';
        } elseif ($workplace === PayrollWorkplace::OTHER) {
            if ($other === null) {
                $errors['workplace_other'][] = 'اكتب مكان العمل عند اختيار «أخرى».';
            } elseif (mb_strlen($other) > 150 || PayrollText::hasControlCharacters($other)) {
                $errors['workplace_other'][] = 'مكان العمل غير صالح (حتى 150 حرفًا).';
            }
        } else {
            $other = null; // obsolete custom-location text never survives a switch away from "other"
        }
        $body = is_numeric($bodyId) ? PayrollBody::find((int) $bodyId) : null;
        if ($body === null) {
            $errors['body_id'][] = 'الهيئة مطلوبة ويجب أن تكون من قائمة الهيئات.';
        } elseif (! $body->is_active && (int) $existing?->payroll_body_id !== $body->id) {
            $errors['body_id'][] = 'الهيئة المختارة معطّلة ولا يمكن إسناد موظف جديد إليها.';
        }
        if ($errors !== []) {
            throw new PayrollException('تعذّر حفظ بيانات الموظف؛ راجع الحقول المحددة.', 'payroll_validation', 422, $errors);
        }

        return [
            'employee_number' => $number, 'full_name' => $name, 'job_title' => $title, 'payroll_body_id' => $body->id,
            'workplace' => $workplace, 'workplace_other' => $other, 'academic_level' => $level,
        ];
    }

    // ── amounts (financial inputs), atomic per request ────────────────────

    /**
     * Apply a batch of cell changes all-or-nothing. Each change: employee_id, expected_revision (of that employee's
     * entry) and any of fixed_salary / deduction / compensation (a present key with null/'' clears the cell).
     *
     * Invalid values => 422 and nothing is written. Any stale revision => 409 and nothing is written.
     *
     * @return array{rows: list<array>}
     */
    public function updateAmounts(array $changes, int $userId): array
    {
        if ($changes === [] || count($changes) > self::MAX_BATCH) {
            throw new PayrollException('عدد الخلايا المرسلة غير مقبول (1 إلى '.self::MAX_BATCH.' صفًا).', 'payroll_validation', 422, ['changes' => ['عدد الصفوف يجب أن يكون بين 1 و '.self::MAX_BATCH.'.']]);
        }

        $errors = [];
        $parsed = [];
        $seen = [];
        foreach (array_values($changes) as $i => $change) {
            $id = is_array($change) ? ($change['employee_id'] ?? null) : null;
            $revision = is_array($change) ? ($change['expected_revision'] ?? null) : null;
            if (! is_int($id) || $id < 1) {
                $errors["changes.{$i}.employee_id"][] = 'معرّف الموظف غير صالح.';

                continue;
            }
            if (! is_int($revision) || $revision < 1) {
                $errors["changes.{$i}.expected_revision"][] = 'رقم نسخة الصف مطلوب.';
            }
            if (isset($seen[$id])) {
                $errors["changes.{$i}.employee_id"][] = 'الموظف مكرر في الطلب نفسه.';
            }
            $seen[$id] = true;
            $values = [];
            foreach (self::AMOUNT_FIELDS as $field) {
                if (! array_key_exists($field, $change)) {
                    continue;
                }
                try {
                    $values[$field] = PayrollMoney::parse($change[$field]);
                } catch (InvalidArgumentException $e) {
                    $errors["changes.{$i}.{$field}"][] = match ($e->getMessage()) {
                        'negative' => 'القيمة لا يمكن أن تكون سالبة.',
                        'out_of_range' => 'القيمة أكبر من الحد المسموح (999999999.99).',
                        default => 'قيمة غير صالحة؛ أدخل رقمًا بحد أقصى منزلتين عشريتين.',
                    };
                }
            }
            if ($values === []) {
                $errors["changes.{$i}.employee_id"][] = 'لا توجد قيمة للتعديل.';
            }
            $parsed[$i] = ['employee_id' => $id, 'expected_revision' => $revision, 'values' => $values];
        }
        if ($errors !== []) {
            throw new PayrollException('قيم غير صالحة؛ لم يُحفظ شيء من هذه العملية.', 'payroll_validation', 422, $errors);
        }

        return DB::transaction(function () use ($parsed, $userId): array {
            $ids = collect($parsed)->pluck('employee_id')->sort()->values()->all();
            $entries = PayrollEntry::query()->whereIn('payroll_employee_id', $ids)->orderBy('payroll_employee_id')->lockForUpdate()->get()->keyBy('payroll_employee_id');

            $missing = [];
            $conflicts = [];
            foreach ($parsed as $i => $change) {
                $entry = $entries->get($change['employee_id']);
                if ($entry === null) {
                    $missing["changes.{$i}.employee_id"][] = 'الموظف غير موجود.';
                } elseif ($entry->revision !== $change['expected_revision']) {
                    $conflicts[] = $change['employee_id'];
                }
            }
            if ($missing !== []) {
                throw new PayrollException('بعض الموظفين غير موجودين؛ لم يُحفظ شيء.', 'not_found', 404, $missing);
            }
            if ($conflicts !== []) {
                throw new PayrollException(
                    'عُدّلت بعض هذه الخلايا من جهة أخرى؛ لم يُحفظ شيء، وبقيت قيمك المعلّقة كما هي.',
                    'payroll_conflict', 409, [],
                    ['conflicts' => collect($conflicts)->map(fn ($id) => ['employee_id' => $id, 'current' => $this->row($id)])->all()],
                );
            }

            foreach ($parsed as $change) {
                $entry = $entries->get($change['employee_id']);
                $attributes = [];
                foreach ($change['values'] as $field => $cents) {
                    $attributes["{$field}_cents"] = $cents;
                }
                $entry->fill($attributes);
                if ($entry->isDirty()) {
                    $entry->fill(['revision' => $entry->revision + 1, 'updated_by_user_id' => $userId])->save();
                }
            }

            return ['rows' => collect($parsed)->map(fn ($change) => $this->row($change['employee_id']))->values()->all()];
        });
    }

    /** Employee/validation exception helper for controllers that validate request shape themselves. */
    public static function validationFailure(ValidationException $e): PayrollException
    {
        return new PayrollException('بيانات الطلب غير صالحة.', 'payroll_validation', 422, $e->errors());
    }
}
