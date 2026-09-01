<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Services\UserIdentityService;

use App\Http\Controllers\Api\AcademicLevelController;
use App\Http\Controllers\Api\AcademicProgramController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AcademicCalendarController;
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
use App\Http\Controllers\Api\DeanSupplementaryExamOfferingController;
use App\Http\Controllers\Api\StudentSupplementaryExamRegistrationController;
use App\Http\Controllers\Api\SupplementaryExamRegistrationOfficeController;
use App\Http\Controllers\Api\SupplementaryExamOverviewController;
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
use App\Http\Controllers\Api\ExamStudentAcademicRecordController;
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
use App\Http\Controllers\Api\AcademicAdvisingRegistrationModificationController;
use App\Http\Controllers\Api\AcademicAdvisingRegistrationWithdrawalController;
use App\Http\Controllers\Api\AcademicProgressionController;
use App\Http\Controllers\Api\AcademicRecordTermController;
use App\Http\Controllers\Api\ApprovedRegistrationRequestController;
use App\Http\Controllers\Api\StudentRegistrationModificationController;
use App\Http\Controllers\Api\GraduationDecisionController;
use App\Http\Controllers\Api\StudentAcademicTermController;
use App\Http\Controllers\Api\StudentCourseRegistrationController;
use App\Http\Controllers\Api\StudentCourseResultController;
use App\Http\Controllers\Api\StudentCreditLimitController;
use App\Http\Controllers\Api\StudentAffairsDashboardController;
use App\Http\Controllers\Api\StudentDocumentController;
use App\Http\Controllers\Api\StudentStatusController;
use App\Http\Controllers\Api\ScientificVicePresidentSupplementaryExamPeriodController;
use App\Http\Controllers\Api\ScientificVicePresidentAcademicCalendarController;
use App\Http\Controllers\Api\ScientificSemesterOfferingController;
use App\Http\Controllers\Api\DeanMinimumEnrollmentController;
use App\Http\Controllers\Api\ScientificMinimumEnrollmentController;
use App\Http\Controllers\Api\StudentRegistrationReplacementController;
use App\Http\Controllers\Api\AcademicAdvisingRegistrationReplacementController;
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
use App\Http\Controllers\Api\MinistryPlacementController;
use App\Http\Controllers\Api\MinistryPlacementApplicantConversionController;
use App\Http\Controllers\Api\MinistryPlacementStudentEnrollmentController;
use App\Http\Controllers\Api\MinistryPlacementReconciliationController;
use App\Http\Controllers\Api\UserRoleController;
use App\Http\Controllers\Api\StudentSupplementaryExamController;
use App\Http\Controllers\Api\SupplementaryExamEligibilityController;
use App\Http\Controllers\Api\SupplementaryExamGradingController;
use App\Http\Controllers\Api\SupplementaryExamMaterializationController;
use App\Http\Controllers\Api\SupplementaryExamReconciliationController;

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
    Route::get('student/supplementary-exams/eligibility', [StudentSupplementaryExamController::class, 'eligibility']);
    Route::get('student/supplementary-exams/deferrals', [StudentSupplementaryExamController::class, 'deferrals']);
    Route::post('student/supplementary-exams/deferrals', [StudentSupplementaryExamController::class, 'declare']);
    Route::post('student/supplementary-exams/deferrals/{deferral}/cancel', [StudentSupplementaryExamController::class, 'cancel']);
    Route::get('supplementary-exam-eligibility', [SupplementaryExamEligibilityController::class, 'index']);
    Route::get('student/supplementary-exams/registrations', [StudentSupplementaryExamRegistrationController::class, 'index']);
    Route::post('student/supplementary-exams/registrations', [StudentSupplementaryExamRegistrationController::class, 'store']);
    Route::post('student/supplementary-exams/registrations/{registration}/cancel', [StudentSupplementaryExamRegistrationController::class, 'cancel']);
    Route::post('registration-office/supplementary-exam-periods/{period}/open-registration', [SupplementaryExamRegistrationOfficeController::class, 'open']);
    Route::get('supplementary-exam-registration-periods', [SupplementaryExamRegistrationOfficeController::class, 'periods']);
    Route::post('registration-office/supplementary-exam-periods/{period}/close-registration', [SupplementaryExamRegistrationOfficeController::class, 'close']);
    Route::get('registration-office/supplementary-exam-periods/{period}/registrations', [SupplementaryExamRegistrationOfficeController::class, 'index']);
    Route::get('supplementary-exam-periods/{period}/registrations', [SupplementaryExamRegistrationOfficeController::class, 'index']);
    Route::post('registration-office/supplementary-exam-registrations', [SupplementaryExamRegistrationOfficeController::class, 'store']);
    Route::post('registration-office/supplementary-exam-registrations/{registration}/cancel', [SupplementaryExamRegistrationOfficeController::class, 'cancel']);
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
    Route::get('academic-calendar/catalog', [AcademicCalendarController::class, 'catalog']);
    Route::get('academic-calendar/events', [AcademicCalendarController::class, 'events']);

    Route::prefix('vice-presidency/scientific/academic-calendar')->group(function (): void {
        Route::get('catalog', [ScientificVicePresidentAcademicCalendarController::class, 'catalog']);
        Route::get('events', [ScientificVicePresidentAcademicCalendarController::class, 'index']);
        Route::post('events', [ScientificVicePresidentAcademicCalendarController::class, 'store']);
        Route::put('events/{event}/drafts/{version}', [ScientificVicePresidentAcademicCalendarController::class, 'updateDraft']);
        Route::post('events/{event}/replacement-drafts', [ScientificVicePresidentAcademicCalendarController::class, 'replacementDraft']);
        Route::post('events/{event}/drafts/{version}/publish', [ScientificVicePresidentAcademicCalendarController::class, 'publish']);
        Route::delete('events/{event}/drafts/{version}', [ScientificVicePresidentAcademicCalendarController::class, 'destroyDraft']);
        Route::post('events/{event}/cancel', [ScientificVicePresidentAcademicCalendarController::class, 'cancel']);
        Route::get('events/{event}/history', [ScientificVicePresidentAcademicCalendarController::class, 'history']);
        Route::post('academic-years/{year}/activate', [ScientificVicePresidentAcademicCalendarController::class, 'activateYear']);
        Route::post('academic-years/{year}/reopen', [ScientificVicePresidentAcademicCalendarController::class, 'reopenYear']);
        Route::post('academic-years/{year}/close', [ScientificVicePresidentAcademicCalendarController::class, 'closeYear']);
    });

    /*
    |--------------------------------------------------------------------------
    | Student Affairs Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('student-affairs/dashboard-stats', [StudentAffairsDashboardController::class, 'dashboardStats'])
        ->middleware(\App\Http\Middleware\RequirePermission::class.':students.view');

    Route::post('ministry-placements/preview', [MinistryPlacementController::class, 'preview']);
    Route::post('ministry-placements/import', [MinistryPlacementController::class, 'import']);
    Route::get('ministry-placement-academic-years', [MinistryPlacementController::class, 'academicYears']);
    Route::get('ministry-placement-programs', [MinistryPlacementController::class, 'programs']);
    Route::get('ministry-placements', [MinistryPlacementController::class, 'index']);
    Route::get('ministry-placements/{batch}', [MinistryPlacementController::class, 'show'])->whereNumber('batch');
    Route::get('ministry-placements/{batch}/records', [MinistryPlacementController::class, 'records'])->whereNumber('batch');
    Route::get('ministry-placements/{batch}/program-matching', [MinistryPlacementController::class, 'programMatching'])->whereNumber('batch');
    Route::post('ministry-placements/{batch}/program-matching/apply-group', [MinistryPlacementController::class, 'applyProgramGroup'])->whereNumber('batch');
    Route::put('ministry-placement-records/{record}/program-match', [MinistryPlacementController::class, 'matchProgram'])->whereNumber('record');
    Route::delete('ministry-placement-records/{record}/program-match', [MinistryPlacementController::class, 'unmatchProgram'])->whereNumber('record');
    Route::get('ministry-placements/{batch}/applicant-conversion', [MinistryPlacementApplicantConversionController::class, 'summary'])->whereNumber('batch');
    Route::post('ministry-placement-records/{record}/convert-to-applicant', [MinistryPlacementApplicantConversionController::class, 'convert'])->whereNumber('record');
    Route::post('ministry-placements/{batch}/applicant-conversion/convert-all', [MinistryPlacementApplicantConversionController::class, 'convertAll'])->whereNumber('batch');
    Route::get('ministry-placement-academic-levels', [MinistryPlacementStudentEnrollmentController::class, 'academicLevels']);
    Route::get('ministry-placements/{batch}/student-enrollment', [MinistryPlacementStudentEnrollmentController::class, 'summary'])->whereNumber('batch');
    Route::post('ministry-placement-records/{record}/enroll-student', [MinistryPlacementStudentEnrollmentController::class, 'enroll'])->whereNumber('record');
    Route::post('ministry-placements/{batch}/student-enrollment/enroll-all', [MinistryPlacementStudentEnrollmentController::class, 'enrollAll'])->whereNumber('batch');
    Route::get('ministry-placement-reconciliation', [MinistryPlacementReconciliationController::class, 'index']);
    Route::get('ministry-placements/{batch}/reconciliation', [MinistryPlacementReconciliationController::class, 'batch'])->whereNumber('batch');

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
        Route::post('student/registration/modification', [StudentRegistrationModificationController::class, 'store']);
        Route::patch('student/registration/modification', [StudentRegistrationModificationController::class, 'update']);
        Route::patch('student/registration/modification/items/{modificationItem}', [StudentRegistrationModificationController::class, 'updateItem']);
        Route::post('student/registration/modification/items/{courseOffering}', [StudentRegistrationModificationController::class, 'addItem']);
        Route::delete('student/registration/modification/items/{modificationItem}', [StudentRegistrationModificationController::class, 'removeItem']);
        Route::post('student/registration/modification/submit', [StudentRegistrationModificationController::class, 'submit']);
        Route::post('student/registration/replacement', [StudentRegistrationReplacementController::class, 'store']);
        Route::patch('student/registration/replacement', [StudentRegistrationReplacementController::class, 'update']);
        Route::post('student/registration/replacement/items', [StudentRegistrationReplacementController::class, 'addItem']);
        Route::patch('student/registration/replacement/items/{replacementItem}', [StudentRegistrationReplacementController::class, 'updateItem']);
        Route::delete('student/registration/replacement/items/{replacementItem}', [StudentRegistrationReplacementController::class, 'removeItem']);
        Route::post('student/registration/replacement/submit', [StudentRegistrationReplacementController::class, 'submit']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration_requests.view')->group(function (): void {
        Route::get('academic-advising/registration-requests', [AcademicAdvisingRegistrationRequestController::class, 'index']);
        Route::get('academic-advising/registration-requests/{registrationRequest}', [AcademicAdvisingRegistrationRequestController::class, 'show']);
        Route::get('academic-advising/registration-modifications', [AcademicAdvisingRegistrationModificationController::class, 'index']);
        Route::get('academic-advising/registration-modifications/{modification}', [AcademicAdvisingRegistrationModificationController::class, 'show']);
    });
    Route::middleware(\App\Http\Middleware\RequirePermission::class.':registration_requests.review')->group(function (): void {
        Route::post('academic-advising/registration-requests/{registrationRequest}/return', [AcademicAdvisingRegistrationRequestController::class, 'returnForModification']);
        Route::post('academic-advising/registration-requests/{registrationRequest}/approve', [AcademicAdvisingRegistrationRequestController::class, 'approve']);
        Route::post('academic-advising/registration-modifications/{modification}/return', [AcademicAdvisingRegistrationModificationController::class, 'returnForModification']);
        Route::post('academic-advising/registration-modifications/{modification}/approve', [AcademicAdvisingRegistrationModificationController::class, 'approve']);
        Route::get('academic-advising/registration-replacements', [AcademicAdvisingRegistrationReplacementController::class, 'index']);
        Route::get('academic-advising/registration-replacements/{replacement}', [AcademicAdvisingRegistrationReplacementController::class, 'show']);
        Route::post('academic-advising/registration-replacements/{replacement}/return', [AcademicAdvisingRegistrationReplacementController::class, 'returnForModification']);
        Route::post('academic-advising/registration-replacements/{replacement}/approve', [AcademicAdvisingRegistrationReplacementController::class, 'approve']);
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
    Route::get('students/{student}/academic-record', [ExamStudentAcademicRecordController::class, 'show']);
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
    Route::get('professor/supplementary-exams', [SupplementaryExamGradingController::class, 'professorIndex']);
    Route::get('professor/supplementary-exams/{offering}/grades', [SupplementaryExamGradingController::class, 'professorGrades']);
    Route::put('professor/supplementary-exams/{offering}/grades', [SupplementaryExamGradingController::class, 'save']);
    Route::post('professor/supplementary-exams/{offering}/submit', [SupplementaryExamGradingController::class, 'submit']);
    Route::post('professor/supplementary-exams/{offering}/resubmit', [SupplementaryExamGradingController::class, 'resubmit']);
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
    Route::get('exams/supplementary-grades', [SupplementaryExamGradingController::class, 'queue']);
    Route::get('exams/supplementary-overview', SupplementaryExamOverviewController::class);
    Route::post('exams/supplementary-grades/{submission}/return', [SupplementaryExamGradingController::class, 'return']);
    Route::post('exams/supplementary-grades/{submission}/approve', [SupplementaryExamGradingController::class, 'approve']);
    Route::post('exams/supplementary-grades/{submission}/publish', [SupplementaryExamGradingController::class, 'publish']);
    Route::post('exams/supplementary-offerings/{offering}/materialize', [SupplementaryExamMaterializationController::class, 'store']);
    Route::get('exams/supplementary-periods/{period}/reconciliation', [SupplementaryExamReconciliationController::class, 'show']);
    Route::get('exams/supplementary-offerings/{offering}/graders', [SupplementaryExamGradingController::class, 'graders']);
    Route::post('exams/supplementary-offerings/{offering}/grader', [SupplementaryExamGradingController::class, 'assign']);
    Route::post('exams/supplementary-periods/{period}/open-grading', [SupplementaryExamGradingController::class, 'open']);

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
    Route::put('dean/registration-offerings/{courseOffering}/proposal', [DeanRegistrationOfferingController::class, 'updateProposal']);
    Route::post('dean/registration-offerings/{courseOffering}/submit', [DeanRegistrationOfferingController::class, 'submit']);
    Route::put('dean/registration-offerings/{courseOffering}/timetable', [DeanRegistrationOfferingController::class, 'replaceTimetable']);
    Route::get('dean/registration-offerings/minimum-enrollment', [DeanMinimumEnrollmentController::class, 'index']);
    Route::post('dean/registration-offerings/minimum-enrollment/{review}/recommend', [DeanMinimumEnrollmentController::class, 'recommend']);
    Route::get('vice-presidency/scientific/semester-offerings/minimum-enrollment', [ScientificMinimumEnrollmentController::class, 'index']);
    Route::get('vice-presidency/scientific/semester-offerings/minimum-enrollment/{review}', [ScientificMinimumEnrollmentController::class, 'show']);
    Route::post('vice-presidency/scientific/semester-offerings/minimum-enrollment/{review}/decide', [ScientificMinimumEnrollmentController::class, 'decide']);
    Route::get('vice-presidency/scientific/semester-offerings', [ScientificSemesterOfferingController::class, 'index']);
    Route::get('vice-presidency/scientific/semester-offerings/{semesterOfferingRequest}', [ScientificSemesterOfferingController::class, 'show']);
    Route::post('vice-presidency/scientific/semester-offerings/{semesterOfferingRequest}/approve', [ScientificSemesterOfferingController::class, 'approve']);
    Route::post('vice-presidency/scientific/semester-offerings/{semesterOfferingRequest}/return', [ScientificSemesterOfferingController::class, 'returnForEditing']);
    Route::get('dean/supplementary-exam-offerings/context', [DeanSupplementaryExamOfferingController::class, 'context']);
    Route::get('dean/supplementary-exam-offerings/catalog', [DeanSupplementaryExamOfferingController::class, 'catalog']);
    Route::get('dean/supplementary-exam-offerings', [DeanSupplementaryExamOfferingController::class, 'index']);
    Route::post('dean/supplementary-exam-offerings', [DeanSupplementaryExamOfferingController::class, 'store']);
    Route::get('dean/supplementary-exam-offerings/{offering}', [DeanSupplementaryExamOfferingController::class, 'show']);
    Route::post('dean/supplementary-exam-offerings/{offering}/close', [DeanSupplementaryExamOfferingController::class, 'close']);
    Route::post('dean/supplementary-exam-offerings/{offering}/reopen', [DeanSupplementaryExamOfferingController::class, 'reopen']);
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
