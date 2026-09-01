<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SemesterRegistrationMinimumCancellationReplacementPhase6ContractTest extends TestCase
{
    public function test_phase6_static_contract(): void
    {
        $contract=require dirname(__DIR__).'/Contracts/semester_registration_minimum_cancellation_replacement_phase6_contract.php';
        self::assertSame([],$contract(dirname(__DIR__,2)));
    }
}
