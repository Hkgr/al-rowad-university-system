<?php

namespace App\Services;

use App\Models\StudentCourseRegistration;

/** Additive, authorized read projection only. Never serializes a student/account model. */
final class IncompleteResultReadProjection
{
    public const RELATIONS = ['student', 'registrationStatus', 'courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester'];

    public function identity(StudentCourseRegistration $registration): array
    {
        $registration->loadMissing(self::RELATIONS);
        $offering = $registration->courseOffering;
        return [
            'student' => ['id' => (int) $registration->student_id, 'number' => $registration->student?->student_number,
                'full_name' => trim(implode(' ', array_filter([$registration->student?->first_name, $registration->student?->last_name])))],
            'course' => ['id' => (int) $offering->course_id, 'code' => $offering->course?->course_code, 'name' => $offering->course?->course_name],
            'academic_year' => ['id' => (int) $offering->academic_year_id, 'name' => $offering->academicYear?->year_name],
            'semester' => ['id' => (int) $offering->semester_id, 'name' => $offering->semester?->semester_name],
            'offering' => ['id' => (int) $offering->getKey(), 'status' => $offering->status],
            'registration_status' => $registration->registrationStatus?->status_code,
        ];
    }

    public function detail(StudentCourseRegistration $registration, array $view): array
    {
        $registration->loadMissing(['courseOffering.gradeComponents', 'studentGradeComponents', 'studentCourseResult.resultStatus']);
        $marks = $registration->studentGradeComponents->keyBy('grade_component_id');
        $required = $registration->courseOffering->gradeComponents->where('is_required', true)
            ->whereIn('component_type', ['theoretical', 'practical'])->sortBy('grade_component_id')->values();
        $configured = $required->isNotEmpty();
        if ($configured) {
            try {
                app(GradeService::class)->assertRequiredPartsPolicyCompatible(
                    $required->contains('component_type', 'theoretical'), $required->contains('component_type', 'practical'),
                    (float) $required->where('component_type', 'theoretical')->sum('max_mark'),
                    (float) $required->where('component_type', 'practical')->sum('max_mark'));
            } catch (\App\Exceptions\GradeException $e) { $configured = false; }
        }
        $resolve = $view['allowed_operations']['resolve'] && $configured;
        $components = $required->map(function ($component) use ($marks, $resolve, $view, $configured): array {
            $mark = $marks->get($component->getKey());
            return ['grade_component_id' => (int) $component->getKey(), 'name' => $component->component_name,
                'type' => $component->component_type, 'max_mark' => $component->max_mark, 'mark' => $mark?->mark,
                'editable' => $resolve && $mark?->mark === null,
                'blocking_code' => $mark?->mark !== null ? 'completed_component_locked' : (!$configured ? 'grade_components_not_configured' : ($resolve ? null : $view['blocking_code']))];
        })->all();
        $result = $registration->studentCourseResult;
        $current = $result ? app(GradeService::class)->getRegistrationGrades((int) $registration->getKey()) : null;
        return $view + $this->identity($registration) + [
            'components' => $components,
            'resolution' => ['available' => $resolve, 'blocking_code' => $configured ? $view['blocking_code'] : 'grade_components_not_configured'],
            'current_result' => $result ? ['id' => (int) $result->getKey(), 'status' => $result->resultStatus?->status_code,
                'theoretical_total' => $result->theoretical_total, 'practical_total' => $result->practical_total,
                'final_mark' => $current['final_mark'] ?? null, 'letter_grade' => $current['letter_grade'] ?? null,
                'grade_points' => $current['grade_points'] ?? null, 'is_official' => $view['is_official'],
                'result_announced_at' => $result->result_announced_at] : null,
        ];
    }
}
