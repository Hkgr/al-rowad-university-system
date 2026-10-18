<?php

require __DIR__.'/CatalogEnvironment.php';
$env = new CatalogEnvironment;
$p = $env->connect();
$command = $argv[1] ?? '';
if ($command === 'complete-runtime-fixture') {
    require __DIR__.'/AcademicPlanRuntimeFixture.php';
    AcademicPlanRuntimeFixture::complete($env, $p);
    echo "Synthetic runtime fixture completed\n"; exit;
}
if ($command === 'test-restore-hours-check') {
    // Recover only the intentional verifier-tampering experiment on this guarded test instance.
    $p->exec('ALTER TABLE academic_plan_versions DROP CONSTRAINT IF EXISTS chk_plan_hours');
    $p->exec('ALTER TABLE academic_plan_versions ADD CONSTRAINT chk_plan_hours CHECK(version_number>0 AND (total_credit_hours IS NULL OR total_credit_hours>0))');
    echo "Restored disposable fixture CHECK\n"; exit;
}
if ($command === 'schema-metadata') {
    echo json_encode($p->query("SELECT table_name, constraint_name, check_clause FROM information_schema.check_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name IN('academic_plan_control','academic_plan_versions','student_academic_plan_assignments','academic_plan_events') ORDER BY table_name,constraint_name")->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
if ($command === 'fixture') {
    // Synthetic prerequisites not covered by the earlier catalog fixture. Never import the production dump.
    foreach (['student_course_registrations' => 'student_course_registration_id',
        'student_registration_requests' => 'student_registration_request_id',
        'student_registration_modification_requests' => 'student_registration_modification_request_id',
        'student_registration_replacement_requests' => 'student_registration_replacement_request_id',
        'student_registration_request_items' => 'student_registration_request_item_id',
        'student_registration_modification_items' => 'student_registration_modification_item_id',
        'student_registration_replacement_items' => 'student_registration_replacement_item_id',
        'student_registration_withdrawal_requests' => 'student_registration_withdrawal_request_id',
        'student_course_results' => 'student_course_result_id', 'grade_approvals' => 'grade_approval_id', 'grade_audit_logs' => 'grade_audit_log_id'] as $table => $id) {
        $p->exec("CREATE TABLE $table ($id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, student_id INT NULL, course_offering_id INT NULL, status VARCHAR(30) NULL, created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB");
    }
    // Match the production index names that the new package deliberately verifies and replaces.
    $p->exec('ALTER TABLE program_courses RENAME INDEX academic_program_id TO uq_program_course');
    $p->exec('ALTER TABLE academic_requirement_groups RENAME INDEX academic_program_id TO uq_program_scope_type');
    echo "Synthetic plan prerequisites created\n"; exit;
}
if ($command === 'complete-decision-fixture') {
    $p->exec('ALTER TABLE permissions ADD COLUMN IF NOT EXISTS permission_name VARCHAR(150) NULL');
    foreach (['student_progression_decisions', 'student_graduation_decisions'] as $table) $p->exec("ALTER TABLE $table ADD COLUMN IF NOT EXISTS student_id INT NULL");
    echo "Synthetic decision references completed\n"; exit;
}
if ($command === 'workflow-test') {
    // Test-only override on the verified disposable database, NEVER a deployment readiness claim.
    $p->exec('UPDATE academic_plan_control SET is_ready=1 WHERE control_id=1');
    try {
        $app = $env->laravel();
        foreach ([\App\Support\ScientificProgramAccess::VIEW, \App\Support\ScientificProgramAccess::PLANS, \App\Support\ScientificProgramAccess::APPROVE, \App\Support\ScientificProgramAccess::ASSIGN] as $code) {
            $id = \Illuminate\Support\Facades\DB::table('permissions')->insertGetId(['module_id' => 1, 'permission_code' => $code], 'permission_id');
            \Illuminate\Support\Facades\DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $id]);
        }
        $p->exec('INSERT INTO students(student_id,academic_program_id) VALUES(2,1)');
        $w = app(\App\Services\AcademicPlanWorkflow::class); $actor = \App\Models\User::findOrFail(1);
        $confirm = fn () => ['revision' => app(\App\Services\AcademicCatalogTransaction::class)->revision(), 'confirmed' => true];
        $w->begin($actor, 1, $confirm());
        try { $p->exec('INSERT INTO students(student_id,academic_program_id) VALUES(3,1)'); throw new RuntimeException('Raw admission bypass'); }
        catch (PDOException $e) { if (!str_contains($e->getMessage(), 'academic_plan_initialization_incomplete')) throw $e; }
        $w->fixTransition($actor, 1, $confirm());
        $source = (int) $p->query('SELECT academic_plan_version_id FROM academic_plan_versions WHERE academic_program_id=1')->fetchColumn();
        try { $p->exec("UPDATE program_courses SET is_active=0 WHERE academic_plan_version_id=$source"); throw new RuntimeException('Fixed membership mutated'); }
        catch (PDOException $e) { if (!str_contains($e->getMessage(), 'academic_plan_locked')) throw $e; }
        $copy = $w->copy($actor, 1, $source, ['revision' => $confirm()['revision'], 'label' => 'Test approved plan']);
        $id = (int) $copy['version']->getKey();
        $w->approve($actor, 1, $id, $confirm());
        $w->setDefault($actor, 1, $id, $confirm());
        $p->exec('INSERT INTO students(student_id,academic_program_id) VALUES(3,1)');
        if ((int) $p->query('SELECT academic_plan_version_id FROM student_academic_plan_assignments WHERE student_id=3 AND current_slot=1')->fetchColumn() !== $id) throw new RuntimeException('Raw new student was not assigned');
        if ((int) $p->query('SELECT academic_plan_version_id FROM student_academic_plan_assignments WHERE student_id=2 AND current_slot=1')->fetchColumn() !== $source) throw new RuntimeException('Default moved old student');
        echo "PASS MariaDB + Laravel initialization / raw admission guard / fixed reference / copy / approve / explicit default / raw writer atomic assignment\n";
    } finally { $p->exec('UPDATE academic_plan_control SET is_ready=0 WHERE control_id=1'); }
    exit;
}
if (in_array($command, ['00_preflight.sql', '01_apply.sql', '02_verify.sql'], true)) {
    foreach ($env->package($p, $command, null, 'academic-program-management') as $row) echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
    exit;
}
throw new RuntimeException('Unknown guarded test command');
