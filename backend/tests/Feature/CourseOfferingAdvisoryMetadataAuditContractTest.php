<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CourseOfferingAdvisoryMetadataAuditContractTest extends TestCase
{
    public function test_offer_adv_01_to_13_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/course_offering_advisory_metadata_audit_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
