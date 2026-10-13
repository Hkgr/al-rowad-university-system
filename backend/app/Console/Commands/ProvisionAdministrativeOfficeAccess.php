<?php

namespace App\Console\Commands;

use App\Support\AdministrativeOfficeAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** One idempotent grant to the existing role; no users, roles, scopes, office units or workflow data created. */
final class ProvisionAdministrativeOfficeAccess extends Command
{
    protected $signature = 'administrative-offices:provision-access {--check : Read only: list missing/incompatible permissions and role grants}';

    protected $description = 'Provision the implemented 71/72/73 office permissions for the existing administrative VP role';

    public function handle(): int
    {
        try {
            return DB::transaction(function (): int {
                $check = (bool) $this->option('check');
                $roleQuery = DB::table('roles')->where('role_code', AdministrativeOfficeAccess::ROLE);
                if (! $check) {
                    $roleQuery->lockForUpdate();
                }
                $role = $roleQuery->sole();
                if (! $role->is_active) {
                    throw new \RuntimeException('Existing administrative VP role must be active.');
                }
                $codes = AdministrativeOfficeAccess::permissions();
                $query = DB::table('permissions')->whereIn('permission_code', $codes)->orderBy('permission_id');
                if (! $check) {
                    $query->lockForUpdate();
                }
                $permissionRows = $query->get();
                if ($permissionRows->pluck('permission_code')->unique()->count() !== $permissionRows->count()) {
                    throw new \RuntimeException('Duplicate permission codes; no grants committed.');
                }
                $permissions = $permissionRows->keyBy('permission_code');
                $modules = DB::table('system_modules')->get()->keyBy('module_id');
                if ($modules->pluck('module_code')->unique()->count() !== $modules->count()) {
                    throw new \RuntimeException('Duplicate module codes; no grants committed.');
                }
                $byCode = $modules->keyBy('module_code');
                $definitions = AdministrativeOfficeAccess::registrablePermissions();
                $grants = DB::table('role_permissions')->where('role_id', $role->role_id)->pluck('permission_id');
                $rows = [];
                $blocked = false;
                foreach ($codes as $code) {
                    $p = $permissions->get($code);
                    $definition = $definitions[$code] ?? null;
                    if ($p && (! $p->is_active || ! $modules->get($p->module_id)?->is_active || ($definition && $modules->get($p->module_id)->module_code !== $definition['module']))) {
                        $state = 'INCOMPATIBLE';
                        $blocked = true;
                    } elseif (! $p) {
                        $state = 'MISSING_PERMISSION';
                        if (! $definition || ! $byCode->get($definition['module'])?->is_active) {
                            $blocked = true;
                        }
                    } else {
                        $state = $grants->contains($p->permission_id) ? 'READY' : 'MISSING_ROLE_GRANT';
                    }
                    $rows[] = [$code, $state];
                }
                $this->table(['Permission', 'State'], $rows);
                if ($check) {
                    $ready = ! $blocked && collect($rows)->every(fn ($row) => $row[1] === 'READY');
                    $this->info($ready ? 'READY' : 'MISSING/BLOCKED — no writes made.');

                    return $ready ? self::SUCCESS : self::FAILURE;
                }
                if ($blocked) {
                    throw new \RuntimeException('Missing/incompatible pre-existing permission or module. Apply the owning feature prerequisites first; no partial grants committed.');
                }
                foreach ($codes as $code) {
                    $p = $permissions->get($code);
                    if (! $p) {
                        $d = $definitions[$code];
                        $id = DB::table('permissions')->insertGetId(['permission_code' => $code, 'permission_name' => $d['label'],
                            'module_id' => $byCode[$d['module']]->module_id, 'description' => 'Implemented administrative directorate office operation.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], 'permission_id');
                    } else {
                        $id = $p->permission_id;
                    }
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->role_id, 'permission_id' => $id, 'granted_at' => now()]);
                }
                $this->info('APPLIED — complete office grants. No user/role/scope/financial data changed; existing grants retained. Re-login to refresh identity.');

                return self::SUCCESS;
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
