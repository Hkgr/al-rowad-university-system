<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Recalculates an exported .xlsx with a real spreadsheet engine (LibreOffice, headless) and returns the computed grid.
 *
 * The cached results PhpSpreadsheet writes are stripped first, so what is read back is only what the exported FORMULAS compute.
 * Formula strings and cached values are never what is compared.
 */
trait RecalculatesWorkbooks
{
    protected function libreOfficeAvailable(): bool
    {
        return trim((string) shell_exec('command -v soffice')) !== '';
    }

    /**
     * @return list<list<string>> the recalculated grid (row 0 = sheet row 1); numbers as plain decimal text, unavailable ("") as ''
     */
    protected function recalculate(string $xlsxPath): array
    {
        $dir = sys_get_temp_dir().'/payroll_lo_'.bin2hex(random_bytes(4));
        mkdir($dir.'/out', 0777, true);
        $stripped = $dir.'/book.xlsx';
        $book = IOFactory::load($xlsxPath);
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setPreCalculateFormulas(false); // no cached results: LibreOffice has to evaluate the formulas itself
        $writer->save($stripped);
        $command = sprintf(
            'soffice -env:UserInstallation=file://%s --headless --convert-to xlsx:"Calc MS Excel 2007 XML" --outdir %s %s 2>&1',
            escapeshellarg($dir.'/profile'), escapeshellarg($dir.'/out'), escapeshellarg($stripped),
        );
        exec($command, $output);
        $this->assertFileExists($dir.'/out/book.xlsx', 'LibreOffice did not produce a workbook: '.implode("\n", $output));
        $sheet = IOFactory::load($dir.'/out/book.xlsx')->getActiveSheet();
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $line = [];
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $line[] = is_float($value) || is_int($value) ? rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.') : (string) $value;
            }
            $rows[] = $line;
        }
        shell_exec('rm -rf '.escapeshellarg($dir));

        return $rows;
    }
}
