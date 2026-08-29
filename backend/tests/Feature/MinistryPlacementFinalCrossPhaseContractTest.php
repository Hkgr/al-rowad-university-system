<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class MinistryPlacementFinalCrossPhaseContractTest extends TestCase
{
    public function test_final_cross_phase_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/ministry_placement_final_cross_phase_contract.php';
        self::assertSame([], $contract(dirname(__DIR__, 2)));
    }
}
