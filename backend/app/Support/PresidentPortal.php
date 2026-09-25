<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataScopeService;

/** Dedicated assigned read permissions; neither virtual administrator grants nor ministry roles suffice. */
final class PresidentPortal
{
    public const SECTIONS = ['dashboard', 'colleges', 'students', 'exams', 'staff', 'leadership', 'followup', 'reports'];

    public static function permissions(): array
    {
        return array_merge(['president_portal.access'], array_map(fn ($s) => "president_portal.{$s}.view", self::SECTIONS));
    }

    public function allows(?User $user, string $section): bool
    {
        if (! $user || $user->accountStatus?->status_code !== 'active') return false;
        $roles = $user->effectiveRoles();
        if (! $roles->contains('university_president') || $roles->contains('ministry_observer')) return false;
        if (! app(DataScopeService::class)->hasActualUniversityScope($user)) return false;
        $permissions = $user->effectivePermissions();
        return ($section === 'access' || in_array($section, self::SECTIONS, true))
            && $permissions->contains('president_portal.access')
            && ($section === 'access' || $permissions->contains("president_portal.{$section}.view"));
    }
}
