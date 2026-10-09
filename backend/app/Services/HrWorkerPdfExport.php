<?php

namespace App\Services;

use App\Models\User;
use App\Services\Payroll\PayrollPaymentService;
use App\Services\Payroll\PayrollPdfDocument;
use App\Services\Payroll\PayrollPdfExport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A fresh, scoped individual record, not a payment order or a fabricated receipt/signature. */
final class HrWorkerPdfExport
{
    public function __construct(private readonly PayrollPdfExport $fonts, private readonly UserIdentityService $identity) {}

    public function build(User $actor, array $worker, array $options, ?array $history): string
    {
        $fontDir = $this->fonts->fontDirectory();
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontDir.'/');
        }
        $logo = base_path('../frontend/public/logo.png');
        if (! is_file($logo)) {
            throw new RuntimeException('The official frontend/public/logo.png asset must be present for HR PDF export.');
        }
        $e = $worker['employee'];
        $name = trim($e['first_name'].' '.$e['last_name']);
        $pdf = new PayrollPdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setCreator('Alrowad University');
        $pdf->setTitle('ملف العامل — '.$name);
        $pdf->setRTL(true);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setMargins(12, 36, 12);
        $pdf->setAutoPageBreak(true, 20);
        $pdf->AddFont('cairo', '', $fontDir.'/cairo.php');
        $pdf->AddFont('cairo', 'B', $fontDir.'/cairob.php');
        $pdf->headerLines = ['ملف العامل — '.$name, 'الرقم الوظيفي: '.$e['employee_number'], 'أعد التقرير: '.$this->identity->documentGenerator($actor)['display_name'].' — '.now()->timezone('Asia/Damascus')->format('Y-m-d H:i').' (دمشق)'];
        $pdf->AddPage();
        $pdf->setRTL(false);
        $pdf->Image($logo, 12, 7, 16, 16, 'PNG');
        $pdf->setRTL(true);
        $pdf->setFont('cairo', '', 10);
        $pdf->setTextColor(26, 46, 16);
        $pdf->setDrawColor(86, 153, 51);
        $body = ['educational' => 'هيئة تعليمية', 'administrative' => 'هيئة إدارية'];
        $pdf->writeHTML('<h3>بيانات العامل</h3><p>الهيئة الحالية: '.$this->escape($body[$e['current_body']] ?? 'غير محددة / لا علاقة نافذة').'</p><p>الرواتب والسجل الوظيفي مصدرهما البيانات المحفوظة؛ هذا التقرير لا ينشئ اعتمادًا أو صرفًا أو توقيعًا.</p>');
        $units = $options['units']->keyBy('organizational_unit_id');
        $positions = $options['positions']->keyBy('position_id');
        $types = ['temporary_contract' => 'تعاقد مؤقت', 'continuous_contract' => 'تعاقد مستمر', 'employment' => 'توظيف'];
        $pdf->writeHTML('<h3>العلاقات الوظيفية المسجلة</h3>');
        foreach ($worker['relationships'] as $relationship) {
            $status = $relationship->superseded_from ? 'تاريخ الاستبدال التشغيلي: '.$relationship->superseded_from : (! empty($relationship->cancelled_from) ? 'تاريخ إلغاء النفاذ: '.$relationship->cancelled_from : 'دون إلغاء مسجل');
            $pdf->writeHTML('<p><b>'.$this->escape($positions->get($relationship->position_id)?->position_title ?? $relationship->job_title).'</b> — '.$this->escape($body[$relationship->body] ?? $relationship->body).' / '.$this->escape($types[$relationship->relationship_type] ?? $relationship->relationship_type).'<br>الجهة: '.$this->escape($units->get($relationship->organizational_unit_id)?->unit_name ?? 'غير محددة').'<br>البداية الأصلية: '.$this->escape($relationship->starts_on).' — النهاية الأصلية: '.$this->escape($relationship->ends_on ?? 'مستمر').'<br>'.$this->escape($status).'</p>');
        }
        $pdf->writeHTML('<h3>سجل الرواتب المصروفة والاستلام</h3>');
        if ($history === null) {
            $pdf->writeHTML('<p>بيانات الرواتب ليست ضمن صلاحيات القراءة لهذا المستخدم.</p>');
        } elseif (! $history['schema_ready']) {
            $pdf->writeHTML('<p>غير متاح: مخطط سجل الصرف والاستلام لم يُثبّت. لا يُفهم ذلك على أنه عدم استلام رواتب.</p>');
        } else {
            $pdf->writeHTML('<p>المصروف المسجل غير الملغى: '.$this->escape($history['summary']['disbursed']).' ل.س — الاستلام الموثق غير الملغى: '.$this->escape($history['summary']['received']).' ل.س</p><p>توثيق الاستلام إدخال صريح من موظف مخول مع مرجع إثبات، وليس توقيعًا مولدًا أو تحققًا بنكيًا. إلغاء سجل خاطئ لا يثبت استرداد المال.</p>');
            // Bounded traversal includes every record; no silent first-page truncation or full-array load.
            $count = 0;
            foreach (DB::table('payroll_payments')->where('employee_id', $e['employee_id'])->orderBy('id')->lazyById(100) as $payment) {
                $status = ['paid' => 'صرف مسجل — الاستلام غير موثق', 'received' => 'استلام موثق', 'voided' => 'سجل ملغى'][$payment->status];
                $pdf->writeHTML('<p><b>الفترة: '.$this->escape($payment->period).' — المبلغ: '.$this->escape(PayrollPaymentService::amount($payment->amount_cents)).' ل.س</b><br>تاريخ الصرف: '.$this->escape($payment->paid_on).' — المرجع: '.$this->escape($payment->reference).'<br>الحالة: '.$this->escape($status).' — تاريخ الاستلام: '.$this->escape($payment->received_on ?? 'غير موثق').'<br>إثبات الاستلام المسجل: '.$this->escape($payment->receipt_evidence ?? 'غير موثق').($payment->void_reason ? '<br>سبب إلغاء السجل: '.$this->escape($payment->void_reason) : '').'</p>');
                $count++;
            }
            if ($count === 0) {
                $pdf->writeHTML('<p>لا توجد دفعات مسجلة في هذا السجل؛ لم تُرحّل رواتب تاريخية تلقائيًا.</p>');
            }
        }

        return $pdf->Output('worker.pdf', 'S');
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
