<?php

namespace App\Console\Commands;

use App\Support\HrOffice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProvisionHrOfficeAccess extends Command
{
    protected $signature = 'hr-office:provision-access {--grant-hr : Assign office read/preparation/classification/recruitment to the existing hr_officer role} {--grant-vp : Assign those permissions plus review to the existing administrative VP role} {--check : Read only}';

    protected $description = 'Register HR office permissions; optional explicit existing-role grants, no accounts, scopes or financial grants';

    public function handle(): int
    {
        try {
            $state = DB::transaction(function (): string {
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
                    return $existing->count() === count(HrOffice::PERMISSIONS) ? 'REGISTERED' : 'MISSING';
                }
                foreach (HrOffice::PERMISSIONS as $code => $label) {
                    $id = $existing->get($code)?->permission_id ?? DB::table('permissions')->insertGetId(['module_id' => $module->module_id, 'permission_code' => $code, 'permission_name' => $label, 'description' => 'Office 711; explicit role + actual scope required.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], 'permission_id');
                    foreach ($roles as $roleCode => $roleId) {
                        if (in_array($code, [HrOffice::PAYROLL_ACCESS, HrOffice::PAYROLL_LINK], true) || ($code === HrOffice::REVIEW && $roleCode !== 'vice_president_administrative')) {
                            continue;
                        }
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id, 'granted_at' => now()]);
                    }
                }

                return 'APPLIED';
            });
            $this->info($state.'. No user, scope, payroll permission or financial value was created.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
