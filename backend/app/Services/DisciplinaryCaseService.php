<?php

namespace App\Services;

use App\Exceptions\DisciplinaryCaseException;
use App\Models\AppealStatus;
use App\Models\CourseOffering;
use App\Models\DisciplinaryCaseAffectedCourse;
use App\Models\DisciplinaryCaseAppeal;
use App\Models\DisciplinaryPenaltyType;
use App\Models\GradeComponent;
use App\Models\ResultStatus;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentDisciplinaryCase;
use Illuminate\Support\Facades\DB;

class DisciplinaryCaseService
{
    public function __construct(private readonly GradeService $grades) {}

    public function createCase(array $data, ?int $userId): StudentDisciplinaryCase
    {
        return DB::transaction(function () use ($data, $userId): StudentDisciplinaryCase {
            $penaltyType = DisciplinaryPenaltyType::query()->findOrFail($data['penalty_type_id']);

            $attributes = [
                'student_id' => $data['student_id'],
                'violation_type_id' => $data['violation_type_id'],
                'trigger_course_offering_id' => $data['trigger_course_offering_id'] ?? null,
                'violation_description' => $data['violation_description'],
                'violation_date' => $data['violation_date'],
                'reported_by_user_id' => $userId,
                'decided_by_authority' => $data['decided_by_authority'],
                'decided_by_user_id' => $userId,
                'decision_number' => $data['decision_number'] ?? null,
                'decision_date' => $data['decision_date'],
                'penalty_type_id' => $data['penalty_type_id'],
                'penalty_start_date' => $data['penalty_start_date'] ?? null,
                'penalty_end_date' => $data['penalty_end_date'] ?? null,
                'is_in_absentia' => (bool) ($data['is_in_absentia'] ?? false),
                'case_status' => 'active',
            ];

            if ($penaltyType->requires_investigation) {
                $attributes['investigation_status'] = 'pending';
            }

            $case = StudentDisciplinaryCase::query()->create($attributes);

            if ($penaltyType->cascades_to_subsequent_courses) {
                $this->applyZeroAndSubsequent($case);
            }

            return $case->fresh([
                'student',
                'violationType',
                'penaltyType',
                'triggerCourseOffering',
                'affectedCourses',
            ]);
        });
    }

    public function applyZeroAndSubsequent(StudentDisciplinaryCase $case): void
    {
        if ($case->trigger_course_offering_id === null) {
            throw new DisciplinaryCaseException(
                'Penalty type zero_and_subsequent requires trigger_course_offering_id to be set.'
            );
        }

        $triggerOffering = CourseOffering::query()->findOrFail($case->trigger_course_offering_id);

        $triggerExamDate = GradeComponent::query()
            ->where('course_offering_id', $triggerOffering->course_offering_id)
            ->where('component_type', 'theoretical')
            ->whereNotNull('exam_date')
            ->orderByDesc('exam_date')
            ->value('exam_date');

        if ($triggerExamDate === null) {
            throw new DisciplinaryCaseException(
                'No exam_date is configured on theoretical grade components for the trigger course offering.'
            );
        }

        $failedStatusId = ResultStatus::query()
            ->where('status_code', 'failed')
            ->value('result_status_id');

        if ($failedStatusId === null) {
            throw new DisciplinaryCaseException('Result status "failed" was not found in result_statuses.');
        }

        $registrations = StudentCourseRegistration::query()
            ->where('student_id', $case->student_id)
            ->whereHas('courseOffering', function ($query) use ($triggerOffering, $triggerExamDate): void {
                $query->where('academic_year_id', $triggerOffering->academic_year_id)
                    ->where('semester_id', $triggerOffering->semester_id)
                    ->whereHas('gradeComponents', function ($components) use ($triggerExamDate): void {
                        $components->where('component_type', 'theoretical')
                            ->whereNotNull('exam_date')
                            ->whereDate('exam_date', '>=', $triggerExamDate);
                    });
            })
            ->with(['studentCourseResult', 'courseOffering'])
            ->orderBy('student_course_registration_id')
            ->lockForUpdate()
            ->get();

        foreach ($registrations as $registration) {
            $this->grades->assertNotSupplementaryMaterialized(
                (int) $registration->student_course_registration_id
            );
            $result = $registration->studentCourseResult;

            DisciplinaryCaseAffectedCourse::query()->create([
                'case_id' => $case->case_id,
                'course_offering_id' => $registration->course_offering_id,
                'previous_theoretical_mark' => $result?->theoretical_total,
                'previous_practical_mark' => $result?->practical_total,
                'previous_coursework_mark' => $result?->coursework_total,
                'previous_final_mark' => $result?->final_mark,
                'previous_result_status_id' => $result?->result_status_id,
                'applied_at' => now(),
            ]);

            if ($result === null) {
                StudentCourseResult::query()->create([
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'theoretical_total' => 0,
                    'practical_total' => 0,
                    'coursework_total' => 0,
                    'final_mark' => 0,
                    'result_status_id' => $failedStatusId,
                ]);
            } else {
                $result->update([
                    'theoretical_total' => 0,
                    'practical_total' => 0,
                    'coursework_total' => 0,
                    'final_mark' => 0,
                    'result_status_id' => $failedStatusId,
                ]);
            }
        }
    }

