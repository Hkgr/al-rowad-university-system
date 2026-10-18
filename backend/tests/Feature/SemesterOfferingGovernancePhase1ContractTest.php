<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SemesterOfferingGovernancePhase1ContractTest extends TestCase
{
    public function test_phase_one_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/semester_offering_governance_phase1_contract.php';

        self::assertSame([], $contract(dirname(__DIR__, 2)));
    }
}
