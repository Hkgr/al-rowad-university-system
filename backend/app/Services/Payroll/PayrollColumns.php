<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Formula\PayrollFormula;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What both export files contain and say: the identity columns, the configured columns marked for export (in saved order),
 * the display formatting of Syrian-pound figures and the shared scope/sort sentences.
 */
final class PayrollColumns
{
    public const TITLE = 'كشف الرواتب — ورقة العمل الحالية';

    public const CURRENT_SHEET_NOTE = 'ورقة عمل حالية وليست سجلًا شهريًا تاريخيًا.';

    public const CURRENCY_NOTE = 'جميع المبالغ بالليرة السورية (ل.س).';

    public const SYMBOL = 'ل.س';

    public const IDENTITY = [
        'employee_number' => 'رقم الموظف', 'full_name' => 'الاسم الكامل', 'job_title' => 'الصفة الوظيفية',
        'body' => 'الهيئة', 'workplace' => 'مكان العمل', 'academic_level' => 'المستوى الأكاديمي',
    ];

    public const SORT_LABELS = ['employee_number' => 'رقم الموظف', 'full_name' => 'الاسم الكامل', 'job_title' => 'الصفة الوظيفية', 'body' => 'الهيئة', 'workplace' => 'مكان العمل', 'academic_level' => 'المستوى الأكاديمي'];

    /**
     * Columns of the file in display order. Identity columns first, then every configured column with visible_export.
     *
     * `kind` is identity|input|formula (entered data vs calculated values); `net` marks the final net payable by its stable key.
     *
     * @return list<array{key:string, heading:string, group:string, group_label:string, type:string, aggregation:string, identity:bool, kind:string, net:bool}>
     */
    public static function exportColumns(array $config): array
    {
        $out = [];
        foreach (self::IDENTITY as $key => $heading) {
            $out[] = ['key' => $key, 'heading' => $heading, 'group' => 'employee', 'group_label' => PayrollConfigService::GROUPS['employee'], 'type' => 'text', 'aggregation' => 'none', 'identity' => true, 'kind' => 'identity', 'net' => false];
        }
        foreach ($config['columns'] as $column) {
            if ($column['visible_export']) {
                $out[] = [
                    'key' => $column['key'], 'heading' => $column['label'].($column['value_type'] === 'amount' ? ' ('.self::SYMBOL.')' : ''), 'group' => $column['group'],
                    'group_label' => PayrollConfigService::GROUPS[$column['group']] ?? $column['group'], 'type' => $column['value_type'],
                    'aggregation' => $column['aggregation'], 'identity' => false, 'kind' => $column['kind'] === 'formula' ? 'formula' : 'input', 'net' => $column['key'] === PayrollTemplate::TOTAL_NET_PAYABLE,
                ];
            }
        }

        return $out;
    }

    /** Value of an identity column on a sheet row. */
    public static function identityValue(array $row, string $key): string
    {
        return (string) match ($key) {
            'employee_number' => $row['employee_number'], 'full_name' => $row['full_name'], 'job_title' => $row['job_title'],
            'body', 'body_name' => $row['body_name'], 'workplace', 'workplace_label' => $row['workplace_label'], 'academic_level' => $row['academic_level'] ?? '',
        };
    }

    /** Readable cell text for the PDF: thousands separators, up to the column's decimals; unavailable cells say why in one word. */
    public static function display(array $column, array $row): string
    {
        if ($column['identity']) {
            return self::identityValue($row, $column['key']);
        }
        $cell = $row['cells'][$column['key']];
        if ($cell['st'] === 'missing') {
            return 'ناقص';
        }
        if ($cell['st'] === 'error') {
            return 'خطأ';
        }
        if ($cell['v'] === null) {
            return '';
        }

        return self::formatValue($cell['v'], $column['type']);
    }

    public static function formatValue(string $value, string $type): string
    {
        if ($type === 'text') {
            return $value;
        }
        $d = BigDecimal::of($value);
        if ($type === 'percent') {
            return self::group(PayrollCalculator::plain($d->withPointMovedRight(2)->toScale(4, RoundingMode::HALF_UP))).'%';
        }

        return self::group($type === 'amount' ? (string) $d->toScale(2, RoundingMode::HALF_UP) : PayrollCalculator::plain($d));
    }

    private static function group(string $plain): string
    {
        $negative = str_starts_with($plain, '-');
        $plain = ltrim($plain, '-');
        [$whole, $fraction] = array_pad(explode('.', $plain, 2), 2, null);

        return ($negative ? '-' : '').number_format((int) $whole).($fraction === null ? '' : '.'.$fraction);
    }

    /** Keys of every column an exported formula needs (transitively), so a formula never points at a missing cell. */
    public static function closure(array $config, array $keys): array
    {
        $calculator = new PayrollCalculator($config['columns'], $config['settings']);
        $needed = [];
        $visit = function (string $key) use (&$visit, &$needed, $calculator): void {
            $ast = $calculator->ast($key);
            foreach ($ast === null ? [] : PayrollFormula::references($ast) as $ref) {
                if ($calculator->column($ref) !== null && ! isset($needed[$ref])) {
                    $needed[$ref] = true;
                    $visit($ref);
                }
            }
        };
        foreach ($keys as $key) {
            $visit($key);
        }

        return array_keys($needed);
    }

    public static function scopeLine(array $snapshot, array $labels): string
    {
        $count = $snapshot['totals']['employees'];

        return $labels === []
            ? "النطاق: كل الصفوف دون مرشحات ({$count} موظفًا)"
            : "النطاق: الصفوف المطابقة للمرشحات فقط ({$count} موظفًا) — ".implode(' · ', $labels);
    }

    public static function sortLine(array $filters, array $config): string
    {
        $label = self::SORT_LABELS[$filters['sort']] ?? collect($config['columns'])->firstWhere('key', $filters['sort'])['label'] ?? $filters['sort'];

        return 'الترتيب: '.$label.($filters['direction'] === 'desc' ? ' (تنازلي)' : ' (تصاعدي)').' — الفراغ في آخر القائمة';
    }

    /** One sentence when totals leave out unavailable cells (never a silent zero). */
    public static function exclusionNote(array $columns, array $snapshot): ?string
    {
        $parts = [];
        foreach ($columns as $column) {
            $excluded = $snapshot['totals']['columns'][$column['key']]['excluded'] ?? 0;
            if ($excluded > 0) {
                $parts[] = trim(preg_replace('/\(.*\)$/u', '', $column['heading'])).": {$excluded}";
            }
        }

        return $parts === [] ? null : 'تستثني المجاميع الخلايا غير المتاحة (ناقصة أو بها خطأ): '.implode('، ', $parts).'.';
    }

    /** Honest statement for the Excel file about cells beyond Excel's 15 significant digits (never silent). */
    public static function precisionNote(array $cells): ?string
    {
        if ($cells === []) {
            return null;
        }
        $shown = implode('، ', array_slice($cells, 0, 12)).(count($cells) > 12 ? '…' : '');

        return 'تنبيه دقة Excel: '.count($cells).' خلية (ملوّنة بالبرتقالي) قد تختلف عن التطبيق بمقدار آخر خانة، لأن Excel يحمل 15 رقمًا معنويًا فقط؛ المرجع هو التطبيق. الخلايا: '.$shown.'.';
    }
}
