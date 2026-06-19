<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\AttendanceSession;
use App\Models\AttendanceStatus;
use App\Models\CourseOffering;
use App\Models\ResultStatus;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public const DEPRIVATION_THRESHOLD = 15;

    private const ACTIVE_REGISTRATION_STATUS = 'registered';

    private const EXCLUDED_REGISTRATION_STATUSES = ['dropped', 'withdrawn'];

    public function getCourseOfferingSessions(int $courseOfferingId): array
    {
        $offering = CourseOffering::query()->findOrFail($courseOfferingId);
        $activeStudentCount = $this->activeRegistrationsQuery($courseOfferingId)->count();

        $sessions = AttendanceSession::query()
            ->where('course_offering_id', $courseOfferingId)
            ->with(['studentAttendances.attendanceStatus'])
            ->orderBy('session_date')
            ->orderBy('attendance_session_id')
            ->get();

        return [
            'course_offering_id' => $offering->course_offering_id,
            'sessions' => $sessions->map(fn (AttendanceSession $session) => $this->formatSessionSummary($session, $activeStudentCount))->values()->all(),
        ];
    }

    public function createCourseOfferingSession(int $courseOfferingId, array $data, int $createdByUserId): array
    {
        $offering = CourseOffering::query()->findOrFail($courseOfferingId);

        $sessionType = $data['session_type'] ?? 'theoretical';
        if ($sessionType === 'lecture') {
            $sessionType = 'theoretical';
        }

        try {
            $session = AttendanceSession::query()->create([
                'course_offering_id' => $offering->course_offering_id,
                'session_type' => $sessionType,
                'session_date' => $data['session_date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'faculty_member_id' => $data['faculty_member_id'] ?? $offering->faculty_member_id,
                'created_by_user_id' => $createdByUserId,
                'notes' => $data['topic'] ?? $data['notes'] ?? null,
            ]);
        } catch (QueryException $exception) {
            throw new AttendanceException('Unable to create attendance session. Please verify session data and try again.');
        }

        $activeStudentCount = $this->activeRegistrationsQuery($courseOfferingId)->count();

        return $this->formatSessionSummary($session->fresh()->load('studentAttendances.attendanceStatus'), $activeStudentCount);
    }

    public function getSessionStudents(int $sessionId): array
    {
        $session = AttendanceSession::query()
            ->with(['courseOffering', 'studentAttendances.attendanceStatus'])
            ->findOrFail($sessionId);

        $attendanceByStudentId = $session->studentAttendances->keyBy('student_id');

        $registrations = $this->activeRegistrationsQuery($session->course_offering_id)
            ->with('student')
            ->orderBy('student_course_registration_id')
            ->get();

        return [
            'attendance_session_id' => $session->attendance_session_id,
            'course_offering_id' => $session->course_offering_id,
            'students' => $registrations->map(function (StudentCourseRegistration $registration) use ($attendanceByStudentId) {
                $attendance = $attendanceByStudentId->get($registration->student_id);

                return [
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'student_id' => $registration->student_id,
                    'student_number' => $registration->student?->student_number,
                    'full_name' => $registration->student
                        ? trim($registration->student->first_name.' '.$registration->student->last_name)
                        : null,
                    'attendance_status' => $attendance?->attendanceStatus ? [
                        'attendance_status_id' => $attendance->attendance_status_id,
                        'status_code' => $attendance->attendanceStatus->status_code,
                        'status_name' => $attendance->attendanceStatus->status_name,
                    ] : null,
                    'attendance_status_id' => $attendance?->attendance_status_id,
                    'notes' => $attendance?->notes,
                ];
            })->values()->all(),
        ];
    }

    public function recordSessionAttendance(int $sessionId, array $records): array
    {
        return DB::transaction(function () use ($sessionId, $records): array {
            $session = AttendanceSession::query()->findOrFail($sessionId);
            $saved = [];

            foreach ($records as $record) {
                $registration = StudentCourseRegistration::query()
                    ->with('registrationStatus')
                    ->find($record['student_course_registration_id']);

                if ($registration === null) {
                    throw new AttendanceException('Registration not found.', [
                        'student_course_registration_id' => ['The selected registration does not exist.'],
                    ]);
                }

                if ((int) $registration->course_offering_id !== (int) $session->course_offering_id) {
                    throw new AttendanceException('Registration does not belong to this attendance session course offering.');
                }

                if (in_array($registration->registrationStatus?->status_code, self::EXCLUDED_REGISTRATION_STATUSES, true)) {
                    throw new AttendanceException('Attendance cannot be recorded for dropped or withdrawn registrations.');
                }

                if ($registration->registrationStatus?->status_code !== self::ACTIVE_REGISTRATION_STATUS) {
                    throw new AttendanceException('Attendance can only be recorded for actively registered students.');
                }

                $statusId = $record['attendance_status_id']
                    ?? $this->attendanceStatusId($record['status_code'] ?? '');

                $attendance = StudentAttendance::query()->updateOrCreate(
                    [
                        'attendance_session_id' => $session->attendance_session_id,
                        'student_id' => $registration->student_id,
                    ],
                    [
                        'attendance_status_id' => $statusId,
                        'notes' => $record['notes'] ?? null,
                    ]
                );

                $attendance->load('attendanceStatus', 'student');

                $saved[] = [
                    'student_attendance_id' => $attendance->student_attendance_id,
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'student_id' => $attendance->student_id,
                    'attendance_status_id' => $attendance->attendance_status_id,
                    'attendance_status' => [
                        'status_code' => $attendance->attendanceStatus?->status_code,
                        'status_name' => $attendance->attendanceStatus?->status_name,
                    ],
                    'notes' => $attendance->notes,
                ];
            }

            return [
                'attendance_session_id' => $session->attendance_session_id,
                'recorded_count' => count($saved),
                'records' => $saved,
            ];
        });
    }

    public function getStudentAttendance(Student $student, ?int $academicYearId = null, ?int $semesterId = null, ?int $courseOfferingId = null): array
    {
        $registrationsQuery = StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->with([
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'studentCourseResult.resultStatus',
            ]);

        if ($courseOfferingId !== null) {
            $registrationsQuery->where('course_offering_id', $courseOfferingId);
        }

        if ($academicYearId !== null) {
            $registrationsQuery->whereHas(
                'courseOffering',
                fn (Builder $query) => $query->where('academic_year_id', $academicYearId)
            );
        }

        if ($semesterId !== null) {
            $registrationsQuery->whereHas(
                'courseOffering',
                fn (Builder $query) => $query->where('semester_id', $semesterId)
            );
        }

        $registrations = $registrationsQuery->orderBy('student_course_registration_id')->get();

        return [
            'student' => [
                'student_id' => $student->student_id,
                'student_number' => $student->student_number,
                'full_name' => trim($student->first_name.' '.$student->last_name),
            ],
            'filters' => [
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
                'course_offering_id' => $courseOfferingId,
            ],
            'courses' => $registrations->map(fn (StudentCourseRegistration $registration) => $this->formatStudentCourseAttendance($registration))->values()->all(),
        ];
    }

    public function getStudentAbsencePercentage(Student $student, int $courseOfferingId): array
    {
        $offering = CourseOffering::query()
            ->with(['course', 'academicYear', 'semester'])
            ->findOrFail($courseOfferingId);

        $stats = $this->calculateAbsenceStats($student->student_id, $courseOfferingId);

        return [
            'student' => [
                'student_id' => $student->student_id,
                'student_number' => $student->student_number,
                'full_name' => trim($student->first_name.' '.$student->last_name),
            ],
            'course_offering' => [
                'course_offering_id' => $offering->course_offering_id,
                'course_code' => $offering->course?->course_code,
                'course_name' => $offering->course?->course_name,
                'academic_year' => $this->compactYear($offering->academicYear),
                'semester' => $this->compactSemester($offering->semester),
            ],
            'total_sessions' => $stats['total_sessions'],
            'present_count' => $stats['present_count'],
            'absent_count' => $stats['absent_count'],
            'excused_count' => $stats['excused_count'],
            'absence_percentage' => $stats['absence_percentage'],
            'deprivation_threshold' => self::DEPRIVATION_THRESHOLD,
            'is_deprived_candidate' => $stats['is_deprived_candidate'],
        ];
    }

    public function getDeprivedStudents(int $courseOfferingId): array
    {
        $offering = CourseOffering::query()
            ->with(['course', 'academicYear', 'semester'])
            ->findOrFail($courseOfferingId);

        $students = $this->activeRegistrationsQuery($courseOfferingId)
            ->with(['student', 'studentCourseResult.resultStatus'])
            ->get()
            ->map(function (StudentCourseRegistration $registration) use ($courseOfferingId) {
                $stats = $this->calculateAbsenceStats($registration->student_id, $courseOfferingId);
                $isAlreadyDeprived = $this->isDeprived($registration);

                return [
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'student_id' => $registration->student_id,
                    'student_number' => $registration->student?->student_number,
                    'full_name' => $registration->student
                        ? trim($registration->student->first_name.' '.$registration->student->last_name)
                        : null,
                    'total_sessions' => $stats['total_sessions'],
                    'absent_count' => $stats['absent_count'],
                    'absence_percentage' => $stats['absence_percentage'],
                    'current_result_status' => $this->compactResultStatus($registration),
                    'is_already_deprived' => $isAlreadyDeprived,
                ];
            })
            ->filter(fn (array $row) => $row['absence_percentage'] > self::DEPRIVATION_THRESHOLD)
            ->values()
            ->all();

        return [
            'course_offering' => [
                'course_offering_id' => $offering->course_offering_id,
                'course_code' => $offering->course?->course_code,
                'course_name' => $offering->course?->course_name,
                'academic_year' => $this->compactYear($offering->academicYear),
                'semester' => $this->compactSemester($offering->semester),
            ],
            'deprivation_threshold' => self::DEPRIVATION_THRESHOLD,
            'students' => $students,
        ];
    }

    public function applyDeprivation(int $courseOfferingId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($courseOfferingId, $userId): array {
            $deprivedStatusId = $this->deprivedResultStatusId();
            $deprivationNote = 'Deprived due to absence percentage greater than '.self::DEPRIVATION_THRESHOLD.'%.';

            $applied = [];
            $skipped = [];

            $registrations = $this->activeRegistrationsQuery($courseOfferingId)
                ->with(['student', 'studentCourseResult.resultStatus'])
                ->get();

            foreach ($registrations as $registration) {
                $stats = $this->calculateAbsenceStats($registration->student_id, $courseOfferingId);

                if ($stats['absence_percentage'] <= self::DEPRIVATION_THRESHOLD) {
                    $skipped[] = [
                        'student_course_registration_id' => $registration->student_course_registration_id,
                        'student_id' => $registration->student_id,
                        'reason' => 'absence_below_threshold',
                    ];

                    continue;
                }

                if ($this->isDeprived($registration)) {
                    $skipped[] = [
                        'student_course_registration_id' => $registration->student_course_registration_id,
                        'student_id' => $registration->student_id,
                        'reason' => 'already_deprived',
                    ];

                    continue;
                }

                $existingResult = $registration->studentCourseResult;

                if ($existingResult === null) {
                    StudentCourseResult::query()->create([
                        'student_course_registration_id' => $registration->student_course_registration_id,
                        'theoretical_total' => 0,
                        'practical_total' => 0,
                        'coursework_total' => 0,
                        'final_mark' => 0,
                        'result_status_id' => $deprivedStatusId,
                        'is_deprived' => true,
                        'calculated_at' => now(),
                        'calculated_by_user_id' => $userId,
                    ]);
                } else {
                    $existingResult->update([
                        'result_status_id' => $deprivedStatusId,
                        'is_deprived' => true,
                        'calculated_at' => now(),
                        'calculated_by_user_id' => $userId,
                    ]);
                }

                $registration->update([
                    'result_status_id' => $deprivedStatusId,
                    'notes' => $deprivationNote,
                ]);

                $applied[] = [
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'student_id' => $registration->student_id,
                    'student_number' => $registration->student?->student_number,
                    'full_name' => $registration->student
                        ? trim($registration->student->first_name.' '.$registration->student->last_name)
                        : null,
                    'absence_percentage' => $stats['absence_percentage'],
                    'result_status' => 'deprived',
                ];
            }

            return [
                'applied_count' => count($applied),
                'skipped_count' => count($skipped),
                'students_updated' => $applied,
                'students_skipped' => $skipped,
            ];
        });
    }

    public function calculateAbsenceStats(int $studentId, int $courseOfferingId): array
    {
        $attendances = StudentAttendance::query()
            ->where('student_id', $studentId)
            ->whereHas('attendanceSession', fn (Builder $query) => $query->where('course_offering_id', $courseOfferingId))
            ->with('attendanceStatus')
            ->get();

        $totalSessions = $attendances->count();
        $absentCount = $attendances->filter(fn (StudentAttendance $attendance) => (bool) $attendance->attendanceStatus?->counts_as_absent)->count();
        $excusedCount = $attendances->filter(fn (StudentAttendance $attendance) => $attendance->attendanceStatus?->status_code === 'excused')->count();
        $presentCount = $attendances->filter(fn (StudentAttendance $attendance) => in_array($attendance->attendanceStatus?->status_code, ['present', 'late'], true))->count();

        $absencePercentage = $totalSessions > 0
            ? round(($absentCount / $totalSessions) * 100, 2)
            : 0.0;

        return [
            'total_sessions' => $totalSessions,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'excused_count' => $excusedCount,
            'absence_percentage' => $absencePercentage,
            'is_deprived_candidate' => $totalSessions > 0 && $absencePercentage > self::DEPRIVATION_THRESHOLD,
        ];
    }

    private function formatStudentCourseAttendance(StudentCourseRegistration $registration): array
    {
        $offering = $registration->courseOffering;
        $stats = $this->calculateAbsenceStats($registration->student_id, (int) $registration->course_offering_id);

        $sessions = StudentAttendance::query()
            ->where('student_id', $registration->student_id)
            ->whereHas('attendanceSession', fn (Builder $query) => $query->where('course_offering_id', $registration->course_offering_id))
            ->with(['attendanceSession', 'attendanceStatus'])
            ->get()
            ->map(fn (StudentAttendance $attendance) => [
                'student_attendance_id' => $attendance->student_attendance_id,
                'attendance_session_id' => $attendance->attendance_session_id,
                'session_date' => $attendance->attendanceSession?->session_date,
                'session_type' => $attendance->attendanceSession?->session_type,
                'attendance_status' => [
                    'status_code' => $attendance->attendanceStatus?->status_code,
                    'status_name' => $attendance->attendanceStatus?->status_name,
                ],
                'notes' => $attendance->notes,
            ])
            ->values()
            ->all();

        return [
            'course_offering_id' => $registration->course_offering_id,
            'course_code' => $offering?->course?->course_code,
            'course_name' => $offering?->course?->course_name,
            'academic_year' => $this->compactYear($offering?->academicYear),
            'semester' => $this->compactSemester($offering?->semester),
            'total_sessions' => $stats['total_sessions'],
            'present_count' => $stats['present_count'],
            'absent_count' => $stats['absent_count'],
            'excused_count' => $stats['excused_count'],
            'absence_percentage' => $stats['absence_percentage'],
            'deprivation_status' => $this->isDeprived($registration) ? 'deprived' : ($stats['is_deprived_candidate'] ? 'candidate' : 'normal'),
            'sessions' => $sessions,
        ];
    }

    private function formatSessionSummary(AttendanceSession $session, int $activeStudentCount): array
    {
        $attendances = $session->relationLoaded('studentAttendances')
            ? $session->studentAttendances
            : $session->studentAttendances()->with('attendanceStatus')->get();

        $presentCount = $attendances->filter(fn (StudentAttendance $attendance) => in_array($attendance->attendanceStatus?->status_code, ['present', 'late'], true))->count();
        $absentCount = $attendances->filter(fn (StudentAttendance $attendance) => (bool) $attendance->attendanceStatus?->counts_as_absent)->count();
        $excusedCount = $attendances->filter(fn (StudentAttendance $attendance) => $attendance->attendanceStatus?->status_code === 'excused')->count();

        return [
            'attendance_session_id' => $session->attendance_session_id,
            'course_offering_id' => $session->course_offering_id,
            'session_date' => $session->session_date,
            'session_type' => $session->session_type,
            'topic' => $session->notes,
            'start_time' => $session->start_time,
            'end_time' => $session->end_time,
            'total_students' => $activeStudentCount,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'excused_count' => $excusedCount,
            'recorded_count' => $attendances->count(),
        ];
    }

    private function activeRegistrationsQuery(int $courseOfferingId): Builder
    {
        return StudentCourseRegistration::query()
            ->where('course_offering_id', $courseOfferingId)
            ->whereHas(
                'registrationStatus',
                fn (Builder $query) => $query->where('status_code', self::ACTIVE_REGISTRATION_STATUS)
            );
    }

    private function isDeprived(StudentCourseRegistration $registration): bool
    {
        $result = $registration->studentCourseResult;

        return (bool) ($result?->is_deprived)
            || $result?->resultStatus?->status_code === 'deprived'
            || $registration->resultStatus?->status_code === 'deprived';
    }

    private function compactResultStatus(StudentCourseRegistration $registration): ?array
    {
        $status = $registration->studentCourseResult?->resultStatus ?? $registration->resultStatus;

        if ($status === null) {
            return null;
        }

        return [
            'status_code' => $status->status_code,
            'status_name' => $status->status_name,
        ];
    }

    private function compactYear($year): ?array
    {
        if ($year === null) {
            return null;
        }

        return [
            'academic_year_id' => $year->academic_year_id,
            'year_name' => $year->year_name,
        ];
    }

    private function compactSemester($semester): ?array
    {
        if ($semester === null) {
            return null;
        }

        return [
            'semester_id' => $semester->semester_id,
            'semester_code' => $semester->semester_code,
            'semester_name' => $semester->semester_name,
        ];
    }

    private function attendanceStatusId(string $statusCode): int
    {
        $statusId = AttendanceStatus::query()->where('status_code', $statusCode)->value('attendance_status_id');

        if ($statusId === null) {
            throw new AttendanceException('Attendance status "'.$statusCode.'" was not found.');
        }

        return (int) $statusId;
    }

    private function deprivedResultStatusId(): int
    {
        $statusId = ResultStatus::query()->where('status_code', 'deprived')->value('result_status_id');

        if ($statusId === null) {
            throw new AttendanceException('Result status "deprived" was not found in result_statuses.');
        }

        return (int) $statusId;
    }
}
