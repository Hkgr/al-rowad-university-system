<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CatalogCompletion\{ApprovedCatalogManifest, CatalogCompletionService};
use Illuminate\Console\Command;

final class ApprovedCatalogCompletionStatus extends Command
{
    protected $signature = 'academic-catalog:completion-status {operation-id} {--actor=} {--manifest=} {--manifest-hash=}';
    protected $description = 'Read the authorized durable completion receipt; never retry a write.';

    public function handle(CatalogCompletionService $service): int
    {
        try {
            $result = $service->status(User::findOrFail($this->option('actor')), new ApprovedCatalogManifest($this->option('manifest'), $this->option('manifest-hash')), $this->argument('operation-id'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
