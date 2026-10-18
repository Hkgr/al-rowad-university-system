<?php
namespace App\Console\Commands;
final class VerifyHistoricalExams extends HistoricalExamCommand
{
    protected $signature='historical-exams:verify {--actor=} {--bundle=} {--bundle-hash=} {--operation-id=} {--output=}';
    protected $description='Read-only content verification against immutable examination action receipts.';
    protected string $operation='verify';
}
