<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class MinistryPlacementPhase4ContractTest extends TestCase
{
    public function test_phase4_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/ministry_placement_phase4_contract.php';
        self::assertSame([], $contract(dirname(__DIR__, 2)));
    }
}
