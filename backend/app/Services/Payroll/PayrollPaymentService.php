<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Support\AdministrativeGovernanceException as Failure;
use App\Support\HrOffice;
use App\Support\OwnerPortal;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** Local, explicitly recorded disbursements. Never pays a bank, rewrites the sheet, or infers receipt. */
final class PayrollPaymentService
{
    public function __construct(private readonly HrOffice $access, private readonly OwnerPortal $owner) {}

    public function ready(): bool
    {
        return Schema::hasColumns('payroll_payments', ['request_id', 'amount_cents', 'employee_id', 'status', 'reference_slot', 'revision', 'received_on', 'receipt_evidence']) && Schema::hasTable('payroll_payment_events') && Schema::hasColumn('payroll_employees', 'employee_id') && Schema::hasColumn('employees', 'hr_revision');
    }

    public function authorize(User $actor, bool $write = false): void
    {
        $permission = $write ? OwnerPortal::PAYMENTS_MANAGE : OwnerPortal::PAYROLL_VIEW;
        if ($this->owner->allows($actor, $permission) && $this->owner->allows($actor, OwnerPortal::PAYROLL_VIEW)) {
            return;
        }
        $this->access->payroll($actor, OwnerPortal::PAYROLL_VIEW);
        if ($write) {
            $this->access->payroll($actor, $permission);
        }
    }

    private function requireReady(): void
    {
        if (! $this->ready()) {
            throw new Failure('سجل الصرف والاستلام غير جاهز. لا يعني ذلك عدم وجود رواتب سابقة.', 503, 'payroll_payments_not_ready');
        }
    }

    private function input(array $data, array $rules): array
    {
        if (array_diff(array_keys($data), array_keys($rules))) {
            throw Failure::invalid('payroll_payment_invalid_fields', 'توجد حقول غير مسموحة.');
        }

        return Validator::make($data, $rules)->validate();
    }

