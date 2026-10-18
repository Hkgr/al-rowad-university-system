<?php
namespace Tests\Feature;
class UniversityEmailPhase3ContractTest extends \PHPUnit\Framework\TestCase
{
    public function test_contract(): void
    {
        require __DIR__.'/../Contracts/university_email_phase3_contract.php';
        $this->assertTrue(true);
    }
}
