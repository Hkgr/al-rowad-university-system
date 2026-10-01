<?php

namespace Tests\Feature;

use Tests\TestCase;

class UniversityEmailPhase1ContractTest extends TestCase
{
    public function test_static_phase_boundaries(): void
    {
        $contract = require base_path('tests/Contracts/university_email_phase1_contract.php');
        $this->assertSame([], $contract(base_path()));
    }
}
