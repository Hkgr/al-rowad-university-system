<?php
namespace App\Console\Commands;
final class PrepareStudentCompletion extends StudentCompletionCommand
{
    protected $signature='student-completion:prepare {--sources=} {--handoff=} {--snapshot=} {--output=}';
    protected $description='Prepare a private, data-only source bundle and offline snapshot report without connecting to a database.';
    protected string $operation='prepare';
}
