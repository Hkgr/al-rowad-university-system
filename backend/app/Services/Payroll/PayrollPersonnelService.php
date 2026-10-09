<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Personnel is the identity source; mirrored financial strings are never an independent employee. */
final class PayrollPersonnelService
{
    public function ready(): bool
    {
        foreach (['payroll_periods', 'payroll_period_entries', 'payroll_period_history', 'payroll_period_events', 'hr_work_time_records'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return Schema::hasColumns('payroll_config', ['personnel_reset_completed_at', 'personnel_reset_manifest', 'personnel_reset_receipt']) && Schema::hasColumn('payroll_payments', 'period_revision_at_record');
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw new PayrollException('مخطط المحاسبة الشهرية غير جاهز.', 'payroll_monthly_not_ready', 503);
        }
    }

    /** Bounded relation lookups; no name matching, no inferred working days or financial amounts. */
    public function identities(?array $ids = null): array
    {
        $q = DB::table('employees as e')->leftJoin('payroll_employees as p', 'p.employee_id', '=', 'e.employee_id')
            ->leftJoin('employee_statuses as s', 's.employee_status_id', '=', 'e.employee_status_id')
            ->leftJoin('organizational_units as u', 'u.organizational_unit_id', '=', 'e.organizational_unit_id');
        if ($ids !== null) {
            $q->whereIn('e.employee_id', $ids);
        }
        $people = $q->orderBy('e.employee_id')->get(['e.employee_id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.hr_body', 'e.hr_revision', 'e.organizational_unit_id', 'u.unit_name', 's.status_code', 's.status_name', 'p.id as id', 'p.revision as employee_revision']);
        $all = $people->pluck('employee_id')->all();
        $relations = collect();
        $positions = collect();
        foreach (array_chunk($all, 500) as $chunk) {
            $relations = $relations->concat(DB::table('hr_employment_relationships as r')->leftJoin('organizational_units as u', 'u.organizational_unit_id', '=', 'r.organizational_unit_id')
                ->leftJoin('colleges as c', 'c.college_id', '=', 'r.college_id')->leftJoin('positions as p', 'p.position_id', '=', 'r.position_id')
                ->whereIn('r.employee_id', $chunk)->orderByDesc('r.starts_on')->orderByDesc('r.id')->get(['r.*', 'u.unit_name', 'c.college_name', 'p.position_title']));
            $positions = $positions->concat(DB::table('employee_positions as ep')->join('positions as p', 'p.position_id', '=', 'ep.position_id')->whereIn('ep.employee_id', $chunk)
                ->where('ep.is_active', true)->where('ep.start_date', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('ep.end_date')->orWhere('ep.end_date', '>=', now()->toDateString()))->orderBy('ep.position_id')->get(['ep.employee_id', 'p.position_title']));
        }
        $byPerson = $relations->groupBy('employee_id');
        $byPosition = $positions->groupBy('employee_id');
        $collegesByUnit = collect();
        $unitIds = $people->pluck('organizational_unit_id')->merge($relations->pluck('organizational_unit_id'))->filter()->unique()->values()->all();
        foreach (array_chunk($unitIds, 500) as $chunk) {
            $collegesByUnit = $collegesByUnit->concat(DB::table('colleges')->whereIn('organizational_unit_id', $chunk)->get(['college_id', 'college_name', 'organizational_unit_id']));
        }
        $collegesByUnit = $collegesByUnit->groupBy('organizational_unit_id');
        $today = now()->toDateString();

        return $people->map(function ($p) use ($byPerson, $byPosition, $collegesByUnit, $today): array {
            $history = $byPerson->get($p->employee_id, collect());
            $r = $history->first(fn ($r) => $r->starts_on <= $today && (! $r->ends_on || $r->ends_on >= $today) && (! $r->superseded_from || $r->superseded_from > $today) && (empty($r->cancelled_from) || $r->cancelled_from > $today));
            $body = $r?->body ?? ($history->isEmpty() ? $p->hr_body : null);
            $body = in_array($body, ['educational', 'administrative'], true) ? $body : null;
            $unit = $r?->organizational_unit_id ?? $p->organizational_unit_id;
            $unitColleges = $collegesByUnit->get($unit, collect());
            // A recorded unique organizational association is not inferred from an account role or body label.
            $college = $unitColleges->count() === 1 ? $unitColleges->first() : null;

            return ['id' => $p->id ? (int) $p->id : null, 'employee_id' => (int) $p->employee_id, 'employee_number' => $p->employee_number,
                'full_name' => trim($p->first_name.' '.$p->last_name), 'job_title' => $r?->position_title ?? $r?->job_title ?? $byPosition->get($p->employee_id, collect())->pluck('position_title')->unique()->implode('، '),
                'body' => $body, 'body_name' => ['educational' => 'هيئة تعليمية', 'administrative' => 'هيئة إدارية'][$body] ?? 'غير محدد',
                'organizational_unit_id' => $unit, 'unit_name' => $r?->unit_name ?? $p->unit_name,
                'college_id' => $r?->college_id ?? $college?->college_id, 'college_name' => $r?->college_name ?? $college?->college_name, 'relationship' => $r ? array_intersect_key((array) $r, array_flip(['id', 'source', 'body', 'relationship_type', 'work_mode', 'starts_on', 'ends_on', 'superseded_from', 'cancelled_from', 'position_id', 'job_title', 'college_id', 'organizational_unit_id', 'revision'])) : null,
                'work_mode' => $r?->work_mode, 'status_code' => $p->status_code, 'status_name' => $p->status_name,
                'hr_revision' => (int) $p->hr_revision, 'employee_revision' => (int) ($p->employee_revision ?? 0),
                'classification_complete' => $r !== null && $body !== null && in_array($r->work_mode, ['full', 'part'], true) && in_array($r->relationship_type, ['temporary_contract', 'continuous_contract', 'employment'], true)];
        })->all();
    }

    /** Explicit synchronization only. Existing values, body metadata, payments and identities are not overwritten. */
    public function synchronize(): array
    {
        $this->requireReady();

        return DB::transaction(function (): array {
            DB::table('payroll_config')->where('id', 1)->lockForUpdate()->first();
            $created = 0;
            foreach (DB::table('employees')->orderBy('employee_id')->lockForUpdate()->get(['employee_id', 'employee_number', 'first_name', 'last_name']) as $e) {
                $p = DB::table('payroll_employees')->where('employee_id', $e->employee_id)->lockForUpdate()->first();
                if (! $p) {
                    $id = DB::table('payroll_employees')->insertGetId(['employee_id' => $e->employee_id, 'employee_number' => $e->employee_number, 'full_name' => trim($e->first_name.' '.$e->last_name), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                    $created++;
                } else {
                    $id = $p->id;
                }
                if (! DB::table('payroll_entries')->where('payroll_employee_id', $id)->exists()) {
                    DB::table('payroll_entries')->insert(['payroll_employee_id' => $id, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            return ['created' => $created, 'employees' => DB::table('employees')->count(), 'unlinked_legacy_profiles' => DB::table('payroll_employees')->whereNull('employee_id')->count()];
        }, 1);
    }
}
