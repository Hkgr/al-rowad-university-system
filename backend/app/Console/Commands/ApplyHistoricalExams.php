<?php
namespace App\Console\Commands;
final class ApplyHistoricalExams extends HistoricalExamCommand
{
    protected $signature='historical-exams:apply {--actor=} {--bundle=} {--bundle-hash=} {--review=} {--review-hash=} {--expected-revision=} {--operation-id=} {--resume} {--output=}';
    protected $description='Explicit reviewed atomic missing-record drafts only; never corrections or approvals.';
    protected string $operation='apply';
}
