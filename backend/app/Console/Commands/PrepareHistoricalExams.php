<?php
namespace App\Console\Commands;
final class PrepareHistoricalExams extends HistoricalExamCommand
{
    protected $signature='historical-exams:prepare {--snapshot=} {--snapshot-hash=} {--exam1=} {--exam1-hash=} {--exam2=} {--exam2-hash=} {--output=}';
    protected $description='Prepare immutable private historical-examination evidence, offline; never execute source SQL or VBA.';
    protected string $operation='prepare';
}
