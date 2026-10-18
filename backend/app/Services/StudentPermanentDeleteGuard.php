<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent student deletion is allowed only for an unused archived shell
 * with no academic or workflow history evidence.
 */
class StudentPermanentDeleteGuard
{
    public const ERROR_CODE = 'student_permanent_delete_blocked';

    public const REQUIRES_ARCHIVE = 'student_permanent_delete_requires_archive';

    /**
     * Safe category names. Never return SQL constraint names.
     *
     * @return list<string>
     */
    public function blockingCategories(Student $student): array
    {
        $related = [];

        if ($student->studentCourseRegistrations()->exists()) {
            $related[] = 'registrations';
        }

        if ($student->studentAttendances()->exists()) {
            $related[] = 'attendance';
        }

        if ($student->studentDocuments()->exists()) {
            $related[] = 'documents';
        }

        if ($student->studentAcademicTerms()->exists()) {
            $related[] = 'academic_terms';
        }

        $registrationIds = $student->studentCourseRegistrations()->pluck('student_course_registration_id');

        if ($registrationIds->isNotEmpty()) {
            if (Schema::hasTable('student_course_results')
                && DB::table('student_course_results')->whereIn('student_course_registration_id', $registrationIds)->exists()) {
                $related[] = 'course_results';
            }

            if (Schema::hasTable('student_grade_components')
                && DB::table('student_grade_components')->whereIn('student_course_registration_id', $registrationIds)->exists()) {
                $related[] = 'grade_components';
            }
        }

        if ($this->tableHasStudent('student_registration_requests', $student)) {
            $related[] = 'registration_requests';
        }

        if ($this->tableHasStudent('student_registration_withdrawal_requests', $student)) {
            $related[] = 'withdrawal_requests';
        }

        if ($this->tableHasStudent('student_progression_decisions', $student)) {
            $related[] = 'progression_decisions';
        }

        if ($this->tableHasStudent('student_graduation_decisions', $student)) {
            $related[] = 'graduation_decisions';
        }

        if ($this->tableHasStudent('student_disciplinary_cases', $student)) {
            $related[] = 'disciplinary_cases';
        }

        if ($this->tableHasStudent('grade_appeals', $student)) {
            $related[] = 'grade_appeals';
        }

        return array_values(array_unique($related));
    }

    private function tableHasStudent(string $table, Student $student): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return DB::table($table)->where('student_id', $student->student_id)->exists();
    }

    /**
     * Recognized MariaDB parent-row FK restriction only (errno 1451).
     * Does not convert arbitrary QueryException or SQLSTATE 23000.
     */
    public function isRestrictedForeignKey(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1451;
    }
}
