<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AcademicCalendarSchemaCompatibilityRepairSqlContractTest extends TestCase
{
    public function test_repair_package_matches_the_narrow_static_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $contract = require $root.'/tests/Contracts/academic_calendar_schema_compatibility_repair_contract.php';

        self::assertSame([], $contract($root));
    }
}
