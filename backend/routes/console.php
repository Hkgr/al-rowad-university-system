<?php

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'rbac:assign-role {user : Username or email address} {role : Role code}',
    function (string $user, string $role): int {
        $userRecord = User::query()
            ->where('username', $user)
            ->orWhere('email', $user)
            ->first();

        if (! $userRecord) {
            $this->error("User [{$user}] was not found.");

            return 1;
        }

        $roleRecord = Role::query()
            ->where('role_code', $role)
            ->where('is_active', true)
            ->first();

        if (! $roleRecord) {
            $this->error("Active role [{$role}] was not found.");

            return 1;
        }

        UserRole::query()->updateOrCreate(
            [
                'user_id' => $userRecord->user_id,
                'role_id' => $roleRecord->role_id,
            ],
            [
                'assigned_by_user_id' => null,
                'assigned_at' => now(),
                'is_active' => true,
            ]
        );

        $this->info("Role [{$role}] assigned to [{$userRecord->username}].");

        return 0;
    }
)->purpose('Assign or reactivate an RBAC role for a system user');
