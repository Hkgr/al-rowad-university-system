<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CatalogCompletion\{ApprovedCatalogManifest, CatalogCompletionService};
use Illuminate\Console\Command;

final class VerifyApprovedCatalogCompletion extends Command
{
    protected $signature = 'academic-catalog:verify-completion {--actor=} {--manifest=} {--manifest-hash=}';
    protected $description = 'Verify actual completed plan/source contents, defaults and every student assignment without writing.';

    public function handle(CatalogCompletionService $service): int
    {
        try {
            $result = $service->verify(User::findOrFail($this->option('actor')), new ApprovedCatalogManifest($this->option('manifest'), $this->option('manifest-hash')));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return $result['status'] === 'COMPLETE' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}
