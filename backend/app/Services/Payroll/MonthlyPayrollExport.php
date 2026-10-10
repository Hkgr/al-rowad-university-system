<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\User;
use App\Services\UserIdentityService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** The selected monthly view; frozen server cells only, never Excel recalculation or payment mutations. */
final class MonthlyPayrollExport
{
    public function __construct(private readonly PayrollPdfExport $fonts, private readonly PayrollConfigService $configs, private readonly UserIdentityService $identity) {}

    /** Exact allowlist matching the two UI projections; identifiers never select arbitrary row fields. */
    public function selectedColumns(array $report): array
    {
        $detailed = $report['export_selection']['view'] === 'detailed';
        $identity = ['employee_number' => 'رقم الموظف', 'full_name' => 'الاسم الكامل', 'job_title' => 'الصفة الوظيفية', 'body_name' => 'الهيئة', 'workplace_label' => 'الكلية / الوحدة', 'academic_level' => 'المستوى الأكاديمي'];
        $allowed = [];
        foreach ($identity as $key => $label) {
            if ($detailed || in_array($key, ['employee_number', 'full_name'], true)) {
                $allowed[$key] = ['key' => $key, 'heading' => $label, 'group' => 'employee', 'group_label' => 'بيانات الموظف', 'type' => 'text', 'aggregation' => 'none', 'identity' => true, 'kind' => 'identity', 'net' => false];
            }
        }
        if (! $detailed) {
            foreach (['employee_status' => 'الحالة الوظيفية', 'placement' => 'الهيئة / الكلية أو الوحدة', 'disbursed' => 'المصروف الفعلي', 'received' => 'الاستلام الموثق', 'remaining' => 'المتبقي', 'payment_status' => 'حالة الصرف', 'completeness' => 'حالة الاستكمال'] as $key => $label) {
                $money = in_array($key, ['disbursed', 'received', 'remaining'], true);
                $allowed[$key] = ['key' => $key, 'heading' => $label.($money ? ' (ل.س)' : ''), 'group' => $money ? 'net' : 'employee', 'group_label' => $money ? 'الصافي والصرف' : 'بيانات الموظف', 'type' => $money ? 'amount' : 'text', 'aggregation' => $money ? 'sum' : 'none', 'identity' => false, 'kind' => 'formula', 'net' => false];
            }
        }
        foreach (PayrollColumns::exportColumns($report['config']) as $column) {
            if ($column['identity']) {
                continue;
            }
            $definition = collect($report['config']['columns'])->firstWhere('key', $column['key']);
            if ($definition['visible_grid'] && ($detailed || (! $definition['is_system'] && $definition['compact']) || $column['net'])) {
                $allowed[$column['key']] = $column;
            }
        }
        $columns = [];
        foreach ($report['export_selection']['columns'] as $key) {
            if (! isset($allowed[$key])) {
                throw new PayrollException('عمود غير ظاهر أو غير مصرح بتصديره في العرض المحدد.', 'payroll_validation', 422);
            }
            $columns[] = $allowed[$key];
        }
        if (! in_array('employee_number', array_column($columns, 'key'), true) || ! in_array('full_name', array_column($columns, 'key'), true)) {
            throw new PayrollException('يجب إبقاء رقم العامل واسمه في الكشف.', 'payroll_validation', 422);
        }

        return $columns;
    }

    private function snapshot(array $report): array
    {
        $rows = [];
        foreach ($report['filtered_rows'] as $row) {
            $row['workplace_label'] = $row['college_name'] ?? $row['unit_name'] ?? 'غير محدد';
            foreach (['disbursed', 'received', 'remaining'] as $key) {
                $row['cells'][$key] = ['v' => $row[$key], 'st' => $row[$key] === null ? 'missing' : null, 'm' => null];
            }
            $texts = ['employee_status' => $row['status_name'] ?? 'غير محدد', 'placement' => $row['body_name'].' — '.$row['workplace_label'], 'payment_status' => ['unpaid' => 'لا صرف مسجل', 'paid' => 'صرف فعلي مسجل', 'received' => 'استلام موثق', 'voided' => 'سجلات ملغاة فقط'][$row['payment_status']], 'completeness' => ['complete' => 'مكتملة', 'incomplete' => 'ناقصة أو بها خطأ', 'warning' => 'تحذير حسابي'][PayrollSheetService::status($row)]];
            foreach ($texts as $key => $text) {
                $row['cells'][$key] = ['v' => $text, 'st' => null, 'm' => null];
            }
            $rows[] = $row;
        }
        $totals = $report['totals'];
        foreach (['disbursed', 'received', 'remaining'] as $key) {
            $totals['columns'][$key] = $totals[$key];
        }

        return ['rows' => $rows, 'config' => $report['config'], 'totals' => $totals, 'filters' => ['sort' => 'employee_number', 'direction' => 'asc'] + $report['filters']];
    }

    private function scope(array $report): string
    {
        return 'الشهر '.$report['period'].' — مراجعة '.$report['revision'].' — '.($report['status'] === 'approved' ? 'معتمد' : 'مسودة').' — '.($report['export_selection']['view'] === 'detailed' ? 'عرض تفصيلي' : 'عرض مختصر').' — '.collect($report['filters'])->except(['page', 'per_page'])->map(fn ($v, $k) => $k.'='.$v)->implode('؛ ');
    }

