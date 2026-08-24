<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AcademicCalendarPhase5RegularExamOccurrenceContractTest extends TestCase
{
    public function test_phase_five_regular_exam_occurrence_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/academic_calendar_phase5_regular_exam_occurrence_contract.php';
        $errors = $contract(dirname(__DIR__, 2));

        self::assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
