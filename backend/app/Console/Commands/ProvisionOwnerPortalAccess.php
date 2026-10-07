<?php

namespace App\Console\Commands;

use App\Support\OwnerPortal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Explicit, targeted, idempotent provisioning of the university-owner role and its permissions.
 * It never creates users, never assigns the role to anyone (user_roles) and never maps an owner
 * permission to any role other than `university_owner`.
 */
class ProvisionOwnerPortalAccess extends Command
{
    protected $signature = 'owner-portal:provision-access {--check : Report the current state without writing anything}';

    protected $description = 'Idempotently create the university_owner role, the owner_portal module and the owner permissions (no users, no assignments)';

    private const TAG = '[owner-portal]';

    public function handle(): int
    {
        try {
            $state = DB::transaction(function (): string {
                $module = DB::table('system_modules')->where('module_code', OwnerPortal::MODULE)->lockForUpdate()->first();
                $role = DB::table('roles')->where('role_code', OwnerPortal::ROLE)->lockForUpdate()->first();
                if (($module && ! $module->is_active) || ($role && ! $role->is_active)) {
                    throw new \RuntimeException('Existing owner module/role is inactive.');
                }
                $codes = array_keys(OwnerPortal::PERMISSIONS);
                $existing = DB::table('permissions')->whereIn('permission_code', $codes)->lockForUpdate()->get()->keyBy('permission_code');
                foreach ($existing as $permission) {
                    if (! $permission->is_active || ($module && (int) $permission->module_id !== (int) $module->module_id) || ! $module) {
                        throw new \RuntimeException('Existing owner permission conflicts with the expected active module.');
                    }
                }
                $foreign = DB::table('role_permissions as rp')->join('permissions as p', 'p.permission_id', '=', 'rp.permission_id')
                    ->join('roles as r', 'r.role_id', '=', 'rp.role_id')->whereIn('p.permission_code', $codes)
                    ->where('r.role_code', '!=', OwnerPortal::ROLE)->exists();
                if ($foreign) {
                    throw new \RuntimeException('An owner permission is mapped to another role.');
                }
                $complete = $module && $role && $existing->count() === count($codes)
                    && DB::table('role_permissions')->where('role_id', $role->role_id)->whereIn('permission_id', $existing->pluck('permission_id'))->count() === count($codes);
                if ($this->option('check')) {
                    return $complete ? 'COMPLETE' : 'MISSING';
                }

                $moduleId = $module?->module_id ?? DB::table('system_modules')->insertGetId([
                    'module_code' => OwnerPortal::MODULE, 'module_name' => 'University owner portal',
                    'description' => 'بوابة مالك الجامعة: الرئيسية والرواتب. '.self::TAG, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ], 'module_id');
                $roleId = $role?->role_id ?? DB::table('roles')->insertGetId([
                    'role_code' => OwnerPortal::ROLE, 'role_name' => 'مالك الجامعة',
                    'description' => 'University owner: Home and Payroll only. Not assigned to anyone by default. '.self::TAG,
                    'is_system_role' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ], 'role_id');
                foreach (OwnerPortal::PERMISSIONS as $code => $label) {
                    $permissionId = $existing->get($code)?->permission_id ?? DB::table('permissions')->insertGetId([
                        'module_id' => $moduleId, 'permission_code' => $code, 'permission_name' => $label,
                        'description' => $label.' '.self::TAG, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ], 'permission_id');
                    if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'granted_at' => now()]);
                    }
                }

                return $complete ? 'COMPLETE' : 'APPLIED';
            });
        } catch (\Throwable $e) {
            $this->error('Provisioning blocked: '.$e->getMessage().' No partial changes were committed.');

            return self::FAILURE;
        }

        $this->info($this->option('check')
            ? "Owner portal access state: {$state}."
            : 'Owner role and permissions are present ('.$state.'). No users, assignments or other roles were changed.');

        return self::SUCCESS;
    }
}
