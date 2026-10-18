<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SemesterRegistrationTimetablePhase4ContractTest extends TestCase
{
    public function test_phase_four_timetable_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/semester_registration_timetable_phase4_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
