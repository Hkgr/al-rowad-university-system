<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
final class CourseResultSymbolsContractTest extends TestCase
{
    public function test_dependency_free_contract(): void
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Contracts/course_result_symbols_phase1_contract.php').' 2>&1',$output,$code);
        self::assertSame(0,$code,implode("\n",$output));
    }
}
