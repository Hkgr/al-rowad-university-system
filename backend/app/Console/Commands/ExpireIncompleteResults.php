<?php

namespace App\Console\Commands;

use App\Services\GradeService;
use Illuminate\Console\Command;

class ExpireIncompleteResults extends Command
{
    protected $signature = 'grades:expire-incomplete';

    protected $description = 'Convert unresolved incomplete results whose deadline has passed';

    public function handle(GradeService $grades): int
    {
        $converted = $grades->expireIncompleteResults();
        $this->info('Expired incomplete results: '.$converted);

        return self::SUCCESS;
    }
}
