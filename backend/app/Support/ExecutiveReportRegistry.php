<?php

namespace App\Support;

final class ExecutiveReportRegistry
{
    public const VERSION = 'executive-reports.v1';
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

    private const MODES_BY_SUBJECT = [
        'students' => ['summary','details','comparison'],
        'enrollments' => ['summary','details','comparison','trend'],
        'faculty' => ['summary','details','comparison','trend'],
        'course_offerings' => ['summary','details','comparison','trend'],
        'academic_performance' => ['summary','details','comparison'],
        'grade_workflow' => ['summary','details','comparison','trend'],
    ];

    private const PERIODS_BY_SUBJECT = [
        'students' => ['none','academic'],
        'enrollments' => ['none','academic','date_range'],
        'faculty' => ['none','academic','date_range'],
        'course_offerings' => ['none','academic','date_range'],
        'academic_performance' => ['none','academic'],
        'grade_workflow' => ['none','academic','date_range'],
    ];

    private const HISTORICAL_METRICS = [
        'enrollments' => ['enrollment_count'],
        'faculty' => ['assigned_sections_count','offerings_count'],
        'course_offerings' => ['course_offering_count'],
        'grade_workflow' => ['submitted_parts_count','returned_parts_count','approved_parts_count'],
    ];

    private const METRIC_META = [
        'student_count'=>['label'=>'عدد الطلاب','unit'=>'students'], 'active_student_count'=>['label'=>'الطلاب النشطون','unit'=>'students'], 'inactive_student_count'=>['label'=>'الطلاب غير النشطين','unit'=>'students'], 'new_student_count'=>['label'=>'الطلاب الملتحقون خلال الفترة','unit'=>'students'],
        'enrollment_count'=>['label'=>'عدد حالات الالتحاق','unit'=>'students'], 'course_offering_count'=>['label'=>'عدد الطروحات','unit'=>'offerings'], 'open_offering_count'=>['label'=>'الطروحات المفتوحة حاليًا','unit'=>'offerings'], 'closed_offering_count'=>['label'=>'الطروحات المغلقة حاليًا','unit'=>'offerings'], 'registered_student_count'=>['label'=>'الطلاب المسجلون حاليًا','unit'=>'students'],
        'faculty_count'=>['label'=>'أعضاء الهيئة التدريسية','unit'=>'faculty'], 'active_faculty_count'=>['label'=>'أعضاء الهيئة النشطون','unit'=>'faculty'], 'assigned_sections_count'=>['label'=>'التكليفات التدريسية','unit'=>'assignments'], 'offerings_count'=>['label'=>'الطروحات المكلف بها','unit'=>'offerings'], 'registered_students_count'=>['label'=>'الطلاب في الطروحات المكلف بها','unit'=>'students'], 'students_per_assigned_faculty'=>['label'=>'الطلاب لكل عضو هيئة مكلف','unit'=>'ratio','rate'=>true],
        'official_result_count'=>['label'=>'النتائج الرسمية','unit'=>'results'], 'passed_count'=>['label'=>'النتائج الناجحة','unit'=>'results'], 'failed_count'=>['label'=>'النتائج الراسبة','unit'=>'results'], 'deprived_count'=>['label'=>'نتائج الحرمان','unit'=>'results'], 'incomplete_count'=>['label'=>'النتائج غير المكتملة','unit'=>'results'], 'official_average'=>['label'=>'متوسط العلامة الرسمية','unit'=>'mark'], 'official_gpa'=>['label'=>'متوسط المعدل الرسمي للطلاب','unit'=>'gpa'], 'attempted_credit_hours'=>['label'=>'الساعات المحاولة','unit'=>'credit_hours'], 'earned_credit_hours'=>['label'=>'الساعات المجتازة','unit'=>'credit_hours'], 'pass_rate'=>['label'=>'نسبة النجاح','unit'=>'percent','rate'=>true], 'failure_rate'=>['label'=>'نسبة الرسوب','unit'=>'percent','rate'=>true],
        'required_parts_count'=>['label'=>'أجزاء العلامة المطلوبة','unit'=>'parts'], 'draft_parts_count'=>['label'=>'الأجزاء المسودة','unit'=>'parts'], 'submitted_parts_count'=>['label'=>'الأجزاء المرسلة','unit'=>'parts'], 'returned_parts_count'=>['label'=>'الأجزاء المعادة','unit'=>'parts'], 'approved_parts_count'=>['label'=>'الأجزاء المعتمدة','unit'=>'parts'], 'completed_offerings_count'=>['label'=>'الطروحات المكتملة رسميًا','unit'=>'offerings'], 'pending_offerings_count'=>['label'=>'الطروحات غير المكتملة','unit'=>'offerings'], 'completion_rate'=>['label'=>'نسبة اكتمال الأجزاء','unit'=>'percent','rate'=>true],
    ];

