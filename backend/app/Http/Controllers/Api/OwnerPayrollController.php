<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollConfigService;
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
 * The browser can never send a payable amount: it is computed here from stored inputs by the configured formulas (Syrian pounds).
 */
class OwnerPayrollController extends Controller
{
    private const REVISION = ['required', 'integer', 'min:1'];

    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly PayrollSheetService $sheet, private readonly PayrollConfigService $configs) {}

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
                'config_revision' => $snapshot['config']['revision'],
            ],
            'config' => $this->configs->present($snapshot['config']),
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

    // ── input values (atomic, carrying the configuration revision they were based on) ──

    public function values(Request $request): JsonResponse
    {
        $request->validate(['changes' => ['required', 'array'], 'config_revision' => self::REVISION]);
        $result = $this->sheet->updateValues($request->input('changes'), (int) $request->input('config_revision'), $this->userId($request));

        return response()->json(['data' => $result['rows'], 'meta' => ['config_revision' => $result['config_revision']]]);
    }

    // ── columns, formulas, settings ───────────────────────────────────────

    private const COLUMN_FIELDS = ['label', 'group', 'kind', 'value_type', 'formula', 'formula_display', 'blank_as_zero', 'allow_negative', 'warn_negative', 'aggregation', 'visible_grid', 'visible_export', 'compact', 'sort_order'];

    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->configs->present($this->configs->load())], 200, self::NO_STORE);
    }

    public function previewConfig(Request $request): JsonResponse
    {
        $request->validate(['column' => ['nullable', 'array'], 'settings' => ['nullable', 'array'], 'employee_id' => ['nullable', 'integer', 'min:1'], 'focus_key' => ['nullable', 'string', 'max:40']]);
        $proposal = ['settings' => (array) $request->input('settings', [])];
        if (is_array($request->input('column'))) {
            $proposal['column'] = $request->only(['column'])['column'];
            $proposal['column'] = array_intersect_key($proposal['column'], array_flip(array_merge(self::COLUMN_FIELDS, ['key'])));
        }
        $config = $this->configs->propose($proposal);
        $employee = $request->input('employee_id') === null ? null : (int) $request->input('employee_id');
        $focus = $request->input('focus_key');
        if ($focus === null && isset($proposal['column'])) {
            $focus = $proposal['column']['key'] ?? null;
            if ($focus === null) { // a new column has no key until saved: the last added one is the proposal
                $focus = collect($config['columns'])->whereNotIn('key', array_column($this->configs->load()['columns'], 'key'))->pluck('key')->first();
            }
        }

        return response()->json(['data' => $this->sheet->impact($config, $employee, $focus) + ['focus_key' => $focus, 'revision' => $this->configs->load()['revision']]], 200, self::NO_STORE);
    }

    public function storeColumn(Request $request): JsonResponse
    {
        $request->validate(['config_revision' => self::REVISION]);

        return response()->json(['data' => $this->configs->createColumn($request->only(self::COLUMN_FIELDS), (int) $request->input('config_revision'), $this->userId($request))], 201);
    }

    public function updateColumn(Request $request, string $column): JsonResponse
    {
        $request->validate(['config_revision' => self::REVISION]);

        return response()->json(['data' => $this->configs->updateColumn($column, $request->only(self::COLUMN_FIELDS), (int) $request->input('config_revision'), $this->userId($request))]);
    }

    public function restoreColumnFormula(Request $request, string $column): JsonResponse
    {
        $request->validate(['config_revision' => self::REVISION]);

        return response()->json(['data' => $this->configs->restoreTemplateFormula($column, (int) $request->input('config_revision'), $this->userId($request))]);
    }

    public function destroyColumn(Request $request, string $column): JsonResponse
    {
        $request->validate(['config_revision' => self::REVISION, 'confirm_values' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->configs->deleteColumn($column, $request->boolean('confirm_values'), (int) $request->input('config_revision'), $this->userId($request))]);
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $request->validate([
            'config_revision' => self::REVISION, 'columns' => ['required', 'array', 'max:200'], 'columns.*.key' => ['required', 'string', 'max:40'],
            'columns.*.group' => ['nullable', 'string', 'max:20'], 'columns.*.visible_grid' => ['nullable', 'boolean'],
            'columns.*.visible_export' => ['nullable', 'boolean'], 'columns.*.compact' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->configs->saveLayout($request->input('columns'), (int) $request->input('config_revision'), $this->userId($request))]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate(['config_revision' => self::REVISION, 'settings' => ['required', 'array']]);

        return response()->json(['data' => $this->configs->updateSettings($request->input('settings'), (int) $request->input('config_revision'), $this->userId($request))]);
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
