<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataScopeService;

final class ExecutiveReportAccess
{
    public function __construct(private DataScopeService $scope) {}

    public function allows(?User $actor): bool
    {
        if ($actor === null || $actor->accountStatus?->status_code !== 'active' || ! $this->scope->hasActualUniversityScope($actor)) return false;
        $roles = $actor->effectiveRoles();
        $permissions = $actor->effectivePermissions();

        return ($roles->contains(VicePresidency::ROLE_SCIENTIFIC)
                && $permissions->contains(VicePresidency::PERMISSION_SCIENTIFIC_ACCESS))
            || ($roles->contains(VicePresidency::ROLE_ADMINISTRATIVE)
                && $permissions->contains(VicePresidency::PERMISSION_ADMINISTRATIVE_ACCESS));
    }

    public function authorize(?User $actor): void { abort_unless($this->allows($actor), 403); }
}
