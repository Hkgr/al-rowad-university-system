<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SemesterRegistrationDeadlinesPhase2ContractTest extends TestCase
{
    public function test_phase_two_deadline_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/semester_registration_deadlines_phase2_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
