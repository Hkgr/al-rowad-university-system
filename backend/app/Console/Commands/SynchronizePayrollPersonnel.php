<?php

namespace App\Console\Commands;

use App\Services\Payroll\PayrollPersonnelService;
use Illuminate\Console\Command;

final class SynchronizePayrollPersonnel extends Command
{
    protected $signature = 'payroll:sync-personnel';

    protected $description = 'Create missing employee_id financial profiles only; never delete or overwrite amounts/history';

    public function handle(PayrollPersonnelService $personnel): int
    {
        try {
            $this->line(json_encode($personnel->synchronize(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
