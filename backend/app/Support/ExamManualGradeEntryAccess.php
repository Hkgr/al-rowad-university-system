<?php

namespace App\Support;

use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Support\Facades\Gate;

final class ExamManualGradeEntryAccess
{
    public const RECORDING_EXEMPTIONS = ['student_request_advisor_approval', 'current_academic_year',
        'student_registration_window', 'open_enrollment_offering', 'weekly_timetable_completeness_and_conflicts'];

    public function __construct(private readonly DataScopeService $scope) {}

    public function authorizeOffering(User $actor, Student $student, CourseOffering $offering): void
    {
        $this->authorize($actor, $student);
        abort_unless($this->scope->scopeManualGradeOfferings(CourseOffering::query(), $actor)
            ->whereKey($offering->getKey())->exists(), 403);
    }

    public function authorize(User $actor, ?Student $student = null, ?StudentCourseRegistration $registration = null): void
    {
        $actor = $actor->fresh();
        abort_if($actor === null, 403);
        abort_unless($actor->accountStatus?->status_code === 'active'
            && $actor->effectiveRoles()->contains('exam_officer')
            && collect(['exams.manage', 'grades.manage'])->diff($actor->effectivePermissions())->isEmpty(), 403);
        Gate::forUser($actor)->authorize('viewAny', Student::class);
        if ($student !== null) {
            $student = $student->fresh();
            abort_if($student === null || $student->trashed(), 404);
            Gate::forUser($actor)->authorize('view', $student);
            abort_unless($this->scope->scopeManualGradeStudents(Student::query(), $actor)->whereKey($student->getKey())->exists(), 403);
        }
        if ($registration !== null) {
            abort_unless($student !== null && (int) $registration->student_id === (int) $student->getKey(), 404);
            abort_unless($this->scope->scopeManualGradeOfferings(CourseOffering::query(), $actor)
                ->whereKey($registration->course_offering_id)->exists(), 403);
        }
    }
}
