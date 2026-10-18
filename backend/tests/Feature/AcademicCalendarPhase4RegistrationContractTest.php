<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AcademicCalendarPhase4RegistrationContractTest extends TestCase
{
    public function test_phase_four_registration_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/academic_calendar_phase4_registration_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
