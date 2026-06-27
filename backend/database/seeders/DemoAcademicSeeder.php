<?php

namespace Database\Seeders;

use App\Models\AcademicLevel;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\AccountStatus;
use App\Models\AttendanceSession;
use App\Models\AttendanceStatus;
use App\Models\College;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\RegistrationStatus;
use App\Models\ResultStatus;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemoAcademicSeeder extends Seeder
{
    private const DEMO_PREFIX = 'DEMO';

    public function run(): void
    {
        DB::transaction(function (): void {
            $statuses = $this->resolveStatuses();
            $userId = $this->resolveSeederUserId();

            $structure = $this->seedAcademicStructure();
            $students = $this->seedStudents($structure, $statuses['student']);
            [$courses, $offerings] = $this->seedCoursesAndOfferings($structure);
            $registrations = $this->seedRegistrations($students, $offerings, $userId, $statuses['registration']);
            $this->seedGrades($registrations, $statuses, $userId);
            $this->seedAttendance($offerings, $registrations, $userId, $statuses);
            $this->syncOfferingSeats($offerings);
        });

        $this->command?->info('DemoAcademicSeeder completed successfully.');
    }

    /**
     * @return array{
     *     registration: RegistrationStatus,
     *     result_passed: ResultStatus,
     *     result_failed: ResultStatus,
     *     student: StudentStatus,
     *     present: AttendanceStatus,
     *     absent: AttendanceStatus
     * }
     */
    private function resolveStatuses(): array
    {
        return [
            'registration' => RegistrationStatus::firstOrCreate(
                ['status_code' => 'registered'],
                ['status_name' => 'Registered', 'is_active' => true]
            ),
            'result_passed' => ResultStatus::firstOrCreate(
                ['status_code' => 'passed'],
                ['status_name' => 'Passed', 'is_active' => true]
            ),
            'result_failed' => ResultStatus::firstOrCreate(
                ['status_code' => 'failed'],
                ['status_name' => 'Failed', 'is_active' => true]
            ),
            'student' => StudentStatus::firstOrCreate(
                ['status_code' => 'active'],
                ['status_name' => 'Active', 'description' => null, 'is_active' => true]
            ),
            'present' => AttendanceStatus::firstOrCreate(
                ['status_code' => 'present'],
                ['status_name' => 'Present', 'counts_as_absent' => false, 'is_active' => true]
            ),
            'absent' => AttendanceStatus::firstOrCreate(
                ['status_code' => 'absent'],
                ['status_name' => 'Absent', 'counts_as_absent' => true, 'is_active' => true]
            ),
        ];
    }

    private function resolveSeederUserId(): int
    {
        $existing = User::query()->orderBy('user_id')->first();

        if ($existing !== null) {
            return $existing->user_id;
        }

        $accountStatus = AccountStatus::firstOrCreate(
            ['status_code' => 'active'],
            ['status_name' => 'Active', 'description' => null, 'is_active' => true]
        );

        $demoUser = User::firstOrCreate(
            ['email' => 'demo.seeder@rowad.edu'],
            [
                'username' => 'demo_seeder',
                'password_hash' => bcrypt('DemoSeeder123!'),
                'account_status_id' => $accountStatus->account_status_id,
                'failed_login_attempts' => 0,
            ]
        );

        $this->command?->warn('Created demo seeder user: demo.seeder@rowad.edu');

        return $demoUser->user_id;
    }

    /**
     * @return array{
     *     colleges: Collection<int, College>,
     *     departments: Collection<int, Department>,
     *     programs: Collection<int, AcademicProgram>,
     *     levels: Collection<int, AcademicLevel>,
     *     year: AcademicYear,
     *     semester: Semester
     * }
     */
    private function seedAcademicStructure(): array
    {
        $collegeNames = [
            'DEMO College of Engineering',
            'DEMO College of Business',
            'DEMO College of Arts & Sciences',
        ];

        $colleges = collect();
        foreach ($collegeNames as $index => $name) {
            $colleges->push(College::updateOrCreate(
                ['college_code' => sprintf('%s-COL-%02d', self::DEMO_PREFIX, $index + 1)],
                [
                    'college_name' => $name,
                    'description' => 'Demo college for frontend performance testing.',
                    'is_active' => true,
                    'organizational_unit_id' => null,
                ]
            ));
        }

        $departmentDefs = [
            ['DEMO-DEP-01', 'DEMO Computer Science', 0],
            ['DEMO-DEP-02', 'DEMO Information Systems', 0],
            ['DEMO-DEP-03', 'DEMO Civil Engineering', 0],
            ['DEMO-DEP-04', 'DEMO Accounting', 1],
            ['DEMO-DEP-05', 'DEMO Business Administration', 1],
            ['DEMO-DEP-06', 'DEMO Mathematics', 2],
        ];

        $departments = collect();
        foreach ($departmentDefs as [$code, $name, $collegeIndex]) {
            $departments->push(Department::updateOrCreate(
                ['department_code' => $code],
                [
                    'college_id' => $colleges[$collegeIndex]->college_id,
                    'department_name' => $name,
                    'description' => 'Demo department.',
                    'is_active' => true,
                    'organizational_unit_id' => null,
                ]
            ));
        }

        $programDefs = [
            ['DEMO-PROG-01', 'DEMO BSc Computer Science', 0, 'Bachelor', 160, 4],
            ['DEMO-PROG-02', 'DEMO BSc Information Systems', 1, 'Bachelor', 150, 4],
            ['DEMO-PROG-03', 'DEMO BSc Civil Engineering', 2, 'Bachelor', 170, 5],
            ['DEMO-PROG-04', 'DEMO BSc Accounting', 3, 'Bachelor', 140, 4],
            ['DEMO-PROG-05', 'DEMO BBA Management', 4, 'Bachelor', 130, 4],
            ['DEMO-PROG-06', 'DEMO BSc Mathematics', 5, 'Bachelor', 145, 4],
        ];

        $programs = collect();
        foreach ($programDefs as [$code, $name, $deptIndex, $degree, $credits, $years]) {
            $programs->push(AcademicProgram::updateOrCreate(
                ['program_code' => $code],
                [
                    'department_id' => $departments[$deptIndex]->department_id,
                    'program_name' => $name,
                    'degree_level' => $degree,
                    'total_credit_hours' => $credits,
                    'duration_years' => $years,
                    'description' => 'Demo academic program.',
                    'is_active' => true,
                ]
            ));
        }

        $levels = collect();
        for ($i = 1; $i <= 4; $i++) {
            $levels->push(AcademicLevel::updateOrCreate(
                ['level_code' => sprintf('%s-L%d', self::DEMO_PREFIX, $i)],
                [
                    'level_name' => "DEMO Year {$i}",
                    'level_order' => $i,
                    'is_active' => true,
                ]
            ));
        }

        $year = AcademicYear::updateOrCreate(
            ['year_name' => '2026-DEMO'],
            [
                'start_date' => '2026-09-01',
                'end_date' => '2027-08-31',
                'is_current' => true,
                'is_active' => true,
            ]
        );

        $semester = Semester::updateOrCreate(
            ['semester_code' => 'DEMO-FALL'],
            [
                'semester_name' => 'DEMO Fall Semester',
                'semester_order' => 1,
                'is_active' => true,
            ]
        );

        Semester::updateOrCreate(
            ['semester_code' => 'DEMO-SPRING'],
            [
                'semester_name' => 'DEMO Spring Semester',
                'semester_order' => 2,
                'is_active' => true,
            ]
        );

        return compact('colleges', 'departments', 'programs', 'levels', 'year', 'semester');
    }

    /**
     * @param  array{programs: Collection<int, AcademicProgram>, levels: Collection<int, AcademicLevel>}  $structure
     * @return Collection<int, Student>
     */
    private function seedStudents(array $structure, StudentStatus $studentStatus): Collection
    {
        $firstNames = [
            'Ahmad', 'Layla', 'Omar', 'Sara', 'Khalid', 'Fatima', 'Hassan', 'Nour',
            'Youssef', 'Maya', 'Rami', 'Huda', 'Tarek', 'Lina', 'Mahmoud', 'Zeinab',
            'أحمد', 'ليلى', 'محمد', 'سارة', 'خالد', 'فاطمة', 'حسن', 'نور',
        ];

        $lastNames = [
            'Ali', 'Hassan', 'Omar', 'Khalil', 'Nasser', 'Saleh', 'Younes', 'Farah',
            'Hamdan', 'Mansour', 'Issa', 'Barakat', 'Jaber', 'Awad', 'Salem', 'Haddad',
            'العلي', 'حسن', 'عمر', 'خليل', 'ناصر', 'صالح', 'يونس', 'فرح',
        ];

        $students = collect();

        for ($i = 1; $i <= 60; $i++) {
            $program = $structure['programs'][($i - 1) % $structure['programs']->count()];
            $level = $structure['levels'][($i - 1) % $structure['levels']->count()];
            $firstName = $firstNames[($i - 1) % count($firstNames)];
            $lastName = $lastNames[intdiv($i - 1, count($firstNames)) % count($lastNames)];

            $students->push(Student::updateOrCreate(
                ['student_number' => sprintf('2026-DEMO-%03d', $i)],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'father_name' => 'Demo Father',
                    'mother_name' => 'Demo Mother',
                    'date_of_birth' => sprintf('200%d-%02d-15', ($i % 5) + 1, ($i % 12) + 1),
                    'gender' => $i % 2 === 0 ? 'female' : 'male',
                    'phone_number' => sprintf('+96391%07d', 1000000 + $i),
                    'email' => sprintf('demo.student%03d@rowad.edu', $i),
                    'address' => 'DEMO Campus City',
                    'nationality' => 'Syrian',
                    'academic_program_id' => $program->academic_program_id,
                    'current_academic_level_id' => $level->academic_level_id,
                    'enrollment_date' => '2026-09-01',
                    'student_status_id' => $studentStatus->student_status_id,
                    'admission_application_id' => null,
                ]
            ));
        }

        return $students;
    }

    /**
     * @param  array{departments: Collection<int, Department>, programs: Collection<int, AcademicProgram>, year: AcademicYear, semester: Semester}  $structure
     * @return array{0: Collection<int, Course>, 1: Collection<int, CourseOffering>}
     */
    private function seedCoursesAndOfferings(array $structure): array
    {
        $courseDefs = [
            ['DEMO101', 'DEMO Introduction to Programming', 3],
            ['DEMO102', 'DEMO Data Structures', 3],
            ['DEMO103', 'DEMO Database Systems', 3],
            ['DEMO104', 'DEMO Web Development', 3],
            ['DEMO105', 'DEMO Object-Oriented Programming', 4],
            ['DEMO106', 'DEMO Statistics I', 3],
            ['DEMO107', 'DEMO Statistics II', 3],
            ['DEMO108', 'DEMO Geographic Information Systems', 3],
            ['DEMO109', 'DEMO Applied Mathematics', 4],
            ['DEMO110', 'DEMO Linear Algebra', 3],
            ['DEMO111', 'DEMO Financial Accounting', 3],
            ['DEMO112', 'DEMO Managerial Accounting', 3],
            ['DEMO113', 'DEMO Principles of Management', 3],
            ['DEMO114', 'DEMO Operations Management', 3],
            ['DEMO115', 'DEMO Business Statistics', 3],
            ['DEMO116', 'DEMO Software Engineering', 4],
            ['DEMO117', 'DEMO Computer Networks', 3],
            ['DEMO118', 'DEMO Calculus I', 4],
            ['DEMO119', 'DEMO Calculus II', 4],
            ['DEMO120', 'DEMO Research Methods', 2],
        ];

        $courses = collect();
        foreach ($courseDefs as [$code, $name, $creditHours]) {
            $courses->push(Course::updateOrCreate(
                ['course_code' => $code],
                [
                    'course_name' => $name,
                    'credit_hours' => $creditHours,
                    'theoretical_hours' => max(1, $creditHours - 1),
                    'practical_hours' => min(2, $creditHours - 1),
                    'description' => 'Demo course for performance testing.',
                    'is_active' => true,
                ]
            ));
        }

        $offerings = collect();
        $offeringIndex = 0;

        for ($i = 0; $i < 30; $i++) {
            $course = $courses[$i % $courses->count()];
            $program = $structure['programs'][$i % $structure['programs']->count()];
            $department = $structure['departments'][$i % $structure['departments']->count()];

            $offerings->push(CourseOffering::updateOrCreate(
                [
                    'course_id' => $course->course_id,
                    'academic_year_id' => $structure['year']->academic_year_id,
                    'semester_id' => $structure['semester']->semester_id,
                    'academic_program_id' => $program->academic_program_id,
                ],
                [
                    'department_id' => $department->department_id,
                    'faculty_member_id' => null,
                    'capacity' => 30,
                    'available_seats' => 30,
                    'status' => 'open',
                ]
            ));

            $offeringIndex++;
        }

        return [$courses, $offerings];
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, CourseOffering>  $offerings
     * @return Collection<int, StudentCourseRegistration>
     */
    private function seedRegistrations(
        Collection $students,
        Collection $offerings,
        int $userId,
        RegistrationStatus $registrationStatus
    ): Collection {
        $registrations = collect();
        $offeringIds = $offerings->pluck('course_offering_id')->values();

        foreach ($students as $index => $student) {
            $registrationCount = 3 + ($index % 3);
            $selectedOfferingIds = collect();

            for ($j = 0; $j < $registrationCount; $j++) {
                $offeringId = $offeringIds[($index * 3 + $j) % $offeringIds->count()];

                if ($selectedOfferingIds->contains($offeringId)) {
                    continue;
                }

                $selectedOfferingIds->push($offeringId);

                $registrations->push(StudentCourseRegistration::updateOrCreate(
                    [
                        'student_id' => $student->student_id,
                        'course_offering_id' => $offeringId,
                    ],
                    [
                        'registration_date' => '2026-09-15',
                        'registered_by_user_id' => $userId,
                        'advisor_user_id' => null,
                        'registration_status_id' => $registrationStatus->registration_status_id,
                        'result_status_id' => null,
                        'notes' => 'DEMO registration',
                    ]
                ));
            }
        }

        return $registrations;
    }

    /**
     * @param  Collection<int, StudentCourseRegistration>  $registrations
     */
    private function seedGrades(
        Collection $registrations,
        array $statuses,
        int $userId
    ): void {
        foreach ($registrations as $index => $registration) {
            $theoretical = 20 + ($index % 35);
            $practical = 10 + ($index % 25);
            $final = $theoretical + $practical;

            $passed = $theoretical >= 15 && $practical >= 10 && $final >= 50;
            $resultStatus = $passed ? $statuses['result_passed'] : $statuses['result_failed'];

            StudentCourseResult::updateOrCreate(
                ['student_course_registration_id' => $registration->student_course_registration_id],
                [
                    'theoretical_total' => $theoretical,
                    'practical_total' => $practical,
                    'coursework_total' => 0,
                    'final_mark' => $final,
                    'result_status_id' => $resultStatus->result_status_id,
                    'is_deprived' => false,
                    'calculated_at' => now(),
                    'calculated_by_user_id' => $userId,
                ]
            );

            $registration->update([
                'result_status_id' => $resultStatus->result_status_id,
            ]);
        }
    }

    /**
     * @param  Collection<int, CourseOffering>  $offerings
     * @param  Collection<int, StudentCourseRegistration>  $registrations
     */
    private function seedAttendance(
        Collection $offerings,
        Collection $registrations,
        int $userId,
        array $statuses
    ): void {
        $sessionOfferings = $offerings->take(5);
        $sessionDates = ['2026-10-01', '2026-10-08', '2026-10-15'];

        foreach ($sessionOfferings as $offering) {
            $offeringRegistrations = $registrations->where('course_offering_id', $offering->course_offering_id);

            foreach ($sessionDates as $dateIndex => $sessionDate) {
                $session = AttendanceSession::updateOrCreate(
                    [
                        'course_offering_id' => $offering->course_offering_id,
                        'session_date' => $sessionDate,
                        'session_type' => 'theoretical',
                    ],
                    [
                        'start_time' => '09:00:00',
                        'end_time' => '10:30:00',
                        'faculty_member_id' => null,
                        'created_by_user_id' => $userId,
                        'notes' => 'DEMO attendance session '.($dateIndex + 1),
                    ]
                );

                foreach ($offeringRegistrations as $regIndex => $registration) {
                    $isAbsent = ($regIndex + $dateIndex) % 7 === 0;
                    $status = $isAbsent ? $statuses['absent'] : $statuses['present'];

                    StudentAttendance::updateOrCreate(
                        [
                            'attendance_session_id' => $session->attendance_session_id,
                            'student_id' => $registration->student_id,
                        ],
                        [
                            'attendance_status_id' => $status->attendance_status_id,
                            'notes' => $isAbsent ? 'DEMO absent' : null,
                        ]
                    );
                }
            }
        }
    }

    /**
     * @param  Collection<int, CourseOffering>  $offerings
     */
    private function syncOfferingSeats(Collection $offerings): void
    {
        foreach ($offerings as $offering) {
            $registeredCount = StudentCourseRegistration::query()
                ->where('course_offering_id', $offering->course_offering_id)
                ->count();

            $offering->update([
                'available_seats' => max(0, (int) $offering->capacity - $registeredCount),
            ]);
        }
    }
}
