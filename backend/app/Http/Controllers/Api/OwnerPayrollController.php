<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollPdfExport;
use App\Services\Payroll\PayrollSheetService;
use App\Services\Payroll\PayrollXlsxExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * University-owner portal API: Home summary and the single current payroll working sheet.
 * Every route is guarded in routes/api.php by RequireOwnerPortal with its own permission.
 * The browser can never send a payable amount: it is computed here from stored cents.
 */
class OwnerPayrollController extends Controller
{
    private const REVISION = ['required', 'integer', 'min:1'];

    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly PayrollSheetService $sheet) {}

    public function home(): JsonResponse
    {
        return response()->json(['data' => $this->sheet->home()], 200, self::NO_STORE);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->sheet->options()], 200, self::NO_STORE);
    }

    public function sheet(Request $request): JsonResponse
    {
        $filters = $this->sheet->filters($request->validate($this->sheet->filterRules()));
        $snapshot = $this->sheet->snapshot($filters);

        return response()->json([
            'data' => $snapshot['rows'],
            'meta' => [
                'totals' => $snapshot['totals'],
                'filters' => $filters,
                'scope_labels' => $this->sheet->scopeLabels($filters),
                'generated_at' => $snapshot['generated_at'],
            ],
        ], 200, self::NO_STORE);
    }

    // ── bodies ────────────────────────────────────────────────────────────

    public function bodies(): JsonResponse
    {
        return response()->json(['data' => $this->sheet->bodies()], 200, self::NO_STORE);
    }

    public function storeBody(Request $request): JsonResponse
    {
        $request->validate(['name' => ['nullable', 'string']]);

        return response()->json(['data' => $this->sheet->createBody($request->input('name'), $this->userId($request))], 201);
    }

    public function updateBody(Request $request, int $body): JsonResponse
    {
        $request->validate(['name' => ['nullable', 'string'], 'expected_revision' => self::REVISION]);

        return response()->json(['data' => $this->sheet->renameBody($body, $request->input('name'), (int) $request->input('expected_revision'), $this->userId($request))]);
    }

    public function setBodyActive(Request $request, int $body, string $state): JsonResponse
    {
        $request->validate(['expected_revision' => self::REVISION]);

        return response()->json(['data' => $this->sheet->setBodyActive($body, $state === 'activate', (int) $request->input('expected_revision'), $this->userId($request))]);
    }

    public function destroyBody(Request $request, int $body): JsonResponse
    {
        $request->validate(['expected_revision' => self::REVISION]);
        $this->sheet->deleteBody($body, (int) $request->input('expected_revision'));

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── employees (identity and classification only) ──────────────────────

    private const EMPLOYEE_FIELDS = ['employee_number', 'full_name', 'job_title', 'body_id', 'workplace', 'workplace_other', 'academic_level'];

    public function storeEmployee(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sheet->createEmployee($request->only(self::EMPLOYEE_FIELDS), $this->userId($request))], 201);
    }

    public function updateEmployee(Request $request, int $employee): JsonResponse
    {
        $request->validate(['expected_revision' => self::REVISION]);

        return response()->json(['data' => $this->sheet->updateEmployee($employee, $request->only(self::EMPLOYEE_FIELDS), (int) $request->input('expected_revision'), $this->userId($request))]);
    }

    // ── amounts ───────────────────────────────────────────────────────────

    public function amounts(Request $request): JsonResponse
    {
        $request->validate(['changes' => ['required', 'array']]);

        return response()->json(['data' => $this->sheet->updateAmounts($request->input('changes'), $this->userId($request))['rows']]);
    }

    // ── exports: same filters and order as the grid, one consistent snapshot ─

    public function exportXlsx(Request $request): BinaryFileResponse
    {
        [$snapshot, $labels] = $this->exportSnapshot($request);
        $path = app(PayrollXlsxExport::class)->build($snapshot, $labels);

        return response()->download($path, $this->fileName('xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] + self::NO_STORE)->deleteFileAfterSend();
    }

    public function exportPdf(Request $request): Response
    {
        [$snapshot, $labels] = $this->exportSnapshot($request);
        $bytes = app(PayrollPdfExport::class)->build($snapshot, $labels);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$this->fileName('pdf').'"'] + self::NO_STORE);
    }

    private function exportSnapshot(Request $request): array
    {
        $filters = $this->sheet->filters($request->validate($this->sheet->filterRules()));

        return [$this->sheet->snapshot($filters), $this->sheet->scopeLabels($filters)];
    }

    private function fileName(string $extension): string
    {
        return 'payroll-working-sheet-'.now()->format('Ymd-His').'.'.$extension;
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->user_id;
    }
}
