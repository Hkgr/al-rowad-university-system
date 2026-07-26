<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AcademicLevelController;
use App\Http\Controllers\Api\AcademicProgramController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AccountStatusController;
use App\Http\Controllers\Api\AdmissionApplicationController;
use App\Http\Controllers\Api\AppealStatusController;
use App\Http\Controllers\Api\ApplicantController;
use App\Http\Controllers\Api\ApprovalStatusController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceStatusController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\BoardDecisionController;
use App\Http\Controllers\Api\BoardDecisionAttachmentController;
use App\Http\Controllers\Api\BoardMeetingController;
use App\Http\Controllers\Api\BoardMemberController;
use App\Http\Controllers\Api\CollegeController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseDepartmentController;
use App\Http\Controllers\Api\CourseInstructorController;
use App\Http\Controllers\Api\CourseOfferingController;
use App\Http\Controllers\Api\CourseOfferingInstructorController;
use App\Http\Controllers\Api\CoursePrerequisiteController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DocumentTypeController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeePositionController;
use App\Http\Controllers\Api\EmployeeStatusController;
use App\Http\Controllers\Api\EmployeeTypeController;
use App\Http\Controllers\Api\EmployeeUnitAssignmentController;
use App\Http\Controllers\Api\FacultyMemberController;
use App\Http\Controllers\Api\GradeAppealController;
use App\Http\Controllers\Api\GradeApprovalController;
use App\Http\Controllers\Api\GradeAuditLogController;
use App\Http\Controllers\Api\GradeComponentController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GradingPolicyController;
use App\Http\Controllers\Api\LibraryAuthorController;
use App\Http\Controllers\Api\LibraryBookController;
use App\Http\Controllers\Api\LibraryBookAuthorController;
use App\Http\Controllers\Api\LibraryBookCopyController;
use App\Http\Controllers\Api\LibraryBorrowingController;
use App\Http\Controllers\Api\LibraryCategoryController;
use App\Http\Controllers\Api\LoginAuditLogController;
use App\Http\Controllers\Api\MeetingAttendeeController;
use App\Http\Controllers\Api\OrganizationalUnitController;
use App\Http\Controllers\Api\OrganizationalUnitTypeController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\ProgramCourseController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RegistrationStatusController;
use App\Http\Controllers\Api\ResultStatusController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\SemesterController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentAcademicTermController;
use App\Http\Controllers\Api\StudentCourseRegistrationController;
use App\Http\Controllers\Api\StudentCourseResultController;
use App\Http\Controllers\Api\StudentCreditLimitController;
use App\Http\Controllers\Api\StudentAffairsDashboardController;
use App\Http\Controllers\Api\StudentDocumentController;
use App\Http\Controllers\Api\StudentGradeComponentController;
use App\Http\Controllers\Api\StudentStatusController;
use App\Http\Controllers\Api\SupplementaryExamPeriodController;
use App\Http\Controllers\Api\SupplementaryExamResultController;
use App\Http\Controllers\Api\SystemModuleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserActivityLogController;
use App\Http\Controllers\Api\UserRoleController;

/*
|--------------------------------------------------------------------------
| Public Authentication Routes
|--------------------------------------------------------------------------
| هذه الراوتات لا تحتاج Token.
| تستخدم من الفرونت لتسجيل الدخول والحصول على Bearer Token.
|--------------------------------------------------------------------------
*/

Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
| هذه الراوتات تحتاج Token عبر Laravel Sanctum.
| تستخدم لمعرفة المستخدم الحالي وتسجيل الخروج.
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::get('user', [AuthController::class, 'current']);
    Route::post('logout', [AuthController::class, 'logout']);
});

