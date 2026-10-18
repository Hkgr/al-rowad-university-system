<?php
namespace App\Console\Commands;
final class PreviewHistoricalExams extends HistoricalExamCommand
{
    protected $signature='historical-exams:preview {--actor=} {--bundle=} {--bundle-hash=} {--output=}';
    protected $description='Read-only scoped reconciliation and immutable missing-record review.';
    protected string $operation='preview';
}
