<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AcademicCalendarPhase3PolicyContractTest extends TestCase
{
    public function test_phase_three_policy_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/academic_calendar_phase3_policy_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
