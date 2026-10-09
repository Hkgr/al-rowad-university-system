<?php

namespace App\Services\Payroll;

use RuntimeException;
use TCPDF_FONTS;

/**
 * PDF export of one consistent snapshot: the selected export columns only, figures in Syrian pounds, headings (group band and
 * column names) repeated on every page, "page X of Y", and the totals closing each table. TCPDF (LGPL) performs Arabic shaping
 * and bidi; the SIL-OFL Cairo font (the UI font) is embedded as a subset. A3 landscape; a wide report is split into page groups
 * that repeat the employee number and name instead of shrinking the type.
 */
final class PayrollPdfExport
{
    private const PAGE_FORMAT = 'A3';

    private const MARGIN = 12;

    private const FONT_SIZE = 9.5;

    private const USABLE = 396; // A3 landscape width minus both margins (mm)

    private const IDENTITY_WIDTHS = ['employee_number' => 26, 'full_name' => 52, 'job_title' => 44, 'body' => 36, 'workplace' => 30, 'academic_level' => 34];

    /** Returns the PDF bytes. */
    public function build(array $snapshot, array $scopeLabels): string
    {
        $fontDir = $this->ensureFonts();
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontDir.'/');
        }
        $config = $snapshot['config'];
        $columns = PayrollColumns::exportColumns($config);

        $pdf = new PayrollPdfDocument('L', 'mm', self::PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->setCreator('Alrowad University');
        $pdf->setTitle(PayrollColumns::TITLE);
        $pdf->setRTL(true);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setMargins(self::MARGIN, 38, self::MARGIN);
        $pdf->setHeaderMargin(8);
        $pdf->setFooterMargin(10);
        $pdf->setAutoPageBreak(false);
        $pdf->setCellPaddings(1.5, 1, 1.5, 1);
        $pdf->AddFont('cairo', '', $fontDir.'/cairo.php');
        $pdf->AddFont('cairo', 'B', $fontDir.'/cairob.php');
        $pdf->headerLines = [
            PayrollColumns::TITLE,
            PayrollColumns::scopeLine($snapshot, $scopeLabels),
            PayrollColumns::sortLine($snapshot['filters'], $config).' — تاريخ التوليد: '.now()->format('Y-m-d H:i'),
            PayrollColumns::CURRENCY_NOTE.' '.PayrollColumns::CURRENT_SHEET_NOTE,
        ];

        $bands = $this->bands($columns);
        foreach ($bands as $index => $band) {
            $pdf->AddPage(); // closes the previous page (its footer still carries the previous label)
            $pdf->bandLabel = count($bands) > 1 ? 'الجزء '.($index + 1).' من '.count($bands) : '';
            $this->drawBand($pdf, $band, $snapshot);
        }

        return $pdf->Output('payroll.pdf', 'S');
    }

    private function width(array $column): float
    {
        return self::IDENTITY_WIDTHS[$column['key']] ?? match ($column['type']) {
            'text' => 36, 'percent' => 22, 'number' => 24, default => 31,
        };
    }

    /**
     * Split the columns over as many page groups as needed to stay readable at 9.5 pt. The first group keeps every identity column;
     * later groups repeat the employee number and name so each row stays identifiable on its own.
     *
     * @return list<array{columns: list<array>, widths: list<float>}>
     */
    private function bands(array $columns): array
    {
        $identity = array_values(array_filter($columns, fn ($c) => $c['identity']));
        $metrics = array_values(array_filter($columns, fn ($c) => ! $c['identity']));
        $anchorWide = $identity;
        $anchorNarrow = array_slice($identity, 0, 2);
        $bands = [];
        $current = $anchorWide;
        $used = array_sum(array_map(fn ($c) => $this->width($c), $current));
        foreach ($metrics as $metric) {
            $w = $this->width($metric);
            if ($used + $w > self::USABLE && count($current) > count($bands === [] ? $anchorWide : $anchorNarrow)) {
                $bands[] = $current;
                $current = $anchorNarrow;
                $used = array_sum(array_map(fn ($c) => $this->width($c), $current));
            }
            $current[] = $metric;
            $used += $w;
        }
        $bands[] = $current;

        return array_map(function (array $cols) {
            $widths = array_map(fn ($c) => $this->width($c), $cols);
            $extra = (self::USABLE - array_sum($widths)) / max(1, count($cols));
            $stretch = $extra > 0 && $extra < 12 ? $extra : 0; // spread slack so the table spans the page, but never balloon a short table

            return ['columns' => $cols, 'widths' => array_map(fn ($w) => $w + $stretch, $widths)];
        }, $bands);
    }

    // Palette (RGB). Hierarchy: dark group band > light column headings; entered data warm, calculated neutral, final net green.
    private const GROUP_FILL = [31, 61, 18];

    private const HEAD_FILL = [238, 244, 231];

    private const HEAD_INPUT_FILL = [250, 243, 226];

    private const HEAD_CALC_FILL = [231, 235, 227];

    private const HEAD_TEXT = [26, 46, 16];

    private const NET_HEAD_FILL = [59, 122, 34];

    private const INPUT_FILL = [255, 251, 242];

    private const CALC_FILL = [243, 244, 240];

    private const NET_FILL = [227, 240, 216];

    private const UNAVAILABLE_FILL = [236, 234, 231];

    private const GRID = [196, 206, 190];

    private const BOUNDARY = [96, 130, 78];

    private const NET_LINE = [47, 106, 28];

    private const INPUT_RULE = [180, 83, 9];

    private const LEGEND_HEIGHT = 5.5;

    private const GROUP_HEIGHT = 8.0;

    private function side(float $width, array $color): array
    {
        return ['width' => $width, 'color' => $color, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0];
    }

    /** Cell border: thin grid lines, a firmer rule on the first column of every group, and a strong rule either side of the final net column. */
    private function border(array $columns, int $i, ?array $bottom = null): array
    {
        $c = $columns[$i];
        $groupStart = $i === 0 || $columns[$i - 1]['group'] !== $c['group'];
        $thin = $this->side(0.15, self::GRID);
        $edge = $c['net'] ? $this->side(0.6, self::NET_LINE) : $thin;

        return ['T' => $thin, 'B' => $bottom ?? $thin, 'L' => $edge, 'R' => $c['net'] ? $edge : ($groupStart ? $this->side(0.45, self::BOUNDARY) : $thin)];
    }

    private function drawBand(PayrollPdfDocument $pdf, array $band, array $snapshot): void
    {
        $columns = $band['columns'];
        $widths = $band['widths'];
        $limit = $pdf->getPageHeight() - 22;
        $this->tableHeader($pdf, $columns, $widths);

        foreach ($snapshot['rows'] as $r) {
            $cells = array_map(fn ($c) => PayrollColumns::display($c, $r), $columns);
            $height = $this->rowHeight($pdf, $cells, $widths);
            if ($pdf->GetY() + $height > $limit) {
                $pdf->AddPage();
                $this->tableHeader($pdf, $columns, $widths);
            }
            $this->row($pdf, $columns, $cells, $widths, $height, $r);
        }

        if ($pdf->GetY() + 9 > $limit) {
            $pdf->AddPage();
            $this->tableHeader($pdf, $columns, $widths);
        }
        $pdf->setFont('cairo', 'B', self::FONT_SIZE);
        $pdf->setTextColor(...self::HEAD_TEXT);
        $pdf->setFillColor(232, 241, 223);
        // The label spans the leading columns that carry no total; each summed column shows its total.
        $labelSpan = 0;
        foreach ($columns as $c) {
            if ($c['aggregation'] === 'sum') {
                break;
            }
            $labelSpan++;
        }
        $labelSpan = max(1, $labelSpan);
        $top = $this->side(0.5, self::BOUNDARY);
        $pdf->MultiCell(array_sum(array_slice($widths, 0, $labelSpan)), 9, 'الإجمالي ('.$snapshot['totals']['employees'].' موظفًا)', ['T' => $top, 'B' => $this->side(0.15, self::GRID), 'L' => $this->side(0.15, self::GRID), 'R' => $this->side(0.45, self::BOUNDARY)], 'R', true, 0, '', '', true, 0, false, true, 9, 'M');
        foreach (array_slice($columns, $labelSpan, null, true) as $i => $c) {
            $total = $c['aggregation'] === 'sum' ? PayrollColumns::formatValue($snapshot['totals']['columns'][$c['key']]['sum'] ?? '0', $c['type']) : '';
            $negative = str_starts_with($total, '-');
            $border = $this->border($columns, $i);
            $border['T'] = $top;
            if ($c['net']) {
                // The final total is the emphasised figure of the report: larger bold type on the darker green, heavy top rule.
                $pdf->setFillColor(207, 229, 191);
                $pdf->setFont('cairo', 'B', 11.5);
                $border['T'] = $this->side(0.9, self::NET_LINE);
            } else {
                $pdf->setFillColor(232, 241, 223);
                $pdf->setFont('cairo', 'B', self::FONT_SIZE);
            }
            $negative ? $pdf->setTextColor(185, 28, 28) : $pdf->setTextColor(...self::HEAD_TEXT);
            $pdf->MultiCell($widths[$i], 9, $total, $border, 'C', true, 0, '', '', true, 0, false, true, 9, 'M');
        }
        $pdf->setTextColor(0, 0, 0);
        $pdf->Ln(9);
        $note = PayrollColumns::exclusionNote($columns, $snapshot);
        if ($note !== null) {
            $pdf->setFont('cairo', '', 8.5);
            $pdf->MultiCell(0, 6, $note, 0, 'R', false, 1);
        }
    }

    /**
     * Three stacked rows, all repeated on every page and every horizontal part: the key to the column styles, the dark group band
     * (consecutive columns of a group share one merged cell, so spans are right for each part), and the light column headings.
     */
    private function tableHeader(PayrollPdfDocument $pdf, array $columns, array $widths): void
    {
        $this->legend($pdf, $columns);

        $pdf->setFont('cairo', 'B', 10.5);
        $pdf->setFillColor(...self::GROUP_FILL);
        $pdf->setTextColor(255, 255, 255);
        $white = $this->side(0.6, [255, 255, 255]);
        for ($i = 0, $n = count($columns); $i < $n;) {
            $j = $i;
            $span = 0.0;
            while ($j < $n && $columns[$j]['group'] === $columns[$i]['group']) {
                $span += $widths[$j];
                $j++;
            }
            $pdf->MultiCell($span, self::GROUP_HEIGHT, $columns[$i]['group_label'], ['T' => $this->side(0.15, self::GROUP_FILL), 'B' => $this->side(0.15, self::GROUP_FILL), 'L' => $white, 'R' => $white], 'C', true, 0, '', '', true, 0, false, true, self::GROUP_HEIGHT, 'M');
            $i = $j;
        }
        $pdf->Ln(self::GROUP_HEIGHT);

        $pdf->setFont('cairo', 'B', self::FONT_SIZE);
        $headings = array_map(fn ($c) => $c['kind'] === 'formula' ? 'ƒ '.$c['heading'] : $c['heading'], $columns);
        $height = 10;
        foreach ($columns as $i => $c) {
            $height = max($height, $pdf->getNumLines($headings[$i], $widths[$i]) * 4.6 + 2.4);
        }
        foreach ($columns as $i => $c) {
            $border = $this->border($columns, $i);
            if ($c['net']) {
                $pdf->setFillColor(...self::NET_HEAD_FILL);
                $pdf->setTextColor(255, 255, 255);
                $border['B'] = $this->side(0.9, self::GROUP_FILL);
            } else {
                $pdf->setFillColor(...match ($c['kind']) {
                    'input' => self::HEAD_INPUT_FILL, 'formula' => self::HEAD_CALC_FILL, default => self::HEAD_FILL,
                });
                $pdf->setTextColor(...self::HEAD_TEXT);
                // Entered columns carry a firm rule under the heading, so the difference survives a black-and-white print.
                $border['B'] = $c['kind'] === 'input' ? $this->side(0.9, self::INPUT_RULE) : $this->side(0.3, self::BOUNDARY);
            }
            $pdf->MultiCell($widths[$i], $height, $headings[$i], $border, 'C', true, 0, '', '', true, 0, false, true, $height, 'M');
        }
        $pdf->Ln($height);
        $pdf->setTextColor(0, 0, 0);
    }

    /** One compact line naming the column styles that are present in this part (swatch + words; the words carry the meaning). */
    private function legend(PayrollPdfDocument $pdf, array $columns): void
    {
        $kinds = array_unique(array_map(fn ($c) => $c['net'] ? 'net' : $c['kind'], $columns));
        $items = array_values(array_filter([
            in_array('input', $kinds, true) ? ['بيانات مدخلة', self::INPUT_FILL, $this->side(0.9, self::INPUT_RULE)] : null,
            in_array('formula', $kinds, true) ? ['قيم محسوبة (ƒ)', self::CALC_FILL, $this->side(0.3, self::BOUNDARY)] : null,
            in_array('net', $kinds, true) ? ['الصافي النهائي', self::NET_HEAD_FILL, $this->side(0.9, self::GROUP_FILL)] : null,
        ]));
        $pdf->setFont('cairo', '', 8.5);
        $pdf->setTextColor(...self::HEAD_TEXT);
        $thin = $this->side(0.15, self::GRID);
        foreach ($items as [$label, $fill, $rule]) {
            $pdf->setFillColor(...$fill);
            $pdf->MultiCell(7, self::LEGEND_HEIGHT, '', ['T' => $thin, 'L' => $thin, 'R' => $thin, 'B' => $rule], 'C', true, 0, '', '', true, 0, false, true, self::LEGEND_HEIGHT, 'M');
            $pdf->MultiCell(36, self::LEGEND_HEIGHT, $label, 0, 'R', false, 0, '', '', true, 0, false, true, self::LEGEND_HEIGHT, 'M');
        }
        $pdf->Ln(self::LEGEND_HEIGHT);
    }

    private function rowHeight(PayrollPdfDocument $pdf, array $cells, array $widths): float
    {
        $pdf->setFont('cairo', 'B', self::FONT_SIZE); // the final net column is bold, the widest case
        $lines = 1;
        foreach ($widths as $i => $width) {
            $lines = max($lines, $pdf->getNumLines((string) $cells[$i], $width));
        }

        return max(8.0, $lines * 4.6 + 2.4);
    }

    private function row(PayrollPdfDocument $pdf, array $columns, array $cells, array $widths, float $height, array $sheetRow): void
    {
        foreach ($columns as $i => $c) {
            $cell = $c['identity'] ? null : $sheetRow['cells'][$c['key']];
            $numeric = ! $c['identity'] && $c['type'] !== 'text';
            $unavailable = $cell !== null && in_array($cell['st'], ['missing', 'error'], true);
            // An unavailable result never wears the "valid payable" green: calculated/net cells go neutral grey with a plain "ناقص"/"خطأ".
            $fill = match (true) {
                $c['identity'] => null,
                $unavailable && ($c['kind'] === 'formula' || $c['net']) => self::UNAVAILABLE_FILL,
                $c['net'] => self::NET_FILL,
                $c['kind'] === 'formula' => self::CALC_FILL,
                default => self::INPUT_FILL,
            };
            $bold = $c['net'] && ! $unavailable;
            $pdf->setFont('cairo', $bold ? 'B' : '', self::FONT_SIZE);
            if ($cell !== null && ($cell['st'] === 'error' || ($cell['v'] !== null && str_starts_with($cell['v'], '-') && $c['type'] === 'amount'))) {
                $pdf->setTextColor(185, 28, 28);
            } elseif ($cell !== null && $cell['st'] === 'missing') {
                $pdf->setTextColor(120, 113, 108);
            }
            if ($fill !== null) {
                $pdf->setFillColor(...$fill);
            }
            $pdf->MultiCell($widths[$i], $height, (string) $cells[$i], $this->border($columns, $i), $numeric ? 'C' : 'R', $fill !== null, 0, '', '', true, 0, false, true, $height, 'M');
            $pdf->setTextColor(0, 0, 0);
        }
        $pdf->Ln($height);
    }

    /** Convert the shipped OFL TTFs into TCPDF font definitions once (cached in storage). */
    private function ensureFonts(): string
    {
        $dir = storage_path('app/pdf-fonts');
        if (is_file($dir.'/cairo.php') && is_file($dir.'/cairob.php') && is_file($dir.'/helvetica.php')) {
            return $dir;
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('PDF font cache directory is not writable.');
        }
        $lock = fopen($dir.'/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            // TCPDF's constructor selects the core "helvetica" definition from the same font directory.
            $core = base_path('vendor/tecnickcom/tcpdf/fonts/helvetica.php');
            if (! is_file($dir.'/helvetica.php') && ! copy($core, $dir.'/helvetica.php')) {
                throw new RuntimeException('Could not prepare the PDF core font definition.');
            }
            foreach (['Cairo-Regular' => 'cairo', 'Cairo-Bold' => 'cairob'] as $file => $name) {
                if (is_file($dir.'/'.$name.'.php')) {
                    continue;
                }
                $tmp = $dir.'/tmp-'.getmypid().'-'.$name;
                @mkdir($tmp, 0775, true);
                copy(resource_path("pdf-fonts/{$file}.ttf"), "{$tmp}/{$name}.ttf");
                if (! TCPDF_FONTS::addTTFfont("{$tmp}/{$name}.ttf", 'TrueTypeUnicode', '', 32, $tmp.'/')) {
                    throw new RuntimeException('Could not convert the PDF font.');
                }
                foreach (glob("{$tmp}/{$name}.*") as $generated) {
                    if (! str_ends_with($generated, '.ttf')) {
                        rename($generated, $dir.'/'.basename($generated));
                    }
                }
                array_map('unlink', glob("{$tmp}/*"));
                @rmdir($tmp);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $dir;
    }

    /** Reuse the same Cairo/TCPDF font preparation for an individual HR document. */
    public function fontDirectory(): string
    {
        return $this->ensureFonts();
    }
}
