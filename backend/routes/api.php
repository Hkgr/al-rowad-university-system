<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Services\UserIdentityService;

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
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseDepartmentController;
use App\Http\Controllers\Api\CourseInstructorController;
use App\Http\Controllers\Api\CourseOfferingController;
use App\Http\Controllers\Api\CourseOfferingInstructorController;
use App\Http\Controllers\Api\DeanCourseOfferingController;
use App\Http\Controllers\Api\DeanDashboardController;
use App\Http\Controllers\Api\DeanRegistrationOfferingController;
use App\Http\Controllers\Api\CoursePrerequisiteController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DisciplinaryCaseAppealController;
use App\Http\Controllers\Api\DisciplinaryCaseController;
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
use App\Http\Controllers\Api\GradeWorkflowController;
use App\Http\Controllers\Api\GradePartWorkflowController;
use App\Http\Controllers\Api\ProfessorCourseOfferingController;
use App\Http\Controllers\Api\GradePartApprovalController;
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
use App\Http\Controllers\Api\StudentSelfRegistrationController;
use App\Http\Controllers\Api\StudentSelfAttendanceController;
use App\Http\Controllers\Api\StudentSelfGpaController;
use App\Http\Controllers\Api\StudentSelfGraduationEligibilityController;
use App\Http\Controllers\Api\StudentSelfRequirementController;
use App\Http\Controllers\Api\StudentSelfTranscriptController;
use App\Http\Controllers\Api\AcademicAdvisingRegistrationRequestController;
use App\Http\Controllers\Api\AcademicAdvisingRegistrationWithdrawalController;
use App\Http\Controllers\Api\AcademicProgressionController;
use App\Http\Controllers\Api\AcademicRecordTermController;
use App\Http\Controllers\Api\ApprovedRegistrationRequestController;
use App\Http\Controllers\Api\GraduationDecisionController;
use App\Http\Controllers\Api\StudentAcademicTermController;
use App\Http\Controllers\Api\StudentCourseRegistrationController;
use App\Http\Controllers\Api\StudentCourseResultController;
use App\Http\Controllers\Api\StudentCreditLimitController;
use App\Http\Controllers\Api\StudentAffairsDashboardController;
use App\Http\Controllers\Api\StudentDocumentController;
use App\Http\Controllers\Api\StudentStatusController;
use App\Http\Controllers\Api\ScientificVicePresidentSupplementaryExamPeriodController;
use App\Http\Controllers\Api\SupplementaryExamPeriodController;
use App\Http\Controllers\Api\SystemModuleController;
use App\Http\Controllers\Api\DeanTeachingAssignmentController;
use App\Http\Controllers\Api\DeanCourseOfferingClosureController;
use App\Http\Controllers\Api\DeanCourseOfferingExceptionController;
use App\Http\Controllers\Api\TeachingStaffAssignmentOfferingController;
use App\Http\Controllers\Api\TeachingStaffController;
use App\Http\Controllers\Api\VicePresidencyTeachingAssignmentController;
use App\Http\Controllers\Api\VicePresidencyCourseOfferingClosureController;
use App\Http\Controllers\Api\VicePresidencyCourseOfferingExceptionController;
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

