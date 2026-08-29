<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class MinistryPlacementPhase5ContractTest extends TestCase
{
    public function test_phase5_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/ministry_placement_phase5_contract.php';
        self::assertSame([], $contract(dirname(__DIR__, 2)));
    }
}
