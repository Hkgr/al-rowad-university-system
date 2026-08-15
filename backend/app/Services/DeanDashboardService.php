<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\College;
use App\Models\CourseOffering;
use App\Models\FacultyMember;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DeanDashboardService
{
    public function __construct(
        private DataScopeService $dataScope,
        private TeachingAssignmentService $teachingAssignments
    ) {
    }

    public function build(User $user, ?int $academicYearId, ?int $semesterId): array
    {
        $college = $this->singleAccessibleCollege($user);
        $filterOptions = $this->filterOptions();
        $context = $this->resolveContext($filterOptions, $academicYearId, $semesterId);
        $capabilities = $this->capabilities($user);

        if ($college === null) {
            return $this->unresolvedPayload($filterOptions, $context, $capabilities);
        }

        $students = $capabilities['students']
            ? $this->studentMetrics($user)
            : $this->unavailableStudentMetrics();

        $teachingStaff = $capabilities['teaching_staff']
            ? $this->activeTeachingStaffCount($user)
            : null;

        $offerings = $capabilities['courses']
            ? $this->offeringMetrics($user, $context['academic_year']['academic_year_id'] ?? null, $context['semester']['semester_id'] ?? null)
            : $this->unavailableOfferingMetrics();

        $attendance = $capabilities['attendance'] && $capabilities['courses']
            ? $this->attendanceMetrics($offerings['offering_ids_query'])
            : $this->unavailableAttendanceMetrics();

        $grades = $capabilities['grades'] && $capabilities['courses']
            ? $this->gradeMetrics($offerings['offering_ids_query'])
            : $this->unavailableGradeMetrics();

        unset($offerings['offering_ids_query']);

        return [
            'college' => $college,
            'college_resolved' => true,
            'context' => $context,
            'filter_options' => $filterOptions,
            'capabilities' => $capabilities,
            'kpis' => [
                'active_students' => $students['active_students'],
                'active_teaching_staff' => $teachingStaff,
                'course_offerings' => $offerings['course_offerings'],
                'open_registration_offerings' => $offerings['open_registration_offerings'],
                'closed_registration_offerings' => $offerings['closed_registration_offerings'],
                'attendance_sessions' => $attendance['attendance_sessions'],
                'average_final_mark' => $grades['average_final_mark'],
                'graded_students_count' => $grades['graded_students_count'],
                'pass_rate' => $grades['pass_rate'],
                'incomplete_assignments' => $offerings['incomplete_assignments'],
            ],
            'charts' => [
                'students_by_program' => $students['students_by_program'],
                'students_by_level' => $students['students_by_level'],
                'offering_statuses' => $offerings['offering_statuses'],
                'teaching_assignment_status' => $offerings['teaching_assignment_status'],
                'average_results_by_program' => $grades['average_results_by_program'],
                'sessions_by_type' => $attendance['sessions_by_type'],
            ],
            'attention' => $this->attentionItems($offerings, $grades, $capabilities),
            'recent_activity' => $attendance['recent_sessions'],
        ];
    }

    /**
     * Fail closed: a College identity is returned only when the user has exactly
     * one accessible College. Missing, empty, or multiple Colleges stay null.
     */
    private function singleAccessibleCollege(User $user): ?array
    {
        $collegeIds = $this->teachingAssignments->accessibleCollegeIdList($user);
        if (count($collegeIds) !== 1) {
            return null;
        }

        $college = College::query()->find($collegeIds[0]);
        if ($college === null) {
            return null;
        }

        $name = trim((string) $college->college_name);
        if ($name === '') {
            return null;
        }

        return [
            'college_id' => (int) $college->college_id,
            'college_name' => $name,
        ];
    }

    private function capabilities(User $user): array
    {
        return [
            'students' => $user->hasPermission('students.view'),
            'teaching_staff' => $user->hasPermission('teaching_staff.view')
                || $user->hasPermission('teaching_staff.manage'),
            'teaching_staff_manage' => $user->hasPermission('teaching_staff.manage'),
            'courses' => $user->hasPermission('courses.view'),
            'course_offerings_manage' => $user->hasPermission('course_offerings.manage')
                || $user->hasPermission('courses.manage'),
            'attendance' => $user->hasPermission('attendance.view'),
            'grades' => $user->hasPermission('grades.view'),
        ];
    }

    private function filterOptions(): array
    {
        return [
            'academic_years' => AcademicYear::query()
                ->orderByDesc('start_date')
                ->get(['academic_year_id', 'year_name', 'is_current', 'is_active'])
                ->map(fn (AcademicYear $year): array => [
                    'academic_year_id' => (int) $year->academic_year_id,
                    'year_name' => $year->year_name,
                    'is_current' => (bool) $year->is_current,
                    'is_active' => (bool) $year->is_active,
                ])
                ->all(),
            'semesters' => Semester::query()
                ->orderBy('semester_order')
                ->get(['semester_id', 'semester_name', 'semester_order', 'is_active'])
                ->map(fn (Semester $semester): array => [
                    'semester_id' => (int) $semester->semester_id,
                    'semester_name' => $semester->semester_name,
                    'semester_order' => (int) $semester->semester_order,
                    'is_active' => (bool) $semester->is_active,
                ])
                ->all(),
        ];
    }

    private function resolveContext(array $filterOptions, ?int $academicYearId, ?int $semesterId): array
    {
        $years = collect($filterOptions['academic_years']);
        $semesters = collect($filterOptions['semesters']);

        $year = $academicYearId !== null
            ? $years->firstWhere('academic_year_id', $academicYearId)
            : $this->exactlyOne($years->where('is_current', true));

        $semester = $semesterId !== null
            ? $semesters->firstWhere('semester_id', $semesterId)
            : $this->exactlyOne($semesters->where('is_active', true));

        return [
            'academic_year' => $year,
            'semester' => $semester,
        ];
    }

    private function exactlyOne(Collection $items): ?array
    {
        return $items->count() === 1 ? $items->first() : null;
    }

    private function unresolvedPayload(array $filterOptions, array $context, array $capabilities): array
    {
        return [
            'college' => null,
            'college_resolved' => false,
            'context' => $context,
            'filter_options' => $filterOptions,
            'capabilities' => $capabilities,
            'kpis' => [
                'active_students' => null,
                'active_teaching_staff' => null,
                'course_offerings' => null,
                'open_registration_offerings' => null,
                'closed_registration_offerings' => null,
                'attendance_sessions' => null,
                'average_final_mark' => null,
                'graded_students_count' => null,
                'pass_rate' => null,
                'incomplete_assignments' => null,
            ],
            'charts' => [
                'students_by_program' => [],
                'students_by_level' => [],
                'offering_statuses' => [],
                'teaching_assignment_status' => [],
                'average_results_by_program' => [],
                'sessions_by_type' => [],
            ],
            'attention' => [],
            'recent_activity' => [],
        ];
    }

    private function activeStudentsQuery(User $user): Builder
    {
        return $this->dataScope->scopeStudents(Student::query(), $user)
            ->whereHas(
                'studentStatus',
                fn (Builder $status) => $status->where('status_code', 'active')
            );
    }

    private function studentMetrics(User $user): array
    {
        $active = $this->activeStudentsQuery($user);
        $studentIds = (clone $active)->select('students.student_id');

        $byProgram = Student::query()
            ->leftJoin(
                'academic_programs',
                'academic_programs.academic_program_id',
                '=',
                'students.academic_program_id'
            )
            ->whereIn('students.student_id', $studentIds)
            ->selectRaw('academic_programs.academic_program_id as academic_program_id')
            ->selectRaw('academic_programs.program_name as program_name')
            ->selectRaw('COUNT(students.student_id) as students_count')
            ->groupBy('academic_programs.academic_program_id', 'academic_programs.program_name')
            ->orderByDesc('students_count')
            ->get()
            ->map(function ($row): array {
                $name = trim((string) $row->program_name);

                return [
                    'academic_program_id' => $row->academic_program_id === null ? null : (int) $row->academic_program_id,
                    'program_name' => $name !== '' ? $name : 'برنامج غير محدد',
                    'students_count' => (int) $row->students_count,
                ];
            })
            ->all();

        $byLevel = Student::query()
            ->leftJoin(
                'academic_levels',
                'academic_levels.academic_level_id',
                '=',
                'students.current_academic_level_id'
            )
            ->whereIn('students.student_id', $studentIds)
            ->selectRaw('academic_levels.academic_level_id as academic_level_id')
            ->selectRaw('academic_levels.level_name as level_name')
            ->selectRaw('academic_levels.level_order as level_order')
            ->selectRaw('COUNT(students.student_id) as students_count')
            ->groupBy(
                'academic_levels.academic_level_id',
                'academic_levels.level_name',
                'academic_levels.level_order'
            )
            ->orderByRaw('academic_levels.level_order IS NULL')
            ->orderBy('academic_levels.level_order')
            ->get()
            ->map(function ($row): array {
                $name = trim((string) $row->level_name);

                return [
                    'academic_level_id' => $row->academic_level_id === null ? null : (int) $row->academic_level_id,
                    'level_name' => $name !== '' ? $name : 'سنة غير محددة',
                    'level_order' => $row->level_order === null ? null : (int) $row->level_order,
                    'students_count' => (int) $row->students_count,
                ];
            })
            ->all();

        return [
            'active_students' => (clone $active)->count('students.student_id'),
            'students_by_program' => $byProgram,
            'students_by_level' => $byLevel,
        ];
    }

    private function unavailableStudentMetrics(): array
    {
        return [
            'active_students' => null,
            'students_by_program' => [],
            'students_by_level' => [],
        ];
    }

    private function activeTeachingStaffCount(User $user): int
    {
        $query = FacultyMember::query()
            ->where('is_active', true)
            ->whereHas(
                'employee',
                fn (Builder $employee) => $employee->whereHas(
                    'employeeStatus',
                    fn (Builder $status) => $status
                        ->where('status_code', 'active')
                        ->where('is_active', true)
                )
            );

        $this->dataScope->scopeFacultyMembers($query, $user);

        return $query->count('faculty_members.faculty_member_id');
    }

    private function scopedOfferingsQuery(User $user): Builder
    {
        $query = CourseOffering::query();
        $this->dataScope->scopeOfferings($query, $user);

        return $query->whereIn(
            'course_offerings.course_offering_id',
            $this->teachingAssignments->offeringsInAccessibleCollegesQuery(
                $this->teachingAssignments->accessibleCollegeIdList($user)
            )
        );
    }

    private function offeringMetrics(User $user, ?int $academicYearId, ?int $semesterId): array
    {
        $query = $this->scopedOfferingsQuery($user);

        if ($academicYearId !== null) {
            $query->where('course_offerings.academic_year_id', $academicYearId);
        }

        if ($semesterId !== null) {
            $query->where('course_offerings.semester_id', $semesterId);
        }

        $offeringIds = (clone $query)->select('course_offerings.course_offering_id');
        $total = (clone $query)->count('course_offerings.course_offering_id');

        $statusRows = (clone $query)
            ->select('course_offerings.status')
            ->selectRaw('COUNT(course_offerings.course_offering_id) as offerings_count')
            ->groupBy('course_offerings.status')
            ->get();

        $open = 0;
        $closed = 0;
        $offeringStatuses = [];
        foreach ($statusRows as $row) {
            $status = (string) ($row->status ?? '');
            $count = (int) $row->offerings_count;
            if ($status === 'open') {
                $open = $count;
                $label = 'متاحة للتسجيل';
            } elseif ($status === 'closed') {
                $closed = $count;
                $label = 'مغلقة';
            } else {
                $label = $status !== '' ? $status : 'حالات أخرى';
            }

            $offeringStatuses[] = [
                'status' => $status !== '' ? $status : 'other',
                'label' => $label,
                'count' => $count,
            ];
        }

        usort($offeringStatuses, function (array $left, array $right): int {
            $rank = static fn (string $status): int => match ($status) {
                'open' => 0,
                'closed' => 1,
                default => 2,
            };

            return $rank($left['status']) <=> $rank($right['status']);
        });

        $complete = (clone $query);
        $this->applyAssignmentFilter($complete, 'fully_assigned');
        $partial = (clone $query);
        $this->applyAssignmentFilter($partial, 'partially_assigned');
        $unassigned = (clone $query);
        $this->applyAssignmentFilter($unassigned, 'unassigned');

        $completeCount = $complete->count('course_offerings.course_offering_id');
        $partialCount = $partial->count('course_offerings.course_offering_id');
        $unassignedCount = $unassigned->count('course_offerings.course_offering_id');

        $incomplete = (clone $query);
        $incomplete->where(fn (Builder $missing) => $this->hasAnyMissingRequiredComponent($missing));
        $incompleteCount = $incomplete->count('course_offerings.course_offering_id');

        $openUnassigned = (clone $query)
            ->where('course_offerings.status', 'open')
            ->where(fn (Builder $missing) => $this->hasAnyMissingRequiredComponent($missing))
            ->count('course_offerings.course_offering_id');

        return [
            'course_offerings' => $total,
            'open_registration_offerings' => $open,
            'closed_registration_offerings' => $closed,
            'incomplete_assignments' => $incompleteCount,
            'open_incomplete_assignments' => $openUnassigned,
            'offering_statuses' => $offeringStatuses,
            'teaching_assignment_status' => [
                [
                    'code' => 'complete',
                    'label' => 'مكتمل',
                    'count' => $completeCount,
                ],
                [
                    'code' => 'partial',
                    'label' => 'جزئي',
                    'count' => $partialCount,
                ],
                [
                    'code' => 'unassigned',
                    'label' => 'بدون تكليف',
                    'count' => $unassignedCount,
                ],
            ],
            'offering_ids_query' => $offeringIds,
        ];
    }

    private function unavailableOfferingMetrics(): array
    {
        return [
            'course_offerings' => null,
            'open_registration_offerings' => null,
            'closed_registration_offerings' => null,
            'incomplete_assignments' => null,
            'open_incomplete_assignments' => null,
            'offering_statuses' => [],
            'teaching_assignment_status' => [],
            'offering_ids_query' => CourseOffering::query()->whereRaw('1 = 0')->select('course_offerings.course_offering_id'),
        ];
    }

    private function attendanceMetrics(Builder $offeringIds): array
    {
        $base = AttendanceSession::query()
            ->whereIn('attendance_sessions.course_offering_id', $offeringIds);

        $typeRows = (clone $base)
            ->selectRaw('attendance_sessions.session_type as session_type')
            ->selectRaw('COUNT(attendance_sessions.attendance_session_id) as sessions_count')
            ->groupBy('attendance_sessions.session_type')
            ->get();

        $theoretical = 0;
        $practical = 0;
        $other = 0;
        foreach ($typeRows as $row) {
            $type = strtolower(trim((string) $row->session_type));
            $count = (int) $row->sessions_count;
            if (in_array($type, ['theoretical', 'lecture'], true)) {
                $theoretical += $count;
            } elseif ($type === 'practical') {
                $practical += $count;
            } else {
                $other += $count;
            }
        }

        $sessionsByType = [
            [
                'session_type' => 'theoretical',
                'label' => 'نظري',
                'count' => $theoretical,
            ],
            [
                'session_type' => 'practical',
                'label' => 'عملي',
                'count' => $practical,
            ],
        ];

        if ($other > 0) {
            $sessionsByType[] = [
                'session_type' => 'other',
                'label' => 'أخرى',
                'count' => $other,
            ];
        }

        $recent = (clone $base)
            ->join(
                'course_offerings as dean_dash_session_offerings',
                'dean_dash_session_offerings.course_offering_id',
                '=',
                'attendance_sessions.course_offering_id'
            )
            ->leftJoin(
                'courses as dean_dash_session_courses',
                'dean_dash_session_courses.course_id',
                '=',
                'dean_dash_session_offerings.course_id'
            )
            ->orderByDesc('attendance_sessions.session_date')
            ->orderByDesc('attendance_sessions.attendance_session_id')
            ->limit(5)
            ->get([
                'attendance_sessions.attendance_session_id',
                'attendance_sessions.session_date',
                'attendance_sessions.session_type',
                'dean_dash_session_courses.course_code as course_code',
                'dean_dash_session_courses.course_name as course_name',
            ])
            ->map(function ($row): array {
                $type = strtolower(trim((string) $row->session_type));
                $label = match ($type) {
                    'theoretical', 'lecture' => 'نظري',
                    'practical' => 'عملي',
                    default => $row->session_type ?: 'جلسة',
                };

                return [
                    'attendance_session_id' => (int) $row->attendance_session_id,
                    'session_date' => $row->session_date?->toDateString() ?? (string) $row->session_date,
                    'session_type' => $type,
                    'session_type_label' => $label,
                    'course_code' => $row->course_code,
                    'course_name' => $row->course_name,
                ];
            })
            ->all();

        return [
            'attendance_sessions' => $theoretical + $practical + $other,
            'sessions_by_type' => $sessionsByType,
            'recent_sessions' => $recent,
        ];
    }

    private function unavailableAttendanceMetrics(): array
    {
        return [
            'attendance_sessions' => null,
            'sessions_by_type' => [],
            'recent_sessions' => [],
        ];
    }

    private function gradeMetrics(Builder $offeringIds): array
    {
        $base = StudentCourseResult::query()
            ->join(
                'student_course_registrations as dean_dash_scr',
                'dean_dash_scr.student_course_registration_id',
                '=',
                'student_course_results.student_course_registration_id'
            )
            ->join(
                'registration_statuses as dean_dash_rs',
                'dean_dash_rs.registration_status_id',
                '=',
                'dean_dash_scr.registration_status_id'
            )
            ->leftJoin(
                'result_statuses as dean_dash_result_st',
                'dean_dash_result_st.result_status_id',
                '=',
                'student_course_results.result_status_id'
            )
            ->whereIn('dean_dash_scr.course_offering_id', $offeringIds)
            ->whereIn('dean_dash_rs.status_code', StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES)
            ->whereNotNull('student_course_results.final_mark');

        $summary = (clone $base)
            ->selectRaw('AVG(student_course_results.final_mark) as average_final_mark')
            ->selectRaw('COUNT(student_course_results.student_course_result_id) as graded_students_count')
            ->selectRaw("SUM(CASE WHEN dean_dash_result_st.status_code = 'passed' THEN 1 ELSE 0 END) as passed_count")
            ->first();

        $graded = (int) ($summary->graded_students_count ?? 0);
        $average = $graded > 0 ? round((float) $summary->average_final_mark, 2) : null;
        $passRate = $graded > 0
            ? round(((int) ($summary->passed_count ?? 0) / $graded) * 100, 2)
            : null;

        $byProgram = $graded > 0
            ? (clone $base)
                ->leftJoin(
                    'course_offerings as dean_dash_grade_offerings',
                    'dean_dash_grade_offerings.course_offering_id',
                    '=',
                    'dean_dash_scr.course_offering_id'
                )
                ->leftJoin(
                    'academic_programs as dean_dash_grade_programs',
                    'dean_dash_grade_programs.academic_program_id',
                    '=',
                    'dean_dash_grade_offerings.academic_program_id'
                )
                ->groupBy(
                    'dean_dash_grade_programs.academic_program_id',
                    'dean_dash_grade_programs.program_name'
                )
                ->selectRaw('dean_dash_grade_programs.academic_program_id as academic_program_id')
                ->selectRaw('dean_dash_grade_programs.program_name as program_name')
                ->selectRaw('AVG(student_course_results.final_mark) as average_final_mark')
                ->selectRaw('COUNT(student_course_results.student_course_result_id) as graded_students_count')
                ->orderByDesc('average_final_mark')
                ->get()
                ->map(function ($row): array {
                    $name = trim((string) $row->program_name);

                    return [
                        'academic_program_id' => $row->academic_program_id === null ? null : (int) $row->academic_program_id,
                        'program_name' => $name !== '' ? $name : 'برنامج غير محدد',
                        'average_final_mark' => $row->average_final_mark === null ? null : round((float) $row->average_final_mark, 2),
                        'graded_students_count' => (int) $row->graded_students_count,
                    ];
                })
                ->all()
            : [];

        return [
            'average_final_mark' => $average,
            'graded_students_count' => $graded > 0 ? $graded : 0,
            'pass_rate' => $passRate,
            'average_results_by_program' => $byProgram,
        ];
    }

    private function unavailableGradeMetrics(): array
    {
        return [
            'average_final_mark' => null,
            'graded_students_count' => null,
            'pass_rate' => null,
            'average_results_by_program' => [],
        ];
    }

    private function attentionItems(array $offerings, array $grades, array $capabilities): array
    {
        $items = [];

        if ($capabilities['courses'] && ($offerings['incomplete_assignments'] ?? 0) > 0) {
            $count = (int) $offerings['incomplete_assignments'];
            $items[] = [
                'code' => 'incomplete_assignments',
                'count' => $count,
                'label' => $count.' مادة بدون تكليف تدريسي مكتمل',
                'href' => '/dean/courses',
            ];
        }

        if ($capabilities['courses'] && ($offerings['open_registration_offerings'] ?? 0) > 0) {
            $count = (int) $offerings['open_registration_offerings'];
            $items[] = [
                'code' => 'open_registration',
                'count' => $count,
                'label' => $count.' مادة مفتوحة للتسجيل',
                'href' => '/dean/registration-offerings',
            ];
        }

        if ($capabilities['courses'] && ($offerings['open_incomplete_assignments'] ?? 0) > 0) {
            $count = (int) $offerings['open_incomplete_assignments'];
            $items[] = [
                'code' => 'open_incomplete_assignments',
                'count' => $count,
                'label' => $count.' مادة مفتوحة بدون مدرس نظري/عملي مكتمل',
                'href' => '/dean/courses',
            ];
        }

        if (
            $capabilities['grades']
            && $capabilities['courses']
            && ($offerings['course_offerings'] ?? 0) > 0
            && (int) ($grades['graded_students_count'] ?? 0) === 0
        ) {
            $items[] = [
                'code' => 'missing_final_grades',
                'count' => 0,
                'label' => 'لا توجد نتائج نهائية متاحة للفصل المحدد',
                'href' => '/dean/courses',
            ];
        }

        return $items;
    }

    private function applyAssignmentFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'fully_assigned' => $query
                ->where(fn (Builder $theoretical) => $this->theoreticalSlotSatisfied($theoretical))
                ->where(fn (Builder $practical) => $this->practicalSlotSatisfied($practical)),
            'unassigned' => $query
                ->where(fn (Builder $required) => $this->hasAnyRequiredComponent($required))
                ->where(fn (Builder $theoretical) => $this->theoreticalSlotUnassignedOrAbsent($theoretical))
                ->where(fn (Builder $practical) => $this->practicalSlotUnassignedOrAbsent($practical)),
            'partially_assigned' => $query
                ->where(fn (Builder $assigned) => $this->hasAnyAssignedRequiredComponent($assigned))
                ->where(fn (Builder $missing) => $this->hasAnyMissingRequiredComponent($missing)),
            default => null,
        };
    }

    private function theoreticalSlotSatisfied(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksTheoreticalComponent($absent))
            ->orWhere(function (Builder $assigned): void {
                $this->requiresTheoreticalComponent($assigned);
                $this->hasActiveRole($assigned, 'theoretical');
            });
    }

    private function practicalSlotSatisfied(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksPracticalComponent($absent))
            ->orWhere(function (Builder $assigned): void {
                $this->requiresPracticalComponent($assigned);
                $this->hasActiveRole($assigned, 'practical');
            });
    }

    private function theoreticalSlotUnassignedOrAbsent(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksTheoreticalComponent($absent))
            ->orWhere(function (Builder $missing): void {
                $this->requiresTheoreticalComponent($missing);
                $this->missingActiveRole($missing, 'theoretical');
            });
    }

    private function practicalSlotUnassignedOrAbsent(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksPracticalComponent($absent))
            ->orWhere(function (Builder $missing): void {
                $this->requiresPracticalComponent($missing);
                $this->missingActiveRole($missing, 'practical');
            });
    }

    private function hasAnyRequiredComponent(Builder $query): void
    {
        $query
            ->where(fn (Builder $theoretical) => $this->requiresTheoreticalComponent($theoretical))
            ->orWhere(fn (Builder $practical) => $this->requiresPracticalComponent($practical));
    }

    private function hasAnyAssignedRequiredComponent(Builder $query): void
    {
        $query
            ->where(function (Builder $theoretical): void {
                $this->requiresTheoreticalComponent($theoretical);
                $this->hasActiveRole($theoretical, 'theoretical');
            })
            ->orWhere(function (Builder $practical): void {
                $this->requiresPracticalComponent($practical);
                $this->hasActiveRole($practical, 'practical');
            });
    }

    private function hasAnyMissingRequiredComponent(Builder $query): void
    {
        $query
            ->where(function (Builder $theoretical): void {
                $this->requiresTheoreticalComponent($theoretical);
                $this->missingActiveRole($theoretical, 'theoretical');
            })
            ->orWhere(function (Builder $practical): void {
                $this->requiresPracticalComponent($practical);
                $this->missingActiveRole($practical, 'practical');
            });
    }

    private function requiresTheoreticalComponent(Builder $query): void
    {
        $query->whereHas('course', fn (Builder $course) => $course->where('theoretical_hours', '>', 0));
    }

    private function requiresPracticalComponent(Builder $query): void
    {
        $query->whereHas('course', fn (Builder $course) => $course->where('practical_hours', '>', 0));
    }

    private function lacksTheoreticalComponent(Builder $query): void
    {
        $query->whereHas(
            'course',
            fn (Builder $course) => $course->where(function (Builder $hours): void {
                $hours->whereNull('theoretical_hours')->orWhere('theoretical_hours', '<=', 0);
            })
        );
    }

    private function lacksPracticalComponent(Builder $query): void
    {
        $query->whereHas(
            'course',
            fn (Builder $course) => $course->where(function (Builder $hours): void {
                $hours->whereNull('practical_hours')->orWhere('practical_hours', '<=', 0);
            })
        );
    }

    private function hasActiveRole(Builder $query, string $role): void
    {
        $query->whereHas(
            'offeringInstructors',
            fn (Builder $slot) => $slot->where('is_active', true)->where('instructor_role', $role)
        );
    }

    private function missingActiveRole(Builder $query, string $role): void
    {
        $query->whereDoesntHave(
            'offeringInstructors',
            fn (Builder $slot) => $slot->where('is_active', true)->where('instructor_role', $role)
        );
    }
}