    public function pdf(User $actor, array $report): string
    {
        $snapshot = $this->snapshot($report);

        return $this->fonts->build($snapshot, [], ['columns' => $this->selectedColumns($report), 'title' => 'كشف الرواتب الشهرية', 'header_lines' => [
            'كشف الرواتب الشهرية — '.($report['export_selection']['view'] === 'detailed' ? 'العرض التفصيلي' : 'العرض المختصر'),
            $this->scope($report),
            'المُصدر: '.$this->identity->documentGenerator($actor)['display_name'].' — '.$report['generated_at'],
            'المبالغ بالليرة السورية. المستحق منفصل عن الصرف والاستلام؛ لا يشكل الكشف إثبات تحويل أو توقيع.',
        ]]);
    }

    public function xlsx(User $actor, array $report): string
    {
        $columns = $this->selectedColumns($report);
        $snapshot = $this->snapshot($report);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('الرواتب الشهرية');
        $sheet->setRightToLeft(true);
        $put = fn ($col, $row, $text) => $sheet->setCellValueExplicit([$col, $row], (string) $text, DataType::TYPE_STRING);
        $put(1, 1, $this->scope($report));
        $put(1, 2, 'المُصدر: '.$this->identity->documentGenerator($actor)['display_name'].' — '.$report['generated_at']);
        $put(1, 3, 'قيم محفوظة من الخادم، وليست معادلات Excel أو إثبات تحويل. جميع المبالغ بالليرة السورية.');
        $end = Coordinate::stringFromColumnIndex(count($columns));
        foreach ([1, 2, 3] as $row) {
            if (count($columns) > 1) {
                $sheet->mergeCells('A'.$row.':'.$end.$row);
            }
        }
        for ($i = 0; $i < count($columns);) {
            $start = $i;
            while ($i + 1 < count($columns) && $columns[$i + 1]['group'] === $columns[$start]['group']) {
                $i++;
            }
            $put($start + 1, 4, $columns[$start]['group_label']);
            if ($i !== $start) {
                $sheet->mergeCells(Coordinate::stringFromColumnIndex($start + 1).'4:'.Coordinate::stringFromColumnIndex($i + 1).'4');
            }
            $i++;
        }
        foreach ($columns as $i => $c) {
            $put($i + 1, 5, $c['heading']);
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth($c['key'] === 'full_name' ? 30 : 22);
        }
        $index = 6;
        foreach ($snapshot['rows'] as $row) {
            foreach ($columns as $i => $c) {
                $put($i + 1, $index, PayrollColumns::display($c, $row));
            }
            $index++;
        }
        foreach ($columns as $i => $c) {
            $total = $snapshot['totals']['columns'][$c['key']]['sum'] ?? null;
            $put($i + 1, $index, $i === 0 ? 'الإجمالي ('.$snapshot['totals']['employees'].')' : ($c['aggregation'] === 'sum' ? ($total === null ? 'غير متاح' : PayrollColumns::formatValue($total, $c['type'])) : ''));
            $color = $c['net'] ? 'E3F0D8' : ($c['kind'] === 'input' ? 'FFFAF0' : ($c['identity'] ? 'FFFFFF' : 'F4F5F1'));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($i + 1).'6:'.Coordinate::stringFromColumnIndex($i + 1).$index)->getFill()->setFillType('solid')->getStartColor()->setRGB($color);
        }
        $sheet->freezePane('C6');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5);
        $sheet->getPageSetup()->setOrientation('landscape');
        $sheet->getStyle('A4:'.$end.'4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A4:'.$end.'4')->getFill()->setFillType('solid')->getStartColor()->setRGB('1F3D12');
        $sheet->getStyle('A5:'.$end.'5')->getFont()->setBold(true);
        $sheet->getStyle('A5:'.$end.'5')->getFill()->setFillType('solid')->getStartColor()->setRGB('EEF4E7');
        $sheet->getStyle('A1:'.$end.$index)->getAlignment()->setWrapText(true);
        $sheet->setAutoFilter('A5:'.$end.($index - 1));
        $definitions = $book->createSheet();
        $definitions->setTitle('تعريف البنود الظاهرة');
        $definitions->setRightToLeft(true);
        foreach ($columns as $i => $c) {
            $definition = collect($report['config']['columns'])->firstWhere('key', $c['key']);
            foreach ([$c['heading'], $c['group_label'], $c['type'], $definition['formula'] ?? 'هوية / قيمة محفوظة'] as $j => $text) {
                $definitions->setCellValueExplicit([$j + 1, $i + 1], $text, DataType::TYPE_STRING);
            }
        }
        $file = tempnam(sys_get_temp_dir(), 'rowad_month_');
        if ($file === false) {
            throw new \RuntimeException('Export temporary directory unavailable.');
        }
        IOFactory::createWriter($book, 'Xlsx')->save($file);
        $book->disconnectWorksheets();

        return $file;
    }
}
