<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * College affiliation of teaching staff, exactly as DataScopeService::scopeFacultyMembers
 * and the executive faculty metrics define it: the employee's home unit
 * (employees.organizational_unit_id) or an open, active employee_unit_assignments row
 * pointing at colleges.organizational_unit_id.
 *
 * Affiliation is NOT a teaching assignment (course_offering_instructors).
 */
final class CollegeAffiliation
{
    /** @return array<int, array{college_id:int, unit_id:int, college_name:string, is_active:bool}> */
    public static function collegeUnits(): array
    {
        return DB::table('colleges')
            ->whereNotNull('organizational_unit_id')
            ->get(['college_id', 'organizational_unit_id', 'college_name', 'is_active'])
            ->mapWithKeys(fn ($row) => [(int) $row->college_id => [
                'college_id' => (int) $row->college_id,
                'unit_id' => (int) $row->organizational_unit_id,
                'college_name' => (string) $row->college_name,
                'is_active' => (bool) $row->is_active,
            ]])
            ->all();
    }

    public static function offeringCollegeId(int $courseOfferingId): ?int
    {
        $row = DB::table('course_offerings as o')
            ->leftJoin('academic_programs as p', 'p.academic_program_id', '=', 'o.academic_program_id')
            ->leftJoin('departments as pd', 'pd.department_id', '=', 'p.department_id')
            ->leftJoin('departments as od', 'od.department_id', '=', 'o.department_id')
            ->where('o.course_offering_id', $courseOfferingId)
            ->selectRaw('COALESCE(pd.college_id, od.college_id) as college_id')
            ->first();

        return $row?->college_id !== null ? (int) $row->college_id : null;
    }

    /** SQL fragment: an active assignment row that has not ended. */
    public static function openAssignment($query, string $alias = ''): void
    {
        $prefix = $alias === '' ? '' : $alias.'.';
        $query->where($prefix.'is_active', 1)
            ->where(fn ($open) => $open->whereNull($prefix.'end_date')->orWhereDate($prefix.'end_date', '>=', now()->toDateString()));
    }

    /** Order faculty_members so that members of the given college unit come first. */
    public static function orderMembersFirst(Builder $query, int $unitId): void
    {
        $today = now()->toDateString();
        $query->orderByRaw(
            'CASE WHEN EXISTS (SELECT 1 FROM employees e WHERE e.employee_id = faculty_members.employee_id AND ('
            .'e.organizational_unit_id = ? OR EXISTS (SELECT 1 FROM employee_unit_assignments a '
            .'WHERE a.employee_id = e.employee_id AND a.organizational_unit_id = ? AND a.is_active = 1 '
            .'AND (a.end_date IS NULL OR a.end_date >= ?)))) THEN 0 ELSE 1 END',
            [$unitId, $unitId, $today]
        );
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  array<int, array{college_id:int, unit_id:int, college_name:string, is_active:bool}>|null  $collegeUnits
     * @return array<int, list<array<string, mixed>>> employee_id => colleges
     */
    public static function collegesForEmployees(array $employeeIds, ?array $collegeUnits = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $collegeUnits ??= self::collegeUnits();
        $byUnit = collect($collegeUnits)->keyBy('unit_id');
        $result = [];

        $homes = DB::table('employees')->whereIn('employee_id', $employeeIds)->whereNotNull('organizational_unit_id')
            ->get(['employee_id', 'organizational_unit_id']);
        foreach ($homes as $home) {
            $college = $byUnit->get((int) $home->organizational_unit_id);
            if ($college !== null) {
                $result[(int) $home->employee_id][$college['college_id']] = [
                    'college_id' => $college['college_id'],
                    'college_name' => $college['college_name'],
                    'source' => 'home_unit',
                    'assignment_id' => null,
                    'start_date' => null,
                ];
            }
        }

        $assignments = DB::table('employee_unit_assignments')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('organizational_unit_id', $byUnit->keys()->all())
            ->where(fn ($q) => self::openAssignment($q))
            ->orderBy('start_date')
            ->get(['assignment_id', 'employee_id', 'organizational_unit_id', 'start_date']);
        foreach ($assignments as $assignment) {
            $college = $byUnit->get((int) $assignment->organizational_unit_id);
            $result[(int) $assignment->employee_id][$college['college_id']] = [
                'college_id' => $college['college_id'],
                'college_name' => $college['college_name'],
                'source' => 'unit_assignment',
                'assignment_id' => (int) $assignment->assignment_id,
                'start_date' => $assignment->start_date,
            ];
        }

        return array_map(fn (array $colleges) => array_values($colleges), $result);
    }
}