    public function revertCase(StudentDisciplinaryCase $case, ?int $userId): void
    {
        DB::transaction(function () use ($case): void {
            $affectedCourses = DisciplinaryCaseAffectedCourse::query()
                ->where('case_id', $case->case_id)
                ->whereNull('reverted_at')
                ->orderBy('course_offering_id')
                ->lockForUpdate()
                ->get();

            $registrations = StudentCourseRegistration::query()
                ->where('student_id', $case->student_id)
                ->whereIn('course_offering_id', $affectedCourses->pluck('course_offering_id'))
                ->orderBy('student_course_registration_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('course_offering_id');
            $results = StudentCourseResult::query()
                ->whereIn('student_course_registration_id', $registrations->modelKeys())
                ->orderBy('student_course_result_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('student_course_registration_id');

            foreach ($affectedCourses as $affected) {
                $registration = $registrations->get((int) $affected->course_offering_id);

                if ($registration !== null) {
                    $this->grades->assertNotSupplementaryMaterialized(
                        (int) $registration->student_course_registration_id
                    );
                    $result = $results->get((int) $registration->student_course_registration_id);

                    $hadPreviousResult = $affected->previous_result_status_id !== null
                        || $affected->previous_theoretical_mark !== null
                        || $affected->previous_practical_mark !== null
                        || $affected->previous_coursework_mark !== null
                        || $affected->previous_final_mark !== null;

                    if ($hadPreviousResult) {
                        if ($result !== null) {
                            $result->update([
                                'theoretical_total' => $affected->previous_theoretical_mark,
                                'practical_total' => $affected->previous_practical_mark,
                                'coursework_total' => $affected->previous_coursework_mark,
                                'final_mark' => $affected->previous_final_mark,
                                'result_status_id' => $affected->previous_result_status_id,
                            ]);
                        }
                    } elseif ($result !== null) {
                        $result->delete();
                    }
                }

                $affected->update(['reverted_at' => now()]);
            }

            $case->update(['case_status' => 'overturned']);
        }, 3);
    }

    public function submitAppeal(int $caseId, array $data): DisciplinaryCaseAppeal
    {
        return DB::transaction(function () use ($caseId, $data): DisciplinaryCaseAppeal {
            $case = StudentDisciplinaryCase::query()->findOrFail($caseId);

            $submittedStatusId = AppealStatus::query()
                ->where('status_code', 'submitted')
                ->value('appeal_status_id');

            if ($submittedStatusId === null) {
                throw new DisciplinaryCaseException('Appeal status "submitted" was not found in appeal_statuses.');
            }

            $appeal = DisciplinaryCaseAppeal::query()->create([
                'case_id' => $case->case_id,
                'submitted_at' => now(),
                'appeal_reason' => $data['appeal_reason'],
                'appeal_status_id' => $submittedStatusId,
            ]);

            $case->update(['case_status' => 'appealed']);

            return $appeal->fresh(['appealStatus', 'disciplinaryCase']);
        });
    }

    public function decideAppeal(int $appealId, string $decisionStatusCode, ?string $notes, ?int $userId): DisciplinaryCaseAppeal
    {
        return DB::transaction(function () use ($appealId, $decisionStatusCode, $notes, $userId): DisciplinaryCaseAppeal {
            $appeal = DisciplinaryCaseAppeal::query()->with('disciplinaryCase')->findOrFail($appealId);

            $statusId = AppealStatus::query()
                ->where('status_code', $decisionStatusCode)
                ->value('appeal_status_id');

            if ($statusId === null) {
                throw new DisciplinaryCaseException(
                    'Appeal status "'.$decisionStatusCode.'" was not found in appeal_statuses.'
                );
            }

            $appeal->update([
                'appeal_status_id' => $statusId,
                'decision_notes' => $notes,
                'decision_date' => now()->toDateString(),
                'reviewed_by_user_id' => $userId,
            ]);

            $case = $appeal->disciplinaryCase;

            if ($decisionStatusCode === 'accepted') {
                $this->revertCase($case, $userId);
            } elseif ($decisionStatusCode === 'rejected') {
                $case->update(['case_status' => 'active']);
            }

            return $appeal->fresh(['appealStatus', 'disciplinaryCase', 'reviewedBy']);
        });
    }
}
