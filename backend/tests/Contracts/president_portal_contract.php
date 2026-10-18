<?php
// Dependency-free source/SQL boundary contract, not a substitute for HTTP/SQL behavior tests.
$root = dirname(__DIR__, 3);
$read = fn ($p) => file_get_contents($root.'/'.$p);
$assert = function ($ok, $message) { if (! $ok) throw new RuntimeException($message); };
$access = $read('backend/app/Support/PresidentPortal.php');
foreach (['effectiveRoles()', 'effectivePermissions()', 'hasActualUniversityScope', 'university_president', 'ministry_observer'] as $term) $assert(str_contains($access, $term), $term);
$assert(! str_contains($access, 'hasPermission('), 'No virtual super-admin grant');
foreach (glob($root.'/backend/app/Services/President/*.php') as $f) {
    $s = file_get_contents($f);
    $assert(! preg_match('/->(insert|update|delete|save|create|lockForUpdate)\s*\(|DB::transaction/', $s), 'Read service must not write or lock');
}
$s = $read('backend/app/Services/President/PresidentReadService.php');
foreach (['MinistryQueries::officialResults()', '$this->dashboard->build', '$this->staff->deans'] as $term) $assert(str_contains($s, $term), 'Canonical read reuse: '.$term);
foreach (['00_preflight.sql','01_apply.sql','02_verify.sql','03_rollback.sql'] as $file) {
    $sql=$read('backend/database/sql/president-portal/'.$file);
    $sql=preg_replace('/--[^\n]*/','',$sql);
    $assert(! preg_match('/\b(PREPARE|EXECUTE|DELIMITER|SIGNAL|CREATE\s+TABLE|ALTER\s+TABLE)\b|DATABASE\s*\(/i',$sql), 'Static RBAC-only SQL');
    $assert(! preg_match('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:alrowad_uni_rust\.)?(users|user_roles|user_access_scopes|roles)\b/i',$sql), 'No accounts, roles or scope provisioning');
    $assert(str_contains($sql, 'OVERALL'), 'Visible terminal result');
}
echo "President portal contract: PASS\n";
