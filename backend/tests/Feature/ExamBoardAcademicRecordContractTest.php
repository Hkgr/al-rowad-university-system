<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ExamBoardAcademicRecordContractTest extends TestCase
{
    public function test_academic_record_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/exam_board_academic_record_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode("\n", $errors));
    }
}
