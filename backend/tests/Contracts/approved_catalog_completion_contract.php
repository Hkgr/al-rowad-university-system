<?php

/** Dependency-free artifact/SQL boundary checks, not a substitute for database behavior. */
require_once dirname(__DIR__, 2).'/app/Exceptions/AcademicPlanException.php';
require_once dirname(__DIR__, 2).'/app/Services/CatalogCompletion/ApprovedCatalogManifest.php';
use App\Services\CatalogCompletion\ApprovedCatalogManifest;
$root = dirname(__DIR__, 2);
$manifest = new ApprovedCatalogManifest($root.'/resources/academic-catalog/approved-2026-10.json');
$assert = static function (bool $yes, string $message): void { if (!$yes) throw new RuntimeException($message); };
$assert(count($manifest->data['sheets']) === 9 && count($manifest->data['formulas']) === 67, 'All sheets and formula caches must be retained');
$assert(count($manifest->programs()) === 15, 'Approved scope changed');
foreach ($manifest->programs() as $program) {
    $groups = $manifest->rows('Requirements', (int) $program['program_id']);
    $assert(count($groups) === 6 && array_sum(array_column($groups, 'required_credit_hours')) === (int) $program['target_total'], 'Independent graduation budgets mismatch');
    foreach ($groups as $g) {
        $rows = array_filter($manifest->rows('Curriculum', (int) $program['program_id']), fn ($r) => $r['requirement_scope'] === $g['requirement_scope'] && $r['course_type'] === $g['requirement_type']);
        $assert(array_sum(array_column($rows, 'target_credits')) === (int) $g['required_credit_hours'], 'Approved subject classification or choice budget changed');
    }
}
$sql = file_get_contents($root.'/database/sql/approved-catalog-completion/01_apply.sql');
$assert(substr_count($sql, 'CREATE OR REPLACE TRIGGER') === 2 && !preg_match('/CREATE\s+TABLE|DISABLE\s+KEYS|FOREIGN_KEY_CHECKS|DELETE\s+FROM|INSERT\s+INTO\s+role_permissions/i', $sql), 'SQL extension exceeds exact trigger scope');
foreach (['00_preflight.sql', '02_verify.sql'] as $file) $assert(!preg_match('/^\s*(CREATE|DROP|ALTER|CALL|INSERT|UPDATE|DELETE)\b/im', file_get_contents($root.'/database/sql/approved-catalog-completion/'.$file)), 'Inspection must remain read-only');
$assert(str_contains($sql, ApprovedCatalogManifest::SHA256) && str_contains($sql, 'CONNECTION_ID()') && str_contains($sql, 'catalog_completion_code_permit'), 'Permit must be durable, manifest/connection-bound');
echo "PASS approved catalog artifact, all six budgets per program and SQL boundaries (static/pure logic only)\n";
