<?php
namespace App\Console\Commands;
final class ApplyHistoricalExams extends HistoricalExamCommand
{
    protected $signature='historical-exams:apply {--actor=} {--bundle=} {--bundle-hash=} {--review=} {--review-hash=} {--expected-revision=} {--operation-id=} {--resume} {--catalog-credits} {--output=}';
    protected $description='Explicit reviewed drafts/staging, or separately authorized catalog-credit publication. Never auto-approve grades.';
    protected string $operation='apply';
}
