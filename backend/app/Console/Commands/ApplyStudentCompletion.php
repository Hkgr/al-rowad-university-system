<?php
namespace App\Console\Commands;
final class ApplyStudentCompletion extends StudentCompletionCommand
{
    protected $signature='student-completion:apply {--actor=} {--bundle=} {--bundle-hash=} {--review=} {--review-hash=} {--operation-id=} {--expected-revision=} {--resume} {--output=}';
    protected $description='Explicit reviewed historical admission, bounded identity correction and discontinuation; atomic per student, no automatic retry.';
    protected string $operation='apply';
}
