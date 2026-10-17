<?php
namespace App\Console\Commands;
final class PreviewStudentCompletion extends StudentCompletionCommand
{
    protected $signature='student-completion:preview {--actor=} {--bundle=} {--bundle-hash=} {--output=}';
    protected $description='Read-only scoped live reconciliation and immutable review proposals.';
    protected string $operation='preview';
}
