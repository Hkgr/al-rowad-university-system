<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ExamManualGradePreparationContractTest extends TestCase
{
    public function test_source_contract(): void
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Contracts/exam_manual_grade_preparation_contract.php').' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }
}
