<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Phase 9 source contracts that do not require the production MariaDB schema.
 */
class StudentRegistrationLifecycleContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_live_staff_mutations_require_the_student_request_workflow(): void
    {
        $service = self::source('app/Services/RegistrationService.php');
        $registerStudent = self::extractMethod($service, 'registerStudent');
        $dropRegistration = self::extractMethod($service, 'dropRegistration');
        $withdrawRegistration = self::extractMethod($service, 'withdrawRegistration');

        self::assertStringContainsString('RegistrationException::liveWorkflowRequired()', $registerStudent);
        self::assertStringContainsString('RegistrationException::liveWorkflowRequired()', $dropRegistration);
        self::assertStringContainsString('RegistrationException::liveWorkflowRequired()', $withdrawRegistration);
        self::assertStringContainsString('registerStudentWithinTransaction', $service);
        self::assertStringNotContainsString(
            'return $this->performRegisterStudent($data, $authenticatedUserId);',
            $registerStudent
        );
    }

    public function test_withdrawn_registrations_are_not_silently_reactivated(): void
    {
        $service = self::source('app/Services/RegistrationService.php');
        $find = self::extractMethod($service, 'findReactivatableRegistration');

        self::assertStringContainsString('StudentCourseRegistration::REACTIVATABLE_STATUSES', $find);
        self::assertStringNotContainsString('EXCLUDED_STATUSES', $find);
        self::assertStringContainsString('RegistrationException::withdrawnNotReactivatable()', $service);
        self::assertStringContainsString("public const REACTIVATABLE_STATUSES = ['dropped'];", self::source('app/Models/StudentCourseRegistration.php'));
    }

    public function test_self_drop_uses_canonical_lock_order_and_does_not_delete(): void
    {
        $service = self::source('app/Services/RegistrationService.php');
        $selfDrop = self::extractMethod($service, 'selfDrop');

        self::assertLessThan(
            strpos($selfDrop, 'lockOffering('),
            strpos($selfDrop, 'lockStudent(')
        );
        self::assertLessThan(
            strpos($selfDrop, 'lockRegistration('),
            strpos($selfDrop, 'lockOffering(')
        );
        self::assertStringContainsString('transitionRegisteredToDropped(', $selfDrop);
        self::assertStringNotContainsString('->delete()', $selfDrop);
        self::assertStringContainsString('available_seats - 1', $service);
        self::assertStringContainsString('available_seats + 1', $service);
        self::assertStringContainsString("where('available_seats', '>', 0)", $service);
    }

    public function test_stale_withdrawal_http_conflict_is_raised_after_transaction_commits(): void
    {
        $workflow = self::source('app/Services/RegistrationWithdrawalService.php');

        self::assertSame(
            2,
            substr_count($workflow, 'return $this->finishDecision($this->decide('),
            'Advisor decide paths must throw stale conflicts only after finishDecision.'
        );
        self::assertStringContainsString(
            'HTTP conflicts for stale withdrawal must be raised AFTER the supersede',
            $workflow
        );
        self::assertStringContainsString(
            'RegistrationException::WITHDRAWAL_STALE => throw RegistrationException::withdrawalStale()',
            $workflow
        );

        $decide = self::extractMethod($workflow, 'decide');
        self::assertStringContainsString('supersedeUnlocked(', $decide);
        self::assertStringContainsString(
            'return $this->decisionConflict($locked, RegistrationException::WITHDRAWAL_STALE);',
            $decide
        );
        self::assertStringNotContainsString(
            'throw RegistrationException::withdrawalStale()',
            $decide
        );

        $finishDecision = self::extractMethod($workflow, 'finishDecision');
        self::assertStringNotContainsString('DB::transaction', $finishDecision);
        self::assertGreaterThan(
            strpos($workflow, 'private function decide('),
            strpos($workflow, 'private function finishDecision(')
        );
    }

    public function test_withdrawal_review_requires_actual_advisor_role_and_assigned_permission(): void
    {
        $workflow = self::source('app/Services/RegistrationWithdrawalService.php');
        $assertCanReview = self::extractMethod($workflow, 'assertCanReview');
        $holds = self::extractMethod($workflow, 'holdsAssignedPermission');

        self::assertStringContainsString('isAcademicAdvisor()', $assertCanReview);
        self::assertStringContainsString('holdsAssignedPermission(', $assertCanReview);
        self::assertStringContainsString('effectivePermissions()', $holds);
        self::assertStringNotContainsString('hasPermission(', $holds);
        self::assertStringContainsString('function isAcademicAdvisor()', self::source('app/Models/User.php'));
    }

    public function test_sql_package_layout_and_lock_order_are_documented(): void
    {
        $dir = dirname(__DIR__, 2).'/database/sql/student-registration-lifecycle';
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists($dir.'/'.$file);
        }

        $readme = self::source('database/sql/student-registration-lifecycle/README.md');
        self::assertStringContainsString('REG9-36', $readme);
        self::assertStringContainsString('SQL-REG9', $readme);
        self::assertStringContainsString('SQL-REG9-37', $readme);
        self::assertStringContainsString('SQL-REG9-38', $readme);
        self::assertStringContainsString('SQL-REG9-39', $readme);
        self::assertStringContainsString('SQL-REG9-40', $readme);
        self::assertStringContainsString('SQL-REG9-41', $readme);
        self::assertStringContainsString('SQL-REG9-42', $readme);
        self::assertStringContainsString('SQL-REG9-RB1', $readme);
        self::assertStringContainsString('BLOCKED_IN_USE', $readme);
        self::assertStringContainsString('students', $readme);
        self::assertStringContainsString('course_offerings', $readme);

        $lifecycle = self::source('app/Support/RegistrationLifecycle.php');
        self::assertStringContainsString('Canonical lock order', $lifecycle);
        self::assertStringContainsString('available_seats -= 1 exactly once', $lifecycle);

        $rollback = self::source('database/sql/student-registration-lifecycle/03_rollback.sql');
        self::assertStringContainsString('PREPARE stmt FROM @sql', $rollback);
        self::assertStringContainsString('BLOCKED_IN_USE', $rollback);
        self::assertStringContainsString('retained_no_provenance', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`student_course_registrations`', $rollback);
        self::assertStringNotContainsString(
            "DELETE FROM `alrowad_uni_rust`.`permissions`",
            $rollback
        );
        self::assertStringNotContainsString(
            'DELETE rp FROM `alrowad_uni_rust`.`role_permissions`',
            $rollback
        );

        $verify = self::source('database/sql/student-registration-lifecycle/02_verify.sql');
        self::assertStringContainsString('wr.student_id <> scr.student_id', $verify);
        self::assertStringContainsString("status_code <> ''withdrawn''", $verify);
        self::assertStringContainsString("index_name = 'idx_srwr_student_status'", $verify);
        self::assertStringContainsString("ENGINE FROM information_schema.tables", $verify);
        self::assertStringContainsString('academic_advisor', $verify);
        self::assertStringContainsString("column_name = 'submission_version'", $verify);

        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $shared) {
            $sql = self::source('database/sql/student-registration-lifecycle/'.$shared);
            self::assertStringContainsString("index_name = 'idx_srwr_student_status'", $sql);
            self::assertStringContainsString("index_name = 'uq_srwr_current_slot'", $sql);
            self::assertMatchesRegularExpression(
                "/index_name = 'idx_srwr_student_status'\\s*\\)\\s*= 1/s",
                $sql,
                $shared.' must require idx_srwr_student_status NON_UNIQUE = 1.'
            );
            self::assertDoesNotMatchRegularExpression(
                "/index_name = 'idx_srwr_student_status'\\s*\\)\\s*= 0/s",
                $sql
            );
        }
    }

    public function test_no_laravel_migration_was_added_for_phase9(): void
    {
        $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        foreach ($migrations as $file) {
            self::assertStringNotContainsString(
                'student_registration_withdrawal',
                basename($file)
            );
        }
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