/*
|--------------------------------------------------------------------------
| API Version 1 Routes
|--------------------------------------------------------------------------
| كل الراوتات التالية محمية بـ auth:sanctum.
| المسار الكامل يبدأ بـ /api/v1/...
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'account.active'])->prefix('v1')->group(function (): void {

    $resource = static function (
        string $name,
        string $controller,
        string $viewPermission,
        string $managePermission,
        bool $staffOnly = false
    ): void {
        $viewMiddleware = ($staffOnly ? 'staff.permission:' : 'permission:').$viewPermission;
        $manageMiddleware = ($staffOnly ? 'staff.permission:' : 'permission:').$managePermission;

        Route::apiResource($name, $controller)
            ->only(['index', 'show'])
            ->middleware($viewMiddleware);

        Route::apiResource($name, $controller)
            ->only(['store', 'update', 'destroy'])
            ->middleware($manageMiddleware);
    };

    $readOnlyResource = static function (
        string $name,
        string $controller,
        string $viewPermission,
        bool $staffOnly = false
    ): void {
        $middleware = ($staffOnly ? 'staff.permission:' : 'permission:').$viewPermission;

        Route::apiResource($name, $controller)
            ->only(['index', 'show'])
            ->middleware($middleware);
    };

    /*
    |--------------------------------------------------------------------------
    | Academic Calendar / Current Context
    |--------------------------------------------------------------------------
    | السنة الحالية والفصل الفعال.
    |--------------------------------------------------------------------------
    */

    Route::get('academic-years/current', [AcademicYearController::class, 'current']);
    Route::get('semesters/active', [SemesterController::class, 'active']);

    /*
    |--------------------------------------------------------------------------
    | Student Affairs Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('student-affairs/dashboard-stats', [StudentAffairsDashboardController::class, 'dashboardStats'])
        ->middleware('staff.permission:students.view');

    /*
    |--------------------------------------------------------------------------
    | Student Profile / Student Dashboard
    |--------------------------------------------------------------------------
    | راوتات مهمة للوحة الطالب أو البحث عن الطلاب.
    |--------------------------------------------------------------------------
    */

    Route::get('students/deleted', [StudentController::class, 'deleted'])
        ->middleware('staff.permission:students.view');
    Route::post('students/{id}/restore', [StudentController::class, 'restore'])
        ->middleware('staff.permission:students.manage');
    Route::delete('students/{id}/force', [StudentController::class, 'forceDestroy'])
        ->middleware('staff.permission:students.manage');
    Route::get('students/search', [StudentController::class, 'search'])
        ->middleware('staff.permission:students.view');
    Route::get('students/{student}/available-courses', [StudentController::class, 'availableCourses'])
        ->middleware('student.access:registration.view');
    Route::get('students/{student}/registered-hours', [StudentController::class, 'registeredHours'])
        ->middleware('student.access:registration.view');
    Route::get('students/{student}/registration-summary', [StudentController::class, 'registrationSummary'])
        ->middleware('student.access:registration.view');
    Route::get('students/{student}/profile', [StudentController::class, 'profile'])
        ->middleware('student.access:students.view');
    Route::get('students/{student}/academic-info', [StudentController::class, 'academicInfo'])
        ->middleware('student.access:students.view');
    Route::get('students/{student}/documents', [StudentController::class, 'documents'])
        ->middleware('student.access:students.view');
    Route::post('students/{student}/documents', [StudentDocumentController::class, 'upload'])
        ->middleware('staff.permission:students.manage');
    Route::get('students/{student}/registrations', [StudentController::class, 'registrations'])
        ->middleware('student.access:registration.view|grades.view|exams.view');
    Route::get('students/{student}/transcript', [StudentController::class, 'transcript'])
        ->middleware('student.access:grades.view');
    Route::get('students/{student}/gpa', [StudentController::class, 'gpa'])
        ->middleware('student.access:grades.view');
    Route::get('students/{student}/cgpa', [StudentController::class, 'cgpa'])
        ->middleware('student.access:grades.view');
    Route::get('students/{student}/attendance', [StudentController::class, 'attendance'])
        ->middleware('student.access:attendance.view');
    Route::get('students/{student}/absence-percentage', [StudentController::class, 'absencePercentage'])
        ->middleware('student.access:attendance.view');

    /*
    |--------------------------------------------------------------------------
    | Academic Structure Relations
    |--------------------------------------------------------------------------
    | علاقات الكليات، الأقسام، البرامج، والخطط الدراسية.
    |--------------------------------------------------------------------------
    */

    Route::get('colleges/{college}/departments', [CollegeController::class, 'departments'])
        ->middleware('permission:academic_structure.view|courses.view|students.view');
    Route::get('departments/{department}/programs', [DepartmentController::class, 'programs'])
        ->middleware('permission:academic_structure.view|courses.view|students.view');
    Route::get('programs/{academic_program}/students', [AcademicProgramController::class, 'students'])
        ->middleware('staff.permission:students.view');
    Route::get('programs/{academic_program}/courses', [AcademicProgramController::class, 'courses'])
        ->middleware('permission:academic_structure.view|courses.view|registration.view');
    Route::get('programs/{id}/mandatory-courses', [AcademicProgramController::class, 'mandatoryCourses'])
        ->middleware('permission:academic_structure.view|courses.view|registration.view');
    Route::get('programs/{id}/elective-courses', [AcademicProgramController::class, 'electiveCourses'])
        ->middleware('permission:academic_structure.view|courses.view|registration.view');
    Route::get('programs/{id}/study-plan', [AcademicProgramController::class, 'studyPlan'])
        ->middleware('permission:academic_structure.view|courses.view|registration.view');

    /*
    |--------------------------------------------------------------------------
    | Course Relations
    |--------------------------------------------------------------------------
    | علاقات المقررات مع الأقسام، البرامج، المتطلبات، والمدرسين.
    |--------------------------------------------------------------------------
    */

    Route::get('courses/{id}/departments', [CourseController::class, 'departments'])
        ->middleware('permission:courses.view');
    Route::get('courses/{id}/programs', [CourseController::class, 'programs'])
        ->middleware('permission:courses.view');
    Route::get('courses/{id}/prerequisites', [CourseController::class, 'prerequisites'])
        ->middleware('permission:courses.view');
    Route::get('courses/{id}/instructors', [CourseController::class, 'instructors'])
        ->middleware('permission:courses.view');

    /*
    |--------------------------------------------------------------------------
    | Course Offerings / Sections / Attendance
    |--------------------------------------------------------------------------
    | الشعب المفتوحة، السعة، الطلاب، الحضور، والحرمان.
    |--------------------------------------------------------------------------
    */

    Route::get('me/course-offerings/open', [CourseOfferingController::class, 'mine'])
        ->middleware('staff.permission:courses.view');
    Route::get('me/faculty-member', [FacultyMemberController::class, 'mine'])
        ->middleware('staff.permission:courses.view|attendance.view|grades.view');
    Route::get('course-offerings/open', [CourseOfferingController::class, 'open'])
        ->middleware('permission:courses.view');
    Route::get('course-offerings/{id}/details', [CourseOfferingController::class, 'details'])
        ->middleware('permission:courses.view');
    Route::get('course-offerings/{id}/students', [CourseOfferingController::class, 'students'])
        ->middleware([
            'staff.permission:courses.view|grades.view|attendance.view|registration.view',
            'faculty.offering:course-offering',
        ]);
    Route::get('course-offerings/{id}/capacity', [CourseOfferingController::class, 'capacity'])
        ->middleware('permission:courses.view');
    Route::get('course-offerings/by-semester', [CourseOfferingController::class, 'bySemester'])
        ->middleware('permission:courses.view');
    Route::get('course-offerings/{id}/grade-sheet', [CourseOfferingController::class, 'gradeSheet'])
        ->middleware([
            'staff.permission:grades.view',
            'faculty.offering:course-offering',
        ]);
    Route::get('course-offerings/{id}/results-summary', [CourseOfferingController::class, 'resultsSummary'])
        ->middleware([
            'staff.permission:grades.view',
            'faculty.offering:course-offering',
        ]);
    Route::get('course-offerings/{id}/attendance-sessions', [CourseOfferingController::class, 'attendanceSessions'])
        ->middleware([
            'staff.permission:attendance.view',
            'faculty.offering:course-offering',
        ]);
    Route::post('course-offerings/{id}/attendance-sessions', [CourseOfferingController::class, 'storeAttendanceSession'])
        ->middleware([
            'staff.permission:attendance.manage',
            'faculty.offering:course-offering',
        ]);
    Route::get('course-offerings/{id}/deprived-students', [CourseOfferingController::class, 'deprivedStudents'])
        ->middleware([
            'staff.permission:attendance.view',
            'faculty.offering:course-offering',
        ]);
    Route::post('course-offerings/{id}/apply-deprivation', [CourseOfferingController::class, 'applyDeprivation'])
        ->middleware('staff.permission:exams.manage');
    Route::get('course-offerings/by-program/{program_id}', [CourseOfferingController::class, 'byProgram'])
        ->middleware('permission:courses.view');
    Route::get('course-offerings/{courseOffering}/instructors', [CourseOfferingInstructorController::class, 'index'])
        ->middleware('staff.permission:courses.view');
    Route::post('course-offerings/{courseOffering}/instructors', [CourseOfferingInstructorController::class, 'store'])
        ->middleware('staff.permission:courses.manage');
    Route::patch('course-offering-instructors/{courseOfferingInstructor}', [CourseOfferingInstructorController::class, 'update'])
        ->middleware('staff.permission:courses.manage');
    Route::delete('course-offering-instructors/{courseOfferingInstructor}', [CourseOfferingInstructorController::class, 'destroy'])
        ->middleware('staff.permission:courses.manage');

    /*
    |--------------------------------------------------------------------------
    | Attendance Operations
    |--------------------------------------------------------------------------
    | تسجيل حضور الطلاب ضمن جلسات الحضور.
    |--------------------------------------------------------------------------
    */

    Route::get('attendance-sessions/{id}/students', [AttendanceController::class, 'sessionStudents'])
        ->middleware([
            'staff.permission:attendance.view',
            'faculty.offering:attendance-session',
        ]);
    Route::post('attendance-sessions/{id}/record', [AttendanceController::class, 'record'])
        ->middleware([
            'staff.permission:attendance.manage',
            'faculty.offering:attendance-session',
        ]);

    /*
    |--------------------------------------------------------------------------
    | Grades / Results Operations
    |--------------------------------------------------------------------------
    | إدخال العلامات، تعديلها، وحساب النتيجة.
    |--------------------------------------------------------------------------
    */

    Route::get('registrations/{id}/grades', [GradeController::class, 'show'])
        ->middleware([
            'staff.permission:grades.view',
            'faculty.offering:registration',
        ]);
    Route::post('registrations/{id}/grades', [GradeController::class, 'store'])
        ->middleware([
            'staff.permission:grades.manage',
            'faculty.offering:registration',
        ]);
    Route::put('registrations/{id}/grades', [GradeController::class, 'update'])
        ->middleware([
            'staff.permission:grades.manage',
            'faculty.offering:registration',
        ]);
    Route::post('registrations/{id}/calculate-result', [GradeController::class, 'calculateResult'])
        ->middleware([
            'staff.permission:grades.manage',
            'faculty.offering:registration',
        ]);

    /*
    |--------------------------------------------------------------------------
    | Course Registration Operations
    |--------------------------------------------------------------------------
    | تسجيل، إسقاط، وانسحاب الطالب من المقررات.
    |--------------------------------------------------------------------------
    */

    Route::post('registrations/register-student', [RegistrationController::class, 'registerStudent'])
        ->middleware('staff.permission:registration.manage');
    Route::post('registrations/{id}/drop', [RegistrationController::class, 'drop'])
        ->middleware('staff.permission:registration.manage');
    Route::post('registrations/{id}/withdraw', [RegistrationController::class, 'withdraw'])
        ->middleware('staff.permission:registration.manage');

    /*
    |--------------------------------------------------------------------------
    | Academic Setup Resources
    |--------------------------------------------------------------------------
    | موارد الإعداد الأكاديمي الأساسية.
    |--------------------------------------------------------------------------
    */

    $academicRead = 'academic_structure.view|courses.view|students.view|registration.view|exams.view';
    $resource('academic-levels', AcademicLevelController::class, $academicRead, 'academic_structure.manage');
    $resource('academic-programs', AcademicProgramController::class, $academicRead, 'academic_structure.manage');
    $resource('academic-years', AcademicYearController::class, $academicRead, 'academic_structure.manage');
    $resource('semesters', SemesterController::class, $academicRead, 'academic_structure.manage');
    $resource('colleges', CollegeController::class, $academicRead, 'academic_structure.manage');
    $resource('departments', DepartmentController::class, $academicRead, 'academic_structure.manage');

    $resource('courses', CourseController::class, 'courses.view', 'courses.manage');
    $resource('course-departments', CourseDepartmentController::class, 'courses.view', 'courses.manage');
    $resource('course-instructors', CourseInstructorController::class, 'courses.view', 'courses.manage', true);
    $resource('course-offerings', CourseOfferingController::class, 'courses.view', 'courses.manage');
    $resource('course-prerequisites', CoursePrerequisiteController::class, 'courses.view', 'courses.manage');
    $resource('program-courses', ProgramCourseController::class, 'courses.view', 'courses.manage');

    /*
    |--------------------------------------------------------------------------
    | Admission / Applicants Resources
    |--------------------------------------------------------------------------
    | طلبات القبول والمتقدمين وحالاتهم.
    |--------------------------------------------------------------------------
    */

    $resource('admission-applications', AdmissionApplicationController::class, 'admissions.view', 'admissions.manage', true);
    $resource('applicants', ApplicantController::class, 'admissions.view', 'admissions.manage', true);
    $resource(
        'document-types',
        DocumentTypeController::class,
        'admissions.view|students.view',
        'system_settings.manage',
        true
    );

    /*
    |--------------------------------------------------------------------------
    | Student Resources
    |--------------------------------------------------------------------------
    | كل الموارد المباشرة المتعلقة بالطالب.
    |--------------------------------------------------------------------------
    */

    $resource('students', StudentController::class, 'students.view', 'students.manage', true);
    $resource('student-academic-terms', StudentAcademicTermController::class, 'students.view', 'students.manage', true);
    $resource(
        'student-course-registrations',
        StudentCourseRegistrationController::class,
        'registration.view',
        'registration.manage',
        true
    );
    $resource('student-course-results', StudentCourseResultController::class, 'exams.view', 'exams.manage', true);
    $resource('student-credit-limits', StudentCreditLimitController::class, 'registration.view', 'registration.manage', true);
    Route::get('student-documents/{studentDocument}/download', [StudentDocumentController::class, 'download'])
        ->middleware('staff.permission:students.view');
    $resource('student-documents', StudentDocumentController::class, 'students.view', 'students.manage', true);
    $resource('student-grade-components', StudentGradeComponentController::class, 'exams.view', 'exams.manage', true);
    $resource(
        'student-statuses',
        StudentStatusController::class,
        'students.view',
        'system_settings.manage',
        true
    );

    /*
    |--------------------------------------------------------------------------
    | Registration / Status Lookup Resources
    |--------------------------------------------------------------------------
    | حالات التسجيل، النتائج، القبول، الاعتراض، الموافقة، والحسابات.
    |--------------------------------------------------------------------------
    */

    $resource(
        'registration-statuses',
        RegistrationStatusController::class,
        'registration.view',
        'system_settings.manage'
    );
    $resource('result-statuses', ResultStatusController::class, 'grades.view', 'system_settings.manage');
    $resource(
        'account-statuses',
        AccountStatusController::class,
        'users_permissions.view',
        'system_settings.manage',
        true
    );
    $resource('appeal-statuses', AppealStatusController::class, 'exams.view', 'system_settings.manage', true);
    $resource('approval-statuses', ApprovalStatusController::class, 'grades.view', 'system_settings.manage', true);
    $resource(
        'attendance-statuses',
        AttendanceStatusController::class,
        'attendance.view',
        'system_settings.manage'
    );

    /*
    |--------------------------------------------------------------------------
    | Grades / Appeals / Policies Resources
    |--------------------------------------------------------------------------
    | العلامات، مكونات العلامة، الاعتراضات، الاعتمادات، وسياسات العلامات.
    |--------------------------------------------------------------------------
    */

    $resource('grade-appeals', GradeAppealController::class, 'exams.view', 'exams.manage', true);
    $resource('grade-approvals', GradeApprovalController::class, 'exams.view', 'exams.manage', true);
    $readOnlyResource('grade-audit-logs', GradeAuditLogController::class, 'exams.view', true);
    $resource('grade-components', GradeComponentController::class, 'exams.view', 'exams.manage', true);
    $resource('grading-policies', GradingPolicyController::class, 'exams.view', 'exams.manage', true);

    /*
    |--------------------------------------------------------------------------
    | Supplementary Exams Resources
    |--------------------------------------------------------------------------
    | الدورات التكميلية ونتائجها.
    |--------------------------------------------------------------------------
    */

    $resource(
        'supplementary-exam-periods',
        SupplementaryExamPeriodController::class,
        'exams.view',
        'exams.manage',
        true
    );
    $resource(
        'supplementary-exam-results',
        SupplementaryExamResultController::class,
        'exams.view',
        'exams.manage',
        true
    );

    /*
    |--------------------------------------------------------------------------
    | Employees / Faculty / Organizational Structure Resources
    |--------------------------------------------------------------------------
    | الموظفون، أعضاء الهيئة، المناصب، والوحدات التنظيمية.
    |--------------------------------------------------------------------------
    */

    $resource('employees', EmployeeController::class, 'hr.view', 'hr.manage', true);
    $resource('employee-positions', EmployeePositionController::class, 'hr.view', 'hr.manage', true);
    $resource('employee-statuses', EmployeeStatusController::class, 'hr.view', 'hr.manage', true);
    $resource('employee-types', EmployeeTypeController::class, 'hr.view', 'hr.manage', true);
    $resource('employee-unit-assignments', EmployeeUnitAssignmentController::class, 'hr.view', 'hr.manage', true);
    $resource(
        'faculty-members',
        FacultyMemberController::class,
        'hr.view|courses.manage',
        'hr.manage',
        true
    );
    $resource(
        'organizational-units',
        OrganizationalUnitController::class,
        'organizational_structure.view|hr.view',
        'organizational_structure.manage',
        true
    );
    $resource(
        'organizational-unit-types',
        OrganizationalUnitTypeController::class,
        'organizational_structure.view|hr.view',
        'organizational_structure.manage',
        true
    );
    $resource('positions', PositionController::class, 'hr.view', 'hr.manage', true);

    /*
    |--------------------------------------------------------------------------
    | Board / Meetings Resources
    |--------------------------------------------------------------------------
    | المجالس، الاجتماعات، القرارات، الأعضاء، والمرفقات.
    |--------------------------------------------------------------------------
    */

    $resource('boards', BoardController::class, 'boards.view', 'boards.manage', true);
    $resource('board-decisions', BoardDecisionController::class, 'boards.view', 'boards.manage', true);
    $resource(
        'board-decision-attachments',
        BoardDecisionAttachmentController::class,
        'boards.view',
        'boards.manage',
        true
    );
    $resource('board-meetings', BoardMeetingController::class, 'boards.view', 'boards.manage', true);
    $resource('board-members', BoardMemberController::class, 'boards.view', 'boards.manage', true);
    $resource('meeting-attendees', MeetingAttendeeController::class, 'boards.view', 'boards.manage', true);

    /*
    |--------------------------------------------------------------------------
    | Library Resources
    |--------------------------------------------------------------------------
    | المكتبة، الكتب، النسخ، المؤلفون، التصنيفات، والاستعارات.
    |--------------------------------------------------------------------------
    */

    $resource('library-authors', LibraryAuthorController::class, 'library.view', 'library.manage');
    $resource('library-books', LibraryBookController::class, 'library.view', 'library.manage');
    $resource('library-book-authors', LibraryBookAuthorController::class, 'library.view', 'library.manage');
    $resource('library-book-copies', LibraryBookCopyController::class, 'library.view', 'library.manage');
    $resource('library-borrowings', LibraryBorrowingController::class, 'library.view', 'library.manage', true);
    $resource('library-categories', LibraryCategoryController::class, 'library.view', 'library.manage');

    /*
    |--------------------------------------------------------------------------
    | Users / Roles / Permissions Resources
    |--------------------------------------------------------------------------
    | المستخدمون، الأدوار، الصلاحيات، وربطها.
    |--------------------------------------------------------------------------
    */

    $resource('users', UserController::class, 'users_permissions.view', 'users_permissions.manage', true);
    $resource('roles', RoleController::class, 'users_permissions.view', 'users_permissions.manage', true);
    $resource('permissions', PermissionController::class, 'users_permissions.view', 'users_permissions.manage', true);
    $resource('user-roles', UserRoleController::class, 'users_permissions.view', 'users_permissions.manage', true);
    Route::apiResource('role-permissions', RolePermissionController::class)
        ->only(['index', 'show'])
        ->middleware('staff.permission:users_permissions.view');
    Route::apiResource('role-permissions', RolePermissionController::class)
        ->only(['store', 'destroy'])
        ->middleware('staff.permission:users_permissions.manage');

    /*
    |--------------------------------------------------------------------------
    | Security / Audit / System Resources
    |--------------------------------------------------------------------------
    | السجلات، الموديولات، وتوكنات إعادة التعيين.
    |--------------------------------------------------------------------------
    */

    $readOnlyResource(
        'login-audit-logs',
        LoginAuditLogController::class,
        'users_permissions.view',
        true
    );
    $readOnlyResource(
        'user-activity-logs',
        UserActivityLogController::class,
        'users_permissions.view',
        true
    );
    $resource(
        'system-modules',
        SystemModuleController::class,
        'users_permissions.view',
        'users_permissions.manage',
        true
    );

    // Password reset token hashes are intentionally not exposed through CRUD.
});
