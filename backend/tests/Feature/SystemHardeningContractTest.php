<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Phase 11 source contracts. Runtime Gate/DataScope tests remain mandatory
 * once the production MariaDB schema and application bootstrap are available.
 */
class SystemHardeningContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_hard11_01_unauthenticated_protected_route_returns_401(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringContainsString("Route::middleware(['auth:sanctum'", $routes);

        $bootstrap = self::source('bootstrap/app.php');
        self::assertStringContainsString('AuthenticationException', $bootstrap);
        self::assertStringContainsString("'error_code' => 'unauthenticated'", $bootstrap);
        self::assertStringContainsString(', 401)', $bootstrap);
    }

    public function test_hard11_02_inactive_account_with_old_token_is_blocked(): void
    {
        $middleware = self::source('app/Http/Middleware/EnsureActiveAccount.php');
        self::assertStringContainsString("accountStatus?->status_code !== 'active'", $middleware);
        self::assertStringContainsString('currentAccessToken()', $middleware);
        self::assertStringContainsString('delete()', $middleware);
        self::assertStringContainsString('AccessDeniedHttpException', $middleware);

        $routes = self::source('routes/api.php');
        self::assertStringContainsString('EnsureActiveAccount::class', $routes);
    }

    public function test_hard11_03_login_throttle_returns_429(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringContainsString("->middleware('throttle:login')", $routes);

        $provider = self::source('app/Providers/AppServiceProvider.php');
        self::assertStringContainsString("RateLimiter::for('login'", $provider);
        self::assertStringContainsString('Limit::perMinute(5)', $provider);
        self::assertStringContainsString('$email.\'|\'.$request->ip()', $provider);
        self::assertStringContainsString('strtolower(trim', $provider);

        $bootstrap = self::source('bootstrap/app.php');
        self::assertStringContainsString('ThrottleRequestsException', $bootstrap);
        self::assertStringContainsString(', 429)', $bootstrap);
        self::assertStringContainsString("'error_code' => 'too_many_requests'", $bootstrap);
    }

    public function test_hard11_04_invalid_login_does_not_reveal_account_existence(): void
    {
        $login = self::source('app/Http/Controllers/Api/LoginController.php');
        self::assertStringContainsString('Invalid email or password', $login);
        self::assertStringContainsString('DUMMY_PASSWORD_HASH', $login);
        self::assertStringContainsString('Hash::check', $login);
        self::assertStringNotContainsString('! $user || ! Hash::check', $login);
        self::assertStringNotContainsString('No account', $login);
        self::assertStringNotContainsString('user not found', $login);
        self::assertSame(1, substr_count($login, 'Invalid email or password'));
    }

    public function test_hard11_05_login_audit_never_stores_password_or_token(): void
    {
        $audit = self::source('app/Services/LoginAuditService.php');
        self::assertStringContainsString("'username_attempted'", $audit);
        self::assertStringContainsString("'login_status'", $audit);
        self::assertStringContainsString("'ip_address'", $audit);
        self::assertStringContainsString("'user_agent'", $audit);
        self::assertStringNotContainsString("'password'", $audit);
        self::assertStringNotContainsString("'password_hash'", $audit);
        self::assertStringNotContainsString('plainTextToken', $audit);
        self::assertStringNotContainsString('Bearer', $audit);
        self::assertStringNotContainsString('$request->input(\'password\'', $audit);

        $login = self::source('app/Http/Controllers/Api/LoginController.php');
        self::assertStringContainsString('STATUS_SUCCESS', $login);
        self::assertStringContainsString('STATUS_FAILED', $login);
        self::assertStringContainsString('STATUS_INACTIVE', $login);
    }

    public function test_hard11_06_through_09_vp_and_super_admin_cannot_bypass_authority(): void
    {
        $workflow = self::source('app/Services/TeachingAssignmentWorkflowService.php');
        $scientific = self::extractMethod($workflow, 'assertScientificReviewer');
        $administrative = self::extractMethod($workflow, 'assertAdministrativeReviewer');
        $holds = self::extractMethod($workflow, 'holdsAssignedPermission');

        self::assertStringContainsString('isScientificVicePresident()', $scientific);
        self::assertStringContainsString('holdsAssignedPermission(', $scientific);
        self::assertStringContainsString('isAdministrativeVicePresident()', $administrative);
        self::assertStringContainsString('holdsAssignedPermission(', $administrative);
        self::assertStringContainsString('effectivePermissions()', $holds);
        self::assertStringNotContainsString('$user->hasPermission(', $scientific);
        self::assertStringNotContainsString('$user->hasPermission(', $administrative);
        self::assertStringNotContainsString('$user->hasPermission(', $holds);
    }

    public function test_hard11_10_dean_wrong_scope_cannot_access_other_college(): void
    {
        $workflow = self::source('app/Services/TeachingAssignmentWorkflowService.php');
        self::assertStringContainsString('assertOfferingInDeanScope(', $workflow);
        $scope = self::extractMethod($workflow, 'assertOfferingInDeanScope');
        self::assertStringContainsString('offeringInAccessibleColleges', $scope);
    }

    public function test_hard11_11_advisor_wrong_scope_cannot_approve_other_student(): void
    {
        $requests = self::source('app/Services/RegistrationRequestService.php');
        $access = self::extractMethod($requests, 'assertCanAccessRequest');
        self::assertStringContainsString('canStaffAccessStudent', $access);

        $withdrawals = self::source('app/Services/RegistrationWithdrawalService.php');
        self::assertStringContainsString('canStaffAccessStudent', $withdrawals);
    }

    public function test_hard11_12_registration_officer_wrong_scope_cannot_finalize(): void
    {
        $terms = self::source('app/Services/AcademicTermSnapshotService.php');
        $access = self::extractMethod($terms, 'assertCanAccessStudent');
        self::assertStringContainsString('canAccessStudent', $access);
        $finalize = self::extractMethod($terms, 'assertCanFinalize');
        self::assertStringContainsString('isRegistrationOfficer()', $finalize);
        self::assertStringContainsString('effectivePermissions()', $finalize);

        $progression = self::source('app/Services/AcademicProgressionService.php');
        self::assertStringContainsString('canAccessStudent', self::extractMethod($progression, 'assertCanAccessStudent'));
        $graduation = self::source('app/Services/GraduationDecisionService.php');
        self::assertStringContainsString('canAccessStudent', self::extractMethod($graduation, 'assertCanAccessStudent'));
    }

    public function test_hard11_13_legacy_course_offering_instructors_remain_blocked(): void
    {
        $controller = self::source('app/Http/Controllers/Api/CourseOfferingInstructorController.php');
        self::assertSame(3, substr_count($controller, 'TeachingAssignmentException::workflowRequired()'));
        self::assertStringNotContainsString('->save(', $controller);
        self::assertStringNotContainsString('forceDelete', $controller);
        self::assertStringNotContainsString('->delete(', $controller);
    }

    public function test_hard11_14_and_15_generic_offering_update_cannot_open_or_close(): void
    {
        $controller = self::source('app/Http/Controllers/Api/CourseOfferingController.php');
        $update = self::extractMethod($controller, 'update');
        self::assertStringContainsString('applyThenGuardOpenCoverage(', $update);
        self::assertStringContainsString('CourseOfferingClosureException::workflowRequired()', $update);
        self::assertStringContainsString("unset(\$attributes['status'], \$attributes['available_seats'])", $update);

        $attributes = self::extractMethod($controller, 'attributesForOfferingUpdate');
        self::assertStringContainsString("unset(\$data['status'], \$data['available_seats'])", $attributes);

        $request = self::source('app/Http/Requests/CourseOffering/UpdateCourseOfferingRequest.php');
        self::assertStringContainsString("'available_seats' => 'prohibited'", $request);
        self::assertStringContainsString("'exceptional' => 'prohibited'", $request);
    }

    public function test_hard11_16_direct_live_registration_fails_closed(): void
    {
        $service = self::source('app/Services/RegistrationService.php');
        self::assertStringContainsString('RegistrationException::liveWorkflowRequired()', self::extractMethod($service, 'registerStudent'));
        self::assertStringContainsString('RegistrationException::liveWorkflowRequired()', self::extractMethod($service, 'dropRegistration'));
        self::assertStringContainsString('RegistrationException::liveWorkflowRequired()', self::extractMethod($service, 'withdrawRegistration'));
    }

    public function test_hard11_17_through_19_drop_and_withdrawal_seat_and_reactivation(): void
    {
        $service = self::source('app/Services/RegistrationService.php');
        self::assertStringContainsString('available_seats - 1', $service);
        self::assertStringContainsString('available_seats + 1', $service);
        self::assertStringContainsString('withdrawnNotReactivatable()', $service);
        self::assertStringContainsString("REACTIVATABLE_STATUSES = ['dropped']", self::source('app/Models/StudentCourseRegistration.php'));
    }

    public function test_hard11_20_through_23_generic_academic_state_mutations_blocked(): void
    {
        $terms = self::source('app/Http/Controllers/Api/StudentAcademicTermController.php');
        self::assertSame(3, substr_count($terms, 'rejectGenericMutation('));

        $student = self::source('app/Http/Controllers/Api/StudentController.php');
        $update = self::extractMethod($student, 'update');
        self::assertStringContainsString('academicLevelProgressionWorkflowRequired()', $update);
        self::assertStringContainsString('graduationDecisionWorkflowRequired()', $update);
        self::assertTrue(
            strpos($update, '$targetStatusCode === AcademicRecordWorkflow::GRADUATED_STATUS') !== false
            && strpos($update, '$currentStatusCode === AcademicRecordWorkflow::GRADUATED_STATUS') !== false
        );
    }

    public function test_hard11_24_locked_grade_cannot_be_edited_through_legacy_endpoint(): void
    {
        $grades = self::source('app/Services/GradeService.php');
        self::assertStringContainsString('assertOfferingGradesEditable(', self::extractMethod($grades, 'updateRegistrationGrades'));
        self::assertStringContainsString("errorCode: 'grades_locked'", $grades);
        self::assertStringContainsString("errorCode: 'legacy_grade_workflow_disabled'", $grades);
    }

    public function test_hard11_25_stale_workflow_supersede_survives_http_409(): void
    {
        $progression = self::source('app/Services/AcademicProgressionService.php');
        $finish = self::extractMethod($progression, 'finishDecision');
        self::assertStringNotContainsString('DB::transaction', $finish);

        $graduation = self::source('app/Services/GraduationDecisionService.php');
        self::assertStringNotContainsString('DB::transaction', self::extractMethod($graduation, 'finishDecision'));

        $teaching = self::source('app/Services/TeachingAssignmentWorkflowService.php');
        self::assertStringContainsString(
            'HTTP conflicts for stale removal must be raised AFTER the supersede',
            $teaching
        );
    }

    public function test_hard11_26_and_27_progression_and_graduation_materialize_once(): void
    {
        $progression = self::source('app/Services/AcademicProgressionService.php');
        self::assertStringContainsString('lockCurrentProgression(', $progression);
        self::assertStringContainsString('academicProgressionAlreadyMaterialized()', $progression);

        $graduation = self::source('app/Services/GraduationDecisionService.php');
        self::assertStringContainsString('lockCurrentGraduation(', $graduation);
        self::assertStringContainsString('graduationAlreadyMaterialized()', $graduation);
    }

    public function test_hard11_28_through_31_student_permanent_delete_history(): void
    {
        $guard = self::source('app/Services/StudentPermanentDeleteGuard.php');
        self::assertStringContainsString("ERROR_CODE = 'student_permanent_delete_blocked'", $guard);
        foreach ([
            'registrations',
            'attendance',
            'documents',
            'academic_terms',
            'course_results',
            'grade_components',
            'registration_requests',
            'withdrawal_requests',
            'progression_decisions',
            'graduation_decisions',
            'disciplinary_cases',
            'grade_appeals',
        ] as $category) {
            self::assertStringContainsString("'".$category."'", $guard);
        }
        self::assertStringContainsString('Schema::hasTable(', $guard);

        $controller = self::source('app/Http/Controllers/Api/StudentController.php');
        $force = self::extractMethod($controller, 'forceDestroy');
        self::assertStringContainsString('lockForUpdate()', $force);
        self::assertStringContainsString('DB::transaction', $force);
        self::assertStringContainsString('StudentPermanentDeleteGuard::ERROR_CODE', $force);
        self::assertStringContainsString('StudentPermanentDeleteGuard::REQUIRES_ARCHIVE', $force);
        self::assertStringContainsString('blocking_categories', $force);
        self::assertStringContainsString(', 409)', $force);
        self::assertStringContainsString('isRestrictedForeignKey', $force);
        self::assertStringContainsString('forceDelete()', $force);
        self::assertStringNotContainsString('$student->delete()', $force);
    }

    public function test_hard11_32_and_33_cross_scope_show_is_rejected(): void
    {
        $studentPolicy = self::source('app/Policies/StudentPolicy.php');
        self::assertStringContainsString('canAccessStudent', $studentPolicy);
        $offeringPolicy = self::source('app/Policies/CourseOfferingPolicy.php');
        self::assertStringContainsString('canAccessOffering', $offeringPolicy);

        $crud = self::source('app/Http/Controllers/Api/Concerns/HandlesApiCrud.php');
        self::assertStringContainsString('scopeResourceQuery(', $crud);
    }

    public function test_hard11_34_current_slot_unique_invariant_enforced(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString('uq_tar_current_slot', $sql);
        self::assertStringContainsString('uq_srwr_current_slot', $sql);
        self::assertStringContainsString('uq_spd_current_slot', $sql);
        self::assertStringContainsString('uq_sgd_current_slot', $sql);
        self::assertStringContainsString('uq_student_term', $sql);
        self::assertStringContainsString('current_slot = 1', $sql);
        self::assertStringContainsString('current_slot <> 1', $sql);
        self::assertStringContainsString("'OVERALL' AS check_name", $sql);
        self::assertStringContainsString('non_unique = 0', $sql);
    }

    public function test_hard11_35_known_business_conflict_does_not_leak_sqlstate(): void
    {
        $bootstrap = self::source('bootstrap/app.php');
        self::assertStringContainsString("config('app.debug') ? \$exception->getMessage() : 'Unexpected error occurred'", $bootstrap);
        self::assertStringNotContainsString('SQLSTATE', $bootstrap);

        $terms = self::source('app/Services/AcademicTermSnapshotService.php');
        self::assertStringContainsString('isDuplicateTermIdentity($exception)', $terms);
        self::assertStringContainsString('1062', $terms);
        self::assertStringContainsString('uq_student_term', $terms);

        $registration = self::source('app/Services/RegistrationService.php');
        $regDup = self::extractMethod($registration, 'isDuplicateRegistrationQueryException');
        self::assertStringContainsString('1062', $regDup);
        self::assertStringNotContainsString('23000', $regDup);
    }

    public function test_login_uses_existing_audit_table_not_a_new_subsystem(): void
    {
        $audit = self::source('app/Services/LoginAuditService.php');
        self::assertStringContainsString('login_audit_logs', $audit);
        self::assertStringContainsString('LoginAuditLog::query()->create(', $audit);

        $routes = self::source('routes/api.php');
        self::assertStringContainsString("Route::post('login', [LoginController::class, 'login'])", $routes);
        self::assertStringContainsString("->only(['index', 'show'])", $routes);
    }

    public function test_phase11_sql_is_read_only_and_fully_qualified(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString('alrowad_uni_rust', $sql);
        self::assertStringNotContainsString('DATABASE()', $sql);
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE ', 'DROP ', 'TRUNCATE '] as $verb) {
            self::assertStringNotContainsString($verb, $sql);
        }
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/database/sql/system-hardening-audit/01_apply.sql');
        $readme = self::source('database/sql/system-hardening-audit/README.md');
        self::assertStringContainsString('READ ONLY', $readme);
        self::assertStringContainsString('01_apply.sql', $readme);
        self::assertStringContainsString('There is **no**', $readme);
    }

    public function test_no_migrations_added_for_phase_11(): void
    {
        $checklist = self::source('docs/production-academic-core-checklist.md');
        self::assertFileExists(dirname(__DIR__, 2).'/docs/academic-core-security-audit.md');
        self::assertFileExists(dirname(__DIR__, 2).'/docs/production-academic-core-checklist.md');
        self::assertStringContainsString('DATABASE DEPLOYMENT VERIFICATION PENDING', $checklist);
        self::assertStringContainsString('APP_DEBUG=false', $checklist);
        self::assertStringContainsString('teaching-assignment-lifecycle/02_verify.sql', $checklist);
        self::assertStringContainsString('student-registration-lifecycle/02_verify.sql', $checklist);
        self::assertStringContainsString('student-academic-progression/02_verify.sql', $checklist);
        self::assertStringContainsString('system-hardening-audit/00_audit.sql', $checklist);
        self::assertStringContainsString('No Laravel migration command is required or permitted', $checklist);
        self::assertStringContainsString('CODE HARDENING READY', $checklist);
    }

    public function test_academic_queues_are_paginated_and_bounded(): void
    {
        $pagination = self::source('app/Support/AcademicQueuePagination.php');
        self::assertStringContainsString('MAX_PER_PAGE = 100', $pagination);

        self::assertStringContainsString('paginate(', self::source('app/Services/AcademicProgressionService.php'));
        self::assertStringContainsString('paginate(', self::source('app/Services/GraduationDecisionService.php'));
        self::assertStringContainsString('paginate(', self::source('app/Services/RegistrationRequestService.php'));
        self::assertStringContainsString('paginate(', self::source('app/Services/RegistrationWithdrawalService.php'));
    }

    public function test_canonical_lock_order_is_documented(): void
    {
        $opening = self::source('app/Services/CourseOfferingOpeningService.php');
        self::assertStringContainsString('Never lock instructors before the offering', $opening);

        $teaching = self::source('app/Services/TeachingAssignmentWorkflowService.php');
        self::assertStringContainsString('must not invert to assignment-then-offering', $teaching);
        self::assertStringContainsString('lockOfferingThenRequest', $teaching);

        $graph = self::source('app/Support/AcademicRecordWorkflow.php');
        self::assertStringContainsString('course_offerings involved for the student', $graph);
    }

    public function test_app_debug_is_not_hardcoded_true(): void
    {
        $config = self::source('config/app.php');
        self::assertStringContainsString("env('APP_DEBUG', false)", $config);
        self::assertStringNotContainsString("'debug' => true", $config);
    }

    public function test_hard11_36_active_unused_student_permanent_delete_is_rejected(): void
    {
        $force = self::extractMethod(self::source('app/Http/Controllers/Api/StudentController.php'), 'forceDestroy');
        self::assertStringContainsString("if (! \$student->trashed())", $force);
        self::assertStringContainsString('StudentPermanentDeleteGuard::REQUIRES_ARCHIVE', $force);
        self::assertStringContainsString(', 409)', $force);
        self::assertTrue(
            strpos($force, "if (! \$student->trashed())") < strpos($force, 'forceDelete()')
        );
        self::assertStringNotContainsString('$student->delete()', $force);
        self::assertStringNotContainsString('->restore(', $force);
    }

    public function test_hard11_37_archived_unused_student_permanent_delete_is_allowed(): void
    {
        $force = self::extractMethod(self::source('app/Http/Controllers/Api/StudentController.php'), 'forceDestroy');
        self::assertStringContainsString('withTrashed()', $force);
        self::assertStringContainsString('lockForUpdate()', $force);
        self::assertStringContainsString('blockingCategories($student)', $force);
        self::assertStringContainsString('$student->forceDelete()', $force);
        self::assertTrue(
            strpos($force, "if (! \$student->trashed())") < strpos($force, 'blockingCategories($student)')
        );
        self::assertTrue(
            strpos($force, 'blockingCategories($student)') < strpos($force, '$student->forceDelete()')
        );
    }

    public function test_hard11_38_archived_student_with_phase_9_10_history_is_blocked(): void
    {
        $guard = self::source('app/Services/StudentPermanentDeleteGuard.php');
        $force = self::extractMethod(self::source('app/Http/Controllers/Api/StudentController.php'), 'forceDestroy');
        self::assertStringContainsString("studentCourseRegistrations()->exists()", $guard);
        self::assertStringContainsString("student_progression_decisions", $guard);
        self::assertStringContainsString("student_graduation_decisions", $guard);
        self::assertStringContainsString("student_registration_withdrawal_requests", $guard);
        self::assertStringContainsString('StudentPermanentDeleteGuard::ERROR_CODE', $force);
        self::assertTrue(
            strpos($force, "if (! \$student->trashed())") < strpos($force, 'blockingCategories($student)')
        );
    }

    public function test_hard11_39_capacity_increase_recomputes_available_seats_from_occupied(): void
    {
        $apply = self::extractMethod(self::source('app/Services/CourseOfferingContextService.php'), 'applyCapacityChange');
        $request = self::source('app/Http/Requests/CourseOffering/UpdateCourseOfferingRequest.php');
        self::assertStringContainsString("unset(\$attributes['available_seats'])", $apply);
        self::assertStringContainsString('lockForUpdate()', $apply);
        self::assertStringContainsString('->current()', $apply);
        self::assertStringContainsString("\$attributes['available_seats'] = \$newCapacity - \$occupied", $apply);
        self::assertStringContainsString("'available_seats' => 'prohibited'", $request);
        self::assertStringContainsString(
            'applyCapacityChange',
            self::extractMethod(self::source('app/Services/CourseOfferingContextService.php'), 'updateOffering')
        );
    }

    public function test_hard11_40_capacity_decrease_that_covers_occupied_recomputes_available_seats(): void
    {
        $apply = self::extractMethod(self::source('app/Services/CourseOfferingContextService.php'), 'applyCapacityChange');
        self::assertStringContainsString('if ($newCapacity < $occupied)', $apply);
        self::assertStringContainsString("\$attributes['available_seats'] = \$newCapacity - \$occupied", $apply);
        self::assertStringContainsString('occupied current registrations', $apply);
        self::assertStringNotContainsString('->drop(', $apply);
        self::assertStringNotContainsString('withdraw', strtolower($apply));
    }

    public function test_hard11_41_capacity_below_occupied_returns_controlled_409(): void
    {
        $apply = self::extractMethod(self::source('app/Services/CourseOfferingContextService.php'), 'applyCapacityChange');
        $exception = self::source('app/Exceptions/CourseOfferingContextException.php');
        self::assertStringContainsString('capacityBelowOccupied()', $apply);
        self::assertStringContainsString('CAPACITY_BELOW_OCCUPIED', $exception);
        self::assertStringContainsString("'course_offering_capacity_below_occupied'", $exception);
        self::assertStringContainsString(', 409,', $exception);
        self::assertStringNotContainsString('cancelRegistration', $apply);
    }

    public function test_hard11_42_capacity_update_and_registration_share_offering_first_lock_order(): void
    {
        $apply = self::extractMethod(self::source('app/Services/CourseOfferingContextService.php'), 'applyCapacityChange');
        $register = self::extractMethod(self::source('app/Services/RegistrationService.php'), 'performRegisterStudent');
        $opening = self::source('app/Services/CourseOfferingOpeningService.php');
        $offeringLockPos = strpos($apply, 'CourseOffering::query()');
        $registrationLockPos = strpos($apply, 'StudentCourseRegistration::query()');
        self::assertNotFalse($offeringLockPos);
        self::assertNotFalse($registrationLockPos);
        self::assertLessThan($registrationLockPos, $offeringLockPos);
        self::assertStringContainsString('lockForUpdate()', $apply);
        self::assertTrue(
            strpos($register, 'CourseOffering::query()') < strpos($register, 'decrementAvailableSeats')
        );
        self::assertStringContainsString('lockForUpdate()', $register);
        self::assertStringContainsString('Never lock instructors before the offering', $opening);
        self::assertStringContainsString('applyThenGuardOpenCoverage', self::extractMethod(
            self::source('app/Http/Controllers/Api/CourseOfferingController.php'),
            'update'
        ));
    }

    public function test_hard11_43_unknown_email_performs_dummy_hash_verification(): void
    {
        $login = self::source('app/Http/Controllers/Api/LoginController.php');
        self::assertMatchesRegularExpression(
            "/DUMMY_PASSWORD_HASH = '\\\$2y\\\$10\\\$[A-Za-z0-9.\\/]{53}'/",
            $login
        );
        self::assertStringContainsString('self::DUMMY_PASSWORD_HASH', $login);
        self::assertStringContainsString('Hash::check($validated[\'password\'], $hash)', $login);
        self::assertStringNotContainsString('! $user || ! Hash::check', $login);
        self::assertDoesNotMatchRegularExpression(
            "/DUMMY_PASSWORD_HASH\s*=\s*'[^$]/",
            $login
        );
    }

    public function test_hard11_44_unknown_email_and_wrong_password_share_the_same_public_credential_error(): void
    {
        $login = self::extractMethod(self::source('app/Http/Controllers/Api/LoginController.php'), 'login');
        self::assertSame(1, substr_count($login, 'Invalid email or password'));
        self::assertSame(1, substr_count($login, ', 422)'));
        self::assertStringContainsString('LoginAuditService::STATUS_FAILED', $login);
        self::assertStringNotContainsString('user not found', strtolower($login));
        self::assertStringNotContainsString('unknown email', strtolower($login));
        self::assertStringNotContainsString('wrong password', strtolower($login));
        self::assertStringContainsString('$user === null || ! $passwordMatches', $login);
    }

    public function test_hard11_45_successful_login_audit_is_written_after_token_issuance(): void
    {
        $login = self::extractMethod(self::source('app/Http/Controllers/Api/LoginController.php'), 'login');
        $tokenPos = strpos($login, 'createToken(');
        $successPos = strpos($login, 'LoginAuditService::STATUS_SUCCESS');
        self::assertNotFalse($tokenPos);
        self::assertNotFalse($successPos);
        self::assertLessThan($successPos, $tokenPos);
        self::assertTrue(strpos($login, 'plainTextToken') < $successPos);
    }

    public function test_sql_hard11_01_missing_required_column_fails_closed_without_dynamic_select(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString("UNION ALL SELECT 'users', 'email'", $sql);
        self::assertStringContainsString("UNION ALL SELECT 'users', 'password_hash'", $sql);
        self::assertStringContainsString('required_core_columns', $sql);
        self::assertStringContainsString('@core_ready = 1', $sql);
        self::assertStringContainsString('information_schema.columns', $sql);
        self::assertMatchesRegularExpression(
            '/SET @sql := IF\(\s*@core_ready = 1,[\s\S]*FROM `alrowad_uni_rust`.`course_offerings`/',
            $sql
        );
        self::assertStringNotContainsString('FROM `alrowad_uni_rust`.`course_offerings` WHERE status', explode('SET @sql := IF(', $sql)[0]);
    }

    public function test_sql_hard11_02_missing_teaching_assignment_action_type_fails(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString("column_name = 'action_type'", $sql);
        self::assertStringContainsString('teaching_assignment_action_type_present', $sql);
        self::assertStringContainsString('AND @action_type_exists = 1 AND @tar_bad_action = 0', $sql);
        self::assertStringContainsString("'SELECT @tar_bad_action := 1'", $sql);
    }

    public function test_sql_hard11_03_withdrawal_current_slot_unique_index_is_required(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString("index_name = 'uq_srwr_current_slot'", $sql);
        self::assertStringContainsString("'student_course_registration_id,current_slot'", $sql);
        self::assertStringContainsString('withdrawal_current_slot_unique', $sql);
        self::assertStringContainsString('@srwr_uq = 1', $sql);
    }

    public function test_sql_hard11_04_progression_current_slot_unique_index_is_required(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString("index_name = 'uq_spd_current_slot'", $sql);
        self::assertStringContainsString("'student_id,academic_year_id,current_slot'", $sql);
        self::assertStringContainsString('progression_current_slot_unique', $sql);
        self::assertStringContainsString('@spd_uq = 1', $sql);
    }

    public function test_sql_hard11_05_graduation_current_slot_unique_index_is_required(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString("index_name = 'uq_sgd_current_slot'", $sql);
        self::assertStringContainsString("'student_id,current_slot'", $sql);
        self::assertStringContainsString('graduation_current_slot_unique', $sql);
        self::assertStringContainsString('@sgd_uq = 1', $sql);
    }

    public function test_sql_hard11_06_course_offering_status_banana_fails(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString("status NOT IN (''open'',''closed'')", $sql);
        self::assertStringContainsString('course_offering_canonical_status', $sql);
        self::assertStringContainsString('@malformed_offering_status = 0', $sql);
    }

    public function test_sql_hard11_07_available_seats_above_capacity_fails(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        self::assertStringContainsString('available_seats > capacity', $sql);
        self::assertStringContainsString('course_offering_available_seats_bounds', $sql);
        self::assertStringContainsString('course_offering_seat_occupancy', $sql);
        self::assertStringContainsString("status_code = ''registered''", $sql);
        self::assertStringContainsString('@offering_seat_bounds = 0', $sql);
        self::assertStringContainsString('@offering_occupancy_mismatch = 0', $sql);
    }

    public function test_sql_hard11_08_generic_vice_president_academic_review_mutation_fails(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        $vpSql = self::extractSqlAssignment($sql, '@generic_vp_forbidden');
        self::assertStringContainsString('teaching_assignments.review_scientific', $vpSql);
        self::assertStringContainsString('course_offerings.exceptional_open.review_scientific', $vpSql);
        self::assertStringContainsString('course_offerings.closure.review_administrative', $vpSql);
        self::assertStringContainsString('registration_withdrawals.review', $vpSql);
        self::assertStringContainsString('academic_records.finalize', $vpSql);
        self::assertStringContainsString('academic_progression.review', $vpSql);
        self::assertStringContainsString('graduation_decisions.review', $vpSql);
        self::assertStringContainsString("role_code = ''vice_president''", $vpSql);
        self::assertStringContainsString('@generic_vp_forbidden = 0', $sql);
    }

    public function test_sql_hard11_09_super_admin_explicit_forbidden_academic_mutation_fails(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        $saSql = self::extractSqlAssignment($sql, '@super_admin_forbidden');
        self::assertStringContainsString('teaching_assignments.manage', $saSql);
        self::assertStringContainsString('course_offerings.exceptional_open.request', $saSql);
        self::assertStringContainsString('course_offerings.closure.request', $saSql);
        self::assertStringContainsString('registration_withdrawals.review', $saSql);
        self::assertStringContainsString('academic_records.finalize', $saSql);
        self::assertStringContainsString('academic_progression.review', $saSql);
        self::assertStringContainsString('graduation_decisions.review', $saSql);
        self::assertStringContainsString("role_code = ''super_admin''", $saSql);
        self::assertStringContainsString('@super_admin_forbidden = 0', $sql);
        self::assertStringContainsString('super_admin_without_explicit_academic_mutations', $sql);
    }

    public function test_sql_hard11_10_clean_phase_8_10_schema_is_documented_as_overall_pass(): void
    {
        $sql = self::source('database/sql/system-hardening-audit/00_audit.sql');
        $readme = self::source('database/sql/system-hardening-audit/README.md');
        self::assertStringContainsString('SELECT \'OVERALL\' AS check_name, IF(', $sql);
        self::assertStringContainsString('@core_ready = 1', $sql);
        self::assertStringContainsString('@tar_uq = 1', $sql);
        self::assertStringContainsString('@srwr_uq = 1', $sql);
        self::assertStringContainsString('@spd_uq = 1', $sql);
        self::assertStringContainsString('@sgd_uq = 1', $sql);
        self::assertStringContainsString('@malformed_offering_status = 0', $sql);
        self::assertStringContainsString('@super_admin_forbidden = 0', $sql);
        self::assertStringContainsString('SQL-HARD11-10', $readme);
        self::assertStringContainsString('OVERALL = PASS', $readme);
        self::assertStringContainsString('SQL NOT EXECUTED', $sql);
    }

    private static function extractSqlAssignment(string $sql, string $variable): string
    {
        $needle = 'SELECT '.$variable.' :=';
        $pos = strpos($sql, $needle);
        self::assertNotFalse($pos, "Expected assignment for {$variable}.");

        return substr($sql, $pos, 1800);
    }

    private static function extractMethod(string $source, string $name): string
    {
        self::assertSame(
            1,
            preg_match(
                '/\n    (?:private|public|protected) function '.preg_quote($name, '/').'\(.*?(?=\n    (?:private|public|protected) function |\n\}\s*\z)/s',
                $source,
                $matches
            ),
            "Expected exactly one method {$name}()."
        );

        return $matches[0];
    }
}
