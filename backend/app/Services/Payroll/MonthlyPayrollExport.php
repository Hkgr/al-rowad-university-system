<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Services\UserIdentityService;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Financial documents use frozen month values; no formula recalculation or receipt mutation on export. */
final class MonthlyPayrollExport
{
    public function __construct(private readonly PayrollPdfExport $fonts, private readonly PayrollConfigService $configs, private readonly UserIdentityService $identity) {}

    private function esc(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? 'لم يحدد'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function pdf(User $actor, array $report): string
    {
        $dir = $this->fonts->fontDirectory();
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $dir.'/');
        }
        $pdf = new MonthlyPayrollDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->AddFont('cairo', '', $dir.'/cairo.php');
        $pdf->AddFont('cairo', 'B', $dir.'/cairob.php');
        $pdf->setTitle('الكشف المحاسبي الشهري '.$report['period']);
        $pdf->setCreator('Alrowad University');
        $pdf->setRTL(true);
        $pdf->setMargins(12, 40, 12);
        $pdf->setAutoPageBreak(true, 20);
        $pdf->headerLines = ['المحاسبة — الرواتب الشهرية', 'الفترة '.$report['period'].' — مراجعة '.$report['revision'].' — '.($report['status'] === 'approved' ? 'مستحق معتمد' : 'مسودة غير معتمدة'), 'المُصدر: '.$this->identity->documentGenerator($actor)['display_name'].' — '.now()->timezone('Asia/Damascus')->format('Y-m-d H:i')];
        $pdf->AddPage();
        $pdf->setFont('cairo', '', 9);
        $pdf->writeHTML('<p>الفلاتر: '.$this->esc($this->scope($report['filters'])).'</p><p>المستحق المحسوب منفصل عن الصرف الفعلي والاستلام الموثق. البيانات غير المدخلة «لم يحدد». لا يشكل الكشف إثبات تحويل أو توقيعًا.</p>');
        $summary = [['العاملون', $report['totals']['employees']], ['الصافي المستحق المحسوب', $report['totals']['columns'][PayrollSheetService::TOTAL_KEY]['sum'] ?? null], ['الصرف الفعلي المسجل', $report['totals']['disbursed']['sum']], ['الاستلام الموثق', $report['totals']['received']['sum']], ['المتبقي (المستحق ناقص المصروف)', $report['totals']['remaining']['sum']]];
        $pdf->writeHTML($this->table(['المؤشر', 'القيمة'], $summary));
        $pdf->writeHTML($this->table(['العامل / الرقم', 'المستحق الصافي', 'المصروف', 'الاستلام', 'المتبقي'], array_map(fn ($r) => [$r['full_name'].' — '.$r['employee_number'], $r['cells'][PayrollSheetService::TOTAL_KEY]['v'] ?? null, $r['disbursed'], $r['received'], $r['remaining']], $report['filtered_rows'])));
        $config = $this->configs->present($report['config']);
        $paymentsByPerson = collect();
        foreach (array_chunk(array_column($report['filtered_rows'], 'employee_id'), 500) as $ids) {
            $paymentsByPerson = $paymentsByPerson->concat(DB::table('payroll_payments')->whereIn('employee_id', $ids)->where('period', $report['period'])->orderBy('paid_on')->orderBy('id')->get());
        }
        $paymentsByPerson = $paymentsByPerson->groupBy('employee_id');
        foreach ($report['filtered_rows'] as $r) {
            $pdf->AddPage();
            $pdf->writeHTML('<h3>'.$this->esc($r['full_name']).'</h3><p>الرقم: '.$this->esc($r['employee_number']).' — الهيئة: '.$this->esc($r['body_name']).'<br>المنصب: '.$this->esc($r['job_title'] ?: 'غير محدد').' — الكلية / الوحدة: '.$this->esc($r['college_name'] ?? $r['unit_name']).'</p>');
            $time = $r['work_time'] ?? null;
            $pdf->writeHTML('<p>بيانات الدوام الموثقة من الموارد البشرية: '.($time ? 'أيام '.$this->esc($time['days']).' / ساعات '.$this->esc($time['hours']).' — المصدر '.$this->esc($time['source_reference']) : 'غير متاحة؛ لا تستنتج من كلي/جزئي').'</p>');
            $items = [];
            foreach ($config['columns'] as $c) {
                $cell = $r['cells'][$c['key']];
                $items[] = [$c['group_label'].' / '.$c['label'], $cell['v'], $c['kind'] === 'formula' ? $c['formula_display'] : 'مدخل مالي صريح', $cell['m'] ?? ''];
            }
            $pdf->writeHTML($this->table(['البند', 'القيمة — ل.س للمبالغ', 'طريقة الاحتساب', 'ملاحظة'], $items));
            $pdf->writeHTML($this->table(['المستحق', 'المصروف', 'الاستلام الموثق', 'المتبقي'], [[$r['cells'][PayrollSheetService::TOTAL_KEY]['v'] ?? null, $r['disbursed'], $r['received'], $r['remaining']]]));
            $payments = $paymentsByPerson->get($r['employee_id'], collect());
            $pdf->writeHTML('<h4>تفاصيل الصرف والاستلام</h4>');
            foreach ($payments as $p) {
                $pdf->writeHTML('<p>المبلغ '.$this->esc(PayrollPaymentService::amount($p->amount_cents)).' — تاريخ الصرف '.$this->esc($p->paid_on).' — المرجع '.$this->esc($p->reference).'<br>الحالة '.$this->esc(['paid' => 'صرف مسجل دون استلام موثق', 'received' => 'استلام موثق', 'voided' => 'سجل ملغى'][$p->status]).' — الاستلام '.$this->esc($p->received_on).'<br>إثبات الاستلام '.$this->esc($p->receipt_evidence).' — سبب السجل '.$this->esc($p->reason).($p->void_reason ? '<br>سبب الإلغاء '.$this->esc($p->void_reason) : '').'</p>');
            }
            if ($payments->isEmpty()) {
                $pdf->writeHTML('<p>لا توجد دفعات فعلية مسجلة لهذه الفترة.</p>');
            }
        }

