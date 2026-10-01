<?php

namespace App\Console\Commands;

use App\Support\{AccountAdministration, UniversityEmailAccess};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Explicit, targeted provisioning; never run the full production seeder. */
class EnableUniversityEmailPermissions extends Command
{
    protected $signature = 'university-email:enable-permissions {--phase2 : Also explicitly provision the three Phase 2 permissions}';
    protected $description = 'Idempotently provision university-email permissions for the existing technical_team role (Phase 2 opt-in)';
    public function handle(): int
    {
        try {
            DB::transaction(function (): void {
                $role = DB::table('roles')->where('role_code', AccountAdministration::ROLE_TECHNICAL_TEAM)->lockForUpdate()->sole();
                $module = DB::table('system_modules')->where('module_code', AccountAdministration::MODULE_CODE)->lockForUpdate()->sole();
                if (! $role->is_active || ! $module->is_active) throw new \RuntimeException('Required role/module is inactive.');
                $permissions = UniversityEmailAccess::PERMISSIONS + ($this->option('phase2') ? UniversityEmailAccess::PHASE2_PERMISSIONS : []);
                foreach ($permissions as $code => $label) {
                    $permission = DB::table('permissions')->where('permission_code', $code)->lockForUpdate()->first();
                    if ($permission && (! $permission->is_active || $permission->module_id !== $module->module_id)) {
                        throw new \RuntimeException('Existing permission conflicts with the expected active module.');
                    }
                    $id = $permission?->permission_id ?? DB::table('permissions')->insertGetId([
                        'module_id' => $module->module_id, 'permission_code' => $code, 'permission_name' => $label,
                        'description' => 'Explicit university email operation permission; technical_team only.',
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now()], 'permission_id');
                    if (! DB::table('role_permissions')->where('role_id', $role->role_id)->where('permission_id', $id)->exists()) {
                        DB::table('role_permissions')->insert(['role_id' => $role->role_id, 'permission_id' => $id, 'granted_at' => now()]);
                    }
                }
            });
        } catch (\Exception) {
            $this->error('Provisioning blocked; verify the existing active technical_team role, users_permissions module and permission compatibility. No partial changes committed.');
            return self::FAILURE;
        }
        $this->info('University email permissions are assigned to technical_team only. No users, scopes or unrelated permissions changed.');
        return self::SUCCESS;
    }
}
