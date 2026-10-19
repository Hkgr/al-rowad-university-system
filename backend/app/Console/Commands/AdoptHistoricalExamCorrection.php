<?php
namespace App\Console\Commands;
final class AdoptHistoricalExamCorrection extends HistoricalExamCommand
{
    protected $signature='historical-exams:adopt-correction {--actor=} {--bundle=} {--bundle-hash=} {--review=} {--review-hash=} {--operation-id=} {--expected-revision=} {--reason=} {--confirmed} {--output=}';
    protected $description='Explicit one-student reviewed historical correction; preserves approvals, announcement history and all before images.';
    protected string $operation='adopt-correction';
}
