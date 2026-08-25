<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamEndToEndHardeningContractTest extends TestCase
{
    #[Test]
    public function dependency_free_hardening_contract_passes(): void
    {
        $contract = base_path('tests/Contracts/supplementary_exam_end_to_end_hardening_contract.php');
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($contract).' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode(PHP_EOL, $output));
    }
}
