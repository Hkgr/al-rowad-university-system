<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Phase 8 source contracts that do not require the production MariaDB schema.
 * Database-backed dual-VP removal tests remain mandatory once that schema is available.
 */
class TeachingAssignmentLifecycleContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_stale_removal_http_conflict_is_raised_after_transaction_commits(): void
    {
        $workflow = self::source('app/Services/TeachingAssignmentWorkflowService.php');

        self::assertSame(
            4,
            substr_count($workflow, 'return $this->finishDecision($this->decide('),
            'VP decide paths must throw stale-removal conflicts only after finishDecision.'
        );
        self::assertStringContainsString(
            'HTTP conflicts for stale removal must be raised AFTER the supersede',
            $workflow
        );
        self::assertStringContainsString(
            'TeachingAssignmentException::REMOVAL_STALE => throw TeachingAssignmentException::removalStale()',
            $workflow
        );
        self::assertStringContainsString(
            'TeachingAssignmentException::REMOVAL_REQUIRES_CLOSED_OFFERING => throw TeachingAssignmentException::removalRequiresClosedOffering()',
            $workflow
        );

        $materializeRemoval = self::extractMethod($workflow, 'materializeRemoval');
        self::assertStringContainsString('supersedeUnlocked(', $materializeRemoval);
        self::assertStringContainsString(
            'return TeachingAssignmentException::REMOVAL_STALE;',
            $materializeRemoval
        );
        self::assertStringContainsString(
            'return TeachingAssignmentException::REMOVAL_REQUIRES_CLOSED_OFFERING;',
            $materializeRemoval
        );
        self::assertStringNotContainsString(
            'throw TeachingAssignmentException::removalStale()',
            $materializeRemoval
        );
        self::assertStringNotContainsString(
            'throw TeachingAssignmentException::removalRequiresClosedOffering()',
            $materializeRemoval
        );

        $finishDecision = self::extractMethod($workflow, 'finishDecision');
        self::assertStringNotContainsString('DB::transaction', $finishDecision);
        self::assertGreaterThan(
            strpos($workflow, 'private function decide('),
            strpos($workflow, 'private function finishDecision(')
        );
    }

    public function test_phase7_open_to_closed_remains_formal_workflow_only(): void
    {
        $exceptionPath = dirname(__DIR__, 2).'/app/Exceptions/CourseOfferingClosureException.php';
        if (! is_file($exceptionPath)) {
            self::markTestSkipped('Phase 7 is not in the current base; rebase onto merged PR #76 first.');
        }

        $opening = self::source('app/Services/CourseOfferingOpeningService.php');
        self::assertStringContainsString('CourseOfferingClosureException::workflowRequired()', $opening);
        self::assertStringContainsString('assertNoPendingInstructorRemoval(', $opening);

        $controller = self::source('app/Http/Controllers/Api/CourseOfferingController.php');
        self::assertStringContainsString('CourseOfferingClosureException::workflowRequired()', $controller);

        $context = self::source('app/Services/CourseOfferingContextService.php');
        self::assertStringContainsString('CourseOfferingClosureException::workflowRequired()', $context);

        $dean = self::source('app/Services/DeanRegistrationOfferingService.php');
        $closeOffering = self::extractMethod($dean, 'closeOffering');
        self::assertStringContainsString('CourseOfferingClosureException::workflowRequired()', $closeOffering);
        self::assertStringNotContainsString('$offering->status = self::STATUS_CLOSED;', $closeOffering);
    }

    public function test_sql_package_layout_is_unchanged(): void
    {
        $dir = dirname(__DIR__, 2).'/database/sql/teaching-assignment-lifecycle';
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists($dir.'/'.$file);
        }
        $readme = self::source('database/sql/teaching-assignment-lifecycle/README.md');
        self::assertStringContainsString('TA8-37', $readme);
        self::assertStringContainsString('TA8-38', $readme);
        self::assertStringContainsString('TA8-39', $readme);
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
            "Expected exactly one private method {$name}()."
        );

        return $matches[0];
    }
}
