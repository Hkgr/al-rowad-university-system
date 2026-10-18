<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\User;
use App\Support\ExamManualGradeEntryAccess;

final class ExamManualGradeEntryService
{
    public function __construct(private readonly DataScopeService $scope,
        private readonly ExamManualGradeEntryAccess $access, private readonly GradePartWorkflowService $workflow) {}

    public function students(User $actor, array $filters): array
    {
        $this->access->authorize($actor);
        $q = $filters['q'];
        $page = $this->scope->scopeManualGradeStudents(Student::query(), $actor)
            ->where(fn ($query) => $query->where('student_number', 'like', '%'.$q.'%')
                ->orWhere('first_name', 'like', '%'.$q.'%')->orWhere('last_name', 'like', '%'.$q.'%')
                ->orWhere(function ($name) use ($q): void {
                    foreach (preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        $name->where(fn ($term) => $term->where('first_name', 'like', '%'.$word.'%')->orWhere('last_name', 'like', '%'.$word.'%'));
                    }
                }))
            ->with('academicProgram.department.college')->orderBy('student_number')->orderBy('student_id')
            ->paginate($filters['per_page'] ?? 15);
        return ['students' => $page->getCollection()->map(fn ($s) => $this->identity($s))->all(), 'meta' => $this->meta($page)];
    }

    public function registrations(User $actor, Student $student, array $filters): array
    {
        $this->access->authorize($actor, $student);
        $query = StudentCourseRegistration::query()->where('student_id', $student->getKey())
            ->whereHas('courseOffering', fn ($q) => $this->scope->scopeManualGradeOfferings($q, $actor));
        $terms = (clone $query)->join('course_offerings as terms', 'terms.course_offering_id', '=', 'student_course_registrations.course_offering_id')
            ->join('academic_years as years', 'years.academic_year_id', '=', 'terms.academic_year_id')
            ->join('semesters', 'semesters.semester_id', '=', 'terms.semester_id')
            ->select('terms.academic_year_id', 'years.year_name', 'terms.semester_id', 'semesters.semester_name')
            ->distinct()->orderByDesc('terms.academic_year_id')->orderBy('terms.semester_id')->get()->toArray();
        $page = $query->whereHas('courseOffering', function ($q) use ($filters): void {
            foreach (['academic_year_id', 'semester_id'] as $key) if (isset($filters[$key])) $q->where($key, $filters[$key]);
        })->with(['registrationStatus', 'resultStatus', 'studentCourseResult.resultStatus',
            'courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester',
            'courseOffering.academicProgram.department.college', 'courseOffering.gradePartApprovals',
            'courseOffering.gradeComponents', 'courseOffering.gradeApprovals.approvalStatus',
            'studentGradeComponents' => fn ($q) => $q->withMax('gradeAuditLogs', 'grade_audit_log_id')])
            ->orderByDesc('student_course_registration_id')->paginate($filters['per_page'] ?? 15);
        return ['student' => $this->identity($student), 'terms' => $terms,
            'registrations' => $this->workflow->manualSnapshots($page->getCollection())->all(), 'meta' => $this->meta($page)];
    }

    private function identity(Student $student): array
    {
        $student->loadMissing('academicProgram.department.college');
        return ['student_id' => (int) $student->getKey(), 'student_number' => $student->student_number,
            'name' => trim($student->first_name.' '.$student->last_name), 'program' => $student->academicProgram?->program_name,
            'college' => $student->academicProgram?->department?->college?->college_name];
    }

    private function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
