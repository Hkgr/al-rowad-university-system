<?php

namespace Tests\Feature;

final class AcademicProgramManagementContractTest extends \PHPUnit\Framework\TestCase
{
    public function test_dependency_free_contract(): void
    {
        ob_start();
        try { require dirname(__DIR__).'/Contracts/academic_program_management_contract.php'; }
        finally { ob_end_clean(); }
        self::assertTrue(true);
    }
}
