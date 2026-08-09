<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserIdentityLinkService
{
    public function link(User $target, User $actor, array $links, ?string $ipAddress = null): User
    {
        return DB::transaction(function () use ($target, $actor, $links, $ipAddress): User {
            $target = User::query()->lockForUpdate()->findOrFail($target->user_id);
            foreach (['student_id', 'employee_id'] as $column) {
                if (array_key_exists($column, $links) && $links[$column] !== null) {
                    $conflict = User::query()->where($column, $links[$column])->whereKeyNot($target->user_id)->exists();
                    if ($conflict) throw ValidationException::withMessages([$column => ['This identity is already linked to another user.']]);
                }
            }
            $changed = array_intersect_key($links, array_flip(['student_id', 'employee_id', 'board_member_id']));
            $target->forceFill($changed)->save();
            UserActivityLog::query()->create([
                'user_id' => $actor->user_id,
                'module_code' => 'users_permissions',
                'action_code' => 'user_identity_linked',
                'description' => 'Updated explicit identity links for user '.$target->user_id.': '.implode(', ', array_keys($changed)),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);
            return $target->fresh();
        });
    }
}
