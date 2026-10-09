<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\User;
use App\Support\OwnerPortal;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** One canonical calculator; persisted month inputs/configuration and immutable approved revisions. */
final class MonthlyPayrollService
{
    public function __construct(private readonly PayrollPersonnelService $personnel, private readonly PayrollConfigService $configs, private readonly PayrollAccountingAccess $access, private readonly PayrollSheetService $sheet) {}

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function decode(?string $value): array
    {
        return $value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function fail(string $code, string $message, int $status = 409): never
    {
        throw new PayrollException($message, $code, $status);
    }

    private function input(array $data, array $rules): array
    {
        if (array_diff(array_keys($data), array_keys($rules))) {
            $this->fail('payroll_validation', 'حقول غير مسموحة.', 422);
        }

        return Validator::make($data, $rules)->validate();
    }

    public function options(User $actor): array
    {
        $this->access->authorize($actor);
        $this->personnel->requireReady();

        return ['periods' => DB::table('payroll_periods')->orderByDesc('period')->get(['period', 'status', 'revision', 'approved_at']),
            'units' => DB::table('organizational_units')->orderBy('unit_name')->get(['organizational_unit_id', 'unit_name']),
            'colleges' => DB::table('colleges')->orderBy('college_name')->get(['college_id', 'college_name']),
            'capabilities' => collect([OwnerPortal::AMOUNTS_EDIT, OwnerPortal::PERIODS_MANAGE, OwnerPortal::PERIODS_CORRECT, OwnerPortal::PAYMENTS_MANAGE, OwnerPortal::EXPORT, OwnerPortal::CONFIG_MANAGE])->mapWithKeys(fn ($p) => [$p => $this->access->allows($actor, $p)])->all()];
    }

    public function filters(array $data): array
    {
        return $this->input($data, ['period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'revision' => 'sometimes|integer|min:1', 'employee_id' => 'sometimes|integer|min:1', 'body' => 'nullable|in:educational,administrative,unknown', 'college_id' => 'sometimes|integer|min:1', 'unit_id' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:120', 'payment_status' => 'nullable|in:unpaid,paid,received,voided', 'completeness' => 'nullable|in:complete,incomplete,warning', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
    }

    /** Payment totals refer to explicit records, never calculation or a fabricated receipt. */
    private function settlements(string $period): array
    {
        return DB::table('payroll_payments')->where('period', $period)->selectRaw("employee_id, SUM(CASE WHEN status <> 'voided' THEN amount_cents ELSE 0 END) AS paid, SUM(CASE WHEN status='received' THEN amount_cents ELSE 0 END) AS received, SUM(CASE WHEN status='voided' THEN 1 ELSE 0 END) AS voided, COUNT(*) AS records")
            ->groupBy('employee_id')->get()->keyBy('employee_id')->all();
    }

    private function rawSnapshot(object $period): array
    {
        $config = $this->decode($period->config_snapshot);
        $saved = DB::table('payroll_period_entries')->where('period_id', $period->id)->orderBy('payroll_employee_id')->get()->keyBy('payroll_employee_id');
        $rows = [];
        if ($period->status === 'approved') {
            foreach ($saved as $entry) {
                $rows[] = $this->decode($entry->identity_snapshot) + ['inputs' => $this->decode($entry->inputs), 'cells' => $this->decode($entry->cells), 'work_time' => $entry->work_time_snapshot ? $this->decode($entry->work_time_snapshot) : null, 'entry_revision' => (int) $entry->revision];
            }
        } else {
            $time = DB::table('hr_work_time_records')->where('period', $period->period)->get()->keyBy('employee_id');
            $calculator = $this->configs->calculator($config);
            foreach ($this->personnel->identities() as $identity) {
                $entry = $identity['id'] ? $saved->get($identity['id']) : null;
                $inputs = $entry ? $this->decode($entry->inputs) : [];
                $rows[] = $identity + ['inputs' => $inputs, 'cells' => $calculator->evaluateRow($inputs), 'work_time' => $time->has($identity['employee_id']) ? (array) $time->get($identity['employee_id']) : null, 'entry_revision' => (int) ($entry->revision ?? 0)];
            }
        }

        return ['period' => $period->period, 'status' => $period->status, 'revision' => (int) $period->revision, 'config' => $config, 'rows' => $rows];
    }

    public function report(User $actor, array $filters): array
    {
        $this->access->authorize($actor);
        $this->personnel->requireReady();

        return DB::transaction(function () use ($filters): array {
            $period = DB::table('payroll_periods')->where('period', $filters['period'])->first();
            if (! $period) {
                $rows = array_values(array_filter($this->personnel->identities(), fn ($r) => (! isset($filters['employee_id']) || $r['employee_id'] == $filters['employee_id']) &&
                    (empty($filters['body']) || ($filters['body'] === 'unknown' ? $r['body'] === null : $r['body'] === $filters['body'])) &&
                    (! isset($filters['college_id']) || $r['college_id'] == $filters['college_id']) &&
                    (! isset($filters['unit_id']) || $r['organizational_unit_id'] == $filters['unit_id']) &&
                    (empty($filters['q']) || str_contains(mb_strtolower($r['full_name'].' '.$r['employee_number']), mb_strtolower(trim($filters['q'])))) &&
                    (empty($filters['payment_status']) || $filters['payment_status'] === 'unpaid')));

                return ['schema_ready' => true, 'initialized' => false, 'period' => $filters['period'], 'rows' => $rows, 'generated_at' => now()->toIso8601String()];
            }
            if (isset($filters['revision']) && $filters['revision'] != $period->revision) {
                $history = DB::table('payroll_period_history')->where('period_id', $period->id)->where('revision', $filters['revision'])->first();
                if (! $history) {
                    $this->fail('payroll_revision_not_found', 'الإصدار المحاسبي غير موجود.', 404);
                }
                $snapshot = $this->decode($history->snapshot);
            } else {
                $snapshot = $this->rawSnapshot($period);
            }
            $settlements = $this->settlements($period->period);
            $currentIdentities = collect($this->personnel->identities(array_column($snapshot['rows'], 'employee_id')))->keyBy('employee_id');
            $rows = [];
            foreach ($snapshot['rows'] as $row) {
                $row['current_hr_revision'] = $currentIdentities->get($row['employee_id'])['hr_revision'] ?? null;
                $row['current_employee_revision'] = $currentIdentities->get($row['employee_id'])['employee_revision'] ?? null;
                $s = $settlements[$row['employee_id']] ?? null;
                $row['disbursed'] = PayrollPaymentService::amount((string) ($s->paid ?? 0));
                $row['received'] = PayrollPaymentService::amount((string) ($s->received ?? 0));
                $row['voided_records'] = (int) ($s->voided ?? 0);
                $due = $row['cells'][PayrollSheetService::TOTAL_KEY]['v'] ?? null;
                $row['remaining'] = $due === null ? null : (string) BigDecimal::of($due)->minus($row['disbursed'])->toScale(2);
                $row['payment_status'] = BigDecimal::of($row['received'])->isPositive() ? 'received' : (BigDecimal::of($row['disbursed'])->isPositive() ? 'paid' : ($row['voided_records'] ? 'voided' : 'unpaid'));
                if (isset($filters['employee_id']) && $row['employee_id'] != $filters['employee_id']) {
                    continue;
                }
                if (! empty($filters['body']) && (($filters['body'] === 'unknown' && $row['body'] !== null) || ($filters['body'] !== 'unknown' && $row['body'] !== $filters['body']))) {
                    continue;
                }
                if (isset($filters['college_id']) && $row['college_id'] != $filters['college_id']) {
                    continue;
                }
                if (isset($filters['unit_id']) && $row['organizational_unit_id'] != $filters['unit_id']) {
                    continue;
                }
                if (! empty($filters['q']) && ! str_contains(mb_strtolower($row['full_name'].' '.$row['employee_number']), mb_strtolower(trim($filters['q'])))) {
                    continue;
                }
                if (! empty($filters['payment_status']) && $row['payment_status'] !== $filters['payment_status']) {
                    continue;
                }
                if (! empty($filters['completeness'])) {
                    $state = PayrollSheetService::status($row);
                    if (($filters['completeness'] === 'complete' && $state === 'incomplete') || ($filters['completeness'] !== 'complete' && $state !== $filters['completeness'])) {
                        continue;
                    }
                }
                $rows[] = $row;
            }
            usort($rows, fn ($a, $b) => strcmp($a['employee_number'], $b['employee_number']) ?: $a['employee_id'] <=> $b['employee_id']);
            $totals = $this->sheet->totals($rows, $snapshot['config']);
            foreach ($totals['columns'] as $key => &$total) {
                $known = count(array_filter($rows, fn ($r) => ($r['cells'][$key]['v'] ?? null) !== null && ! in_array($r['cells'][$key]['st'] ?? null, ['missing', 'error'], true)));
                $total['excluded'] = count($rows) - $known;
                if ($known === 0) {
                    $total['sum'] = null;
                }
            } unset($total);
            foreach (['disbursed', 'received', 'remaining'] as $key) {
                $sum = BigDecimal::zero();
                $missing = 0;
                foreach ($rows as $row) {
                    $row[$key] === null ? $missing++ : $sum = $sum->plus($row[$key]);
                }
                $totals[$key] = ['sum' => $rows === [] || $missing === count($rows) ? null : (string) $sum->toScale(2), 'excluded' => $missing];
            }

            return $snapshot + ['current_revision' => (int) $period->revision, 'schema_ready' => true, 'initialized' => true, 'filtered_rows' => $rows, 'totals' => $totals, 'filters' => $filters, 'generated_at' => now()->toIso8601String(),
                'revisions' => DB::table('payroll_period_history')->where('period_id', $period->id)->orderByDesc('revision')->get(['revision', 'reason', 'created_at'])];
        });
    }

    private function lockedReceipt(User $actor, array $data, string $action): array
    {
        $intent = $data + ['actor' => $actor->user_id, 'action' => $action];
        ksort($intent);
        $hash = hash('sha256', $this->json($intent));
        $old = DB::table('payroll_period_events')->where('request_id', $data['request_id'])->lockForUpdate()->first();
        if ($old && ($old->actor_user_id != $actor->user_id || ! hash_equals($old->payload_hash, $hash))) {
            $this->fail('payroll_request_mismatch', 'معرف العملية مستخدم بمحتوى مختلف.');
        }
        if ($old && ! empty($this->decode($old->details)['is_correction'])) {
            $this->access->authorize($actor, OwnerPortal::PERIODS_CORRECT);
        }

        return [$hash, $old ? $this->decode($old->result) : null];
    }

    public function home(User $actor): array
    {
        if (! app(OwnerPortal::class)->allows($actor, OwnerPortal::HOME_VIEW)) {
            abort(403);
        }
        if (! $this->access->allows($actor, OwnerPortal::PAYROLL_VIEW) || ! $this->personnel->ready()) {
            return ['available' => false, 'reason' => 'ملخص المحاسبة الشهرية غير متاح دون مخططه وصلاحية قراءة الرواتب.', 'generated_at' => now()->toIso8601String()];
        }
        $period = DB::table('payroll_periods')->orderByDesc('period')->value('period');
        if (! $period) {
            return ['available' => false, 'reason' => 'لم تُعدّ فترة محاسبية بعد. افتح الرواتب واختر الشهر؛ هوية العاملين تأتي من النظام.', 'generated_at' => now()->toIso8601String()];
        }
        $r = $this->report($actor, ['period' => $period]);
        $rows = $r['filtered_rows'];
        $totals = $r['totals'];
        $sum = fn ($key) => $totals['columns'][$key]['sum'] ?? null;
        $groups = function (string $key, string $label) use ($rows): array {
            return collect($rows)->groupBy(fn ($r) => $r[$key] ?? '')->map(function ($items, $value) use ($label): array {
                $net = BigDecimal::zero();
                $known = 0;
                foreach ($items as $item) {
                    $v = $item['cells'][PayrollSheetService::TOTAL_KEY]['v'] ?? null;
                    if ($v !== null) {
                        $net = $net->plus($v);
                        $known++;
                    }
                }

                return ['value' => $value === '' && $label === 'body_name' ? 'unknown' : (string) $value, 'label' => $items->first()[$label] ?? 'غير محدد', 'employees' => $items->count(), 'incomplete' => $items->filter(fn ($r) => PayrollSheetService::status($r) === 'incomplete')->count(), 'net_payable' => $known ? (string) $net->toScale(2) : null];
            })->values()->all();
        };
        $attention = collect($rows)->filter(fn ($r) => PayrollSheetService::status($r) !== 'complete');

        return ['available' => true, 'period' => $period, 'period_status' => $r['status'], 'employees' => count($rows), 'complete' => $totals['complete'], 'incomplete' => $totals['incomplete'], 'warnings' => $totals['warnings'],
            'totals' => ['gross_entitlement' => $sum('gross_entitlement'), 'insurance' => $sum('insurance'), 'income_tax' => $sum('salary_tax') === null || $sum('compensation_tax') === null ? null : (string) BigDecimal::of($sum('salary_tax'))->plus($sum('compensation_tax'))->toScale(2), 'other_deductions' => $sum('other_deductions'), 'net_payable' => $sum(PayrollSheetService::TOTAL_KEY)],
            'excluded' => ['net_payable' => $totals['columns'][PayrollSheetService::TOTAL_KEY]['excluded'] ?? count($rows)], 'by_body' => $groups('body', 'body_name'), 'by_workplace' => $groups('organizational_unit_id', 'unit_name'),
            'attention' => ['total' => $attention->count(), 'items' => $attention->take(50)->map(fn ($r) => ['employee_id' => $r['employee_id'], 'employee_number' => $r['employee_number'], 'full_name' => $r['full_name'], 'status' => PayrollSheetService::status($r), 'issues' => array_values(array_map(fn ($c) => ['message' => $c['m']], array_filter($r['cells'], fn ($c) => $c['m'] !== null)))])->all()], 'generated_at' => $r['generated_at']];
    }

    private function event(User $actor, object $period, array $data, string $action, string $hash, array $details, array $result): void
    {
        DB::table('payroll_period_events')->insert(['request_id' => $data['request_id'], 'payload_hash' => $hash, 'period_id' => $period->id, 'action' => $action, 'actor_user_id' => $actor->user_id, 'details' => $this->json($details), 'result' => $this->json($result), 'created_at' => now()]);
    }

    public function prepare(User $actor, array $data): array
    {
        $this->access->authorize($actor, OwnerPortal::PERIODS_MANAGE);
        $this->personnel->requireReady();
        $d = $this->input($data, ['request_id' => 'required|uuid', 'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'confirmed' => 'required|accepted']);

        return $this->atomic(function () use ($actor, $d): array {
            $this->access->authorize($actor, OwnerPortal::PERIODS_MANAGE);
            DB::table('payroll_config')->where('id', 1)->lockForUpdate()->first();
            [$hash, $old] = $this->lockedReceipt($actor, $d, 'prepared');
            if ($old) {
                return $old;
            }
            $period = DB::table('payroll_periods')->where('period', $d['period'])->lockForUpdate()->first();
            if (! $period) {
                $id = DB::table('payroll_periods')->insertGetId(['period' => $d['period'], 'config_snapshot' => $this->json($this->configs->load()), 'created_by_user_id' => $actor->user_id, 'created_at' => now(), 'updated_at' => now()]);
                $period = DB::table('payroll_periods')->where('id', $id)->first();
            }
            $result = ['period' => $period->period, 'revision' => (int) $period->revision, 'status' => $period->status];
            $this->event($actor, $period, $d, 'prepared', $hash, ['starts_blank' => true, 'no_previous_values_copied' => true], $result);

            return $result;
        });
    }

    public function save(User $actor, array $data): array
    {
        $this->access->authorize($actor, OwnerPortal::AMOUNTS_EDIT);
        $this->personnel->requireReady();
        $d = $this->input($data, ['request_id' => 'required|uuid', 'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'revision' => 'required|integer|min:1', 'changes' => 'required|array|min:1|max:1000', 'correction_reason' => 'sometimes|string|max:4000|regex:/\S/u', 'confirmed' => 'required|accepted']);

        return $this->atomic(function () use ($actor, $d): array {
            $this->access->authorize($actor, OwnerPortal::AMOUNTS_EDIT);
            DB::table('payroll_config')->where('id', 1)->lockForUpdate()->first();
            [$hash, $old] = $this->lockedReceipt($actor, $d, 'values_saved');
            if ($old) {
                return $old;
            }
            $period = DB::table('payroll_periods')->where('period', $d['period'])->lockForUpdate()->first();
            if (! $period || $period->revision != $d['revision']) {
                $this->fail('payroll_conflict', 'تغير الشهر أو مراجعته. احتفظ بمسودتك وراجع الحالة الحالية صراحة.');
            }
            if ($period->status === 'approved') {
                $this->access->authorize($actor, OwnerPortal::PERIODS_CORRECT);
                if (empty($d['correction_reason']) || trim($d['correction_reason']) === '') {
                    $this->fail('payroll_correction_reason_required', 'تصحيح شهر معتمد يحتاج سببًا صريحًا.', 422);
                }
            }
            $config = $this->decode($period->config_snapshot);
            $columns = collect($config['columns'])->keyBy('key');
            $calculator = $this->configs->calculator($config);
            $changes = [];
            $seen = [];
            foreach ($d['changes'] as $raw) {
                if (! is_array($raw)) {
                    $this->fail('payroll_validation', 'صف غير صالح.', 422);
                }
                $c = $this->input($raw, ['employee_id' => 'required|integer|min:1', 'entry_revision' => 'required|integer|min:0', 'hr_revision' => 'required|integer|min:1', 'values' => 'required|array|min:1']);
                if (isset($seen[$c['employee_id']])) {
                    $this->fail('payroll_validation', 'العامل مكرر.', 422);
                } $seen[$c['employee_id']] = true;
                $changes[] = $c;
            }
            usort($changes, fn ($a, $b) => $a['employee_id'] <=> $b['employee_id']);
            $financial = DB::table('payroll_employees')->whereIn('employee_id', array_keys($seen))->orderBy('id')->lockForUpdate()->get()->keyBy('employee_id');
            $people = DB::table('employees')->whereIn('employee_id', array_keys($seen))->orderBy('employee_id')->lockForUpdate()->get()->keyBy('employee_id');
            $identities = collect($this->personnel->identities(array_keys($seen)))->keyBy('employee_id');
            $diff = [];
            foreach ($changes as $c) {
                $p = $financial->get($c['employee_id']);
                $e = $people->get($c['employee_id']);
                if (! $p || ! $e) {
                    $this->fail('payroll_personnel_sync_required', 'السجل المالي للعامل غير جاهز؛ شغّل مزامنة العاملين.', 409);
                }
                if ($e->hr_revision != $c['hr_revision']) {
                    $this->fail('payroll_conflict', 'تغير سياق العامل في الموارد البشرية.');
                }
                $entry = DB::table('payroll_period_entries')->where('period_id', $period->id)->where('payroll_employee_id', $p->id)->lockForUpdate()->first();
                if ((int) ($entry->revision ?? 0) !== (int) $c['entry_revision']) {
                    $this->fail('payroll_conflict', 'تغير صف العامل في هذا الشهر.');
                }
                $inputs = $entry ? $this->decode($entry->inputs) : [];
                $before = $inputs;
                foreach ($c['values'] as $key => $value) {
                    $column = $columns->get($key);
                    if (! $column || $column['kind'] !== 'input') {
                        $this->fail('payroll_validation', 'المكون محسوب أو غير معروف.', 422);
                    }
                    try {
                        $parsed = PayrollInput::parse($column, $value);
                    } catch (\InvalidArgumentException $ex) {
                        $this->fail('payroll_validation', $ex->getMessage(), 422);
                    }
                    if ($parsed === null) {
                        unset($inputs[$key]);
                    } else {
                        $inputs[$key] = (string) $parsed;
                    }
                }
                ksort($inputs);
                ksort($before);
                if ($inputs === $before) {
                    continue;
                }
                $cells = $calculator->evaluateRow($inputs);
                if ($period->status === 'approved' && in_array($cells[PayrollSheetService::TOTAL_KEY]['st'] ?? 'missing', ['missing', 'error'], true)) {
                    $this->fail('payroll_incomplete', 'التصحيح لا يجوز أن يجعل المستحق المعتمد ناقصًا.', 422);
                }
                $work = DB::table('hr_work_time_records')->where('employee_id', $e->employee_id)->where('period', $period->period)->first();
                $values = ['identity_snapshot' => $this->json($identities[$e->employee_id]), 'work_time_snapshot' => $work ? $this->json($work) : null, 'inputs' => $this->json($inputs), 'cells' => $this->json($cells), 'revision' => ($entry->revision ?? 0) + 1, 'updated_at' => now()];
                if ($entry) {
                    DB::table('payroll_period_entries')->where('id', $entry->id)->update($values);
                } else {
                    DB::table('payroll_period_entries')->insert($values + ['period_id' => $period->id, 'payroll_employee_id' => $p->id, 'created_at' => now()]);
                }
                $diff[] = ['employee_id' => $e->employee_id, 'before' => $before, 'after' => $inputs, 'reason' => $d['correction_reason'] ?? null];
            }
            $changed = $diff !== [];
            if ($changed) {
                DB::table('payroll_periods')->where('id', $period->id)->update(['revision' => $period->revision + 1, 'updated_at' => now()]);
            }
            $current = DB::table('payroll_periods')->where('id', $period->id)->first();
            if ($changed && $current->status === 'approved') {
                $this->history($actor, $current, $d['correction_reason']);
            }
            $result = ['period' => $period->period, 'revision' => (int) $current->revision, 'changed' => $changed];
            $this->event($actor, $current, $d, 'values_saved', $hash, ['changes' => $diff, 'is_correction' => $period->status === 'approved'], $result);

            return $result;
        });
    }

    private function history(User $actor, object $period, string $reason): void
    {
        DB::table('payroll_period_history')->insert(['period_id' => $period->id, 'revision' => $period->revision, 'snapshot' => $this->json($this->rawSnapshot($period)), 'actor_user_id' => $actor->user_id, 'reason' => $reason, 'created_at' => now()]);
    }

    public function approve(User $actor, array $data): array
    {
        $this->access->authorize($actor, OwnerPortal::PERIODS_MANAGE);
        $this->personnel->requireReady();
        $d = $this->input($data, ['request_id' => 'required|uuid', 'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);

        return $this->atomic(function () use ($actor, $d): array {
            $this->access->authorize($actor, OwnerPortal::PERIODS_MANAGE);
            DB::table('payroll_config')->where('id', 1)->lockForUpdate()->first();
            [$hash, $old] = $this->lockedReceipt($actor, $d, 'approved');
            if ($old) {
                return $old;
            }
            $p = DB::table('payroll_periods')->where('period', $d['period'])->lockForUpdate()->first();
            if (! $p || $p->status !== 'draft' || $p->revision != $d['revision']) {
                $this->fail('payroll_conflict', 'الشهر تغير أو سبق اعتماده.');
            }
            DB::table('employees')->orderBy('employee_id')->lockForUpdate()->get(['employee_id']);
            DB::table('payroll_employees')->orderBy('id')->lockForUpdate()->get(['id']);
            $snapshot = $this->rawSnapshot($p);
            if ($snapshot['rows'] === []) {
                $this->fail('payroll_empty', 'لا يوجد عاملون في الفترة.', 422);
            }
            foreach ($snapshot['rows'] as $row) {
                if (! $row['id'] || in_array($row['cells'][PayrollSheetService::TOTAL_KEY]['st'] ?? 'missing', ['missing', 'error'], true)) {
                    $this->fail('payroll_incomplete', 'استكمل المبالغ المطلوبة لكل عامل قبل اعتماد الشهر؛ لا يُملأ النقص بصفر.', 422);
                }
                $entry = DB::table('payroll_period_entries')->where('period_id', $p->id)->where('payroll_employee_id', $row['id'])->first();
                $identity = array_diff_key($row, array_flip(['inputs', 'cells', 'work_time', 'entry_revision']));
                $values = ['identity_snapshot' => $this->json($identity), 'work_time_snapshot' => $row['work_time'] ? $this->json($row['work_time']) : null, 'inputs' => $this->json($row['inputs']), 'cells' => $this->json($row['cells']), 'updated_at' => now()];
                if ($entry) {
                    DB::table('payroll_period_entries')->where('id', $entry->id)->update($values);
                } else {
                    DB::table('payroll_period_entries')->insert($values + ['period_id' => $p->id, 'payroll_employee_id' => $row['id'], 'created_at' => now()]);
                }
            }
            DB::table('payroll_periods')->where('id', $p->id)->update(['status' => 'approved', 'revision' => $p->revision + 1, 'approved_by_user_id' => $actor->user_id, 'approved_at' => now(), 'updated_at' => now()]);
            $p = DB::table('payroll_periods')->where('id', $p->id)->first();
            $this->history($actor, $p, 'اعتماد مستحقات الشهر — دون صرف أو استلام تلقائي');
            $result = ['period' => $p->period, 'revision' => (int) $p->revision, 'status' => 'approved'];
            $this->event($actor, $p, $d, 'approved', $hash, ['no_payment_created' => true], $result);

            return $result;
        });
    }

    public function result(User $actor, string $uuid): array
    {
        $this->access->authorize($actor);
        $this->personnel->requireReady();
        $event = DB::table('payroll_period_events')->where('request_id', $uuid)->where('actor_user_id', $actor->user_id)->first();
        if (! $event) {
            $this->fail('payroll_result_not_found', 'لا توجد نتيجة محفوظة متاحة لهذه العملية.', 404);
        }
        $this->access->authorize($actor, $event->action === 'values_saved' ? OwnerPortal::AMOUNTS_EDIT : OwnerPortal::PERIODS_MANAGE);
        if (! empty($this->decode($event->details)['is_correction'])) {
            $this->access->authorize($actor, OwnerPortal::PERIODS_CORRECT);
        }

        return $this->decode($event->result);
    }

    private function atomic(\Closure $work): array
    {
        try {
            return DB::transaction($work, 1);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '40001', '19'], true) || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                $this->fail('payroll_conflict', 'تعارض مالي؛ راجع النتيجة دون إعادة تلقائية.');
            }
            throw $e;
        }
    }
}
