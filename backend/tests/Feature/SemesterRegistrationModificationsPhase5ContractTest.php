<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SemesterRegistrationModificationsPhase5ContractTest extends TestCase
{
    public function test_phase5_static_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/semester_registration_modifications_phase5_contract.php';

        self::assertSame([], $contract(dirname(__DIR__, 2)));
    }
}
