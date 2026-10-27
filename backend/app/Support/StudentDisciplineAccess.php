<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Existing Student Affairs grants, actual operator role and actual academic scope. */
final class StudentDisciplineAccess
{
    public static function allows(?User $actor, bool $write = false): bool
    {
        if ($actor?->isSuperAdmin()) {
            return true;
        }
        if (! $actor || $actor->accountStatus?->status_code !== 'active'
            || ! $actor->hasPermission('students.view')
            || ($write && ! $actor->hasPermission('students.manage'))) {
            return false;
        }
        $scope = app(DataScopeService::class);
        if ($actor->effectiveRoles()->contains('vice_president_administrative') && $scope->hasActualUniversityScope($actor)) {
            return true;
        }

        return $actor->effectiveRoles()->contains('registration_officer')
            && collect($scope->scopes($actor))->contains(fn ($s) => in_array($s['type'], ['university', 'college', 'department', 'program', 'section'], true));
    }

    public static function authorize(?User $actor, bool $write = false): void
    {
        abort_unless(self::allows($actor, $write), 403, 'ليس لديك صلاحية الوصول إلى سجل العقوبات.');
    }

    public static function students(User $actor): Builder
    {
        // Actual scope, without a student-self or instructor-derived entitlement.
        return app(DataScopeService::class)->scopeManualGradeStudents(Student::withTrashed(), $actor);
    }

    public static function student(User $actor, int $id, bool $write = false): Student
    {
        self::authorize($actor, $write);
        $query = self::students($actor);
        if ($write) {
            $query->lockForUpdate();
        }
        $student = $query->findOrFail($id);
        if ($write) {
            abort_unless(app(DataScopeService::class)->canMutateStudent($actor, $student), 403);
        }

        return $student;
    }

    public static function ready(): void
    {
        foreach (['student_disciplinary_cases', 'disciplinary_violation_types', 'disciplinary_penalty_types', 'disciplinary_case_appeals', 'disciplinary_case_affected_courses', 'appeal_statuses'] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'سجل العقوبات غير متاح؛ مخطط الخدمة الحالية غير مكتمل.');
        }
    }

    public static function identity(Student $student): array
    {
        return ['student_id' => $student->student_id, 'student_number' => $student->student_number, 'is_archived' => $student->trashed(),
            'full_name' => trim(implode(' ', array_filter([$student->first_name, $student->father_name, $student->last_name])))];
    }

    /** Presentation only; mutation authorization is always evaluated again. */
    public static function presentationWrite(Request $request): bool
    {
        $key = 'disciplinary.presentation.write';
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, self::allows($request->user(), true));
        }

        return $request->attributes->get($key);
    }
}
