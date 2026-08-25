<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CourseOfferingOperationalSourceOfTruthContractTest extends TestCase
{
    public function test_operational_source_of_truth_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/course_offering_operational_source_of_truth_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode("\n", $errors));
    }
}
