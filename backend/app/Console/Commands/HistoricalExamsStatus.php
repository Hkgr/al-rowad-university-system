<?php
namespace App\Console\Commands;
final class HistoricalExamsStatus extends HistoricalExamCommand
{
    protected $signature='historical-exams:status {--actor=} {--operation-id=} {--catalog-credits} {--output=}';
    protected $description='Read an actor-owned historical-examination operation after fresh scope authorization.';
    protected string $operation='status';
}
