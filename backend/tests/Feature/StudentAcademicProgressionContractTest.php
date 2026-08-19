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
        self::assertGreaterThanOrEqual(2, substr_count($update, 'AcademicRecordWorkflow::GRADUATED_STATUS'));
        self::assertStringContainsString('$targetStatusCode !== $currentStatusCode', $update);
        self::assertStringContainsString('AcademicRecordWorkflow::GRADUATED_STATUS', $update);
        self::assertTrue(
            strpos($update, '$targetStatusCode === AcademicRecordWorkflow::GRADUATED_STATUS') !== false
            && strpos($update, '$currentStatusCode === AcademicRecordWorkflow::GRADUATED_STATUS') !== false
        );
        self::assertStringNotContainsString('updateGraduatedStatus', $controller);
        self::assertStringNotContainsString('assertEligible($locked)', $update);
        $request = self::source('app/Http/Requests/Student/UpdateStudentRequest.php');
        self::assertStringContainsString('first_name', $request);
        self::assertStringContainsString('phone_number', $request);
        self::assertStringContainsString("'address'", $request);
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
        self::assertStringContainsString('lockStudentAcademicGraph(', $finalize);
        self::assertStringContainsString('unfinalizedAcademicWorkForTerm(', $finalize);
        self::assertStringContainsString('academicResultsNotFinal()', $finalize);
        self::assertTrue(
            strpos($finalize, 'lockStudentAcademicGraph(') < strpos($finalize, 'unfinalizedAcademicWorkForTerm(')
        );
        self::assertTrue(
            strpos($finalize, 'unfinalizedAcademicWorkForTerm(') < strpos($finalize, 'upsertComputedTerm(')
        );
        self::assertStringContainsString('upsertComputedTerm(', $finalize);

        $upsert = self::extractMethod($terms, 'upsertComputedTerm');
        self::assertTrue(
            strpos($upsert, 'unfinalizedAcademicWorkForTerm(') < strpos($upsert, "'is_finalized' => true")
        );

        $grades = self::source('app/Services/GradeService.php');
        self::assertStringContainsString('public function unfinalizedAcademicWorkForTerm(', $grades);
        $termWork = self::extractMethod($grades, 'unfinalizedAcademicWorkForTerm');
        self::assertStringContainsString('collectUnfinalizedAcademicWork(', $termWork);
        $collect = self::extractMethod($grades, 'collectUnfinalizedAcademicWork');
        self::assertStringContainsString('academicAttempts(requireResult: false)', $collect);
        self::assertStringContainsString('isOfficiallyVisibleAttempt(', $collect);

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
        $termGpa = self::extractMethod($progression, 'officialTermGpa');
        self::assertStringContainsString('latestOfficialSemesterIdForYear(', $termGpa);
        self::assertStringContainsString('isFinalized()', $termGpa);
        self::assertStringContainsString('officialTermMetrics(', $termGpa);
        self::assertStringNotContainsString("orderByDesc('semester_id')", $termGpa);
        self::assertStringNotContainsString('getGpaOverview(', $termGpa);
        self::assertTrue(
            strpos($termGpa, 'latestOfficialSemesterIdForYear(') < strpos($termGpa, "where('semester_id', \$semesterId)")
        );
        self::assertTrue(
            strpos($termGpa, 'isFinalized()') < strpos($termGpa, '$term->term_gpa')
        );
        self::assertTrue(
            strpos($termGpa, '$term->term_gpa') < strpos($termGpa, 'officialTermMetrics(')
        );

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
        foreach (['AC10-01', 'AC10-21', 'AC10-40', 'AC10-41', 'AC10-46', 'AC10-47', 'AC10-48', 'SQL-AC10', 'SQL-AC10-17', 'SQL-AC10-22', 'SQL-AC10-23', 'SQL-AC10-26', 'SQL-AC10-27', 'SQL-AC10-28', 'BLOCKED_IN_USE', 'registration_officer', 'graduated', '4.0', 'retained_no_provenance'] as $needle) {
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
            self::assertStringContainsString('referenced_table_name', $sql);
            self::assertStringContainsString("referenced_column_name = 'user_id'", $sql);
            self::assertStringContainsString("LOWER(column_type) = 'tinyint(1)'", $sql);
            self::assertStringContainsString('numeric_precision', $sql);
            self::assertStringContainsString("index_name = 'idx_spe_decision'", $sql);
            self::assertStringContainsString("index_name = 'idx_sge_actor'", $sql);
            self::assertStringContainsString("index_name = 'idx_spd_reviewer'", $sql);
            self::assertStringContainsString("constraint_name = 'fk_sat_finalized_by'", $sql);
            self::assertMatchesRegularExpression(
                "/index_name = 'idx_spd_student_status'\\s*AND non_unique = 1/s",
                $sql
            );
        }

        self::assertStringContainsString('PREPARE stmt FROM @sql', $preflight);
        self::assertStringContainsString('@missing_required_columns', $preflight);
        self::assertStringContainsString('legacy_term_duplicates', $preflight);
        self::assertStringContainsString('@apply_ready := @overall_ready', $apply);
        self::assertStringContainsString('ROLLBACK', $apply);
        self::assertStringContainsString('OVERALL', $verify);
        self::assertStringContainsString('PASS', $verify);
        self::assertStringContainsString('PREPARE stmt FROM @sql', $rollback);
        self::assertStringContainsString('BLOCKED_IN_USE', $rollback);
        self::assertStringContainsString('retained_no_provenance', $rollback);
        self::assertStringContainsString('RETAINED_NO_PROVENANCE', $rollback);
        self::assertStringNotContainsString('DROP COLUMN', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`student_progression_decisions`', $rollback);
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

    public function test_ac10_41_term_finalize_rejects_non_final_target_term_work(): void
    {
        $exception = self::source('app/Exceptions/AcademicRecordException.php');
        self::assertStringContainsString("ACADEMIC_RESULTS_NOT_FINAL = 'academic_results_not_final'", $exception);
        self::assertStringContainsString('409, self::ACADEMIC_RESULTS_NOT_FINAL', $exception);

        $finalize = self::extractMethod(self::source('app/Services/AcademicTermSnapshotService.php'), 'finalize');
        self::assertStringContainsString('unfinalizedAcademicWorkForTerm($locked, $academicYearId, $semesterId) !== []', $finalize);
        self::assertStringContainsString('academicResultsNotFinal()', $finalize);
        self::assertTrue(
            strpos($finalize, 'academicResultsNotFinal()') < strpos($finalize, 'upsertComputedTerm($locked, $academicYearId, $semesterId, $user, finalize: true)')
        );

        $collect = self::extractMethod(self::source('app/Services/GradeService.php'), 'collectUnfinalizedAcademicWork');
        self::assertStringContainsString("->where('academic_year_id', \$academicYearId)", $collect);
        self::assertStringContainsString("->where('semester_id', \$semesterId)", $collect);
        self::assertStringContainsString('isOfficiallyVisibleAttempt(', $collect);
    }

    public function test_ac10_42_term_finalize_snapshots_official_term_evidence(): void
    {
        $terms = self::source('app/Services/AcademicTermSnapshotService.php');
        $upsert = self::extractMethod($terms, 'upsertComputedTerm');
        self::assertStringContainsString('officialTermMetrics($student, $academicYearId, $semesterId)', $upsert);
        self::assertStringContainsString("'is_finalized' => true", $upsert);
        self::assertTrue(
            strpos($upsert, 'unfinalizedAcademicWorkForTerm(') < strpos($upsert, "'is_finalized' => true")
        );

        $recalculate = self::extractMethod($terms, 'recalculate');
        self::assertStringContainsString('finalize: false', $recalculate);
        self::assertStringNotContainsString('academicResultsNotFinal()', $recalculate);
    }

    public function test_ac10_43_and_44_progression_term_gpa_uses_finalized_snapshot_only(): void
    {
        $termGpa = self::extractMethod(self::source('app/Services/AcademicProgressionService.php'), 'officialTermGpa');
        self::assertStringContainsString('$term !== null && $term->isFinalized()', $termGpa);
        self::assertStringContainsString('officialTermMetrics(', $termGpa);
        self::assertGreaterThanOrEqual(1, substr_count($termGpa, '$term->term_gpa'));
        self::assertTrue(strpos($termGpa, 'isFinalized()') < strpos($termGpa, '$term->term_gpa'));

        $build = self::extractMethod(self::source('app/Services/AcademicProgressionService.php'), 'buildEvidence');
        self::assertStringContainsString('officialTermGpa(', $build);

        $decide = self::extractMethod(self::source('app/Services/AcademicProgressionService.php'), 'decide');
        self::assertStringContainsString('buildEvidence(', $decide);
        self::assertStringContainsString('lockStudentAcademicGraph(', $decide);
        self::assertTrue(
            strpos($decide, 'lockStudentAcademicGraph(') < strpos($decide, 'buildEvidence(')
        );
    }

    public function test_ac10_47_and_48_latest_term_uses_grade_service_chronology(): void
    {
        $grades = self::source('app/Services/GradeService.php');
        self::assertStringContainsString('public function latestOfficialSemesterIdForYear(', $grades);
        $latest = self::extractMethod($grades, 'latestOfficialSemesterIdForYear');
        self::assertStringContainsString('loadOfficialVisibleAttempts(', $latest);
        self::assertStringContainsString('officialTermChronologyKey(', $latest);
        self::assertStringContainsString('$offering?->academic_year_id', $latest);
        self::assertStringNotContainsString('studentAcademicTerms(', $latest);
        self::assertStringNotContainsString("orderByDesc('semester_id')", $latest);

        $termGpa = self::extractMethod(self::source('app/Services/AcademicProgressionService.php'), 'officialTermGpa');
        self::assertTrue(
            strpos($termGpa, 'latestOfficialSemesterIdForYear(') < strpos($termGpa, 'studentAcademicTerms(')
        );
        self::assertStringContainsString("where('academic_year_id', \$academicYearId)", $termGpa);
        self::assertStringContainsString("where('semester_id', \$semesterId)", $termGpa);
        self::assertStringNotContainsString("orderByDesc('semester_id')", $termGpa);
        self::assertTrue(
            strpos($termGpa, "where('semester_id', \$semesterId)") < strpos($termGpa, 'isFinalized()')
        );
        self::assertTrue(
            strpos($termGpa, 'isFinalized()') < strpos($termGpa, 'officialTermMetrics($student, $academicYearId, $semesterId)')
        );
    }

    public function test_ac10_45_and_46_graduated_status_is_protected_in_both_directions(): void
    {
        $update = self::extractMethod(self::source('app/Http/Controllers/Api/StudentController.php'), 'update');
        self::assertStringContainsString('$targetStatusCode === AcademicRecordWorkflow::GRADUATED_STATUS', $update);
        self::assertStringContainsString('$currentStatusCode === AcademicRecordWorkflow::GRADUATED_STATUS', $update);
        self::assertStringContainsString('graduationDecisionWorkflowRequired()', $update);
        self::assertStringContainsString('$locked->update($data)', $update);

        $exception = self::source('app/Exceptions/AcademicRecordException.php');
        self::assertStringContainsString("GRADUATION_DECISION_WORKFLOW_REQUIRED = 'graduation_decision_workflow_required'", $exception);
        self::assertStringContainsString('entered or left through the formal graduation decision workflow', $exception);

        $request = self::source('app/Http/Requests/Student/UpdateStudentRequest.php');
        self::assertStringContainsString("'phone_number'", $request);
        self::assertStringContainsString("'address'", $request);
    }

    public function test_sql_ac10_17_through_22_exact_compatibility_fail_closed_and_provenance(): void
    {
        $preflight = self::source('database/sql/student-academic-progression/00_preflight.sql');
        $apply = self::source('database/sql/student-academic-progression/01_apply.sql');
        $verify = self::source('database/sql/student-academic-progression/02_verify.sql');
        $rollback = self::source('database/sql/student-academic-progression/03_rollback.sql');

        foreach ([$preflight, $apply, $verify] as $sql) {
            self::assertStringContainsString('LOWER(c.data_type) <> required.data_type', $sql);
            self::assertStringContainsString('k.referenced_table_name = required.ref_table', $sql);
            self::assertStringContainsString('k.referenced_column_name = required.ref_column', $sql);
            self::assertStringContainsString("referenced_table_name = 'users'", $sql);
            self::assertStringContainsString("referenced_column_name = 'user_id'", $sql);
            self::assertStringContainsString("constraint_name = 'fk_sat_finalized_by'", $sql);
            self::assertStringContainsString("AND constraint_type = 'FOREIGN KEY') = 7", $sql);
            self::assertStringContainsString("required.dflt IS NULL AND required.is_nullable = 'NO' AND c.column_default IS NOT NULL", $sql);
            self::assertStringContainsString("required.onupd = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%on update current_timestamp%'", $sql);
            self::assertStringContainsString("required.onupd = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%on update current_timestamp%'", $sql);
            self::assertStringContainsString("required.autoinc = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%auto_increment%'", $sql);
            self::assertMatchesRegularExpression("/SELECT 'submitted_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP'.*0, 0/s", $sql);
            self::assertMatchesRegularExpression("/SELECT 'updated_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP'.*0, 1/s", $sql);
            self::assertMatchesRegularExpression(
                "/column_name = 'finalized_at'\\s+AND LOWER\\(data_type\\) = 'timestamp'[\\s\\S]*?NOT LIKE '%on update current_timestamp%'/",
                $sql
            );
            self::assertMatchesRegularExpression(
                "/column_name = 'is_finalized'\\s+AND LOWER\\(data_type\\) = 'tinyint'[\\s\\S]*?NOT LIKE '%auto_increment%'[\\s\\S]*?NOT LIKE '%on update current_timestamp%'/",
                $sql
            );
            self::assertMatchesRegularExpression(
                "/column_name = 'earned_hours'\\s+AND LOWER\\(data_type\\) = 'int'[\\s\\S]*?NOT LIKE '%on update current_timestamp%'/",
                $sql
            );
            self::assertMatchesRegularExpression(
                "/column_name = 'attempted_hours'\\s+AND LOWER\\(data_type\\) = 'int'[\\s\\S]*?NOT LIKE '%on update current_timestamp%'/",
                $sql
            );
            self::assertMatchesRegularExpression(
                "/column_name = 'finalized_by_user_id'\\s+AND LOWER\\(data_type\\) = 'int'[\\s\\S]*?NOT LIKE '%on update current_timestamp%'/",
                $sql
            );
            self::assertMatchesRegularExpression("/index_name = 'idx_spd_reviewer'\\s*AND non_unique = 1/s", $sql);
            self::assertMatchesRegularExpression("/index_name = 'idx_spe_decision'\\s*AND non_unique = 1/s", $sql);
            self::assertMatchesRegularExpression("/index_name = 'idx_sge_actor'\\s*AND non_unique = 1/s", $sql);
        }

        self::assertStringContainsString("IF(@overall_ready = 1, 'READY', 'BLOCKED')", $preflight);
        self::assertStringContainsString('@missing_required_columns = 0', $preflight);
        self::assertStringContainsString('never SQL error #1146', $preflight);
        self::assertStringContainsString("IF(@apply_ready = 1 AND @rbac_post_ok = 1, 'APPLIED', 'BLOCKED')", $apply);
        self::assertStringContainsString('@apply_ready := @overall_ready', $apply);
        self::assertStringContainsString("IF(@structure_ok = 1 AND @invariants_ok = 1, 'PASS', 'FAIL')", $verify);
        self::assertStringContainsString('fk_sat_finalized_by', $verify);
        self::assertStringContainsString("'promoted'", $verify);
        self::assertStringContainsString("'retained'", $verify);
        self::assertStringContainsString("decision_result <> ''graduated''", $verify);

        foreach ([$preflight, $apply, $verify] as $sql) {
            self::assertDoesNotMatchRegularExpression(
                '/^SELECT\\s+.*FROM `alrowad_uni_rust`\\.`(?:roles|student_statuses|permissions)`/m',
                $sql
            );
        }

        self::assertStringContainsString('retained_no_provenance', $rollback);
        self::assertStringContainsString('RETAINED_NO_PROVENANCE', $rollback);
        self::assertStringNotContainsString('DROP COLUMN', $rollback);
        self::assertStringNotContainsString('DROP FOREIGN KEY', $rollback);
        self::assertStringContainsString('skip_drop_is_finalized', $rollback);
        self::assertStringContainsString('skip_drop_fk_sat_finalized_by', $rollback);
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
