<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PayrollException;
use App\Http\Controllers\Controller;
use App\Services\Payroll\MonthlyPayrollExport;
use App\Services\Payroll\MonthlyPayrollService;
use App\Services\Payroll\PayrollAccountingAccess;
use App\Services\Payroll\PayrollConfigService;
use App\Support\OwnerPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MonthlyPayrollController extends Controller
{
    public function __construct(private readonly MonthlyPayrollService $months) {}

    private function response(array $data)
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function options(Request $r)
    {
        return $this->response($this->months->options($r->user()));
    }

    public function report(Request $r, PayrollConfigService $config)
    {
        $f = $this->months->filters($r->query());
        $data = $this->months->report($r->user(), $f);
        $rows = $data['filtered_rows'] ?? $data['rows'];
        $total = count($rows);
        $page = $f['page'] ?? 1;
        $size = $f['per_page'] ?? 15;
        unset($data['filtered_rows'], $data['rows']);
        if (isset($data['config'])) {
            $data['config'] = $config->present($data['config']);
        }

        return $this->response($data + ['rows' => array_slice($rows, ($page - 1) * $size, $size), 'meta' => ['total' => $total, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $size)), 'per_page' => $size]]);
    }

    public function prepare(Request $r)
    {
        return $this->response($this->months->prepare($r->user(), $r->all()));
    }

    public function save(Request $r)
    {
        return $this->response($this->months->save($r->user(), $r->all()));
    }

    public function approve(Request $r)
    {
        return $this->response($this->months->approve($r->user(), $r->all()));
    }

    public function result(Request $r, string $uuid)
    {
        return $this->response($this->months->result($r->user(), $uuid));
    }

    public function configuration(Request $r)
    {
        return $this->response($this->months->configuration($r->user(), $r->query()));
    }

    public function previewConfiguration(Request $r)
    {
        return $this->response($this->months->configurationPreview($r->user(), $r->all()));
    }

    public function saveConfiguration(Request $r)
    {
        return $this->response($this->months->saveConfiguration($r->user(), $r->all()));
    }

    public function export(Request $r, string $kind, PayrollAccountingAccess $access, MonthlyPayrollExport $export)
    {
        $access->authorize($r->user(), OwnerPortal::EXPORT);
        $selection = $r->validate(['view' => 'required|in:compact,detailed', 'columns' => 'required|array|min:1|max:100', 'columns.*' => 'required|string|max:40|distinct']);
        $f = $this->months->filters($r->except(['view', 'columns']));

        return DB::transaction(function () use ($r, $kind, $f, $export, $selection) {
            $report = $this->months->report($r->user(), $f);
            if (! $report['initialized']) {
                throw new PayrollException('لم تُعدّ الفترة المحاسبية بعد.', 'payroll_period_missing', 409);
            }
            $report['export_selection'] = $selection;
            $export->selectedColumns($report);
            if ($kind === 'xlsx') {
                return response()->download($export->xlsx($r->user(), $report), 'payroll-'.$f['period'].'.xlsx', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
            }
            try {
                $bytes = $export->pdf($r->user(), $report);
            } catch (\Throwable $e) {
                if ($e instanceof PayrollException) {
                    throw $e;
                }
                report($e);
                throw new PayrollException('تعذر إعداد PDF؛ تحقق من اعتماديات Composer وخط Cairo والشعار.', 'payroll_pdf_not_ready', 503);
            }

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="payroll-'.$f['period'].'.pdf"', 'Cache-Control' => 'private, no-store']);
        });
    }
}
