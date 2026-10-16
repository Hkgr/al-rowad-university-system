<?php

namespace App\Console\Commands;

use App\Exceptions\GradeException;
use App\Models\CourseIncompleteCase;
use App\Services\IncompleteResultService;
use App\Support\IncompleteOutcomeGuard;
use Illuminate\Console\Command;

final class ExpireIncompleteResults extends Command
{
    protected $signature = 'course-results:expire-incomplete {--batch=100 : Maximum cases per invocation (1-1000)}';
    protected $description = 'Expire overdue explicit I cases atomically; no inferred grants or invented component marks.';
    public function handle(IncompleteResultService $service): int
    {
        $size = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($size === false) { $this->error('batch must be between 1 and 1000.'); return self::INVALID; }
        IncompleteOutcomeGuard::assertReady();
        $ids = CourseIncompleteCase::where('state', 'active')->where('deadline_at', '<', now('UTC')->format('Y-m-d H:i:s'))
            ->orderBy('deadline_at')->orderBy('incomplete_case_id')->limit($size)->pluck('incomplete_case_id');
        $failed = false; $count = 0;
        foreach ($ids as $id) {
            try { if ($service->expire((int) $id)) $count++; }
            catch (GradeException $e) { $failed = true; $this->error('Case '.$id.': '.$e->errorCode); }
        }
        $this->info('Expired: '.$count.'. Examined: '.$ids->count().'.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
