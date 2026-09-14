<?php

require __DIR__.'/CatalogEnvironment.php';
$environment = new CatalogEnvironment;
$p = $environment->connect();
$check = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$package = fn (string $name, ?string $stop = null) => $environment->package($p, $name, $stop, 'academic-program-management');
$terminal = function (array $rows, string $wanted) use ($check): void {
    $last = end($rows); $check(($last['result'] ?? '') === $wanted, 'Unexpected terminal result: '.json_encode($last));
};
$academicSnapshot = function () use ($p): string {
    $rows = [];
    foreach (['students', 'program_courses', 'academic_requirement_groups', 'academic_programs'] as $table) {
        // Schema changes are expected; business values, IDs and timestamps must be unchanged.
        $columns = $p->query("SELECT column_name FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='$table' AND column_name NOT IN('academic_plan_version_id','plan_scope_key','plan_state','default_academic_plan_version_id','archived_at') ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
        $rows[$table] = $p->query('SELECT '.implode(',', array_map(fn ($c) => '`'.$c.'`', $columns))." FROM $table ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode($rows));
};
$before = $academicSnapshot();
$terminal($package('00_preflight.sql'), 'READY');
$package('01_apply.sql', 'CREATE TABLE IF NOT EXISTS academic_plan_versions');
$check((int) $p->query('SELECT is_ready FROM academic_plan_control WHERE control_id=1')->fetchColumn() === 0, 'Interrupted package claims readiness');
$terminal($package('00_preflight.sql'), 'READY');
$terminal($package('01_apply.sql'), 'APPLIED');
$terminal($package('02_verify.sql'), 'PASS');
$check($before === $academicSnapshot(), 'Package changed academic business data');
$check((int) $p->query('SELECT COUNT(*) FROM academic_plan_versions')->fetchColumn() === 0, 'SQL fabricated plans');
$check((int) $p->query('SELECT COUNT(*) FROM student_academic_plan_assignments')->fetchColumn() === 0, 'SQL assigned students');
$p->exec('ALTER TABLE academic_plan_versions ADD COLUMN IF NOT EXISTS test_compatible_extra VARCHAR(20) NULL');
$terminal($package('02_verify.sql'), 'PASS');
$package('01_apply.sql', 'UPDATE academic_plan_control SET is_ready=0');
$environment->laravel();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage()."\n"); exit(1); });
try { App\Services\AcademicPlanContext::assertReady(); throw new RuntimeException('Unready schema permitted context read'); }
catch (App\Exceptions\AcademicPlanException $e) { $check($e->errorCode === 'academic_plan_schema_not_ready', 'Wrong controlled readiness error'); }
try { $p->exec("INSERT INTO students(academic_program_id) VALUES(1)"); throw new RuntimeException('Raw student writer ignored unavailable plan schema'); }
catch (PDOException $e) { $check(str_contains($e->getMessage(), 'academic_plan_schema_not_ready'), 'Wrong raw-writer guard'); }
$terminal($package('01_apply.sql'), 'APPLIED');
$terminal($package('02_verify.sql'), 'PASS');
$p->exec('ALTER TABLE academic_plan_versions DROP CONSTRAINT chk_plan_hours');
$terminal($package('02_verify.sql'), 'FAIL');
$p->exec('ALTER TABLE academic_plan_versions ADD CONSTRAINT chk_plan_hours CHECK(version_number>0 AND (total_credit_hours IS NULL OR total_credit_hours>0))');
$terminal($package('02_verify.sql'), 'PASS');
$check($before === $academicSnapshot(), 'Resume/verify changed academic business data');
echo "PASS actual MariaDB: first apply interrupted after two tables, compatible resume, no backfill, extra-column compatibility, unready app/raw-write rejection, reapply, missing CHECK detection, restored verification\n";
