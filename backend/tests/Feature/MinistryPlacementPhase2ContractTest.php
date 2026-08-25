<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinistryPlacementPhase2ContractTest extends TestCase
{
    #[Test]
    public function phase_two_contract_remains_narrow_and_safe(): void
    {
        $contract = require base_path('tests/Contracts/ministry_placement_phase2_contract.php');
        $this->assertSame([], $contract(base_path()), implode(PHP_EOL, $contract(base_path())));
    }
}
