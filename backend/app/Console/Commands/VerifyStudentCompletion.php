<?php
namespace App\Console\Commands;
final class VerifyStudentCompletion extends StudentCompletionCommand
{
    protected $signature='student-completion:verify {--actor=} {--bundle=} {--bundle-hash=} {--operation-id=} {--output=}';
    protected $description='Verify current written values and protected academic evidence without mutation.';
    protected string $operation='verify';
}
