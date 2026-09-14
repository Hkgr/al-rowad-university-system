<?php

namespace App\Support;

use App\Models\{AcademicProgram, User};
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder;

final class ScientificProgramAccess
{
    public const VIEW = 'academic_structure.view';
    public const MANAGE = 'academic_structure.manage';
    public const PLANS = 'vice_presidency.scientific.programs.plans.manage';
    public const APPROVE = 'vice_presidency.scientific.programs.plans.approve';
    public const ASSIGN = 'vice_presidency.scientific.programs.plans.assign';
    public const ARCHIVE = 'vice_presidency.scientific.programs.archive';
    public const DELETE = 'vice_presidency.scientific.programs.delete';

    public function __construct(private DataScopeService $scope) {}

    public function authorize(User $actor, string $permission = self::VIEW): void
    {
        $fresh = $actor->fresh();
        abort_unless($fresh && $fresh->accountStatus?->status_code === 'active'
            && $fresh->effectiveRoles()->contains('vice_president_scientific')
            && collect(['vice_presidency.scientific.access', self::VIEW, $permission])->diff($fresh->effectivePermissions())->isEmpty(), 403);
        abort_unless(collect($this->scope->scopes($fresh))->contains(fn ($s) => in_array($s['type'], ['university', 'college', 'department', 'program'], true)), 403);
    }

    public function programs(User $actor): Builder
    {
        return $this->scope->scopeProgramsForMutation(AcademicProgram::query(), $actor);
    }

    public function capabilities(User $actor): array
    {
        $permissions = $actor->fresh()->effectivePermissions();
        return collect(['edit' => self::MANAGE, 'plans' => self::PLANS, 'approve' => self::APPROVE,
            'assign' => self::ASSIGN, 'archive' => self::ARCHIVE, 'delete' => self::DELETE])
            ->map(fn ($code) => $permissions->contains($code))->all();
    }
}
