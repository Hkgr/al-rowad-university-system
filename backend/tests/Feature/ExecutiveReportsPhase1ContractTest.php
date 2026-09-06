<?php

namespace Tests\Feature;

use App\Support\ExecutiveReportRegistry;
use App\Support\OfficialGradeScale;
use PHPUnit\Framework\TestCase;

class ExecutiveReportsPhase1ContractTest extends TestCase
{
    public function test_dependency_free_contract_passes(): void
    {
        $script = dirname(__DIR__).'/Contracts/executive_reports_phase1_contract.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    public function test_registry_and_shared_grade_scale_are_stable(): void
    {
        self::assertSame(4.0, OfficialGradeScale::points(OfficialGradeScale::letter(98), 'passed'));
        self::assertSame(0.0, OfficialGradeScale::points('A+', 'incomplete'));
        self::assertSame(0.0, OfficialGradeScale::points('B', 'failed'));
        self::assertContains('academic_performance', ExecutiveReportRegistry::subjects());
        self::assertSame(100, ExecutiveReportRegistry::LIMITS['per_page']);
    }
}
