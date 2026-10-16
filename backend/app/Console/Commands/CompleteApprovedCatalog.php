<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CatalogCompletion\{ApprovedCatalogManifest, CatalogCompletionService};
use Illuminate\Console\Command;

final class CompleteApprovedCatalog extends Command
{
    protected $signature = 'academic-catalog:complete {--actor=} {--manifest=} {--manifest-hash=} {--expected-revision=} {--operation-id=} {--apply} {--resume}';
    protected $description = 'Preview the approved manifest, or explicitly apply/resume guarded catalog completion.';

    public function handle(CatalogCompletionService $service): int
    {
        try {
            if ($this->option('apply')) foreach (['actor', 'manifest', 'manifest-hash', 'expected-revision', 'operation-id'] as $key) {
                if (!$this->option($key)) throw new \InvalidArgumentException('--'.$key.' is required for --apply.');
            }
            if ($this->option('resume') && !$this->option('apply')) throw new \InvalidArgumentException('--resume requires --apply and a newly reviewed revision.');
            $actor = User::findOrFail($this->option('actor'));
            $manifest = new ApprovedCatalogManifest($this->option('manifest'), $this->option('manifest-hash'));
            $result = $this->option('apply') ? $service->apply($actor, $manifest, (string) $this->option('expected-revision'), $this->option('operation-id'), (bool) $this->option('resume')) : $service->preview($actor, $manifest);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return in_array($result['status'] ?? null, ['PARTIAL', 'BLOCKED', 'IN_PROGRESS'], true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(json_encode(['error_code' => $e->errorCode ?? 'catalog_completion_failed', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
