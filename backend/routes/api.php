<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AcademicLevelController;
use App\Http\Controllers\Api\AcademicProgramController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AccountStatusController;
use App\Http\Controllers\Api\AdmissionApplicationController;
use App\Http\Controllers\Api\AppealStatusController;
use App\Http\Controllers\Api\ApplicantController;
use App\Http\Controllers\Api\ApprovalStatusController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceSessionController;
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
use App\Http\Controllers\Api\PasswordResetTokenController;
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
use App\Http\Controllers\Api\StudentAttendanceController;
use App\Http\Controllers\Api\StudentCourseRegistrationController;
use App\Http\Controllers\Api\StudentCourseResultController;
use App\Http\Controllers\Api\StudentCreditLimitController;
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

Route::post('login', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $user = User::query()->where('email', $validated['email'])->first();

    if (! $user || ! Hash::check($validated['password'], $user->password_hash)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid email or password',
            'errors' => ['email' => ['The provided credentials are incorrect.']],
        ], 422);
    }

    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ],
    ]);
});

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
| هذه الراوتات تحتاج Token عبر Laravel Sanctum.
| تستخدم لمعرفة المستخدم الحالي وتسجيل الخروج.
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('user', function (Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully',
            'data' => $request->user(),
        ]);
    });

    Route::post('logout', function (Request $request) {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => [],
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| API Version 1 Routes
|--------------------------------------------------------------------------
| كل الراوتات التالية محمية بـ auth:sanctum.
| المسار الكامل يبدأ بـ /api/v1/...
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {

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
    | Student Profile / Student Dashboard
    |--------------------------------------------------------------------------
    | راوتات مهمة للوحة الطالب أو البحث عن الطلاب.
    |--------------------------------------------------------------------------
    */

    Route::get('students/search', [StudentController::class, 'search']);
    Route::get('students/{student}/available-courses', [StudentController::class, 'availableCourses']);
    Route::get('students/{student}/registered-hours', [StudentController::class, 'registeredHours']);
    Route::get('students/{student}/registration-summary', [StudentController::class, 'registrationSummary']);
    Route::get('students/{student}/profile', [StudentController::class, 'profile']);
    Route::get('students/{student}/academic-info', [StudentController::class, 'academicInfo']);
    Route::get('students/{student}/documents', [StudentController::class, 'documents']);
    Route::get('students/{student}/registrations', [StudentController::class, 'registrations']);
    Route::get('students/{student}/transcript', [StudentController::class, 'transcript']);
    Route::get('students/{student}/gpa', [StudentController::class, 'gpa']);
    Route::get('students/{student}/cgpa', [StudentController::class, 'cgpa']);
    Route::get('students/{student}/attendance', [StudentController::class, 'attendance']);
    Route::get('students/{student}/absence-percentage', [StudentController::class, 'absencePercentage']);

    /*
    |--------------------------------------------------------------------------
    | Academic Structure Relations
    |--------------------------------------------------------------------------
    | علاقات الكليات، الأقسام، البرامج، والخطط الدراسية.
    |--------------------------------------------------------------------------
    */

    Route::get('colleges/{college}/departments', [CollegeController::class, 'departments']);
    Route::get('departments/{department}/programs', [DepartmentController::class, 'programs']);
    Route::get('programs/{academic_program}/students', [AcademicProgramController::class, 'students']);
    Route::get('programs/{academic_program}/courses', [AcademicProgramController::class, 'courses']);
    Route::get('programs/{id}/mandatory-courses', [AcademicProgramController::class, 'mandatoryCourses']);
    Route::get('programs/{id}/elective-courses', [AcademicProgramController::class, 'electiveCourses']);
    Route::get('programs/{id}/study-plan', [AcademicProgramController::class, 'studyPlan']);

    /*
    |--------------------------------------------------------------------------
    | Course Relations
    |--------------------------------------------------------------------------
    | علاقات المقررات مع الأقسام، البرامج، المتطلبات، والمدرسين.
    |--------------------------------------------------------------------------
    */

    Route::get('courses/{id}/departments', [CourseController::class, 'departments']);
    Route::get('courses/{id}/programs', [CourseController::class, 'programs']);
    Route::get('courses/{id}/prerequisites', [CourseController::class, 'prerequisites']);
    Route::get('courses/{id}/instructors', [CourseController::class, 'instructors']);

    /*
    |--------------------------------------------------------------------------
    | Course Offerings / Sections / Attendance
    |--------------------------------------------------------------------------
    | الشعب المفتوحة، السعة، الطلاب، الحضور، والحرمان.
    |--------------------------------------------------------------------------
    */

    Route::get('course-offerings/open', [CourseOfferingController::class, 'open']);
    Route::get('course-offerings/{id}/details', [CourseOfferingController::class, 'details']);
    Route::get('course-offerings/{id}/students', [CourseOfferingController::class, 'students']);
    Route::get('course-offerings/{id}/capacity', [CourseOfferingController::class, 'capacity']);
    Route::get('course-offerings/by-semester', [CourseOfferingController::class, 'bySemester']);
    Route::get('course-offerings/{id}/grade-sheet', [CourseOfferingController::class, 'gradeSheet']);
    Route::get('course-offerings/{id}/results-summary', [CourseOfferingController::class, 'resultsSummary']);
    Route::get('course-offerings/{id}/attendance-sessions', [CourseOfferingController::class, 'attendanceSessions']);
    Route::post('course-offerings/{id}/attendance-sessions', [CourseOfferingController::class, 'storeAttendanceSession']);
    Route::get('course-offerings/{id}/deprived-students', [CourseOfferingController::class, 'deprivedStudents']);
    Route::post('course-offerings/{id}/apply-deprivation', [CourseOfferingController::class, 'applyDeprivation']);
    Route::get('course-offerings/by-program/{program_id}', [CourseOfferingController::class, 'byProgram']);

    /*
    |--------------------------------------------------------------------------
    | Attendance Operations
    |--------------------------------------------------------------------------
    | تسجيل حضور الطلاب ضمن جلسات الحضور.
    |--------------------------------------------------------------------------
    */

    Route::get('attendance-sessions/{id}/students', [AttendanceController::class, 'sessionStudents']);
    Route::post('attendance-sessions/{id}/record', [AttendanceController::class, 'record']);

    /*
    |--------------------------------------------------------------------------
    | Grades / Results Operations
    |--------------------------------------------------------------------------
    | إدخال العلامات، تعديلها، وحساب النتيجة.
    |--------------------------------------------------------------------------
    */

    Route::get('registrations/{id}/grades', [GradeController::class, 'show']);
    Route::post('registrations/{id}/grades', [GradeController::class, 'store']);
    Route::put('registrations/{id}/grades', [GradeController::class, 'update']);
    Route::post('registrations/{id}/calculate-result', [GradeController::class, 'calculateResult']);

    /*
    |--------------------------------------------------------------------------
    | Course Registration Operations
    |--------------------------------------------------------------------------
    | تسجيل، إسقاط، وانسحاب الطالب من المقررات.
    |--------------------------------------------------------------------------
    */

    Route::post('registrations/register-student', [RegistrationController::class, 'registerStudent']);
    Route::post('registrations/{id}/drop', [RegistrationController::class, 'drop']);
    Route::post('registrations/{id}/withdraw', [RegistrationController::class, 'withdraw']);

    /*
    |--------------------------------------------------------------------------
    | Academic Setup Resources
    |--------------------------------------------------------------------------
    | موارد الإعداد الأكاديمي الأساسية.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('academic-levels', AcademicLevelController::class);
    Route::apiResource('academic-programs', AcademicProgramController::class);
    Route::apiResource('academic-years', AcademicYearController::class);
    Route::apiResource('semesters', SemesterController::class);
    Route::apiResource('colleges', CollegeController::class);
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('courses', CourseController::class);
    Route::apiResource('course-departments', CourseDepartmentController::class);
    Route::apiResource('course-instructors', CourseInstructorController::class);
    Route::apiResource('course-offerings', CourseOfferingController::class);
    Route::apiResource('course-prerequisites', CoursePrerequisiteController::class);
    Route::apiResource('program-courses', ProgramCourseController::class);

    /*
    |--------------------------------------------------------------------------
    | Admission / Applicants Resources
    |--------------------------------------------------------------------------
    | طلبات القبول والمتقدمين وحالاتهم.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('admission-applications', AdmissionApplicationController::class);
    Route::apiResource('applicants', ApplicantController::class);
    Route::apiResource('document-types', DocumentTypeController::class);

    /*
    |--------------------------------------------------------------------------
    | Student Resources
    |--------------------------------------------------------------------------
    | كل الموارد المباشرة المتعلقة بالطالب.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('students', StudentController::class);
    Route::apiResource('student-academic-terms', StudentAcademicTermController::class);
    Route::apiResource('student-attendance', StudentAttendanceController::class);
    Route::apiResource('student-course-registrations', StudentCourseRegistrationController::class);
    Route::apiResource('student-course-results', StudentCourseResultController::class);
    Route::apiResource('student-credit-limits', StudentCreditLimitController::class);
    Route::apiResource('student-documents', StudentDocumentController::class);
    Route::apiResource('student-grade-components', StudentGradeComponentController::class);
    Route::apiResource('student-statuses', StudentStatusController::class);

    /*
    |--------------------------------------------------------------------------
    | Registration / Status Lookup Resources
    |--------------------------------------------------------------------------
    | حالات التسجيل، النتائج، القبول، الاعتراض، الموافقة، والحسابات.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('registration-statuses', RegistrationStatusController::class);
    Route::apiResource('result-statuses', ResultStatusController::class);
    Route::apiResource('account-statuses', AccountStatusController::class);
    Route::apiResource('appeal-statuses', AppealStatusController::class);
    Route::apiResource('approval-statuses', ApprovalStatusController::class);
    Route::apiResource('attendance-statuses', AttendanceStatusController::class);

    /*
    |--------------------------------------------------------------------------
    | Grades / Appeals / Policies Resources
    |--------------------------------------------------------------------------
    | العلامات، مكونات العلامة، الاعتراضات، الاعتمادات، وسياسات العلامات.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('grade-appeals', GradeAppealController::class);
    Route::apiResource('grade-approvals', GradeApprovalController::class);
    Route::apiResource('grade-audit-logs', GradeAuditLogController::class);
    Route::apiResource('grade-components', GradeComponentController::class);
    Route::apiResource('grading-policies', GradingPolicyController::class);

    /*
    |--------------------------------------------------------------------------
    | Supplementary Exams Resources
    |--------------------------------------------------------------------------
    | الدورات التكميلية ونتائجها.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('supplementary-exam-periods', SupplementaryExamPeriodController::class);
    Route::apiResource('supplementary-exam-results', SupplementaryExamResultController::class);

    /*
    |--------------------------------------------------------------------------
    | Employees / Faculty / Organizational Structure Resources
    |--------------------------------------------------------------------------
    | الموظفون، أعضاء الهيئة، المناصب، والوحدات التنظيمية.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('employees', EmployeeController::class);
    Route::apiResource('employee-positions', EmployeePositionController::class);
    Route::apiResource('employee-statuses', EmployeeStatusController::class);
    Route::apiResource('employee-types', EmployeeTypeController::class);
    Route::apiResource('employee-unit-assignments', EmployeeUnitAssignmentController::class);
    Route::apiResource('faculty-members', FacultyMemberController::class);
    Route::apiResource('organizational-units', OrganizationalUnitController::class);
    Route::apiResource('organizational-unit-types', OrganizationalUnitTypeController::class);
    Route::apiResource('positions', PositionController::class);

    /*
    |--------------------------------------------------------------------------
    | Board / Meetings Resources
    |--------------------------------------------------------------------------
    | المجالس، الاجتماعات، القرارات، الأعضاء، والمرفقات.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('boards', BoardController::class);
    Route::apiResource('board-decisions', BoardDecisionController::class);
    Route::apiResource('board-decision-attachments', BoardDecisionAttachmentController::class);
    Route::apiResource('board-meetings', BoardMeetingController::class);
    Route::apiResource('board-members', BoardMemberController::class);
    Route::apiResource('meeting-attendees', MeetingAttendeeController::class);

    /*
    |--------------------------------------------------------------------------
    | Library Resources
    |--------------------------------------------------------------------------
    | المكتبة، الكتب، النسخ، المؤلفون، التصنيفات، والاستعارات.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('library-authors', LibraryAuthorController::class);
    Route::apiResource('library-books', LibraryBookController::class);
    Route::apiResource('library-book-authors', LibraryBookAuthorController::class);
    Route::apiResource('library-book-copies', LibraryBookCopyController::class);
    Route::apiResource('library-borrowings', LibraryBorrowingController::class);
    Route::apiResource('library-categories', LibraryCategoryController::class);

    /*
    |--------------------------------------------------------------------------
    | Users / Roles / Permissions Resources
    |--------------------------------------------------------------------------
    | المستخدمون، الأدوار، الصلاحيات، وربطها.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('permissions', PermissionController::class);
    Route::apiResource('user-roles', UserRoleController::class);
    Route::apiResource('role-permissions', RolePermissionController::class);

    /*
    |--------------------------------------------------------------------------
    | Security / Audit / System Resources
    |--------------------------------------------------------------------------
    | السجلات، الموديولات، وتوكنات إعادة التعيين.
    |--------------------------------------------------------------------------
    */

    Route::apiResource('login-audit-logs', LoginAuditLogController::class);
    Route::apiResource('user-activity-logs', UserActivityLogController::class);
    Route::apiResource('system-modules', SystemModuleController::class);
    Route::apiResource('password-reset-tokens', PasswordResetTokenController::class);
});
