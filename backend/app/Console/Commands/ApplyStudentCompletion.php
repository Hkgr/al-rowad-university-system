<?php
namespace App\Console\Commands;
final class ApplyStudentCompletion extends StudentCompletionCommand
{
    protected $signature='student-completion:apply {--actor=} {--bundle=} {--bundle-hash=} {--review=} {--review-hash=} {--operation-id=} {--expected-revision=} {--resume} {--output=}';
    protected $description='Explicit ready-only historical student completion/discontinuation, atomic per student; never automatically retries.';
    protected string $operation='apply';
}
