<?php

namespace App\Support;

final class ExecutiveReportRegistry
{
    public const LIMITS = ['ids_per_filter' => 50, 'selected_ids' => 200, 'metrics' => 12, 'dimensions' => 3, 'per_page' => 100, 'points' => 500];
    public const MODES = ['summary', 'details', 'comparison', 'trend'];
    public const COMPARISONS = ['previous_period', 'previous_semester', 'previous_academic_year', 'selected_scopes', 'custom'];

    private const SUBJECTS = [
        'students' => [
            'label' => 'الطلاب',
            'metrics' => ['student_count', 'active_student_count', 'inactive_student_count', 'new_student_count', 'official_result_count', 'official_average', 'official_gpa', 'attempted_credit_hours', 'earned_credit_hours'],
            'dimensions' => ['college', 'department', 'program', 'academic_level', 'student_status', 'academic_year', 'semester'],
            'filters' => ['college_ids', 'department_ids', 'program_ids', 'academic_year_ids', 'semester_ids', 'academic_level_ids', 'student_status_codes'],
        ],
        'enrollments' => [
            'label' => 'الالتحاق الجامعي', 'metrics' => ['enrollment_count'],
            'dimensions' => ['college', 'department', 'program', 'student_status', 'academic_year', 'day', 'week', 'month'],
            'filters' => ['college_ids', 'department_ids', 'program_ids', 'academic_year_ids', 'student_status_codes'],
        ],
        'faculty' => [
            'label' => 'الهيئة التدريسية',
            'metrics' => ['faculty_count', 'active_faculty_count', 'assigned_sections_count', 'offerings_count', 'registered_students_count', 'students_per_assigned_faculty', 'draft_parts_count', 'submitted_parts_count', 'returned_parts_count', 'approved_parts_count', 'completion_rate'],
            'dimensions' => ['college', 'faculty_member', 'academic_year', 'semester', 'course'],
            'filters' => ['college_ids', 'academic_year_ids', 'semester_ids', 'course_ids', 'offering_ids'],
            'groups_may_overlap' => true,
        ],
        'course_offerings' => [
            'label' => 'الطروحات الأكاديمية',
            'metrics' => ['course_offering_count', 'open_offering_count', 'closed_offering_count', 'registered_student_count'],
            'dimensions' => ['college', 'department', 'program', 'academic_year', 'semester', 'course', 'offering_status', 'day', 'week', 'month'],
            'filters' => ['college_ids', 'department_ids', 'program_ids', 'academic_year_ids', 'semester_ids', 'course_ids', 'offering_ids', 'offering_statuses'],
        ],
        'academic_performance' => [
            'label' => 'الأداء الأكاديمي',
            'metrics' => ['official_result_count', 'passed_count', 'failed_count', 'deprived_count', 'incomplete_count', 'official_average', 'official_gpa', 'attempted_credit_hours', 'earned_credit_hours', 'pass_rate', 'failure_rate'],
            'dimensions' => ['college', 'department', 'program', 'academic_year', 'semester', 'academic_level', 'result_status', 'course'],
            'filters' => ['college_ids', 'department_ids', 'program_ids', 'academic_year_ids', 'semester_ids', 'academic_level_ids', 'course_ids', 'offering_ids', 'result_status_codes'],
        ],
        'grade_workflow' => [
            'label' => 'سير عمل العلامات',
            'metrics' => ['required_parts_count', 'draft_parts_count', 'submitted_parts_count', 'returned_parts_count', 'approved_parts_count', 'completed_offerings_count', 'pending_offerings_count', 'completion_rate'],
            'dimensions' => ['college', 'department', 'program', 'academic_year', 'semester', 'course', 'grade_workflow_status', 'day', 'week', 'month'],
            'filters' => ['college_ids', 'department_ids', 'program_ids', 'academic_year_ids', 'semester_ids', 'course_ids', 'offering_ids', 'grade_workflow_statuses'],
        ],
    ];

    public static function all(): array { return self::SUBJECTS; }
    public static function definitions(): array
    {
        return collect(self::SUBJECTS)->map(function (array $definition, string $subject): array {
            $definition['modes'] = self::MODES;
            $definition['sortable'] = array_values(array_diff(array_merge($definition['dimensions'], $definition['metrics']), ['official_gpa']));
            $definition['period_capabilities'] = [
                'current_snapshot' => in_array($subject, ['students','faculty','course_offerings','grade_workflow'], true),
                'academic' => true,
                'date_range' => in_array($subject, ['enrollments','course_offerings','grade_workflow'], true),
            ];
            $definition['history_availability'] = match ($subject) {
                'academic_performance' => ['as_of_date' => false, 'reason' => 'historical_data_unavailable'],
                'faculty' => ['membership' => 'current_snapshot', 'workload' => 'current_snapshot'],
                default => ['available' => true],
            };
            return $definition;
        })->all();
    }
    public static function subject(string $subject): ?array { return self::SUBJECTS[$subject] ?? null; }
    public static function subjects(): array { return array_keys(self::SUBJECTS); }
}
