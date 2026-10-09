<?php

namespace App\Console\Commands;

use App\Support\HrOffice;
use App\Support\OwnerPortal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProvisionHrOfficeAccess extends Command
{
    protected $signature = 'hr-office:provision-access {--grant-hr : Assign office read/preparation/classification/recruitment to the existing hr_officer role} {--grant-vp : Assign those permissions plus review to the existing administrative VP role} {--grant-vp-payroll : Grant only existing payroll view/link/identity permissions to the existing administrative VP role} {--grant-vp-actions : Explicitly grant relationship correction/cancellation and individual worker PDF export to the existing administrative VP role} {--grant-vp-payments : Explicitly grant disbursement/receipt recording to the existing administrative VP role} {--check : Read only}';

    protected $description = 'Register HR office permissions; explicit existing-role grants, optional VP payroll view/link, no accounts, scopes, amount or formula grants';

    private const VP_PAYROLL = [HrOffice::PAYROLL_ACCESS, OwnerPortal::PAYROLL_VIEW, HrOffice::PAYROLL_LINK, OwnerPortal::EMPLOYEES_MANAGE];

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
                $extraCodes = array_merge($this->option('grant-vp-payroll') ? self::VP_PAYROLL : [], $this->option('grant-vp-actions') ? self::VP_ACTIONS : [], $this->option('grant-vp-payments') ? [OwnerPortal::PAYMENTS_MANAGE] : []);
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
                        throw new \RuntimeException('Existing active owner payroll view/employee permissions in the owner module are required.');
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
            $this->info($state.'. No user, scope or financial value was created; no owner-portal, amount-edit or formula-management permission was granted.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
