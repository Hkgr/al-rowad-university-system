<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Phase 10 source contracts that do not require the production MariaDB schema.
 */
class StudentAcademicProgressionContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_generic_academic_term_mutations_are_blocked(): void
    {
        $controller = self::source('app/Http/Controllers/Api/StudentAcademicTermController.php');
        self::assertStringContainsString('rejectGenericMutation()', $controller);
        self::assertSame(3, substr_count($controller, 'rejectGenericMutation('));

        $store = self::source('app/Http/Requests/StudentAcademicTerm/StoreStudentAcademicTermRequest.php');
        $update = self::source('app/Http/Requests/StudentAcademicTerm/UpdateStudentAcademicTermRequest.php');
        foreach ([$store, $update] as $request) {
            self::assertStringContainsString("'term_gpa' => 'prohibited'", $request);
            self::assertStringContainsString("'cumulative_gpa' => 'prohibited'", $request);
            self::assertStringContainsString("'academic_level_id' => 'prohibited'", $request);
        }

        $exception = self::source('app/Exceptions/AcademicRecordException.php');
        self::assertStringContainsString("ACADEMIC_TERM_WORKFLOW_REQUIRED = 'academic_term_workflow_required'", $exception);
        self::assertStringContainsString("ACADEMIC_TERM_FINALIZED = 'academic_term_finalized'", $exception);
    }

    public function test_generic_student_update_protects_level_and_graduated_status(): void
    {
        $controller = self::source('app/Http/Controllers/Api/StudentController.php');
        $update = self::extractMethod($controller, 'update');

        self::assertStringContainsString('academicLevelProgressionWorkflowRequired()', $update);
        self::assertStringContainsString('graduationDecisionWorkflowRequired()', $update);
        self::assertStringContainsString('AcademicRecordWorkflow::GRADUATED_STATUS', $update);
        self::assertStringNotContainsString('updateGraduatedStatus', $controller);
        self::assertStringNotContainsString('assertEligible($locked)', $update);
        self::assertStringContainsString('first_name', self::source('app/Http/Requests/Student/UpdateStudentRequest.php'));
        self::assertStringContainsString('phone_number', self::source('app/Http/Requests/Student/UpdateStudentRequest.php'));
    }

    public function test_canonical_calculators_are_reused_not_duplicated(): void
    {
        $grades = self::source('app/Services/GradeService.php');
        self::assertStringContainsString('public function officialAcademicAttempts(Student $student): Builder', $grades);
        self::assertStringContainsString('public function officialTermMetrics(', $grades);
        self::assertStringContainsString('public function officialCumulativeMetrics(', $grades);
        self::assertStringContainsString("repeated_courses_handling' => 'highest_attempt_only'", $grades);
        self::assertStringContainsString("'maximum' => 4.0", $grades);

        $terms = self::source('app/Services/AcademicTermSnapshotService.php');
        self::assertStringContainsString('officialTermMetrics(', $terms);
        self::assertStringNotContainsString('letterGradeFromFinalMark', $terms);

        $progression = self::source('app/Services/AcademicProgressionService.php');
        self::assertStringContainsString('officialCumulativeMetrics(', $progression);
        self::assertStringContainsString('unfinalizedAcademicWork(', $progression);
        self::assertStringContainsString('$this->graduation->evaluate(', $progression);
        $candidate = self::extractMethod($progression, 'candidateNextLevel');
        self::assertStringContainsString("where('level_order', '>', (int) \$current->level_order)", $candidate);
        self::assertStringContainsString("orderBy('level_order')", $candidate);
        self::assertStringContainsString('ProgramCourse::query()', $candidate);
        self::assertStringContainsString('->first();', $candidate);

        $graduation = self::source('app/Services/GraduationDecisionService.php');
        self::assertStringContainsString('$this->graduation->evaluate(', $graduation);
        self::assertStringContainsString('$this->graduation->assertEligible(', $graduation);
        self::assertStringContainsString('officialCumulativeMetrics(', $graduation);
        self::assertStringContainsString('gpaPolicy->satisfies(', $graduation);
        self::assertStringNotContainsString('letterGradeFromFinalMark', $graduation);

        $policy = self::source('app/Support/GraduationGpaPolicy.php');
        self::assertStringContainsString('MINIMUM_CUMULATIVE_GPA = 2.0', $policy);
        self::assertStringContainsString('SCALE_MAXIMUM = 4.0', $policy);
    }

    public function test_official_grade_only_and_term_immutability(): void
    {
        $grades = self::source('app/Services/GradeService.php');
        $official = self::extractMethod($grades, 'officialAcademicAttempts');
        self::assertStringContainsString('constrainAuthoritativeApprovedGradeApproval(', $official);
        self::assertStringContainsString("academicAttempts()", $official);

        $terms = self::source('app/Services/AcademicTermSnapshotService.php');
        $finalize = self::extractMethod($terms, 'finalize');
        self::assertStringContainsString('isFinalized()', $finalize);
        self::assertStringContainsString('upsertComputedTerm(', $finalize);

        $reject = self::extractMethod($terms, 'rejectGenericMutation');
        self::assertStringContainsString('academicTermFinalized()', $reject);
        self::assertStringContainsString('academicTermWorkflowRequired()', $reject);
    }

    public function test_progression_and_graduation_are_idempotent_and_stale_commits_before_409(): void
    {
        $progression = self::source('app/Services/AcademicProgressionService.php');
        self::assertStringContainsString(
            'HTTP conflicts for stale progression must be raised AFTER the supersede',
            $progression
        );
        self::assertStringContainsString(
            'AcademicRecordException::ACADEMIC_PROGRESSION_STALE => throw AcademicRecordException::academicProgressionStale()',
            $progression
        );
        $decide = self::extractMethod($progression, 'decide');
        self::assertStringContainsString('supersedeUnlocked(', $decide);
        self::assertStringNotContainsString('throw AcademicRecordException::academicProgressionStale()', $decide);
        self::assertStringContainsString('isMaterialized()', $decide);
        self::assertStringContainsString("decision_result === AcademicRecordWorkflow::RESULT_PROMOTED", $decide);
        $finish = self::extractMethod($progression, 'finishDecision');
        self::assertStringNotContainsString('DB::transaction', $finish);

        $graduation = self::source('app/Services/GraduationDecisionService.php');
        self::assertStringContainsString(
            'HTTP conflicts for stale graduation must be raised AFTER the supersede',
            $graduation
        );
        $gDecide = self::extractMethod($graduation, 'decide');
        self::assertStringContainsString('supersedeUnlocked(', $gDecide);
        self::assertStringNotContainsString('throw AcademicRecordException::graduationDecisionStale()', $gDecide);
        self::assertStringContainsString("status_code', AcademicRecordWorkflow::GRADUATED_STATUS", $gDecide);
        $gFinish = self::extractMethod($graduation, 'finishDecision');
        self::assertStringNotContainsString('DB::transaction', $gFinish);
    }

    public function test_review_requires_actual_registration_officer_role_and_assigned_permission(): void
    {
        $progression = self::source('app/Services/AcademicProgressionService.php');
        $assert = self::extractMethod($progression, 'assertCanReview');
        self::assertStringContainsString('isRegistrationOfficer()', $assert);
        self::assertStringContainsString('effectivePermissions()', $assert);
        self::assertStringNotContainsString('hasPermission(', $assert);

        $graduation = self::source('app/Services/GraduationDecisionService.php');
        $gAssert = self::extractMethod($graduation, 'assertCanReview');
        self::assertStringContainsString('isRegistrationOfficer()', $gAssert);
        self::assertStringContainsString('effectivePermissions()', $gAssert);
        self::assertStringNotContainsString('hasPermission(', $gAssert);

        $terms = self::source('app/Services/AcademicTermSnapshotService.php');
        $finalizeAuth = self::extractMethod($terms, 'assertCanFinalize');
        self::assertStringContainsString('isRegistrationOfficer()', $finalizeAuth);
        self::assertStringContainsString('effectivePermissions()', $finalizeAuth);
        self::assertStringNotContainsString('hasPermission(', $finalizeAuth);

        self::assertStringContainsString('function isRegistrationOfficer()', self::source('app/Models/User.php'));
        self::assertStringContainsString("AUTHORITY_ROLE = 'registration_officer'", self::source('app/Support/AcademicRecordWorkflow.php'));
        self::assertStringContainsString("GRADUATED_STATUS = 'graduated'", self::source('app/Support/AcademicRecordWorkflow.php'));
    }

    public function test_lock_order_is_compatible_with_grade_finalization(): void
    {
        $workflow = self::source('app/Support/AcademicRecordWorkflow.php');
        self::assertStringContainsString('course_offerings involved for the student', $workflow);
        self::assertStringContainsString('student_course_registrations', $workflow);

        $locker = self::source('app/Services/AcademicRecordGraphLocker.php');
        $lock = self::extractMethod($locker, 'lockStudentAcademicGraph');
        $studentLock = strpos($lock, '$this->lockStudent(');
        $offeringIds = strpos($lock, 'officialLockOfferingIds(');
        $offeringQuery = strpos($lock, 'CourseOffering::query()');
        $registrationQuery = strpos($lock, 'StudentCourseRegistration::query()');
        $termQuery = strpos($lock, 'StudentAcademicTerm::query()');
        self::assertNotFalse($studentLock);
        self::assertNotFalse($offeringIds);
        self::assertNotFalse($offeringQuery);
        self::assertNotFalse($registrationQuery);
        self::assertNotFalse($termQuery);
        self::assertTrue($studentLock < $offeringIds);
        self::assertTrue($offeringIds < $offeringQuery);
        self::assertTrue($offeringQuery < $registrationQuery);
        self::assertTrue($registrationQuery < $termQuery);
    }

    public function test_sql_package_layout_and_no_migration(): void
    {
        $dir = dirname(__DIR__, 2).'/database/sql/student-academic-progression';
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists($dir.'/'.$file);
        }

        $readme = self::source('database/sql/student-academic-progression/README.md');
        foreach (['AC10-01', 'AC10-21', 'AC10-40', 'SQL-AC10', 'BLOCKED_IN_USE', 'registration_officer', 'graduated', '4.0'] as $needle) {
            self::assertStringContainsString($needle, $readme);
        }

        $preflight = self::source('database/sql/student-academic-progression/00_preflight.sql');
        $apply = self::source('database/sql/student-academic-progression/01_apply.sql');
        $verify = self::source('database/sql/student-academic-progression/02_verify.sql');
        $rollback = self::source('database/sql/student-academic-progression/03_rollback.sql');

        foreach ([$preflight, $apply, $verify] as $sql) {
            self::assertStringContainsString('alrowad_uni_rust', $sql);
            self::assertStringNotContainsString('DATABASE()', $sql);
            self::assertStringContainsString("role_code = 'registration_officer'", $sql);
            self::assertStringContainsString("status_code = 'graduated'", $sql);
            self::assertStringContainsString("index_name = 'uq_spd_current_slot'", $sql);
            self::assertStringContainsString("index_name = 'idx_spd_student_status'", $sql);
            self::assertMatchesRegularExpression(
                "/index_name = 'idx_spd_student_status'\\s*AND non_unique = 1/s",
                $sql
            );
        }

        self::assertStringContainsString('legacy_term_duplicates', $preflight);
        self::assertStringContainsString('@apply_ready := @overall_ready', $apply);
        self::assertStringContainsString('ROLLBACK', $apply);
        self::assertStringContainsString('OVERALL', $verify);
        self::assertStringContainsString('PASS', $verify);
        self::assertStringContainsString('PREPARE stmt FROM @sql', $rollback);
        self::assertStringContainsString('BLOCKED_IN_USE', $rollback);
        self::assertStringContainsString('retained_no_provenance', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`students`', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`student_academic_terms`', $rollback);
        self::assertStringNotContainsString("DELETE FROM `alrowad_uni_rust`.`permissions`", $rollback);

        $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        foreach ($migrations as $file) {
            self::assertStringNotContainsString('student_progression', basename($file));
            self::assertStringNotContainsString('student_graduation', basename($file));
            self::assertStringNotContainsString('academic_record', basename($file));
        }
    }

    public function test_existing_self_service_routes_remain(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringContainsString("Route::get('student/transcript'", $routes);
        self::assertStringContainsString("Route::get('student/gpa-overview'", $routes);
        self::assertStringContainsString("Route::get('student/graduation-eligibility'", $routes);
        self::assertStringContainsString("Route::get('student/requirements'", $routes);
        self::assertStringContainsString('academic-records/students/{student}/terms', $routes);
        self::assertStringContainsString('academic-progression/{student}/submit', $routes);
        self::assertStringContainsString('graduation-decisions/{student}/submit', $routes);
    }

    private static function extractMethod(string $source, string $name): string
    {
        self::assertSame(
            1,
            preg_match(
                '/\n    (?:private|public|protected) function '.preg_quote($name, '/').'\(.*?\n    (?:private|public|protected) function /s',
                $source,
                $matches
            ),
            "Expected exactly one method {$name}() with a following method."
        );

        return $matches[0];
    }
}
