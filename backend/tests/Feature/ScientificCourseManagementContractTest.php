<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class ScientificCourseManagementContractTest extends TestCase
{
    public function test_source_and_manual_sql_boundaries(): void
    {
        require dirname(__DIR__).'/Contracts/scientific_course_management_contract.php';
        $this->addToAssertionCount(1);
    }
}
