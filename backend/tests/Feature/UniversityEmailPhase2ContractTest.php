<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
final class UniversityEmailPhase2ContractTest extends TestCase
{
    public function test_contract(): void
    {
        ob_start(); require dirname(__DIR__).'/Contracts/university_email_phase2_contract.php';
        $this->assertStringContainsString('passed', ob_get_clean());
    }
}
