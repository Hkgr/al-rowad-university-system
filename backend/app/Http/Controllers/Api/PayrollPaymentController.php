<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollPaymentService;
use App\Support\AdministrativeGovernanceException as Failure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PayrollPaymentController extends Controller
{
    public function __construct(private readonly PayrollPaymentService $payments) {}

    public function index(Request $request, int $employee): JsonResponse
    {
        $this->payments->authorize($request->user());
        if (! $this->payments->ready()) {
            return $this->response($this->payments->history($request->user(), 0, $request->query()));
        }
        $personId = DB::table('payroll_employees')->where('id', $employee)->value('employee_id');
        if (! $personId) {
            throw Failure::conflict('payroll_payment_identity_required', 'اربط الملف المالي بالعامل أولًا.');
        }

        return $this->response($this->payments->history($request->user(), $personId, $request->query()));
    }

    public function record(Request $request, int $employee): JsonResponse
    {
        return $this->response($this->payments->record($request->user(), $employee, $request->all()), 201);
    }

    public function receive(Request $request, int $payment): JsonResponse
    {
        return $this->response($this->payments->transition($request->user(), $payment, 'receive', $request->all()));
    }

    public function cancel(Request $request, int $payment): JsonResponse
    {
        return $this->response($this->payments->transition($request->user(), $payment, 'void', $request->all()));
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['Cache-Control' => 'private, no-store']);
    }
}
