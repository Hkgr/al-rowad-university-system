<?php

namespace App\Support;

use App\Models\{AcademicProgram, Course, Department, User};
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder;

final class ScientificCourseAccess
{
    public const VIEW = 'vice_presidency.scientific.courses.view';
    public const MANAGE = 'vice_presidency.scientific.courses.manage';

    public function __construct(private DataScopeService $scope) {}

    public function authorize(User $actor, bool $write = false): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->accountStatus?->status_code === 'active'
            && $actor->effectiveRoles()->contains('vice_president_scientific')
            && collect(['vice_presidency.scientific.access', self::VIEW, ...($write ? [self::MANAGE] : [])])->diff($actor->effectivePermissions())->isEmpty(), 403);
        abort_unless(collect($this->scope->scopes($actor))->contains(fn ($s) => in_array($s['type'], ['university', 'college', 'department', 'program'], true)), 403);
    }

    public function programs(User $actor): Builder
    {
        return $this->scope->scopeProgramsForMutation(AcademicProgram::query(), $actor);
    }

    public function departments(User $actor): Builder
    {
        if ($this->scope->hasActualUniversityScope($actor)) return Department::query();
        $scopes = collect($this->scope->scopes($actor));
        return Department::query()->where(fn ($q) => $q->whereIn('college_id', $scopes->where('type', 'college')->pluck('id'))
            ->orWhereIn('department_id', $scopes->where('type', 'department')->pluck('id'))
            ->orWhereIn('department_id', $this->programs($actor)->select('department_id')));
    }

    public function courses(User $actor): Builder
    {
        if ($this->scope->hasActualUniversityScope($actor)) return Course::query();
        return Course::query()->where(fn ($q) => $q->whereHas('courseDepartments', fn ($d) => $d->whereIn('department_id', $this->departments($actor)->select('department_id')))
            ->orWhereHas('programCourses', fn ($p) => $p->whereIn('academic_program_id', $this->programs($actor)->select('academic_program_id'))));
    }

    public function canEditOrigin(User $actor, Course $course): bool
    {
        if ($this->scope->hasActualUniversityScope($actor)) return true;
        // Curriculum visibility is NOT ownership of a shared origin.
        if (!$course->courseDepartments()->exists()) return false;
        if ($course->programCourses()->whereHas('requirementMapping.requirementGroup', fn ($q) => $q->where('requirement_scope', 'university'))->exists()) return false;
        $scopes = collect($this->scope->scopes($actor));
        $ownedDepartments = Department::query()->where(fn ($q) => $q->whereIn('college_id', $scopes->where('type', 'college')->pluck('id'))
            ->orWhereIn('department_id', $scopes->where('type', 'department')->pluck('id')))->select('department_id');
        return !$course->courseDepartments()->whereNotIn('department_id', $ownedDepartments)->exists()
            && !$course->programCourses()->whereNotIn('academic_program_id', $this->programs($actor)->select('academic_program_id'))->exists();
    }

    public function university(User $actor): bool { return $this->scope->hasActualUniversityScope($actor); }

    public function canCreateOrigin(User $actor): bool
    {
        return collect($this->scope->scopes($actor))->contains(fn ($s) => in_array($s['type'], ['university', 'college', 'department'], true));
    }
}
