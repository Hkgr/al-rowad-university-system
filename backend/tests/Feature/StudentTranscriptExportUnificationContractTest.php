<?php

namespace Tests\Feature;

use Tests\TestCase;

class StudentTranscriptExportUnificationContractTest extends TestCase
{
    public function test_dependency_free_contract(): void
    {
        $contract = require dirname(__DIR__).'/Contracts/student_transcript_export_unification_contract.php';

        self::assertSame([], $contract(dirname(__DIR__, 2)));
    }
}
