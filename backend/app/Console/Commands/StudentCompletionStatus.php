<?php
namespace App\Console\Commands;
final class StudentCompletionStatus extends StudentCompletionCommand
{
    protected $signature='student-completion:status {--actor=} {--operation-id=} {--output=}';
    protected $description='Read an actor-bound reconciliation receipt without treating missing receipts as proof of rollback.';
    protected string $operation='status';
}
