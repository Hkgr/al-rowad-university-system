<?php

namespace App\Console\Commands;

use App\Support\HrOffice;
use App\Support\OwnerPortal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProvisionHrOfficeAccess extends Command
{
    protected $signature = 'hr-office:provision-access {--grant-hr : Assign office read/preparation/classification/recruitment/work-time rights to the existing hr_officer role} {--grant-vp : Assign those permissions plus review to the existing administrative VP role} {--grant-vp-payroll : Grant accounting entry/read only to the existing administrative VP} {--grant-vp-accounting : Explicitly grant amount editing, period preparation/correction, payments and financial export; not formulas or owner-portal access} {--grant-vp-actions : Explicitly grant relationship correction/cancellation and individual worker PDF export} {--grant-vp-payments : Explicitly grant disbursement/receipt recording} {--check : Read only}';

    protected $description = 'Register HR permissions and explicit VP accounting grants; no accounts/scopes, owner-portal access or formula-management grants';

    private const VP_PAYROLL = [HrOffice::PAYROLL_ACCESS, OwnerPortal::PAYROLL_VIEW];

    private const VP_ACTIONS = [HrOffice::CORRECT, HrOffice::CANCEL, HrOffice::WORKER_EXPORT];

    public function handle(): int
    {
        try {
            $state = DB::transaction(function (): string {
                // One shared command mutex/order, including the pre-existing core grant options.
                $module = DB::table('system_modules')->where('module_code', 'vice_presidency')->lockForUpdate()->first();
                if (! $module || ! $module->is_active) {
                    throw new \RuntimeException('Active vice_presidency module required.');
                }
                $existing = DB::table('permissions')->whereIn('permission_code', array_keys(HrOffice::PERMISSIONS))->lockForUpdate()->get()->keyBy('permission_code');
                foreach ($existing as $p) {
                    if (! $p->is_active || $p->module_id != $module->module_id) {
                        throw new \RuntimeException('Conflicting HR permission/module.');
                    }
                }
                $payrollRole = null;
                $payrollPermissions = collect();
                $extraCodes = array_values(array_unique(array_merge($this->option('grant-vp-payroll') ? self::VP_PAYROLL : [], $this->option('grant-vp-actions') ? self::VP_ACTIONS : [], $this->option('grant-vp-payments') ? [OwnerPortal::PAYMENTS_MANAGE] : [], $this->option('grant-vp-accounting') ? [HrOffice::PAYROLL_ACCESS, OwnerPortal::PAYROLL_VIEW, OwnerPortal::AMOUNTS_EDIT, OwnerPortal::PERIODS_MANAGE, OwnerPortal::PERIODS_CORRECT, OwnerPortal::PAYMENTS_MANAGE, OwnerPortal::EXPORT] : [])));
                $ownerCodes = array_values(array_filter($extraCodes, fn ($code) => str_starts_with($code, 'owner_payroll.')));
                if ($extraCodes !== []) {
                    $payrollRole = DB::table('roles')->where('role_code', 'vice_president_administrative')->where('is_active', true)->lockForUpdate()->first();
                    if (! $payrollRole) {
                        throw new \RuntimeException('Existing active role required: vice_president_administrative');
                    }
                }
                if ($ownerCodes !== []) {
                    $ownerModule = DB::table('system_modules')->where('module_code', OwnerPortal::MODULE)->lockForUpdate()->first();
                    $payrollPermissions = DB::table('permissions')->whereIn('permission_code', $ownerCodes)->lockForUpdate()->get()->keyBy('permission_code');
                    if (! $ownerModule?->is_active || $payrollPermissions->count() !== count($ownerCodes) || $payrollPermissions->contains(fn ($p) => ! $p->is_active || $p->module_id != $ownerModule->module_id)) {
                        throw new \RuntimeException('All selected active payroll permissions must exist in the owner module. Run owner-portal:provision-access first.');
                    }
                }
                $roles = [];
                foreach (['hr' => 'hr_officer', 'vp' => 'vice_president_administrative'] as $flag => $code) {
                    if ($this->option('grant-'.$flag)) {
                        $role = DB::table('roles')->where('role_code', $code)->where('is_active', true)->lockForUpdate()->first();
                        if (! $role) {
                            throw new \RuntimeException('Existing active role required: '.$code);
                        }
                        $roles[$code] = $role->role_id;
                    }
                }
                if ($this->option('check')) {
                    if ($existing->count() !== count(HrOffice::PERMISSIONS)) {
                        return 'MISSING';
                    }
                    if ($payrollRole) {
                        $ids = $existing->merge($payrollPermissions)->only($extraCodes)->pluck('permission_id');

                        return DB::table('role_permissions')->where('role_id', $payrollRole->role_id)->whereIn('permission_id', $ids)->count() === count($extraCodes) ? 'VP_GRANTS_READY' : 'VP_GRANTS_MISSING';
                    }

                    return 'REGISTERED';
                }
                foreach (HrOffice::PERMISSIONS as $code => $label) {
                    $id = $existing->get($code)?->permission_id ?? DB::table('permissions')->insertGetId(['module_id' => $module->module_id, 'permission_code' => $code, 'permission_name' => $label, 'description' => 'Office 711; explicit role + actual scope required.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], 'permission_id');
                    if ($payrollRole && in_array($code, $extraCodes, true)) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $payrollRole->role_id, 'permission_id' => $id, 'granted_at' => now()]);
                    }
                    foreach ($roles as $roleCode => $roleId) {
                        if (in_array($code, array_merge([HrOffice::PAYROLL_ACCESS, HrOffice::PAYROLL_LINK], self::VP_ACTIONS), true) || ($code === HrOffice::REVIEW && $roleCode !== 'vice_president_administrative')) {
                            continue;
                        }
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id, 'granted_at' => now()]);
                    }
                }
                if ($payrollRole) {
                    foreach ($payrollPermissions as $permission) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $payrollRole->role_id, 'permission_id' => $permission->permission_id, 'granted_at' => now()]);
                    }
                }

                return 'APPLIED';
            });
            $this->info($state.'. No user, scope or financial value created. Accounting rights only when explicitly selected; no owner-portal or formula-management grant.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
