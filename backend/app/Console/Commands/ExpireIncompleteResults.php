<?php

namespace App\Console\Commands;

use App\Exceptions\GradeException;
use App\Models\CourseIncompleteCase;
use App\Services\IncompleteResultService;
use App\Support\IncompleteOutcomeGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Log};

final class ExpireIncompleteResults extends Command
{
    protected $signature = 'course-results:expire-incomplete {--batch=100 : Maximum cases per invocation (1-1000)}';
    protected $description = 'Expire overdue explicit I cases atomically; no inferred grants or invented component marks.';
    public function handle(IncompleteResultService $service): int
    {
        $size = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($size === false) { $this->error('batch must be between 1 and 1000.'); return self::INVALID; }
        if (DB::transactionLevel() !== 0) { $this->error('Expiration must run outside an existing transaction.'); return self::FAILURE; }
        IncompleteOutcomeGuard::assertReady();
        $driver = config('cache.stores.'.config('cache.default').'.driver');
        if (in_array($driver, ['array', 'null', 'session', 'octane', 'failover'], true)) {
            $this->error('Expiration requires a persistent cache with atomic locks; the progress cursor must survive scheduled invocations.');
            return self::FAILURE;
        }
        $connection = DB::connection();
        $key = 'course-results:expiry-scan:'.hash('sha256', json_encode([$connection->getDriverName(), $connection->getConfig('host'), $connection->getConfig('port'), $connection->getDatabaseName()]));
        $lock = null; $acquired = false;
        try {
            $cache = Cache::store(); $lock = $cache->lock($key.':lock', 3600);
            $acquired = $lock->get();
            if (!$acquired) { $this->error('Expiration scan is already running.'); return self::FAILURE; }
            $cursor = $cache->get($key);
            if ($cursor !== null && (!is_array($cursor) || !isset($cursor['deadline'], $cursor['id'])
                || !is_int($cursor['id']) || $cursor['id'] < 1 || !is_string($cursor['deadline']) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $cursor['deadline']))) {
                $this->error('Expiration progress cursor is invalid; no academic data was changed.'); return self::FAILURE;
            }
            $query = CourseIncompleteCase::where('state', 'active')->where('deadline_at', '<', now('UTC')->format('Y-m-d H:i:s'));
            $ordered = fn ($q) => $q->orderBy('deadline_at')->orderBy('incomplete_case_id')->limit($size)->get(['incomplete_case_id', 'deadline_at']);
            $after = clone $query;
            if ($cursor !== null) $after->where(fn ($q) => $q->where('deadline_at', '>', $cursor['deadline'])
                ->orWhere(fn ($tie) => $tie->where('deadline_at', $cursor['deadline'])->where('incomplete_case_id', '>', $cursor['id'])));
            $rows = $ordered($after);
            if ($rows->isEmpty() && $cursor !== null) $rows = $ordered($query); // One bounded wrap, never an offset scan.
            $failed = false; $count = 0;
            foreach ($rows as $row) {
                try { if ($service->expire((int) $row->getKey())) $count++; }
                catch (GradeException $e) {
                    $failed = true; $this->error('Case '.$row->getKey().': '.$e->errorCode);
                    Log::warning('Incomplete expiration blocked', ['incomplete_case_id' => (int) $row->getKey(), 'error_code' => $e->errorCode]);
                }
                catch (\Throwable $e) { $failed = true; report($e); $this->error('Case '.$row->getKey().': expiration failed; see application log ('.get_class($e).').'); }
                // Operational state only, outside the independently completed/rolled-back case transaction.
                // A blocked row stays untouched but cannot pin every later scheduled batch.
                if (!$cache->forever($key, ['deadline' => $row->deadline_at->utc()->format('Y-m-d H:i:s'), 'id' => (int) $row->getKey()]))
                    throw new \RuntimeException('Expiration progress cursor could not be persisted.');
            }
            $this->info('Expired: '.$count.'. Examined: '.$rows->count().'.');
            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            report($e); $this->error('Expiration progress/cache unavailable; review the application log.'); return self::FAILURE;
        } finally {
            if ($acquired) try { $lock->release(); } catch (\Throwable $e) {
                report($e); $this->error('Expiration progress lock could not be released; review the cache.'); return self::FAILURE;
            }
        }
    }
}
