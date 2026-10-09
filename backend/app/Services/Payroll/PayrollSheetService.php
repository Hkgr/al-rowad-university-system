<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\Payroll\PayrollBody;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEntry;
use App\Support\PayrollWorkplace;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owner payroll working sheet (one current sheet, no periods).
 *
 * Employee identity/classification live in payroll_employees, every input amount in payroll_entry_values, and every calculated
 * cell is produced by PayrollCalculator from the saved configuration. Nothing here is shared with operational personnel data.
 */
class PayrollSheetService
{
    public const MAX_BATCH = 1000;

    public const META_SORTS = ['employee_number', 'full_name', 'job_title', 'body', 'workplace', 'academic_level'];

    public const COMPLETENESS = ['complete', 'incomplete', 'warning'];

    public const TOTAL_KEY = PayrollTemplate::TOTAL_NET_PAYABLE;

    public function __construct(private readonly PayrollConfigService $configs) {}

    // ── filters ───────────────────────────────────────────────────────────

    /**
     * Normalise the sheet query (also used by exports, so grid, Home links and files agree).
     * The blank academic-level filter is its own flag: it can never collide with a real level typed by a user.
     */
    public function filters(array $input, ?array $config = null): array
    {
        $config ??= $this->configs->load();
        $sort = $input['sort'] ?? 'employee_number';
        $known = array_merge(self::META_SORTS, array_column($config['columns'], 'key'));
        if (! is_string($sort) || ! in_array($sort, $known, true)) {
            throw new PayrollException('عمود الترتيب غير معروف.', 'payroll_validation', 422, ['sort' => ['عمود الترتيب غير معروف.']]);
        }
        $level = isset($input['academic_level']) && $input['academic_level'] !== '' ? (string) $input['academic_level'] : null;
        $blank = filter_var($input['academic_level_blank'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($blank && $level !== null) {
            throw new PayrollException('اختر مستوى أكاديميًا محددًا أو «غير محدد» وليس الاثنين معًا.', 'payroll_validation', 422, ['academic_level' => ['مرشحان متعارضان.']]);
        }
        $search = PayrollText::clean($input['search'] ?? null);

        return [
            'search' => $search === null ? null : mb_substr($search, 0, 100),
            'payroll_employee_id' => isset($input['payroll_employee_id']) && $input['payroll_employee_id'] !== '' ? (int) $input['payroll_employee_id'] : null,
            'body_id' => isset($input['body_id']) && $input['body_id'] !== '' ? (int) $input['body_id'] : null,
            'workplace' => in_array($input['workplace'] ?? null, PayrollWorkplace::codes(), true) ? $input['workplace'] : null,
            'academic_level' => $level,
            'academic_level_blank' => $blank,
            'completeness' => in_array($input['completeness'] ?? null, self::COMPLETENESS, true) ? $input['completeness'] : null,
            'sort' => $sort,
            'direction' => ($input['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        ];
    }

    public function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'payroll_employee_id' => ['nullable', 'integer', 'min:1'],
            'body_id' => ['nullable', 'integer', 'min:1'],
            'workplace' => ['nullable', 'string', 'in:'.implode(',', PayrollWorkplace::codes())],
            'academic_level' => ['nullable', 'string', 'max:255'],
            'academic_level_blank' => ['nullable', 'boolean'],
            'completeness' => ['nullable', 'string', 'in:'.implode(',', self::COMPLETENESS)],
            'sort' => ['nullable', 'string', 'max:40'],
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
        if (($filters['payroll_employee_id'] ?? null) !== null) {
            $query->where('e.id', $filters['payroll_employee_id']);
        }
        if ($filters['body_id'] !== null) {
            $query->where('e.payroll_body_id', $filters['body_id']);
        }
        if ($filters['workplace'] !== null) {
            $query->where('e.workplace', $filters['workplace']);
        }
        if ($filters['academic_level_blank']) {
            $query->whereNull('e.academic_level');
        } elseif ($filters['academic_level'] !== null) {
            $query->where('e.academic_level', $filters['academic_level']);
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

    /** SQL ordering for identity columns; value/calculated columns are ordered in PHP after calculation. */
    private function applyMetaSort(Builder $query, array $filters): Builder
    {
        // Calculated/value columns are ordered in PHP afterwards; ties must then fall back to ascending employee number.
        $meta = in_array($filters['sort'], self::META_SORTS, true);
        $direction = $meta && $filters['direction'] === 'desc' ? 'DESC' : 'ASC';
        $workplace = 'CASE e.workplace '.collect(PayrollWorkplace::LABELS)->except(PayrollWorkplace::OTHER)
            ->map(fn ($label, $code) => "WHEN '{$code}' THEN '{$label}'")->implode(' ')
            ." ELSE COALESCE(e.workplace_other, '".PayrollWorkplace::LABELS[PayrollWorkplace::OTHER]."') END";
        $expression = match ($filters['sort']) {
            'full_name' => 'e.full_name',
            'job_title' => 'e.job_title',
            'body' => 'b.name',
            'workplace' => $workplace,
            'academic_level' => 'e.academic_level',
            default => 'e.employee_number',
        };
        $query->orderByRaw("({$expression}) IS NULL ASC")->orderByRaw("({$expression}) {$direction}");
        if ($expression !== 'e.employee_number') {
            $query->orderBy('e.employee_number');
        }

        return $query->orderBy('e.id');
    }

    private const COLUMNS = [
        'e.id', 'e.employee_number', 'e.full_name', 'e.job_title', 'e.payroll_body_id', 'b.name as body_name', 'b.is_active as body_is_active',
        'e.workplace', 'e.workplace_other', 'e.academic_level', 'e.revision as employee_revision', 'e.updated_at as employee_updated_at',
        'n.revision as entry_revision', 'n.updated_at as entry_updated_at',
    ];

    // ── rows ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string, BigDecimal|string>> input values by employee id then column key */
    private function inputsFor(array $employeeIds, array $config): array
    {
        $byId = collect($config['columns'])->where('kind', 'input')->keyBy('id');
        $inputs = [];
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            foreach (DB::table('payroll_entry_values')->whereIn('payroll_employee_id', $chunk)->get() as $v) {
                $column = $byId->get((int) $v->payroll_column_id);
                if ($column === null) {
                    continue;
                }
                $inputs[(int) $v->payroll_employee_id][$column['key']] = $column['value_type'] === 'text'
                    ? $v->value_text
                    : PayrollCalculator::fromStored((int) $v->value_scaled);
            }
        }

        return $inputs;
    }

    /** Joined identity row + calculated cells, in the shape the API and exports share. */
    private function present(object $row, array $inputs, PayrollCalculator $calculator): array
    {
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
            'cells' => $calculator->evaluateRow($inputs),
            'employee_revision' => (int) $row->employee_revision,
            'entry_revision' => (int) $row->entry_revision,
            'updated_at' => max((string) $row->employee_updated_at, (string) $row->entry_updated_at) ?: null,
        ];
    }

    /** @return list<array> */
    private function computeRows(Collection $joined, array $config): array
    {
        $calculator = $this->configs->calculator($config);
        $inputs = $this->inputsFor($joined->pluck('id')->map(fn ($id) => (int) $id)->all(), $config);

        return $joined->map(fn ($row) => $this->present($row, $inputs[(int) $row->id] ?? [], $calculator))->values()->all();
    }

    /** One employee in the grid shape, or null. */
    public function row(int $employeeId, ?array $config = null): ?array
    {
        $config ??= $this->configs->load();
        $row = $this->base()->where('e.id', $employeeId)->first(self::COLUMNS);

        return $row === null ? null : $this->computeRows(collect([$row]), $config)[0];
    }

    public static function status(array $row): string
    {
        $cell = $row['cells'][self::TOTAL_KEY] ?? null;
        if ($cell === null) {
            return 'complete';
        }

        return match ($cell['st']) {
            'missing', 'error' => 'incomplete',
            default => collect($row['cells'])->contains(fn ($c) => $c['st'] === 'warning') ? 'warning' : 'complete',
        };
    }

    /**
     * Consistent read of everything matching the filters in the requested order. Rows, configuration and totals come from one
     * transaction and the totals are summed from those very rows: the grid, Home and both exports can never disagree.
     */
    public function snapshot(array $filters): array
    {
        return DB::transaction(function () use ($filters): array {
            $config = $this->configs->load();
            $joined = $this->applyMetaSort($this->applyFilters($this->base(), $filters), $filters)->get(self::COLUMNS);
            $rows = $this->computeRows($joined, $config);
            if ($filters['completeness'] !== null) {
                $rows = array_values(array_filter($rows, fn ($r) => match ($filters['completeness']) {
                    'complete' => self::status($r) !== 'incomplete',
                    'incomplete' => self::status($r) === 'incomplete',
                    'warning' => self::status($r) === 'warning',
                }));
            }
            if (! in_array($filters['sort'], self::META_SORTS, true)) {
                $rows = $this->sortByColumn($rows, $filters['sort'], $filters['direction']);
            }

            return ['rows' => $rows, 'totals' => $this->totals($rows, $config), 'config' => $config, 'generated_at' => now()->toIso8601String(), 'filters' => $filters];
        });
    }

    /** Stable sort; blank, unavailable and error cells always last. */
    private function sortByColumn(array $rows, string $key, string $direction): array
    {
        $factor = $direction === 'desc' ? -1 : 1;
        $indexed = array_map(null, array_keys($rows), $rows);
        usort($indexed, function ($a, $b) use ($key, $factor) {
            $x = $a[1]['cells'][$key]['v'] ?? null;
            $y = $b[1]['cells'][$key]['v'] ?? null;
            if ($x === null || $y === null) {
                return $x === $y ? $a[0] <=> $b[0] : ($x === null ? 1 : -1);
            }
            $c = is_numeric($x) && is_numeric($y) ? BigDecimal::of($x)->compareTo(BigDecimal::of($y)) : strcmp(mb_strtolower((string) $x), mb_strtolower((string) $y));

            return $c === 0 ? $a[0] <=> $b[0] : $c * $factor;
        });

        return array_column($indexed, 1);
    }

    /**
     * Column totals over the given rows. A total sums the cells that exist; unavailable (missing/error) cells are excluded
     * and counted, so an incomplete record is never shown as a healthy zero.
     */
    public function totals(array $rows, array $config): array
    {
        $columns = [];
        foreach ($config['columns'] as $column) {
            if ($column['aggregation'] !== 'sum') {
                continue;
            }
            $sum = BigDecimal::zero();
            $excluded = 0;
            foreach ($rows as $row) {
                $cell = $row['cells'][$column['key']];
                if (in_array($cell['st'], ['missing', 'error'], true)) {
                    $excluded++;
                } elseif ($cell['v'] !== null) {
                    $sum = $sum->plus($cell['v']);
                }
            }
            $columns[$column['key']] = ['sum' => PayrollCalculator::format($sum, $column['value_type']), 'excluded' => $excluded];
        }
        $statuses = array_map(fn ($r) => self::status($r), $rows);

        return [
            'employees' => count($rows),
            'complete' => count(array_filter($statuses, fn ($s) => $s !== 'incomplete')),
            'incomplete' => count(array_filter($statuses, fn ($s) => $s === 'incomplete')),
            'warnings' => count(array_filter($statuses, fn ($s) => $s === 'warning')),
            'columns' => $columns,
        ];
    }

    // ── Home ──────────────────────────────────────────────────────────────

    /** Owner overview over the whole sheet, computed by the same calculator and totals as the grid and exports. */
    public function home(): array
    {
        return DB::transaction(function (): array {
            $config = $this->configs->load();
            $joined = $this->base()->orderBy('e.employee_number')->orderBy('e.id')->get(self::COLUMNS);
            $rows = $this->computeRows($joined, $config);
            $totals = $this->totals($rows, $config);
            $sum = fn (string $key) => $totals['columns'][$key]['sum'] ?? '0.00';
            $groups = fn (callable $keyOf, callable $labelOf, string $param) => collect($rows)->groupBy($keyOf)->map(function ($group, $value) use ($labelOf, $param) {
                $first = $group->first();
                $statuses = $group->map(fn ($r) => self::status($r));

                return [
                    'value' => (string) $value, 'label' => $labelOf($first), 'filter' => $param,
                    'employees' => $group->count(), 'incomplete' => $statuses->filter(fn ($s) => $s === 'incomplete')->count(),
                    'net_payable' => $this->netOf($group->all()),
                ];
            })->sortByDesc('employees')->values()->all();

            $attention = collect($rows)->map(fn ($r) => [$r, self::status($r)])->filter(fn ($p) => $p[1] !== 'complete')
                ->sortBy(fn ($p) => ['incomplete' => 0, 'warning' => 1][$p[1]])->values();

            return [
                'currency' => ['code' => 'SYP', 'symbol' => 'ل.س'],
                'employees' => $totals['employees'], 'complete' => $totals['complete'], 'incomplete' => $totals['incomplete'], 'warnings' => $totals['warnings'],
                'totals' => [
                    'gross_entitlement' => $sum('gross_entitlement'), 'insurance' => $sum('insurance'),
                    'income_tax' => PayrollCalculator::format(BigDecimal::of($sum('salary_tax'))->plus($sum('compensation_tax')), 'amount'),
                    'other_deductions' => $sum('other_deductions'), 'total_deductions' => $sum('total_deductions'), 'net_payable' => $sum(self::TOTAL_KEY),
                ],
                'excluded' => ['net_payable' => $totals['columns'][self::TOTAL_KEY]['excluded'] ?? 0],
                'by_body' => $groups(fn ($r) => $r['body_id'], fn ($r) => $r['body_name'], 'body_id'),
                'by_workplace' => $groups(fn ($r) => $r['workplace'], fn ($r) => PayrollWorkplace::LABELS[$r['workplace']] ?? $r['workplace'], 'workplace'),
                'attention' => [
                    'total' => $attention->count(),
                    'items' => $attention->take(50)->map(fn ($p) => [
                        'employee_id' => $p[0]['id'], 'employee_number' => $p[0]['employee_number'], 'full_name' => $p[0]['full_name'], 'status' => $p[1],
                        'issues' => collect($p[0]['cells'])->filter(fn ($c, $k) => $p[1] === 'incomplete' ? $k === self::TOTAL_KEY : $c['st'] === 'warning')
                            ->map(fn ($c, $k) => ['key' => $k, 'label' => collect($config['columns'])->firstWhere('key', $k)['label'], 'message' => $c['m']])->values()->all(),
                    ])->all(),
                ],
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    /** Net payable of a group of rows: unavailable cells are excluded (never counted as zero). */
    private function netOf(array $rows): string
    {
        $sum = BigDecimal::zero();
        foreach ($rows as $r) {
            $v = $r['cells'][self::TOTAL_KEY]['v'] ?? null;
            if ($v !== null) {
                $sum = $sum->plus($v);
            }
        }

        return PayrollCalculator::format($sum, 'amount');
    }

    public function options(): array
    {
        return [
            'bodies' => $this->bodies(),
            'workplaces' => collect(PayrollWorkplace::LABELS)->map(fn ($label, $code) => ['value' => $code, 'label' => $label])->values(),
            'academic_levels' => DB::table('payroll_employees')->whereNotNull('academic_level')->distinct()->orderBy('academic_level')->pluck('academic_level')->values(),
            'completeness' => [['value' => 'incomplete', 'label' => 'ناقصة أو بها خطأ'], ['value' => 'warning', 'label' => 'بها تحذير حسابي'], ['value' => 'complete', 'label' => 'مكتملة']],
        ];
    }

    /** Human-readable description of the active filters, for the on-screen scope label and the exports. */
    public function scopeLabels(array $filters): array
    {
        $labels = [];
        if (($filters['payroll_employee_id'] ?? null) !== null) {
            $employee = DB::table('payroll_employees')->where('id', $filters['payroll_employee_id'])->first(['full_name', 'employee_number']);
            $labels[] = $employee ? 'ملف الرواتب: '.$employee->full_name.' — '.$employee->employee_number : 'ملف الرواتب المحدد غير موجود';
        }
        if ($filters['search'] !== null) {
            $labels[] = 'بحث: '.$filters['search'];
        }
        if ($filters['body_id'] !== null) {
            $labels[] = 'الهيئة: '.(DB::table('payroll_bodies')->where('id', $filters['body_id'])->value('name') ?? 'غير موجودة');
        }
        if ($filters['workplace'] !== null) {
            $labels[] = 'مكان العمل: '.PayrollWorkplace::LABELS[$filters['workplace']];
        }
        if ($filters['academic_level_blank']) {
            $labels[] = 'المستوى الأكاديمي: غير محدد';
        } elseif ($filters['academic_level'] !== null) {
            $labels[] = 'المستوى الأكاديمي: '.$filters['academic_level'];
        }
        if ($filters['completeness'] !== null) {
            $labels[] = 'الحالة: '.['complete' => 'مكتملة', 'incomplete' => 'ناقصة أو بها خطأ', 'warning' => 'بها تحذير حسابي'][$filters['completeness']];
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
        try {
            $id = DB::transaction(function () use ($input, $userId): int {
                // The target body is read FOR UPDATE inside this transaction: a concurrent deactivation can no longer slip in
                // between the "is it active" check and the insert.
                $data = $this->validatedEmployee($input, null);
                $employee = PayrollEmployee::create($data + ['revision' => 1, 'created_by_user_id' => $userId, 'updated_by_user_id' => $userId]);
                // Blank inputs: no stored values at all, never prefilled with zero.
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

    /**
     * Must run inside the writing transaction: the selected body is locked here.
     *
     * @return array{employee_number:string, full_name:string, job_title:string, payroll_body_id:int, workplace:string, workplace_other:?string, academic_level:?string}
     */
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
        $body = is_numeric($bodyId) ? PayrollBody::query()->lockForUpdate()->find((int) $bodyId) : null;
        if ($body === null) {
            $errors['body_id'][] = 'الهيئة مطلوبة ويجب أن تكون من قائمة الهيئات.';
        } elseif (! $body->is_active && (int) $existing?->payroll_body_id !== $body->id) {
            $errors['body_id'][] = 'الهيئة المختارة معطّلة ولا يمكن إسناد موظف إليها.';
        }
        if ($errors !== []) {
            throw new PayrollException('تعذّر حفظ بيانات الموظف؛ راجع الحقول المحددة.', 'payroll_validation', 422, $errors);
        }

        return [
            'employee_number' => $number, 'full_name' => $name, 'job_title' => $title, 'payroll_body_id' => $body->id,
            'workplace' => $workplace, 'workplace_other' => $other, 'academic_level' => $level,
        ];
    }

    // ── input values, atomic per request ──────────────────────────────────

    /**
     * Apply a batch of cell changes all-or-nothing. Each change: employee_id, expected_revision (of that employee's value row) and
     * values = { column key => value | null }. A null/"" clears the cell (blank), which is different from an entered zero.
     *
     * Invalid values => 422 and nothing is written. A stale row revision => 409 and nothing is written. A stale configuration
     * (columns/settings changed meanwhile) => 409 `payroll_config_conflict` and nothing is written.
     *
     * @return array{rows: list<array>, config_revision: int}
     */
    public function updateValues(array $changes, int $configRevision, int $userId): array
    {
        if ($changes === [] || count($changes) > self::MAX_BATCH) {
            throw new PayrollException('عدد الصفوف المرسلة غير مقبول (1 إلى '.self::MAX_BATCH.' صفًا).', 'payroll_validation', 422, ['changes' => ['عدد الصفوف يجب أن يكون بين 1 و '.self::MAX_BATCH.'.']]);
        }

        return DB::transaction(function () use ($changes, $configRevision, $userId): array {
            // The configuration is read under a shared lock: a column/setting change cannot interleave with this save.
            $current = (int) DB::table('payroll_config')->where('id', 1)->sharedLock()->value('revision');
            if ($current !== $configRevision) {
                throw new PayrollException('تغيّرت إعدادات الأعمدة أو المعادلات منذ تحميل الصفحة؛ لم يُحفظ شيء، وبقيت قيمك المعلّقة.', 'payroll_config_conflict', 409, [], ['config' => $this->configs->present($this->configs->load())]);
            }
            $config = $this->configs->load();
            $columns = collect($config['columns'])->keyBy('key');

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
                $submitted = is_array($change['values'] ?? null) ? $change['values'] : [];
                foreach ($submitted as $key => $raw) {
                    $column = $columns->get((string) $key);
                    if ($column === null || $column['kind'] !== 'input') {
                        $errors["changes.{$i}.values.{$key}"][] = $column === null ? 'عمود غير معروف.' : 'عمود محسوب: يُحسب تلقائيًا ولا يقبل التعديل.';

                        continue;
                    }
                    try {
                        $values[$column['key']] = PayrollInput::parse($column, $raw);
                    } catch (InvalidArgumentException $e) {
                        $errors["changes.{$i}.values.{$key}"][] = $e->getMessage();
                    }
                }
                if ($values === [] && ! isset($errors["changes.{$i}.values"]) && $submitted === []) {
                    $errors["changes.{$i}.values"][] = 'لا توجد قيمة للتعديل.';
                }
                $parsed[$i] = ['employee_id' => $id, 'expected_revision' => $revision, 'values' => $values];
            }
            if ($errors !== []) {
                throw new PayrollException('قيم غير صالحة؛ لم يُحفظ شيء من هذه العملية.', 'payroll_validation', 422, $errors);
            }

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
                    ['conflicts' => collect($conflicts)->map(fn ($id) => ['employee_id' => $id, 'current' => $this->row($id, $config)])->all()],
                );
            }

            $existing = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::table('payroll_entry_values')->whereIn('payroll_employee_id', $chunk)->get() as $v) {
                    $existing[(int) $v->payroll_employee_id][(int) $v->payroll_column_id] = $v;
                }
            }
            foreach ($parsed as $change) {
                $dirty = false;
                foreach ($change['values'] as $key => $value) {
                    $column = $columns[$key];
                    $stored = $value === null ? null : ($column['value_type'] === 'text' ? $value : PayrollCalculator::toStored($value));
                    $row = $existing[$change['employee_id']][$column['id']] ?? null;
                    $before = $row === null ? null : ($column['value_type'] === 'text' ? $row->value_text : ($row->value_scaled === null ? null : (int) $row->value_scaled));
                    if ($before === $stored) {
                        continue;
                    }
                    $dirty = true;
                    $where = ['payroll_employee_id' => $change['employee_id'], 'payroll_column_id' => $column['id']];
                    if ($stored === null) {
                        DB::table('payroll_entry_values')->where($where)->delete();
                    } elseif ($row === null) {
                        DB::table('payroll_entry_values')->insert($where + [
                            'value_scaled' => $column['value_type'] === 'text' ? null : $stored, 'value_text' => $column['value_type'] === 'text' ? $stored : null,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('payroll_entry_values')->where($where)->update([
                            'value_scaled' => $column['value_type'] === 'text' ? null : $stored, 'value_text' => $column['value_type'] === 'text' ? $stored : null, 'updated_at' => now(),
                        ]);
                    }
                }
                if ($dirty) {
                    $entry = $entries->get($change['employee_id']);
                    $entry->fill(['revision' => $entry->revision + 1, 'updated_by_user_id' => $userId])->save();
                }
            }

            $rows = $this->base()->whereIn('e.id', $ids)->get(self::COLUMNS);
            $byId = collect($this->computeRows($rows, $config))->keyBy('id');

            return ['rows' => collect($parsed)->map(fn ($c) => $byId[$c['employee_id']])->values()->all(), 'config_revision' => $configRevision];
        });
    }

    // ── what-if (nothing is saved) ────────────────────────────────────────

    /**
     * Evaluate a proposed configuration against saved values: validation errors, the effect on one employee's cells, and the effect
     * on every employee's total net payable. Used by the column dialog and the settings tab before anything is saved.
     */
    public function impact(array $proposedConfig, ?int $employeeId, ?string $focusKey): array
    {
        $current = $this->configs->load();
        $before = $this->allCells($current);
        $after = $this->allCells($proposedConfig);
        $changed = 0;
        $errorsIntroduced = 0;
        foreach ($after as $id => $cells) {
            $a = $cells[self::TOTAL_KEY]['v'] ?? null;
            $b = $before[$id][self::TOTAL_KEY]['v'] ?? null;
            $changed += $a !== $b ? 1 : 0;
            $errorsIntroduced += in_array($cells[self::TOTAL_KEY]['st'] ?? null, ['missing', 'error'], true) && ! in_array($before[$id][self::TOTAL_KEY]['st'] ?? null, ['missing', 'error'], true) ? 1 : 0;
        }
        $sum = function (array $cellsById) {
            $s = BigDecimal::zero();
            $excluded = 0;
            foreach ($cellsById as $cells) {
                $c = $cells[self::TOTAL_KEY] ?? ['v' => null, 'st' => null];
                $c['v'] !== null ? $s = $s->plus($c['v']) : $excluded += in_array($c['st'], ['missing', 'error'], true) ? 1 : 0;
            }

            return ['sum' => PayrollCalculator::format($s, 'amount'), 'excluded' => $excluded];
        };
        $preview = null;
        if ($employeeId !== null && isset($after[$employeeId])) {
            $keys = $focusKey !== null ? [$focusKey] : array_column($proposedConfig['columns'], 'key');
            $preview = ['employee_id' => $employeeId, 'cells' => array_intersect_key($after[$employeeId], array_flip($keys)), 'before' => array_intersect_key($before[$employeeId] ?? [], array_flip($keys))];
        }

        return [
            'net_payable' => ['before' => $sum($before), 'after' => $sum($after)],
            'employees_changed' => $changed, 'errors_introduced' => $errorsIntroduced, 'employees' => count($after), 'preview' => $preview,
        ];
    }

    /** @return array<int, array<string, array>> cells of every employee under a configuration */
    private function allCells(array $config): array
    {
        $calculator = $this->configs->calculator($config);
        $employees = DB::table('payroll_employees')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inputs = $this->inputsFor($employees, $config);
        $out = [];
        foreach ($employees as $id) {
            $out[$id] = $calculator->evaluateRow($inputs[$id] ?? []);
        }

        return $out;
    }
}
