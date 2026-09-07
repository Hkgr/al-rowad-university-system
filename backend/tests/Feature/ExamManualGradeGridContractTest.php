<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ExamManualGradeGridContractTest extends TestCase
{
    public function test_dependency_free_contract(): void
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Contracts/exam_manual_grade_grid_contract.php').' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }
}
