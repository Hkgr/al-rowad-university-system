<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AcademicCalendarPhase2ContractTest extends TestCase
{
    public function test_manual_rbac_package_is_narrow_and_phase_one_is_untouched(): void
    {
        $root = dirname(__DIR__, 2);
        $package = $root.'/database/sql/academic-calendar-phase2-rbac';
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists($package.'/'.$file);
        }
        self::assertSame([], glob($root.'/database/migrations/*academic*calendar*') ?: []);
        $apply = file_get_contents($package.'/01_apply.sql');
        self::assertStringContainsString('academic_calendar.manage', $apply);
        self::assertStringContainsString('vice_president_scientific', $apply);
        self::assertStringNotContainsString('CREATE TABLE', strtoupper($apply));

        foreach (['00_preflight.sql', '02_verify.sql'] as $readOnlyFile) {
            $sql = file_get_contents($package.'/'.$readOnlyFile);
            self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/im', $sql);
        }
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql'] as $sqlFile) {
            $sql = strtoupper(file_get_contents($package.'/'.$sqlFile));
            foreach (['DATABASE()', 'DELIMITER', 'SIGNAL', 'CREATE PROCEDURE', 'CREATE FUNCTION'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $sql);
            }
        }
    }

    public function test_read_routes_and_explicit_management_actions_are_declared(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/routes/api.php');
        foreach (['academic-calendar/catalog', 'academic-calendar/events', 'replacement-drafts', '/publish', '/cancel', '/history', '/activate', '/reopen', '/close'] as $contract) {
            self::assertStringContainsString($contract, $routes);
        }
        self::assertStringContainsString('effectivePermissions()', file_get_contents($root.'/app/Services/AcademicCalendarService.php'));
        self::assertStringNotContainsString('hasPermission(AcademicCalendar::PERMISSION_MANAGE', file_get_contents($root.'/app/Services/AcademicCalendarService.php'));
    }

    public function test_no_calendar_workflow_enforcement_or_phase_one_sql_changes_are_part_of_phase_two(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/AcademicCalendarService.php');
        foreach (['GradeService', 'GradePartWorkflowService', 'SupplementaryExam', 'RegistrationService', 'isWindowOpen'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $service);
        }
        self::assertStringContainsString("publication_status = 'superseded'", $service);
        self::assertStringContainsString("publication_status = 'published'", $service);
        self::assertStringContainsString('lockForUpdate()', $service);
        self::assertStringContainsString('whereNull(\'ace.cancelled_at\')', $service);
    }

    public function test_public_payload_keeps_cancellation_reason_private_and_optional_change_reason_is_nullable(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/AcademicCalendarService.php');
        $controller = file_get_contents($root.'/app/Http/Controllers/Api/ScientificVicePresidentAcademicCalendarController.php');
        $publicPayload = explode('private function managementPayload', explode('private function publicPayload', $service, 2)[1], 2)[0];

        self::assertStringNotContainsString("'cancellation_reason'", $publicPayload);
        self::assertStringContainsString("'cancellation_reason' => \$event->cancellation_reason", $service);
        self::assertStringContainsString("'change_reason' => ['sometimes', 'nullable', 'string', 'max:2000']", $controller);
        self::assertStringContainsString("blank(\$data['change_reason'] ?? null)", $service);
    }
}
