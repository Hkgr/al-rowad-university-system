<?php
namespace App\Console\Commands;
final class ReviewHistoricalExamCorrection extends HistoricalExamCommand
{
    protected $signature='historical-exams:review-correction {--actor=} {--bundle=} {--bundle-hash=} {--work-item=} {--output=}';
    protected $description='Read-only, scoped review of one staged historical correction; never adopt or approve during preview.';
    protected string $operation='review-correction';
}
