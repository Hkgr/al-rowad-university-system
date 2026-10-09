<?php

namespace App\Console\Commands;

use App\Services\Payroll\PayrollPersonnelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** One explicitly authorized destructive maintenance operation, never called by migration/GET/sync. */
final class ReplaceTrialPayroll extends Command
{
    protected $signature = 'payroll:replace-trial {--execute} {--preview-token=} {--confirm=} {--backup-confirmed} {--maintenance-confirmed}';

    protected $description = 'Preview, or explicitly replace ALL trial payroll profiles/values/payments with blank personnel profiles once';

    private const TABLES = ['payroll_payment_events', 'payroll_payments', 'payroll_period_events', 'payroll_period_history', 'payroll_period_entries', 'payroll_periods', 'payroll_entry_values', 'payroll_entries', 'payroll_employees'];

    private function manifest(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Required table missing: '.$table);
            }
            $rows[$table] = DB::table($table)->count();
        }
        $revisions = [];
        foreach (['payroll_config' => 'id', 'employees' => 'employee_id', 'payroll_employees' => 'id', 'payroll_entries' => 'payroll_employee_id', 'payroll_payments' => 'id', 'payroll_periods' => 'id'] as $table => $key) {
            $rev = $table === 'employees' ? 'hr_revision' : 'revision';
            $revisions[$table] = DB::table($table)->orderBy($key)->get([$key, $rev])->all();
        }

        return ['delete_counts' => $rows, 'create_profiles' => DB::table('employees')->count(), 'revisions' => $revisions];
    }

    private function checkForeignKeys(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (self::TABLES as $table) {
            if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('ENGINE') !== 'InnoDB') {
                throw new RuntimeException('Atomic replacement requires existing InnoDB: '.$table);
            }
        }
        $refs = DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())->whereIn('REFERENCED_TABLE_NAME', self::TABLES)->get(['TABLE_NAME']);
        foreach ($refs as $ref) {
            if (! in_array($ref->TABLE_NAME, self::TABLES, true)) {
                throw new RuntimeException('Unexpected dependent table; no deletion permitted: '.$ref->TABLE_NAME);
            }
        }
    }

    public function handle(PayrollPersonnelService $personnel): int
    {
        try {
            $personnel->requireReady();

            return DB::transaction(function () use ($personnel): int {
                $state = DB::table('payroll_config')->where('id', 1)->lockForUpdate()->first();
                if (! $state || $state->personnel_reset_completed_at) {
                    throw new RuntimeException('Trial replacement already completed or configuration missing. Use ordinary synchronization, never repeat deletion.');
                }
                $this->checkForeignKeys();
                if ($this->option('execute')) {
                    if ($this->option('confirm') !== 'DELETE_ALL_TRIAL_PAYROLL' || ! $this->option('backup-confirmed') || ! $this->option('maintenance-confirmed')) {
                        throw new RuntimeException('Execution requires exact confirmation, verified backup and maintenance flags.');
                    }
                    if (DB::table('payroll_periods')->orderBy('id')->lockForUpdate()->first()) {
                        throw new RuntimeException('New monthly accounting data already exists. Stop: the pre-existing trial authorization cannot silently erase newly recorded month data.');
                    }
                    // Under required maintenance: lock real personnel and all financial parents before validating preview.
                    DB::table('employees')->orderBy('employee_id')->lockForUpdate()->get(['employee_id']);
                    foreach (['payroll_periods', 'payroll_employees', 'payroll_entries', 'payroll_payments'] as $table) {
                        DB::table($table)->lockForUpdate()->get();
                    }
                }
                $manifest = $this->manifest();
                $token = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
                $this->line(json_encode(['delete_counts' => $manifest['delete_counts'], 'create_profiles' => $manifest['create_profiles'], 'preview_token' => $token, 'preserved' => ['employees', 'users', 'faculty_members', 'HR contracts/approvals/history', 'payroll configuration/columns/formulas/settings/bodies']], JSON_THROW_ON_ERROR));
                if (! $this->option('execute')) {
                    return self::SUCCESS;
                }
                if (! hash_equals($token, (string) $this->option('preview-token'))) {
                    throw new RuntimeException('Preview changed; take a new preview. Nothing deleted.');
                }
                foreach (self::TABLES as $table) {
                    DB::table($table)->delete();
                }
                // Original personnel/accounts/contracts untouched; every rebuilt profile starts with NO amounts.
                $personnel->synchronize();
                DB::table('payroll_config')->where('id', 1)->update(['personnel_reset_completed_at' => now(), 'personnel_reset_manifest' => $token, 'personnel_reset_receipt' => json_encode(['delete_counts' => $manifest['delete_counts'], 'profiles_created' => $manifest['create_profiles'], 'executed_at' => now()->toIso8601String(), 'execution_context' => 'Explicit Artisan maintenance; no application approval or actor fabricated'], JSON_THROW_ON_ERROR)]);
                $this->info('REPLACED atomically. No trial financial amount or disbursement transferred.');

                return self::SUCCESS;
            }, 1);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
