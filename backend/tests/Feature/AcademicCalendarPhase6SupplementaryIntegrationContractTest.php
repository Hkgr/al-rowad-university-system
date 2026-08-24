<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AcademicCalendarPhase6SupplementaryIntegrationContractTest extends TestCase
{
    public function test_phase_six_supplementary_integration_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/academic_calendar_phase6_supplementary_integration_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
