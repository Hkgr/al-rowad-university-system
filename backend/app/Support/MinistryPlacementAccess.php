<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataScopeService;

final class MinistryPlacementAccess
{
    public const VIEW = 'admissions.view';

    public const MANAGE = 'admissions.manage';

    public function __construct(private readonly DataScopeService $scope) {}

    public function canView(?User $actor): bool
    {
        return $this->allows($actor, self::VIEW);
    }

    public function canManage(?User $actor): bool
    {
        return $this->allows($actor, self::MANAGE);
    }

    private function allows(?User $actor, string $permission): bool
    {
        return $actor !== null
            && $actor->effectivePermissions()->contains($permission)
            && $this->scope->hasActualUniversityScope($actor);
    }
}
