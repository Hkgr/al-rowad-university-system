<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinistryPlacementPhase1ContractTest extends TestCase
{
    #[Test]
    public function phase_one_contract_remains_narrow_and_safe(): void
    {
        $contract = require base_path('tests/Contracts/ministry_placement_phase1_contract.php');
        $this->assertSame([], $contract(base_path()), implode(PHP_EOL, $contract(base_path())));
    }
}
