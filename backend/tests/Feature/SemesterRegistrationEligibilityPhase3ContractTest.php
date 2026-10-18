<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SemesterRegistrationEligibilityPhase3ContractTest extends TestCase
{
    public function test_phase_three_eligibility_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/semester_registration_eligibility_phase3_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