    private function atomic(Closure $work): array
    {
        try {
            return DB::transaction($work, 1);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw Failure::conflict('payroll_payment_conflict', 'تعارضت العملية مع تعديل آخر؛ راجع الحالة الحالية دون إعادة تلقائية.');
            }
            throw $e;
        }
    }

    public static function amount(int|string $cents): string
    {
        return (string) BigDecimal::of((string) $cents)->withPointMovedLeft(2)->toScale(2);
    }

    public function present(object $payment): array
    {
        $row = (array) $payment;
        $row['amount'] = self::amount($payment->amount_cents);
        $row['identity_snapshot'] = json_decode($payment->identity_snapshot, true, 512, JSON_THROW_ON_ERROR);
        unset($row['payload_hash'], $row['amount_cents']);

        return $row;
    }

    private function event(User $actor, int $id, string $action, array $details): void
    {
        DB::table('payroll_payment_events')->insert(['payment_id' => $id, 'action' => $action, 'actor_user_id' => $actor->user_id, 'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    /** Financial employee → personnel → payment. Same parent order as explicit HR/payroll linkage. */
    private function employee(int $id): array
    {
        $payroll = DB::table('payroll_employees')->where('id', $id)->lockForUpdate()->first();
        if (! $payroll || ! $payroll->employee_id) {
            throw Failure::conflict('payroll_payment_identity_required', 'اربط الملف المالي بالعامل صراحة قبل تسجيل الصرف.');
        }
        $person = DB::table('employees')->where('employee_id', $payroll->employee_id)->lockForUpdate()->first();
        if (! $person) {
            throw Failure::conflict('payroll_payment_identity_required', 'هوية العامل المرتبطة غير متاحة.');
        }

        return [$payroll, $person];
    }

    public function record(User $actor, int $payrollId, array $data): array
    {
        $this->authorize($actor, true);
        $this->requireReady();
        $d = $this->input($data, ['request_id' => 'required|uuid', 'expected_payroll_revision' => 'required|integer|min:1', 'expected_employee_revision' => 'required|integer|min:1', 'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'amount' => 'required', 'reference' => 'required|string|max:120|regex:/\S/u', 'reason' => 'required|string|max:4000|regex:/\S/u', 'confirmed' => 'required|accepted']);
        try {
            $amount = PayrollInput::parse(['value_type' => 'amount', 'allow_negative' => false], $d['amount']);
            if (! $amount instanceof BigDecimal || ! $amount->isPositive()) {
                throw new InvalidArgumentException('أدخل مبلغ صرف فعليًا أكبر من الصفر.');
            }
        } catch (InvalidArgumentException $e) {
            throw Failure::invalid('payroll_payment_amount_invalid', $e->getMessage());
        }
        $d['amount_cents'] = (int) (string) $amount->withPointMovedRight(2)->toScale(0);
        $d['reference'] = trim($d['reference']);
        unset($d['amount']);
        $intent = $d + ['payroll_employee_id' => $payrollId, 'actor_user_id' => $actor->user_id];
        ksort($intent);
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $payrollId, $d, $hash): array {
                $this->authorize($actor, true);
                [$payroll, $person] = $this->employee($payrollId);
                $old = DB::table('payroll_payments')->where('request_id', $d['request_id'])->lockForUpdate()->first();
                // Lost-response replay is checked after authorization and locks, BEFORE revision validation.
                if ($old) {
                    if ($old->recorded_by_user_id != $actor->user_id || ! hash_equals($old->payload_hash, $hash)) {
                        throw Failure::conflict('payroll_payment_request_mismatch', 'معرف الحفظ مستخدم بمحتوى أو منفذ مختلف.');
                    }

                    return $this->present($old);
                }
                if ((int) $payroll->revision !== (int) $d['expected_payroll_revision'] || (int) $person->hr_revision !== (int) $d['expected_employee_revision']) {
                    throw Failure::conflict('payroll_payment_stale', 'تغيرت هوية الملف المالي؛ راجع الملف قبل تسجيل الصرف.');
                }
                $identity = ['employee_id' => $person->employee_id, 'employee_number' => $person->employee_number, 'name' => trim($person->first_name.' '.$person->last_name), 'payroll_employee_number' => $payroll->employee_number, 'payroll_full_name' => $payroll->full_name, 'payroll_body_id' => $payroll->payroll_body_id];
                $id = DB::table('payroll_payments')->insertGetId(['request_id' => $d['request_id'], 'payload_hash' => $hash, 'payroll_employee_id' => $payrollId, 'employee_id' => $person->employee_id, 'period' => $d['period'], 'paid_on' => $d['paid_on'], 'amount_cents' => $d['amount_cents'], 'currency' => 'SYP', 'reference' => $d['reference'], 'reason' => $d['reason'], 'identity_snapshot' => json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'recorded_by_user_id' => $actor->user_id, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('payroll_payments')->where('id', $id)->first();
                $this->event($actor, $id, 'disbursement_recorded', ['before' => null, 'after' => $this->present($row), 'external_transfer_performed_by_system' => false]);

                return $this->present($row);
            }, 1);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '19', '40001'], true) || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw Failure::conflict('payroll_payment_conflict', 'تعارض الحفظ أو تكرر مرجع الدفعة؛ تحقق من النتيجة الحالية دون إعادة تلقائية.');
            }
            throw $e;
        }
    }

    public function transition(User $actor, int $id, string $action, array $data): array
    {
        $this->authorize($actor, true);
        $this->requireReady();
        $rules = ['revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted'];
        $rules += $action === 'receive' ? ['received_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'receipt_evidence' => 'required|string|max:4000|regex:/\S/u'] : ['reason' => 'required|string|max:4000|regex:/\S/u'];
        if (! in_array($action, ['receive', 'void'], true)) {
            throw Failure::invalid('payroll_payment_action_invalid', 'إجراء غير مسموح.');
        }
        $d = $this->input($data, $rules);

        return $this->atomic(function () use ($actor, $id, $action, $d): array {
            $this->authorize($actor, true);
            $hint = DB::table('payroll_payments')->where('id', $id)->first() ?? throw Failure::denied('payroll_payment_not_found', 'الدفعة غير متاحة.');
            [$payroll, $person] = $this->employee($hint->payroll_employee_id);
            $row = DB::table('payroll_payments')->where('id', $id)->lockForUpdate()->first();
            if ($row->employee_id != $person->employee_id || $row->payroll_employee_id != $payroll->id) {
                throw Failure::conflict('payroll_payment_identity_changed', 'هوية الدفعة لا تطابق العامل المرتبط.');
            }
            if ((int) $row->revision !== (int) $d['revision'] || $row->status === 'voided' || ($action === 'receive' && $row->status !== 'paid')) {
                throw Failure::conflict('payroll_payment_stale', 'تغيرت الدفعة أو لم يعد الإجراء متاحًا.');
            }
            if ($action === 'receive' && $d['received_on'] < $row->paid_on) {
                throw Failure::invalid('payroll_payment_receipt_date', 'تاريخ الاستلام لا يسبق تاريخ الصرف.');
            }
            $changes = $action === 'receive'
                ? ['status' => 'received', 'received_on' => $d['received_on'], 'receipt_evidence' => $d['receipt_evidence'], 'received_at' => now(), 'received_by_user_id' => $actor->user_id]
                : ['status' => 'voided', 'reference_slot' => null, 'voided_at' => now(), 'voided_by_user_id' => $actor->user_id, 'void_reason' => $d['reason']];
            DB::table('payroll_payments')->where('id', $id)->update($changes + ['revision' => $row->revision + 1, 'updated_at' => now()]);
            $after = DB::table('payroll_payments')->where('id', $id)->first();
            $this->event($actor, $id, $action === 'receive' ? 'receipt_recorded' : 'record_voided', ['before' => $this->present($row), 'after' => $this->present($after), 'refund_performed_by_system' => false]);

            return $this->present($after);
        });
    }

    public function history(User $actor, int $employee, array $input = []): array
    {
        $this->authorize($actor);
        $d = $this->input($input, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        if (! $this->ready()) {
            return ['schema_ready' => false, 'rows' => [], 'summary' => null, 'reason' => 'payroll_payments_not_ready'];
        }
        $q = DB::table('payroll_payments')->where('employee_id', $employee);
        $totals = (clone $q)->selectRaw("COUNT(*) as records, SUM(CASE WHEN status <> 'voided' THEN amount_cents ELSE 0 END) as disbursed, SUM(CASE WHEN status = 'received' THEN amount_cents ELSE 0 END) as received")->first();
        $page = $q->orderByDesc('paid_on')->orderByDesc('id')->paginate($d['per_page'] ?? 15, ['*'], 'page', $d['page'] ?? 1);

        return ['schema_ready' => true, 'rows' => collect($page->items())->map(fn ($row) => $this->present($row)), 'summary' => ['records' => (int) $totals->records, 'disbursed' => self::amount((string) ($totals->disbursed ?? 0)), 'received' => self::amount((string) ($totals->received ?? 0)), 'currency' => 'SYP'], 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]];
    }
}