Route::post('login', [LoginController::class, 'login'])
    ->middleware('throttle:login');

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
| هذه الراوتات تحتاج Token عبر Laravel Sanctum.
| تستخدم لمعرفة المستخدم الحالي وتسجيل الخروج.
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureActiveAccount::class])->group(function (): void {
    Route::get('user', function (Request $request, UserIdentityService $identity) {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully',
            'data' => $identity->payload($request->user()),
        ]);
    });

    Route::post('logout', function (Request $request) {
        $token = $request->user()?->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

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

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureActiveAccount::class])->prefix('v1')->group(function (): void {
    Route::put('users/{user}/identity', [UserController::class, 'linkIdentity'])
        ->middleware(\App\Http\Middleware\RequirePermission::class.':users_permissions.manage');

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
        ->middleware(\App\Http\Middleware\RequirePermission::class.':students.view');

    /*
    |--------------------------------------------------------------------------
    | Student Profile / Student Dashboard
    |--------------------------------------------------------------------------
    | راوتات مهمة للوحة الطالب أو البحث عن الطلاب.
    |--------------------------------------------------------------------------
    */

    Route::get('students/deleted', [StudentController::class, 'deleted']);
    Route::post('students/{id}/restore', [StudentController::class, 'restore']);
    Route::delete('students/{id}/force', [StudentController::class, 'forceDestroy']);
    Route::get('students/search', [StudentController::class, 'search']);
    Route::get('students/{student}/available-courses', [StudentController::class, 'availableCourses']);
    Route::get('students/{student}/registered-hours', [StudentController::class, 'registeredHours']);
    Route::get('students/{student}/registration-summary', [StudentController::class, 'registrationSummary']);
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration.view')->group(function (): void {
        Route::get('student/registration', [StudentSelfRegistrationController::class, 'show']);
        Route::post('student/registration/request/items/{courseOffering}', [StudentSelfRegistrationController::class, 'addItem']);
        Route::delete('student/registration/request/items/{requestItem}', [StudentSelfRegistrationController::class, 'removeItem']);
        Route::patch('student/registration/request', [StudentSelfRegistrationController::class, 'updateRequest']);
        Route::post('student/registration/request/submit', [StudentSelfRegistrationController::class, 'submit']);
        Route::post('student/registration/{registration}/drop', [StudentSelfRegistrationController::class, 'drop']);
        Route::post('student/registration/{registration}/withdrawal', [StudentSelfRegistrationController::class, 'submitWithdrawal']);
        Route::post('student/registration/withdrawals/{withdrawalRequest}/resubmit', [StudentSelfRegistrationController::class, 'resubmitWithdrawal']);
        Route::get('student/registration/withdrawals', [StudentSelfRegistrationController::class, 'withdrawals']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration_requests.view')->group(function (): void {
        Route::get('academic-advising/registration-requests', [AcademicAdvisingRegistrationRequestController::class, 'index']);
        Route::get('academic-advising/registration-requests/{registrationRequest}', [AcademicAdvisingRegistrationRequestController::class, 'show']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration_requests.review')->group(function (): void {
        Route::post('academic-advising/registration-requests/{registrationRequest}/return', [AcademicAdvisingRegistrationRequestController::class, 'returnForModification']);
        Route::post('academic-advising/registration-requests/{registrationRequest}/approve', [AcademicAdvisingRegistrationRequestController::class, 'approve']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration_withdrawals.view')->group(function (): void {
        Route::get('academic-advising/registration-withdrawals', [AcademicAdvisingRegistrationWithdrawalController::class, 'index']);
        Route::get('academic-advising/registration-withdrawals/{withdrawalRequest}', [AcademicAdvisingRegistrationWithdrawalController::class, 'show']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration_withdrawals.review')->group(function (): void {
        Route::post('academic-advising/registration-withdrawals/{withdrawalRequest}/return', [AcademicAdvisingRegistrationWithdrawalController::class, 'returnForModification']);
        Route::post('academic-advising/registration-withdrawals/{withdrawalRequest}/approve', [AcademicAdvisingRegistrationWithdrawalController::class, 'approve']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration.view')->group(function (): void {
        Route::get('registration-requests/approved', [ApprovedRegistrationRequestController::class, 'index']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':grades.view')->group(function (): void {
        Route::get('student/transcript', [StudentSelfTranscriptController::class, 'show']);
        Route::get('student/gpa-overview', [StudentSelfGpaController::class, 'show']);
        Route::get('student/requirements', [StudentSelfRequirementController::class, 'show']);
        Route::get('student/graduation-eligibility', [StudentSelfGraduationEligibilityController::class, 'show']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':attendance.view')->group(function (): void {
        Route::get('student/attendance-overview', [StudentSelfAttendanceController::class, 'show']);
    });
    Route::get('students/{student}/profile', [StudentController::class, 'profile']);
    Route::get('students/{student}/academic-info', [StudentController::class, 'academicInfo']);
    Route::get('students/{student}/requirements', [StudentController::class, 'requirements']);
    Route::get('students/{student}/graduation-eligibility', [StudentController::class, 'graduationEligibility']);
    Route::get('students/{student}/documents', [StudentController::class, 'documents']);
    Route::post('students/{student}/documents', [StudentDocumentController::class, 'upload']);
    Route::get('students/{student}/registrations', [StudentController::class, 'registrations']);
    Route::get('students/{student}/transcript', [StudentController::class, 'transcript']);
    Route::get('students/{student}/gpa', [StudentController::class, 'gpa']);
    Route::get('students/{student}/cgpa', [StudentController::class, 'cgpa']);
    Route::get('students/{student}/attendance', [StudentController::class, 'attendance']);
    Route::get('students/{student}/absence-percentage', [StudentController::class, 'absencePercentage']);

    Route::middleware(\App\Http\Middleware\RequirePermission::class.':academic_records.view')->group(function (): void {
        Route::get('academic-records/students/{student}/terms', [AcademicRecordTermController::class, 'index']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':academic_records.finalize')->group(function (): void {
        Route::post('academic-records/students/{student}/terms/{academicYear}/{semester}/recalculate', [AcademicRecordTermController::class, 'recalculate']);
        Route::post('academic-records/students/{student}/terms/{academicYear}/{semester}/finalize', [AcademicRecordTermController::class, 'finalize']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':academic_progression.view')->group(function (): void {
        Route::get('academic-progression/students/{student}/evaluate', [AcademicProgressionController::class, 'evaluate']);
        Route::get('academic-progression', [AcademicProgressionController::class, 'index']);
        Route::get('academic-progression/{progressionDecision}', [AcademicProgressionController::class, 'show']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':academic_progression.review')->group(function (): void {
        Route::post('academic-progression/{student}/submit', [AcademicProgressionController::class, 'submit']);
        Route::post('academic-progression/{progressionDecision}/return', [AcademicProgressionController::class, 'returnForModification']);
        Route::post('academic-progression/{progressionDecision}/approve', [AcademicProgressionController::class, 'approve']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':graduation_decisions.view')->group(function (): void {
        Route::get('graduation-decisions', [GraduationDecisionController::class, 'index']);
        Route::get('graduation-decisions/{graduationDecision}', [GraduationDecisionController::class, 'show']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':graduation_decisions.review')->group(function (): void {
        Route::post('graduation-decisions/{student}/submit', [GraduationDecisionController::class, 'submit']);
        Route::post('graduation-decisions/{graduationDecision}/return', [GraduationDecisionController::class, 'returnForModification']);
        Route::post('graduation-decisions/{graduationDecision}/approve', [GraduationDecisionController::class, 'approve']);
    });

    /*
    |--------------------------------------------------------------------------
    | Academic Structure Relations
    |--------------------------------------------------------------------------
    | علاقات الكليات، الأقسام، البرامج، والخطط الدراسية.
    |--------------------------------------------------------------------------
    */

    Route::get('colleges/{college}/departments', [CollegeController::class, 'departments'])->middleware(\App\Http\Middleware\RequirePermission::class.':academic_structure.view');
    Route::get('departments/{department}/programs', [DepartmentController::class, 'programs'])->middleware(\App\Http\Middleware\RequirePermission::class.':academic_structure.view');
    Route::get('departments/{id}/statistics', [DepartmentController::class, 'statistics']);
    Route::get('programs/{academic_program}/students', [AcademicProgramController::class, 'students'])->middleware(\App\Http\Middleware\RequirePermission::class.':students.view');
    Route::get('programs/{academic_program}/courses', [AcademicProgramController::class, 'courses'])->middleware(\App\Http\Middleware\RequirePermission::class.':academic_structure.view');
    Route::get('programs/{id}/mandatory-courses', [AcademicProgramController::class, 'mandatoryCourses'])->middleware(\App\Http\Middleware\RequirePermission::class.':academic_structure.view');
    Route::get('programs/{id}/elective-courses', [AcademicProgramController::class, 'electiveCourses'])->middleware(\App\Http\Middleware\RequirePermission::class.':academic_structure.view');
    Route::get('programs/{id}/study-plan', [AcademicProgramController::class, 'studyPlan'])->middleware(\App\Http\Middleware\RequirePermission::class.':academic_structure.view');

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
    Route::get('courses/{id}/statistics', [CourseController::class, 'statistics']);

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
    Route::get('professor/course-offerings', [ProfessorCourseOfferingController::class, 'index']);
    Route::get('course-offerings/{offering}/grade-parts-workflow', [GradePartWorkflowController::class, 'show']);
    Route::put('registrations/{registration}/grade-parts/{part}', [GradePartWorkflowController::class, 'update']);
    Route::post('course-offerings/{offering}/grade-parts/submit-my-parts', [GradePartWorkflowController::class, 'submitMyParts']);
    Route::post('course-offerings/{offering}/grade-parts/{part}/submit', [GradePartWorkflowController::class, 'submit']);
    Route::get('course-offerings/{courseOffering}/grade-workflow', [GradeWorkflowController::class, 'show']);
    Route::post('course-offerings/{courseOffering}/submit-grades', [GradeWorkflowController::class, 'submit']);
    Route::get('course-offerings/{id}/results-summary', [CourseOfferingController::class, 'resultsSummary']);
    Route::get('course-offerings/{id}/attendance-sessions', [CourseOfferingController::class, 'attendanceSessions']);
    Route::post('course-offerings/{id}/attendance-sessions', [CourseOfferingController::class, 'storeAttendanceSession']);
    Route::get('course-offerings/{id}/deprived-students', [CourseOfferingController::class, 'deprivedStudents']);
    Route::post('course-offerings/{id}/apply-deprivation', [CourseOfferingController::class, 'applyDeprivation']);
    Route::get('course-offerings/by-program/{program_id}', [CourseOfferingController::class, 'byProgram']);
    Route::get('course-offerings/{courseOffering}/instructors', [CourseOfferingInstructorController::class, 'index']);
    Route::post('course-offerings/{courseOffering}/instructors', [CourseOfferingInstructorController::class, 'store']);
    Route::patch('course-offering-instructors/{courseOfferingInstructor}', [CourseOfferingInstructorController::class, 'update']);
    Route::delete('course-offering-instructors/{courseOfferingInstructor}', [CourseOfferingInstructorController::class, 'destroy']);

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

    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration.manage')->group(function (): void {
        Route::post('registrations/register-student', [RegistrationController::class, 'registerStudent']);
        Route::post('registrations/{id}/drop', [RegistrationController::class, 'drop']);
        Route::post('registrations/{id}/withdraw', [RegistrationController::class, 'withdraw']);
    });

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
    Route::apiResource('student-academic-terms', StudentAcademicTermController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::apiResource('student-course-registrations', StudentCourseRegistrationController::class)
        ->only(['index', 'show']);
    // Result mutations must go through GradeController/GradeService so registration
    // eligibility, section ownership, and result workflow rules cannot be bypassed.
    Route::apiResource('student-course-results', StudentCourseResultController::class)
        ->only(['index', 'show']);
    Route::apiResource('student-credit-limits', StudentCreditLimitController::class);
    Route::get('student-documents/{studentDocument}/download', [StudentDocumentController::class, 'download']);
    Route::apiResource('student-documents', StudentDocumentController::class);
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
    Route::get('grade-part-approvals', [GradePartApprovalController::class, 'index']);
    Route::get('grade-part-approvals/{approval}', [GradePartApprovalController::class, 'show']);
    Route::post('grade-part-approvals/{approval}/approve', [GradePartApprovalController::class, 'approve']);
    Route::post('grade-part-approvals/{approval}/return-for-correction', [GradePartApprovalController::class, 'returnForCorrection']);
    Route::post('grade-approvals/{gradeApproval}/approve', [GradeApprovalController::class, 'approve']);
    Route::post('grade-approvals/{gradeApproval}/return-for-correction', [GradeApprovalController::class, 'returnForCorrection']);
    Route::apiResource('grade-approvals', GradeApprovalController::class)->only(['index', 'show']);
    Route::apiResource('grade-audit-logs', GradeAuditLogController::class);
    Route::apiResource('grade-components', GradeComponentController::class);
    Route::apiResource('grading-policies', GradingPolicyController::class);
    Route::apiResource('disciplinary-cases', DisciplinaryCaseController::class)
        ->only(['index', 'show', 'store']);
    Route::apiResource('disciplinary-case-appeals', DisciplinaryCaseAppealController::class)
        ->only(['index', 'show', 'store']);
    Route::post('disciplinary-case-appeals/{id}/decide', [DisciplinaryCaseAppealController::class, 'decide']);
    Route::get('students/{student}/disciplinary-cases', [DisciplinaryCaseController::class, 'forStudent']);

    /*
    |--------------------------------------------------------------------------
    | Supplementary Exams Resources
    |--------------------------------------------------------------------------
    | الدورات التكميلية ونتائجها.
    |--------------------------------------------------------------------------
    */

    Route::get('supplementary-exam-periods', [SupplementaryExamPeriodController::class, 'index']);
    Route::get('supplementary-exam-periods/{period}', [SupplementaryExamPeriodController::class, 'show']);

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
    Route::get('faculty-members/me', [FacultyMemberController::class, 'me']);
    Route::apiResource('faculty-members', FacultyMemberController::class);
    Route::get('dean/dashboard', [DeanDashboardController::class, 'show'])
        ->middleware(\App\Http\Middleware\RequirePermission::class.':dashboards.view');
    Route::get('dean/course-offerings', [DeanCourseOfferingController::class, 'index']);
    Route::get('dean/course-offerings/{courseOffering}/students', [DeanCourseOfferingController::class, 'students']);
    Route::get('dean/course-offerings/{courseOffering}/sessions', [DeanCourseOfferingController::class, 'sessions']);
    Route::get('dean/course-offerings/{courseOffering}', [DeanCourseOfferingController::class, 'show']);
    Route::get('dean/registration-offerings', [DeanRegistrationOfferingController::class, 'index']);
    Route::post('dean/registration-offerings/open', [DeanRegistrationOfferingController::class, 'open']);
    Route::post('dean/registration-offerings/bulk-prepare', [DeanRegistrationOfferingController::class, 'bulkPrepare']);
    Route::post('dean/registration-offerings/{courseOffering}/open', [DeanRegistrationOfferingController::class, 'reopen']);
    Route::post('dean/registration-offerings/{courseOffering}/close', [DeanRegistrationOfferingController::class, 'close']);
    Route::get('dean/teaching-assignments', [DeanTeachingAssignmentController::class, 'index']);
    Route::post('dean/teaching-assignments', [DeanTeachingAssignmentController::class, 'store']);
    Route::post('dean/teaching-assignments/removals', [DeanTeachingAssignmentController::class, 'requestRemoval']);
    Route::post('dean/teaching-assignments/{teachingAssignmentRequest}/resubmit', [DeanTeachingAssignmentController::class, 'resubmit']);
    Route::post('dean/teaching-assignments/{teachingAssignmentRequest}/replace', [DeanTeachingAssignmentController::class, 'replace']);
    Route::post('dean/teaching-assignments/{teachingAssignmentRequest}/withdraw-removal', [DeanTeachingAssignmentController::class, 'withdrawRemoval']);
    Route::get('dean/course-offering-exceptions', [DeanCourseOfferingExceptionController::class, 'index']);
    Route::post('dean/course-offering-exceptions', [DeanCourseOfferingExceptionController::class, 'store']);
    Route::get('dean/course-offering-exceptions/{courseOfferingExceptionRequest}', [DeanCourseOfferingExceptionController::class, 'show']);
    Route::post('dean/course-offering-exceptions/{courseOfferingExceptionRequest}/resubmit', [DeanCourseOfferingExceptionController::class, 'resubmit']);
    Route::get('dean/course-offering-closures', [DeanCourseOfferingClosureController::class, 'index']);
    Route::post('dean/course-offering-closures', [DeanCourseOfferingClosureController::class, 'store']);
    Route::get('dean/course-offering-closures/{courseOfferingClosureRequest}', [DeanCourseOfferingClosureController::class, 'show']);
    Route::post('dean/course-offering-closures/{courseOfferingClosureRequest}/resubmit', [DeanCourseOfferingClosureController::class, 'resubmit']);
    Route::get('vice-presidency/teaching-assignments', [VicePresidencyTeachingAssignmentController::class, 'index']);
    Route::get('vice-presidency/teaching-assignments/{teachingAssignmentRequest}', [VicePresidencyTeachingAssignmentController::class, 'show']);
    Route::post('vice-presidency/teaching-assignments/{teachingAssignmentRequest}/scientific/approve', [VicePresidencyTeachingAssignmentController::class, 'approveScientific']);
    Route::post('vice-presidency/teaching-assignments/{teachingAssignmentRequest}/scientific/return', [VicePresidencyTeachingAssignmentController::class, 'returnScientific']);
    Route::post('vice-presidency/teaching-assignments/{teachingAssignmentRequest}/administrative/approve', [VicePresidencyTeachingAssignmentController::class, 'approveAdministrative']);
    Route::post('vice-presidency/teaching-assignments/{teachingAssignmentRequest}/administrative/return', [VicePresidencyTeachingAssignmentController::class, 'returnAdministrative']);
    Route::get('vice-presidency/course-offering-exceptions', [VicePresidencyCourseOfferingExceptionController::class, 'index']);
    Route::get('vice-presidency/course-offering-exceptions/{courseOfferingExceptionRequest}', [VicePresidencyCourseOfferingExceptionController::class, 'show']);
    Route::post('vice-presidency/course-offering-exceptions/{courseOfferingExceptionRequest}/scientific/approve', [VicePresidencyCourseOfferingExceptionController::class, 'approveScientific']);
    Route::post('vice-presidency/course-offering-exceptions/{courseOfferingExceptionRequest}/scientific/return', [VicePresidencyCourseOfferingExceptionController::class, 'returnScientific']);
    Route::post('vice-presidency/course-offering-exceptions/{courseOfferingExceptionRequest}/administrative/approve', [VicePresidencyCourseOfferingExceptionController::class, 'approveAdministrative']);
    Route::post('vice-presidency/course-offering-exceptions/{courseOfferingExceptionRequest}/administrative/return', [VicePresidencyCourseOfferingExceptionController::class, 'returnAdministrative']);
    Route::get('vice-presidency/course-offering-closures', [VicePresidencyCourseOfferingClosureController::class, 'index']);
    Route::get('vice-presidency/course-offering-closures/{courseOfferingClosureRequest}', [VicePresidencyCourseOfferingClosureController::class, 'show']);
    Route::post('vice-presidency/course-offering-closures/{courseOfferingClosureRequest}/scientific/approve', [VicePresidencyCourseOfferingClosureController::class, 'approveScientific']);
    Route::post('vice-presidency/course-offering-closures/{courseOfferingClosureRequest}/scientific/return', [VicePresidencyCourseOfferingClosureController::class, 'returnScientific']);
    Route::post('vice-presidency/course-offering-closures/{courseOfferingClosureRequest}/administrative/approve', [VicePresidencyCourseOfferingClosureController::class, 'approveAdministrative']);
    Route::post('vice-presidency/course-offering-closures/{courseOfferingClosureRequest}/administrative/return', [VicePresidencyCourseOfferingClosureController::class, 'returnAdministrative']);
    Route::get('vice-presidency/scientific/supplementary-exam-periods', [ScientificVicePresidentSupplementaryExamPeriodController::class, 'index']);
    Route::get('vice-presidency/scientific/supplementary-exam-periods/{period}', [ScientificVicePresidentSupplementaryExamPeriodController::class, 'show']);
    Route::post('vice-presidency/scientific/supplementary-exam-periods', [ScientificVicePresidentSupplementaryExamPeriodController::class, 'store']);
    Route::get('teaching-staff', [TeachingStaffController::class, 'index']);
    Route::get('teaching-staff/assignment-instructors', [TeachingStaffAssignmentOfferingController::class, 'instructors']);
    Route::get('teaching-staff/assignment-offerings', [TeachingStaffAssignmentOfferingController::class, 'index']);
    Route::get('teaching-staff/assignment-offerings/{courseOffering}', [TeachingStaffAssignmentOfferingController::class, 'show']);
    Route::put('teaching-staff/assignment-offerings/{courseOffering}/slots', [TeachingStaffAssignmentOfferingController::class, 'updateSlots']);
    Route::get('teaching-staff/{facultyMember}/assignments', [TeachingStaffController::class, 'assignments']);
    Route::get('teaching-staff/{facultyMember}/sessions', [TeachingStaffController::class, 'sessions']);
    Route::get('teaching-staff/{facultyMember}', [TeachingStaffController::class, 'show']);
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

    Route::apiResource('login-audit-logs', LoginAuditLogController::class)
        ->only(['index', 'show'])
        ->middleware(\App\Http\Middleware\RequireSystemAdministrator::class);
    Route::apiResource('user-activity-logs', UserActivityLogController::class);
    Route::apiResource('system-modules', SystemModuleController::class);
    Route::apiResource('password-reset-tokens', PasswordResetTokenController::class)
        ->only(['index', 'show'])
        ->middleware(\App\Http\Middleware\RequireSystemAdministrator::class);
});