        return $pdf->Output('monthly-payroll.pdf', 'S');
    }

    private function scope(array $f): string
    {
        return collect($f)->except(['page', 'per_page'])->map(fn ($v, $k) => $k.'='.$v)->implode('؛ ');
    }

    private function table(array $headers, array $rows): string
    {
        $html = '<table border="1" cellpadding="5"><thead><tr style="background-color:#243d16;color:#ffffff">';
        foreach ($headers as $h) {
            $html .= '<th>'.$this->esc($h).'</th>';
        } $html .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>';
            foreach ($r as $cell) {
                $html .= '<td>'.$this->esc($cell).'</td>';
            } $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    public function xlsx(User $actor, array $report): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('الكشف الشهري');
        $sheet->setRightToLeft(true);
        $columns = $report['config']['columns'];
        $headers = array_merge(['الرقم', 'العامل', 'الهيئة', 'الكلية / الوحدة', 'الحالة الوظيفية'], array_column($columns, 'label'), ['المصروف', 'الاستلام الموثق', 'المتبقي']);
        $sheet->setCellValueExplicit('A1', 'الفترة '.$report['period'].' — مراجعة '.$report['revision'].' — '.$this->scope($report['filters']), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A2', 'المُصدر: '.$this->identity->documentGenerator($actor)['display_name'].' — '.$report['generated_at'], DataType::TYPE_STRING);
        $this->textRow($sheet, 4, $headers);
        $index = 5;
        foreach ($report['filtered_rows'] as $r) {
            $values = array_merge([$r['employee_number'], $r['full_name'], $r['body_name'], $r['college_name'] ?? $r['unit_name'], $r['status_name']], array_map(fn ($c) => $r['cells'][$c['key']]['v'] ?? 'لم يحدد', $columns), [$r['disbursed'], $r['received'], $r['remaining'] ?? 'لم يحدد']);
            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit([$i + 1, $index], (string) ($value ?? 'غير محدد'), DataType::TYPE_STRING);
            } $index++;
        }
        $summary = $book->createSheet();
        $summary->setTitle('الإجماليات');
        $summary->setRightToLeft(true);
        $this->textRow($summary, 1, ['البند', 'المجموع الدقيق', 'سجلات غير محددة / مستثناة']);
        $summaryRow = 2;
        foreach ($report['totals']['columns'] as $key => $total) {
            $label = collect($columns)->firstWhere('key', $key)['label'];
            $this->textRow($summary, $summaryRow++, [$label, $total['sum'] ?? 'لم يحدد', $total['excluded']]);
        }
        foreach (['disbursed' => 'المصروف الفعلي', 'received' => 'الاستلام الموثق', 'remaining' => 'المتبقي'] as $key => $label) {
            $this->textRow($summary, $summaryRow++, [$label, $report['totals'][$key]['sum'] ?? 'لم يحدد', $report['totals'][$key]['excluded']]);
        }
        $this->textRow($summary, $summaryRow, ['المبالغ نصوص عشرية دقيقة والقيم محفوظة؛ لا توجد معادلات Excel تعيد احتساب تاريخ الشهر.']);
        $sheet->freezePane('C5');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4);
        $sheet->getPageSetup()->setOrientation('landscape');
        $end = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A4:'.$end.'4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A4:'.$end.'4')->getFill()->setFillType('solid')->getStartColor()->setRGB('243D16');
        $sheet->getStyle('A1:'.$end.$index)->getAlignment()->setWrapText(true);
        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setWidth($i === 2 ? 30 : 22);
        }
        $definitions = $book->createSheet();
        $definitions->setTitle('البنود والمعادلات');
        $definitions->setRightToLeft(true);
        $this->textRow($definitions, 1, ['البند', 'المجموعة', 'النوع', 'المعادلة المحفوظة']);
        foreach ($columns as $i => $c) {
            $this->textRow($definitions, $i + 2, [$c['label'], $c['group'], $c['kind'], $c['formula'] ?? 'مدخل صريح']);
        }
        $paymentsSheet = $book->createSheet();
        $paymentsSheet->setTitle('الصرف والاستلام');
        $paymentsSheet->setRightToLeft(true);
        $paymentsSheet->fromArray(['العامل', 'المرجع', 'تاريخ الصرف', 'المبلغ', 'الحالة', 'تاريخ الاستلام', 'الإثبات', 'سبب الإلغاء'], null, 'A1');
        $i = 2;
        foreach (array_chunk(array_column($report['filtered_rows'], 'employee_id'), 500) as $ids) {
            foreach (DB::table('payroll_payments')->where('period', $report['period'])->whereIn('employee_id', $ids)->orderBy('id')->get() as $p) {
                $name = json_decode($p->identity_snapshot, true)['name'];
                $values = [$name, $p->reference, $p->paid_on, PayrollPaymentService::amount($p->amount_cents), ['paid' => 'صرف غير موثق الاستلام', 'received' => 'استلام موثق', 'voided' => 'ملغى'][$p->status], $p->received_on, $p->receipt_evidence, $p->void_reason];
                foreach ($values as $col => $value) {
                    $paymentsSheet->setCellValueExplicit([$col + 1, $i], (string) ($value ?? ''), DataType::TYPE_STRING);
                } $i++;
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

    private function textRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $col => $value) {
            $sheet->setCellValueExplicit([$col + 1, $row], (string) ($value ?? ''), DataType::TYPE_STRING);
        }
    }
}
