<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentAcademicTerm;
use App\Models\StudentCourseRegistration;
use App\Models\StudentGraduationDecision;
use App\Models\StudentProgressionDecision;
use App\Support\AcademicRecordWorkflow;
use Illuminate\Support\Collection;

class AcademicRecordGraphLocker
{
    public function __construct(private GradeService $grades)
    {
    }

    public function lockStudent(int $studentId): Student
    {
        return Student::query()
            ->whereKey($studentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Canonical Phase 10 lock order shared by term finalization, progression,
     * and graduation. Compatible with GradeService / GradeApprovalWorkflowService
     * (CourseOffering then registrations) and Phase 9 (Student then offering).
     *
     * @return array{0: Student, 1: Collection<int, StudentCourseRegistration>, 2: Collection<int, StudentAcademicTerm>}
     */
    public function lockStudentAcademicGraph(int $studentId): array
    {
        $student = $this->lockStudent($studentId);
        $offeringIds = $this->grades->officialLockOfferingIds($student);

        if ($offeringIds !== []) {
            CourseOffering::query()
                ->whereIn('course_offering_id', $offeringIds)
                ->orderBy('course_offering_id')
                ->lockForUpdate()
                ->get();
        }

        $registrations = StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->orderBy('student_course_registration_id')
            ->lockForUpdate()
            ->get();

        $terms = StudentAcademicTerm::query()
            ->where('student_id', $student->student_id)
            ->orderBy('academic_year_id')
            ->orderBy('semester_id')
            ->orderBy('student_academic_term_id')
            ->lockForUpdate()
            ->get();

        return [$student, $registrations, $terms];
    }

    public function lockCurrentProgression(int $studentId, int $academicYearId): ?StudentProgressionDecision
    {
        return StudentProgressionDecision::query()
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->where('current_slot', AcademicRecordWorkflow::CURRENT_SLOT)
            ->lockForUpdate()
            ->first();
    }

    public function lockProgressionById(int $decisionId): ?StudentProgressionDecision
    {
        return StudentProgressionDecision::query()
            ->whereKey($decisionId)
            ->lockForUpdate()
            ->first();
    }

    public function lockCurrentGraduation(int $studentId): ?StudentGraduationDecision
    {
        return StudentGraduationDecision::query()
            ->where('student_id', $studentId)
            ->where('current_slot', AcademicRecordWorkflow::CURRENT_SLOT)
            ->lockForUpdate()
            ->first();
    }

    public function lockGraduationById(int $decisionId): ?StudentGraduationDecision
    {
        return StudentGraduationDecision::query()
            ->whereKey($decisionId)
            ->lockForUpdate()
            ->first();
    }
}