    private const DIMENSION_LABELS = ['college'=>'الكلية','department'=>'القسم','program'=>'البرنامج','academic_level'=>'المستوى','student_status'=>'حالة الطالب','academic_year'=>'السنة الأكاديمية','semester'=>'الفصل','course'=>'المقرر','offering_status'=>'حالة الطرح','result_status'=>'حالة النتيجة','faculty_member'=>'عضو الهيئة','grade_workflow_status'=>'حالة سير العلامات','day'=>'اليوم','week'=>'الأسبوع','month'=>'الشهر'];

    private const DETAIL_SORTABLE = [
        'students'=>['student_count','active_student_count','inactive_student_count','new_student_count','official_result_count','official_average','attempted_credit_hours','earned_credit_hours'],
        'enrollments'=>['enrollment_count','college','department','program','student_status','academic_year'],
        'faculty'=>['faculty_count','active_faculty_count','faculty_member','college','academic_year','semester','course'],
        'course_offerings'=>['course_offering_count','open_offering_count','closed_offering_count','college','department','program','academic_year','semester','course','offering_status'],
        'academic_performance'=>['official_result_count','passed_count','failed_count','deprived_count','incomplete_count','official_average','attempted_credit_hours','earned_credit_hours','college','department','program','academic_year','semester','academic_level','result_status','course'],
        'grade_workflow'=>['required_parts_count','draft_parts_count','submitted_parts_count','returned_parts_count','approved_parts_count','college','department','program','academic_year','semester','grade_workflow_status','course','day','week','month'],
    ];

    public static function all(): array { return self::SUBJECTS; }
    public static function definitions(): array
    {
        return collect(self::SUBJECTS)->map(function (array $definition, string $subject): array {
            $definition['metrics'] = collect($definition['metrics'])->map(function($code){$metric=['code'=>$code]+(self::METRIC_META[$code]??['label'=>$code,'unit'=>'count']);if($metric['rate']??false)$metric+=['value_shape'=>['numerator','denominator','value'],'zero_denominator'=>['value'=>null,'reason'=>'zero_denominator']];return$metric;})->values()->all();
            $definition['dimensions'] = collect($definition['dimensions'])->map(fn($code)=>['code'=>$code,'label'=>self::DIMENSION_LABELS[$code]??$code])->values()->all();
            $definition['modes'] = self::MODES_BY_SUBJECT[$subject];
            $definition['sortable'] = self::sortable($subject);
            $definition['detail_sortable'] = self::detailSortable($subject);
            $definition['period_capabilities'] = [
                'supported' => self::PERIODS_BY_SUBJECT[$subject],
                'date_range_metrics' => self::HISTORICAL_METRICS[$subject] ?? [],
            ];
            $definition['history_availability'] = match ($subject) {
                'academic_performance' => ['marks_as_of_date' => false, 'reason' => 'historical_data_unavailable'],
                'students' => ['population'=>'current_snapshot','academic_membership'=>'selected_period_registrations'],
                'faculty' => ['membership'=>'current_snapshot','effective_workload'=>'current_snapshot','assignment_events'=>'append_only'],
                'course_offerings' => ['status_capacity'=>'current_snapshot','creation'=>'persisted_timestamp'],
                'grade_workflow' => ['current_parts'=>'current_snapshot','part_events'=>'append_only'],
                default => ['enrollment'=>'persisted_timestamp'],
            };
            return $definition;
        })->all();
    }
    public static function subject(string $subject): ?array { return self::SUBJECTS[$subject] ?? null; }
    public static function subjects(): array { return array_keys(self::SUBJECTS); }
    public static function modes(string $subject): array { return self::MODES_BY_SUBJECT[$subject] ?? []; }
    public static function periods(string $subject): array { return self::PERIODS_BY_SUBJECT[$subject] ?? []; }
    public static function historicalMetrics(string $subject): array { return self::HISTORICAL_METRICS[$subject] ?? []; }
    public static function metric(string $code): array { return ['code'=>$code]+(self::METRIC_META[$code]??['label'=>$code,'unit'=>'count']); }
    public static function sortable(string $subject): array { $definition=self::SUBJECTS[$subject]??['dimensions'=>[],'metrics'=>[]];return array_values(array_diff(array_merge($definition['dimensions'],$definition['metrics']),['official_gpa'])); }
    public static function detailSortable(string $subject): array { return self::DETAIL_SORTABLE[$subject]??[]; }
}
