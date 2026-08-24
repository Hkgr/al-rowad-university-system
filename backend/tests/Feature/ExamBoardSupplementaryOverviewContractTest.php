<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExamBoardSupplementaryOverviewContractTest extends TestCase
{
    #[Test]
    public function dependency_free_contract_passes(): void
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Contracts/exam_board_supplementary_overview_contract.php'));
        exec($command, $output, $status);

        self::assertSame(0, $status, implode(PHP_EOL, $output));
    }
}
