<?php

namespace App\Console\Commands;

use App\Services\Payroll\PayrollPdfExport;
use Illuminate\Console\Command;

final class PreparePayrollPdfFonts extends Command
{
    protected $signature = 'payroll:prepare-pdf-fonts';

    protected $description = 'Prepare bundled OFL Cairo with the officially Composer-installed TCPDF core fonts; no database writes';

    public function handle(PayrollPdfExport $pdf): int
    {
        try {
            $this->info('PDF font cache prepared: '.$pdf->fontDirectory());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
