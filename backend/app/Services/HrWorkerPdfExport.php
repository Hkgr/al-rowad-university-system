<?php

namespace App\Services;

use App\Models\User;
use App\Services\Payroll\MonthlyPayrollDocument;
use App\Services\Payroll\PayrollPdfExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** A fresh, scoped individual record, not a payment order or a fabricated receipt/signature. */
final class HrWorkerPdfExport
{
    public function __construct(private readonly PayrollPdfExport $fonts, private readonly UserIdentityService $identity) {}

    public function build(User $actor, array $worker, array $options): string
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
        $pdf = new MonthlyPayrollDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setCreator('Alrowad University');
        $pdf->setTitle('ملف العامل — '.$name);
        $pdf->setRTL(true);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setMargins(12, 40, 12);
        $pdf->setAutoPageBreak(true, 20);
        $pdf->AddFont('cairo', '', $fontDir.'/cairo.php');
        $pdf->AddFont('cairo', 'B', $fontDir.'/cairob.php');
        $pdf->headerLines = ['الموارد البشرية — ملف العامل', 'الرقم الوظيفي: '.$e['employee_number'], 'أعد التقرير: '.$this->identity->documentGenerator($actor)['display_name'].' — '.now()->timezone('Asia/Damascus')->format('Y-m-d H:i').' (دمشق)'];
        $pdf->AddPage();
        $pdf->setFont('cairo', '', 10);
        $pdf->setTextColor(26, 46, 16);
        $pdf->setDrawColor(86, 153, 51);
        $body = ['educational' => 'هيئة تعليمية', 'administrative' => 'هيئة إدارية'];
        $pdf->writeHTML('<h3>'.$this->escape($name).'</h3><p>الهيئة الحالية: '.$this->escape($body[$e['current_body']] ?? 'غير محددة / لا علاقة نافذة').'</p><p>سجل الموارد البشرية فقط؛ المحاسبة والصرف والاستلام في كشف مستقل. لا ينشئ التقرير اعتمادًا أو توقيعًا.</p>');
        $units = $options['units']->keyBy('organizational_unit_id');
        $positions = $options['positions']->keyBy('position_id');
        $types = ['temporary_contract' => 'تعاقد مؤقت', 'continuous_contract' => 'تعاقد مستمر', 'employment' => 'توظيف'];
        $pdf->writeHTML('<h3>العلاقات الوظيفية المسجلة</h3>');
        foreach ($worker['relationships'] as $relationship) {
            $status = $relationship->superseded_from ? 'تاريخ الاستبدال التشغيلي: '.$relationship->superseded_from : (! empty($relationship->cancelled_from) ? 'تاريخ إلغاء النفاذ: '.$relationship->cancelled_from : 'دون إلغاء مسجل');
            $pdf->writeHTML('<p><b>'.$this->escape($positions->get($relationship->position_id)?->position_title ?? $relationship->job_title).'</b> — '.$this->escape($body[$relationship->body] ?? $relationship->body).' / '.$this->escape($types[$relationship->relationship_type] ?? $relationship->relationship_type).'<br>نمط الدوام المسجل: '.$this->escape(['full' => 'كلي', 'part' => 'جزئي'][$relationship->work_mode] ?? 'غير محدد').'<br>الجهة: '.$this->escape($units->get($relationship->organizational_unit_id)?->unit_name ?? 'غير محددة').'<br>البداية الأصلية: '.$this->escape($relationship->starts_on).' — النهاية الأصلية: '.$this->escape($relationship->ends_on ?? 'مستمر').'<br>'.$this->escape($status).'<br>المصدر: '.$this->escape(['approved_request' => 'طلب معتمد مسجل', 'legacy_classification' => 'توثيق قديم دون اعتماد تاريخي مفترض', 'audited_correction' => 'تصحيح موثق مع حفظ الأصل'][$relationship->source] ?? $relationship->source).'</p>');
        }
        $pdf->writeHTML('<h3>المناصب والانتماءات التاريخية</h3>');
        foreach ($worker['positions'] as $position) {
            $pdf->writeHTML('<p>'.$this->escape($positions->get($position->position_id)?->position_title ?? 'غير محدد').' — '.$this->escape($position->start_date).' إلى '.$this->escape($position->end_date ?? 'قيد مفتوح').' — '.($position->is_active ? 'فعال' : 'معطل').'</p>');
        }
        foreach ($worker['affiliations'] as $a) {
            $pdf->writeHTML('<p>'.$this->escape($units->get($a->organizational_unit_id)?->unit_name ?? 'غير محدد').' — '.$this->escape($a->start_date).' إلى '.$this->escape($a->end_date ?? 'قيد مفتوح').'</p>');
        }
        $pdf->writeHTML('<h3>الأيام والساعات الفعلية الموثقة</h3>');
        if (Schema::hasTable('hr_work_time_records')) {
            foreach (DB::table('hr_work_time_records')->where('employee_id', $e['employee_id'])->orderBy('id')->lazyById(100) as $time) {
                $pdf->writeHTML('<p>الشهر '.$this->escape($time->period).' — أيام '.$this->escape($time->days ?? 'غير محدد').' / ساعات '.$this->escape($time->hours ?? 'غير محدد').'<br>المصدر '.$this->escape($time->source_reference).' — المراجعة '.$time->revision.'</p>');
            }
        } else {
            $pdf->writeHTML('<p>بيانات الدوام غير متاحة؛ لا تُستنتج من نوع العلاقة.</p>');
        }
        $pdf->writeHTML('<h3>التصحيح والإلغاء المسجلان</h3>');
        foreach (DB::table('hr_workforce_events')->where('subject_type', 'employee')->where('subject_id', $e['employee_id'])->whereIn('action', ['relationship_corrected', 'relationship_cancelled', 'classification_completed', 'work_time_recorded'])->orderBy('id')->lazyById(100) as $event) {
            $details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
            $pdf->writeHTML('<p>'.$this->escape(['relationship_corrected' => 'تصحيح علاقة', 'relationship_cancelled' => 'إلغاء نفاذ علاقة', 'classification_completed' => 'توثيق البيانات القائمة', 'work_time_recorded' => 'توثيق الدوام الفعلي'][$event->action]).' — '.$this->escape($event->created_at).' — مرجع المنفذ '.$event->actor_user_id.'<br>'.$this->escape($details['reason'] ?? $details['after']['source_reference'] ?? '').'<br>نافذ من '.$this->escape($details['effective_on'] ?? null).'</p>');
        }

        return $pdf->Output('worker.pdf', 'S');
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
