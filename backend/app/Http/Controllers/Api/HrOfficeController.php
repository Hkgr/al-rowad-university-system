<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HrOfficeService;
use App\Services\HrWorkerPdfExport;
use App\Services\Payroll\PayrollPaymentService;
use App\Support\AdministrativeGovernanceException as Failure;
use App\Support\HrOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class HrOfficeController extends Controller
{
    public function __construct(private readonly HrOfficeService $hr) {}

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['Cache-Control' => 'private, no-store']);
    }

    public function options(Request $r): JsonResponse
    {
        return $this->response($this->hr->options($r->user()));
    }

    public function index(Request $r, string $section): JsonResponse
    {
        return $this->response($this->hr->listing($r->user(), $section, $r->query()));
    }

    public function worker(Request $r, int $employee): JsonResponse
    {
        return $this->response($this->hr->worker($r->user(), $employee));
    }

    public function workerFile(Request $r, int $employee, PayrollPaymentService $payments): JsonResponse
    {
        $worker = $this->hr->worker($r->user(), $employee);
        $d = $r->validate(['payment_page' => 'sometimes|integer|min:1']);

        return $this->response(['worker' => $worker, 'options' => $this->hr->options($r->user()), 'payments' => $worker['can_open_payroll'] ? $payments->history($r->user(), $employee, ['page' => $d['payment_page'] ?? 1]) : null]);
    }

    public function workerPdf(Request $r, int $employee, PayrollPaymentService $payments, HrWorkerPdfExport $pdf): Response
    {
        app(HrOffice::class)->authorize($r->user(), HrOffice::WORKER_EXPORT);
        try {
            $bytes = DB::transaction(function () use ($r, $employee, $payments, $pdf): string {
                $worker = $this->hr->worker($r->user(), $employee);

                return $pdf->build($r->user(), $worker, $this->hr->options($r->user()), $worker['can_open_payroll'] ? $payments->history($r->user(), $employee) : null);
            });
        } catch (Failure $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new Failure('تعذر إعداد PDF. تحقق من خط Cairo وأصول الشعار وإعدادات التصدير.', 503, 'hr_pdf_not_ready');
        }

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="worker-'.$employee.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function relationshipAction(Request $r, int $relationship): JsonResponse
    {
        return $this->response($this->hr->changeRelationship($r->user(), $relationship, $r->all()));
    }

    public function cancelRequest(Request $r, int $request): JsonResponse
    {
        return $this->response($this->hr->cancelRequest($r->user(), $request, $r->all()));
    }

    public function storeNeed(Request $r): JsonResponse
    {
        return $this->response($this->hr->saveNeed($r->user(), $r->all()), 201);
    }

    public function updateNeed(Request $r, int $need): JsonResponse
    {
        return $this->response($this->hr->saveNeed($r->user(), $r->all(), $need));
    }

    public function candidate(Request $r, int $candidate): JsonResponse
    {
        return $this->response($this->hr->candidate($r->user(), $candidate));
    }

    public function storeCandidate(Request $r): JsonResponse
    {
        return $this->response($this->hr->saveCandidate($r->user(), $r->all()), 201);
    }

    public function updateCandidate(Request $r, int $candidate): JsonResponse
    {
        return $this->response($this->hr->saveCandidate($r->user(), $r->all(), $candidate));
    }

    public function interview(Request $r, int $candidate, ?int $interview = null): JsonResponse
    {
        return $this->response($this->hr->interview($r->user(), $candidate, $r->all(), $interview));
    }

    public function decline(Request $r, int $candidate): JsonResponse
    {
        return $this->response($this->hr->decline($r->user(), $candidate, $r->all()));
    }

    public function storeRequest(Request $r): JsonResponse
    {
        return $this->response($this->hr->saveRequest($r->user(), $r->all()), 201);
    }

    public function updateRequest(Request $r, int $request): JsonResponse
    {
        return $this->response($this->hr->saveRequest($r->user(), $r->all(), $request));
    }

    public function detail(Request $r, int $request): JsonResponse
    {
        return $this->response($this->hr->request($r->user(), $request));
    }

    public function submit(Request $r, int $request): JsonResponse
    {
        return $this->response($this->hr->submit($r->user(), $request, $r->all()));
    }

    public function decide(Request $r, int $request): JsonResponse
    {
        return $this->response($this->hr->decide($r->user(), $request, $r->all()));
    }

    public function classify(Request $r, int $employee): JsonResponse
    {
        return $this->response($this->hr->classify($r->user(), $employee, $r->all()));
    }

    public function payrollOptions(Request $r): JsonResponse
    {
        return $this->response($this->hr->payrollLookup($r->user(), $r->query()));
    }

    public function payrollPersonnel(Request $r): JsonResponse
    {
        return $this->response($this->hr->payrollPersonnelLookup($r->user(), $r->query()));
    }

    public function linkPayroll(Request $r, int $employee): JsonResponse
    {
        return $this->response($this->hr->linkPayroll($r->user(), $employee, $r->all()));
    }
}
