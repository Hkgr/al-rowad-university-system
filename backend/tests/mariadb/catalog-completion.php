<?php

require __DIR__.'/CatalogEnvironment.php';
$environment = new CatalogEnvironment;
$p = $environment->connect();
$command = $argv[1] ?? '';
if (in_array($command, ['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql'], true)) {
    $rows = $environment->package($p, $command, null, 'approved-catalog-completion');
    echo json_encode(end($rows), JSON_THROW_ON_ERROR)."\n"; exit;
}
$app = $environment->laravel();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage()."\n"); exit(1); });
$db = \Illuminate\Support\Facades\DB::class;
$p = $db::connection()->getPdo(); // Parent mutations must use the same locked connection, not a second self-blocking PDO.
$manifest = new \App\Services\CatalogCompletion\ApprovedCatalogManifest;
$actor = \App\Models\User::findOrFail(1);
$revision = fn () => app(\App\Services\AcademicCatalogTransaction::class)->revision();
$check = static function ($yes, $message): void { if (!$yes) throw new RuntimeException($message); };
if ($command === 'worker') {
    echo json_encode(['ready' => (int) $p->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n"; flush();
    $job = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    echo "{\"entered\":true}\n"; flush();
    $paused = false; $lastAssignment = false; $queries = [];
    $pause = static function (string $point): void { echo json_encode(['paused' => $point])."\n"; flush(); if (trim(fgets(STDIN)) !== 'continue') throw new RuntimeException('Invalid independent barrier'); };
    $db::listen(function ($q) use ($job, &$paused, &$lastAssignment, &$queries, $pause) {
        $queries[] = $q->sql;
        if (($job['pause'] ?? '') === 'read_snapshot' && !$paused && preg_match('/^select \* from `courses` order by/i', $q->sql)) {
            $paused = true; $pause('read_snapshot');
        }
        if (($job['pause'] ?? '') === 'before_assignments' && !$paused && preg_match('/^select `student_id` from `students` where `academic_program_id` = \? order by/i', $q->sql)
            && (int) ($q->bindings[0] ?? 0) === 40) {
            $paused = true;
            if ($job['interrupt'] ?? false) throw new RuntimeException('synthetic_interruption');
            $pause('before_assignments');
        }
        if (($job['pause'] ?? '') === 'after_assignment' && str_starts_with($q->sql, 'insert into `user_activity_logs`') && in_array('catalog_completion.assignment', $q->bindings, true)) {
            foreach ($q->bindings as $value) if (is_string($value) && str_contains($value, '"student_ids":[1040]')) $lastAssignment = true;
        }
    });
    $app['events']->listen(\Illuminate\Database\Events\TransactionCommitted::class, function ($event) use (&$paused, &$lastAssignment, $pause) {
        if ($lastAssignment && !$paused && $event->connection->transactionLevel() === 0) { $paused = true; $pause('after_assignment'); }
    });
    try {
        if ($job['kind'] === 'apply') $result = app(\App\Services\CatalogCompletion\CatalogCompletionService::class)->apply($actor, $manifest, $job['revision'], $job['operation_id'], $job['resume'] ?? false);
        elseif (in_array($job['kind'], ['preview', 'verify'], true)) {
            $result = app(\App\Services\CatalogCompletion\CatalogCompletionService::class)->{$job['kind']}($actor, $manifest);
            foreach ($queries as $sql) if (preg_match('/for update|lock in share mode|^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql)) throw new RuntimeException('Read emitted a lock/write: '.$sql);
            $result = ['status' => $result['status'] ?? 'PREVIEW', 'revision' => $result['revision'], 'read_queries' => count($queries)];
        }
        elseif ($job['kind'] === 'student') $result = \App\Services\AcademicPlanContext::transaction(function () use ($db, $job) {
            $db::table('students')->insert(['student_id' => $job['student_id'], 'academic_program_id' => 40]);
            return ['version_id' => \App\Services\AcademicPlanContext::forStudent(\App\Models\Student::findOrFail($job['student_id']))->versionId];
        });
        else throw new RuntimeException('Worker kind allowlist');
        echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'code' => $e->errorCode ?? get_class($e), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR)."\n"; }
    exit;
}
if ($command === 'prepare') {
    $check(!$db::getSchemaBuilder()->hasTable('academic_plan_control'), 'Seed legacy contexts before installing plan controls, never bypass installed guards.');
    $p->exec('ALTER TABLE user_roles ADD COLUMN IF NOT EXISTS assigned_at DATETIME NULL');
    foreach (['academic_requirement_groups', 'program_courses', 'student_course_registrations', 'student_registration_requests',
        'student_registration_modification_requests', 'student_registration_replacement_requests', 'student_progression_decisions', 'student_graduation_decisions'] as $table) {
        $p->exec("ALTER TABLE $table MODIFY updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
    }
    foreach (['academic_levels' => 'level_order', 'semesters' => 'semester_order'] as $table => $column) $p->exec("ALTER TABLE $table ADD COLUMN IF NOT EXISTS $column INT NULL");
    $p->exec('UPDATE academic_levels SET level_order=1 WHERE academic_level_id=1');
    $p->exec('UPDATE semesters SET semester_order=1 WHERE semester_id=1');
    foreach (range(2, 5) as $id) $db::table('academic_levels')->insert(['academic_level_id' => $id, 'level_name' => 'Synthetic '.$id, 'level_order' => $id]);
    $db::table('semesters')->insert(['semester_id' => 2, 'semester_name' => 'Synthetic second', 'semester_order' => 2]);
    foreach ([\App\Support\ScientificProgramAccess::VIEW, \App\Support\ScientificProgramAccess::PLANS, \App\Support\ScientificProgramAccess::APPROVE, \App\Support\ScientificProgramAccess::ASSIGN] as $code) {
        $id = $db::table('permissions')->where('permission_code', $code)->value('permission_id');
        if (!$id) $id = $db::table('permissions')->insertGetId(['module_id' => 1, 'permission_code' => $code], 'permission_id');
        $db::table('role_permissions')->updateOrInsert(['role_id' => 1, 'permission_id' => $id]);
    }
    foreach ($manifest->rows('Catalog') as $c) $db::table('courses')->insert(['course_id' => (int) $c['course_id'], 'course_code' => $c['course_code'], 'course_name' => $c['course_name'],
        'credit_hours' => (int) $c['credit_hours'], 'theoretical_hours' => $manifest::nullableInt($c['theoretical_hours']), 'practical_hours' => $manifest::nullableInt($c['practical_hours']), 'is_active' => (bool) $c['is_active']]);
    $p->exec("UPDATE courses SET updated_at='2025-01-01 00:00:00'");
    $p->exec('ALTER TABLE courses MODIFY updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    foreach ($manifest->programs() as $row) {
        $college = (int) $row['college_id']; $department = (int) $row['department_id']; $id = (int) $row['program_id'];
        $db::table('colleges')->insertOrIgnore(['college_id' => $college, 'college_name' => 'Synthetic college '.$college]);
        $db::table('departments')->insert(['department_id' => $department, 'college_id' => $college, 'department_name' => 'Synthetic department '.$department]);
        $db::table('academic_programs')->insert(['academic_program_id' => $id, 'department_id' => $department,
            'program_name' => in_array($id, [40, 41, 42], true) ? $row['current_name'] : 'Synthetic unmatched '.$id, 'total_credit_hours' => (int) $row['current_total']]);
    }
    $db::table('departments')->insert(['department_id' => 27, 'college_id' => 13, 'department_name' => 'Synthetic excluded architecture']);
    $db::table('academic_programs')->insert(['academic_program_id' => 27, 'department_id' => 27, 'program_name' => 'Synthetic excluded architecture', 'total_credit_hours' => 165]);
    foreach ([[27, 780], [28, 780], [28, 892]] as [$program, $course]) $db::table('program_courses')->insert(['academic_program_id' => $program, 'course_id' => $course, 'course_type' => 'mandatory', 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'updated_at' => '2025-01-01 00:00:00']);
    $group = $db::table('academic_requirement_groups')->insertGetId(['academic_program_id' => 41, 'group_code' => 'SYN-INCOMPLETE-41',
        'group_name' => 'Synthetic missing budget', 'requirement_scope' => 'university', 'requirement_type' => 'mandatory', 'required_credit_hours' => null, 'is_active' => false, 'updated_at' => '2025-01-01 00:00:00'], 'requirement_group_id');
    $membership = $db::table('program_courses')->insertGetId(['academic_program_id' => 41, 'course_id' => 403, 'course_type' => 'mandatory', 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'updated_at' => '2025-01-01 00:00:00'], 'program_course_id');
    // Match the supplied production incident contract BEFORE installing any repair: both placements NOT NULL.
    $p->exec("ALTER TABLE program_courses MODIFY academic_program_id INT NOT NULL, MODIFY course_id INT NOT NULL, MODIFY course_type VARCHAR(50) NOT NULL COMMENT 'mandatory or elective', MODIFY academic_level_id INT NOT NULL, MODIFY recommended_semester_id INT NOT NULL");
    $db::table('program_course_requirement_groups')->insert(['program_course_id' => $membership, 'requirement_group_id' => $group]);
    foreach ($manifest->programs() as $row) $db::table('students')->insert(['student_id' => 1000 + (int) $row['program_id'], 'academic_program_id' => (int) $row['program_id']]);
    $db::table('students')->insert(['student_id' => 1027, 'academic_program_id' => 27]);
    $p->exec('ALTER TABLE course_offerings ADD COLUMN IF NOT EXISTS result_announced_at DATETIME NULL');
    foreach ([780, 892] as $course) {
        $db::table('course_offerings')->insert(['course_offering_id' => $course, 'academic_program_id' => 28, 'course_id' => $course, 'academic_year_id' => 1, 'semester_id' => 1, 'result_announced_at' => '2025-04-03 12:00:00']);
        $db::table('student_course_registrations')->insert(['student_course_registration_id' => $course, 'student_id' => 1028, 'course_offering_id' => $course, 'registration_status_id' => 1]);
        $db::table('student_course_results')->insert(['student_course_registration_id' => $course, 'result_status_id' => 1, 'final_mark' => 80, 'theoretical_total' => 80]);
        $db::table('grade_approvals')->insert(['course_offering_id' => $course, 'approval_status_id' => 1]);
    }
    $db::table('course_offerings')->insert(['course_offering_id' => 9001, 'academic_program_id' => 40, 'course_id' => 403, 'academic_year_id' => 1, 'semester_id' => 1, 'result_announced_at' => '2025-04-03 12:00:00']);
    $db::table('student_course_registrations')->insert(['student_course_registration_id' => 9001, 'student_id' => 1040, 'course_offering_id' => 9001, 'registration_status_id' => 1, 'updated_at' => '2025-01-01 00:00:00']);
    $db::table('student_progression_decisions')->insert(['student_id' => 1040, 'status' => 'approved', 'current_slot' => 0, 'updated_at' => '2025-01-01 00:00:00']);
    $db::table('student_course_results')->insert(['student_course_registration_id' => 9001, 'result_status_id' => 1, 'final_mark' => 80, 'theoretical_total' => 80]);
    $db::table('grade_approvals')->insert(['course_offering_id' => 9001, 'approval_status_id' => 1]);
    $p->exec('CREATE TABLE student_grade_components(student_grade_component_id INT PRIMARY KEY,student_course_registration_id INT NOT NULL,grade_component_id INT NOT NULL,obtained_mark DECIMAL(6,3) NULL,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB');
    $db::table('grade_components')->insert(['grade_component_id' => 9001, 'course_offering_id' => 9001, 'component_type' => 'theoretical', 'max_mark' => 100, 'is_required' => true]);
    $db::table('student_grade_components')->insert(['student_grade_component_id' => 9001, 'student_course_registration_id' => 9001, 'grade_component_id' => 9001, 'obtained_mark' => 0, 'created_at' => '2025-04-01 00:00:00']);
    $p->exec('CREATE TABLE grade_part_approvals(grade_part_approval_id INT PRIMARY KEY,course_offering_id INT NOT NULL,component_type VARCHAR(20) NOT NULL,status VARCHAR(20) NOT NULL,submission_version INT NOT NULL,approved_at DATETIME NULL) ENGINE=InnoDB');
    $p->exec("INSERT INTO grade_part_approvals VALUES(9001,9001,'theoretical','approved',2,'2025-04-02 12:00:00')");
    $p->exec('CREATE TABLE grade_part_approval_events(grade_part_approval_event_id INT PRIMARY KEY,grade_part_approval_id INT NOT NULL,action VARCHAR(30) NOT NULL,performed_at DATETIME NOT NULL) ENGINE=InnoDB');
    $p->exec("INSERT INTO grade_part_approval_events VALUES(9001,9001,'approved','2025-04-02 12:00:00')");
    $db::table('students')->where('student_id', 1042)->update(['deleted_at' => '2025-04-04 00:00:00']);
    echo "Prepared synthetic academic contexts and approved result; no private student records imported\n"; exit;
}
if ($command === 'runtime') {
    $service = app(\App\Services\CatalogCompletion\CatalogCompletionService::class);
    $withoutProvenance = static function ($rows): array {
        return array_map(static function ($row) { $r = (array) $row; unset($r['academic_plan_version_id'], $r['plan_program_course_id'], $r['plan_scope_key']); return $r; }, $rows);
    };
    $operational = fn () => [
        $withoutProvenance($db::table('student_course_registrations')->orderBy('student_course_registration_id')->get()->all()),
        $withoutProvenance($db::table('student_progression_decisions')->orderBy('student_progression_decision_id')->get()->all()),
        $withoutProvenance($db::table('academic_requirement_groups')->where('group_code', 'SYN-INCOMPLETE-41')->get()->all()),
        $withoutProvenance($db::table('program_courses')->where('program_course_id', $db::table('program_course_requirement_groups')->where('requirement_group_id', $db::table('academic_requirement_groups')->where('group_code', 'SYN-INCOMPLETE-41')->value('requirement_group_id'))->value('program_course_id'))->get()->all()),
    ];
    $snapshot = fn () => [$db::table('student_course_results')->orderBy('student_course_result_id')->get()->all(),
        $db::table('student_grade_components')->orderBy('student_grade_component_id')->get()->all(),
        $db::table('grade_part_approvals')->orderBy('grade_part_approval_id')->get()->all(),
        $db::table('grade_part_approval_events')->orderBy('grade_part_approval_event_id')->get()->all(),
        $db::table('grade_approvals')->orderBy('grade_approval_id')->get()->all(),
        $db::table('course_offerings')->orderBy('course_offering_id')->get()->all()];
    $grades = app(\App\Services\GradeService::class);
    $student = fn () => \App\Models\Student::findOrFail(1040);
    $gpaBefore = $grades->calculateGpa($student(), 1, 1);
    $transcriptBefore = $grades->getTranscript($student());
    $before = $snapshot(); $operationalBefore = $operational(); $epoch = $revision();
    $preview = $service->preview($actor, $manifest);
    $check($epoch === $revision() && $db::table('academic_plan_versions')->count() === 0, 'Preview wrote academic data');
    $operation = (string) \Illuminate\Support\Str::uuid();
    $result = $service->apply($actor, $manifest, $epoch, $operation);
    foreach ([40 => 141, 41 => 82, 42 => 82] as $id => $hours) {
        $context = \App\Services\AcademicPlanContext::forProgram($id);
        $check((int) $context->requiredHours === $hours, json_encode($result['groups']));
        $check(\App\Services\AcademicPlanContext::forStudent(\App\Models\Student::withTrashed()->findOrFail(1000 + $id))->versionId === $context->versionId, 'Current/archived student not assigned');
    }
    $check($before == $snapshot(), 'Results, approvals or announcement timestamps changed');
    $check($operationalBefore === $operational(), 'Fixation changed existing academic values/IDs or ON UPDATE timestamps');
    $registrationVersion = (int) $db::table('student_course_registrations')->where('student_course_registration_id', 9001)->value('academic_plan_version_id');
    $check($db::table('academic_plan_versions')->where('academic_plan_version_id', $registrationVersion)->value('status') === 'transitional', 'An old registration was relinked to the new default');
    $check($gpaBefore === $grades->calculateGpa($student(), 1, 1), 'Canonical GPA changed');
    array_walk_recursive($transcriptBefore, function (&$value, $key) { if ($key === 'program_name') $value = 'برنامج الإجازة في التدريب الرياضي'; });
    $check($transcriptBefore === $grades->getTranscript($student()), 'Transcript changed beyond the approved current program-name correction');
    $check($result === $service->apply($actor, $manifest, $epoch, $operation), 'Lost response replay did not precede epoch check');
    $check($result === $service->status($actor, $manifest, $operation), 'Durable receipt mismatch');
    $versions = $db::table('academic_plan_versions')->count();
    $service->apply($actor, $manifest, $revision(), (string) \Illuminate\Support\Str::uuid());
    $check($versions === $db::table('academic_plan_versions')->count(), 'Second operation duplicated unchanged plans');
    $p->exec('INSERT INTO students(student_id,academic_program_id) VALUES(2040,40)');
    $check((int) $p->query('SELECT academic_plan_version_id FROM student_academic_plan_assignments WHERE student_id=2040 AND current_slot=1')->fetchColumn() === \App\Services\AcademicPlanContext::forProgram(40)->versionId, 'New student did not receive explicit default');
    $verification = $service->verify($actor, $manifest);
    foreach ([40, 41, 42] as $id) $check($verification['programs'][$id]['historical_source_matches'] && $verification['programs'][$id]['plan_content_matches'], 'Content verification failed');
    file_put_contents($environment->config['directory'].'/completion-runtime.json', json_encode(['version' => $p->query('SELECT VERSION()')->fetchColumn(),
        'isolation' => $p->query('SELECT @@tx_isolation')->fetchColumn(), 'result' => $result, 'verify' => $verification], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "PASS real MariaDB preview/publication/archived assignments/old-result content/replay/no-op/new-admission/verification ". $p->query('SELECT VERSION()')->fetchColumn()."\n"; exit;
}
if ($command === 'code-permutation') {
    $tx = app(\App\Services\AcademicCatalogTransaction::class);
    $correction = app(\App\Services\CatalogCompletion\ApprovedCatalogCodeCorrection::class);
    $projection = app(\App\Services\CatalogCompletion\CatalogCompletionProjection::class)->build($manifest);
    $rows = [$projection['rows']['P28-R0114'], $projection['rows']['P28-R0115']];
    $operation = $db::table('user_activity_logs')->where('action_code', 'catalog_completion.started')->first();
    $operation = json_decode($operation->description, true)['operation_id'];
    $before = $p->query('SELECT * FROM courses WHERE course_id IN(780,892) ORDER BY course_id')->fetchAll(PDO::FETCH_ASSOC);
    $members = $p->query('SELECT * FROM program_courses WHERE academic_program_id=27 ORDER BY program_course_id')->fetchAll(PDO::FETCH_ASSOC);
    $grades = app(\App\Services\GradeService::class); $student = fn () => \App\Models\Student::findOrFail(1028);
    $historicalBefore = $grades->getTranscript($student()); $gpaBefore = $grades->calculateGpa($student(), 1, 1)['gpa'];
    $evidenceBefore = [$p->query('SELECT * FROM student_course_results ORDER BY student_course_result_id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM grade_approvals ORDER BY grade_approval_id')->fetchAll(PDO::FETCH_ASSOC)];
    $run = function () use ($actor, $manifest, $operation, $rows, $correction, $revision) {
        app(\App\Services\AcademicPlanWorkflow::class)->fixTransition($actor, 28, ['revision' => $revision(), 'confirmed' => true]);
        $correction->apply($actor, $manifest, $operation, $rows);
    };
    try { $tx->run(function () use ($run) { $run(); throw new RuntimeException('rollback_probe'); }); }
    catch (RuntimeException $e) { if ($e->getMessage() !== 'rollback_probe') throw $e; }
    $check($before === $p->query('SELECT * FROM courses WHERE course_id IN(780,892) ORDER BY course_id')->fetchAll(PDO::FETCH_ASSOC), 'Failed code permutation leaked writes');
    $tx->run($run);
    $after = $p->query('SELECT * FROM courses WHERE course_id IN(780,892) ORDER BY course_id')->fetchAll(PDO::FETCH_ASSOC);
    $expected = $before; $expected[0]['course_code'] = 'FE113'; $expected[1]['course_code'] = 'FE114';
    $check($after === $expected, 'Permutation changed more than codes');
    $check($members === $p->query('SELECT * FROM program_courses WHERE academic_program_id=27 ORDER BY program_course_id')->fetchAll(PDO::FETCH_ASSOC), 'Excluded memberships changed');
    $historicalAfter = $grades->getTranscript($student());
    $old = collect($historicalBefore['terms'][0]['courses'])->keyBy('course_id');
    foreach ($historicalAfter['terms'][0]['courses'] as $course) {
        $id = $course['course_id']; $registration = \App\Models\StudentCourseRegistration::with('courseOffering.course')->findOrFail($id);
        $summary = (new \App\Http\Resources\RegistrationSummaryItemResource($registration))->resolve();
        $check($course['course_code'] === $old[$id]['course_code'] && $course['recorded_course_code'] === $old[$id]['course_code'], 'Historical civil reference lost');
        $check($course['current_course_code'] === ($id === 780 ? 'FE113' : 'FE114'), 'Corrected code missing');
        $check($course['grades'] === $old[$id]['grades'] && $course['course_name'] === $old[$id]['course_name'] && $course['credit_hours'] === $old[$id]['credit_hours'], 'Civil academic outcome changed');
        $check($summary['course_code'] === $course['course_code'] && $grades->getRegistrationGrades($id)['course']['course_code'] === $course['course_code'], 'Registration reader differs from transcript/export data');
    }
    $check($gpaBefore === $grades->calculateGpa($student(), 1, 1)['gpa'], 'Civil GPA changed');
    $check($historicalAfter === $grades->getTranscript($student()), 'Repeated historical read differs');
    $check($evidenceBefore === [$p->query('SELECT * FROM student_course_results ORDER BY student_course_result_id')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT * FROM grade_approvals ORDER BY grade_approval_id')->fetchAll(PDO::FETCH_ASSOC)], 'Civil marks/approvals changed');
    // Exact permit is one-shot. Re-execution cannot create another event or mutate old references.
    $events = $db::table('academic_plan_events')->count();
    try { $tx->run(fn () => $correction->apply($actor, $manifest, $operation, $rows)); throw new RuntimeException('Correction ran twice'); }
    catch (\App\Exceptions\AcademicPlanException $e) { $check($e->errorCode === 'catalog_completion_code_before_image_changed', $e->getMessage()); }
    $check($events === $db::table('academic_plan_events')->count(), 'Repeated correction wrote history');
    $tx->run(function () use ($db) {
        $db::table('course_offerings')->insert(['course_offering_id' => 1780, 'academic_program_id' => 28, 'course_id' => 780, 'academic_year_id' => 1, 'semester_id' => 2]);
        $old = $db::table('student_course_registrations')->where('student_course_registration_id', 780)->first();
        $db::table('student_course_registrations')->insert(['student_course_registration_id' => 1780, 'student_id' => 1028, 'course_offering_id' => 1780, 'registration_status_id' => 1,
            'academic_plan_version_id' => $old->academic_plan_version_id, 'plan_program_course_id' => $old->plan_program_course_id]);
    });
    $new = \App\Support\HistoricalCourseCode::fields(\App\Models\StudentCourseRegistration::findOrFail(1780));
    $check($new['course_code'] === 'FE113' && $new['recorded_course_code'] === null && $new['original_plan_course_code'] === 'FE114', 'New context received a fabricated historical reference');
    try { $p->exec("UPDATE courses SET course_code='FE114-wrong' WHERE course_id=780"); throw new RuntimeException('Ordinary code writer bypassed guards'); }
    catch (PDOException $e) { $check(str_contains($e->getMessage(), 'academic_catalog_history_locked'), $e->getMessage()); }
    echo "PASS exact code-only permutation/rollback/same IDs and fields/excluded memberships/ordinary writer lock/historical civil readers and numerical results/current context/one-shot repeat\n"; exit;
}
if ($command === 'package-negative') {
    $before = $p->query('SELECT * FROM courses ORDER BY course_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) $environment->package($p, $file, null, 'approved-catalog-completion');
    // The original catalog verifier describes the pre-plan indexes/triggers. The installed plan verifier supersedes it.
    $verified = $environment->package($p, '02_verify.sql', null, 'academic-program-management');
    $check(end($verified)['result'] === 'PASS', 'Installed plan verifier rejected the known exact extension');
    $row = $p->query("SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='sc_plan_course_u'")->fetchColumn();
    $p->exec('CREATE OR REPLACE TRIGGER sc_plan_course_u BEFORE UPDATE ON courses FOR EACH ROW BEGIN DO 1; END');
    foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
        try {
            $rows = $environment->package($p, $file, null, 'approved-catalog-completion');
            if ($file === '01_apply.sql') throw new RuntimeException('Unknown trigger accepted');
            $check(in_array(end($rows)['result'], ['BLOCKED', 'FAIL'], true), 'Unknown trigger accepted by read-only inspection');
        }
        catch (PDOException $e) { $check(str_contains($e->getMessage(), 'catalog_completion_unrecognized_trigger'), $e->getMessage()); }
    }
    try { app(\App\Services\CatalogCompletion\ApprovedCatalogCodeCorrection::class)->assertReady(); throw new RuntimeException('Readiness accepted no-op trigger'); }
    catch (\App\Exceptions\AcademicPlanException $e) { $check($e->errorCode === 'catalog_completion_code_schema_not_ready', $e->getMessage()); }
    $check(str_contains($p->query("SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='sc_plan_course_u'")->fetchColumn(), 'DO 1'), 'Installer overwrote unknown trigger');
    $p->exec('CREATE OR REPLACE TRIGGER sc_plan_course_u BEFORE UPDATE ON courses FOR EACH ROW '.$row);
    $environment->package($p, '02_verify.sql', null, 'approved-catalog-completion');
    try { $environment->package($p, '03_rollback.sql', null, 'approved-catalog-completion'); throw new RuntimeException('Rollback erased installed correction history'); }
    catch (PDOException $e) { $check(str_contains($e->getMessage(), 'catalog_completion_history_requires_forward_fix'), $e->getMessage()); }
    $check($before === $p->query('SELECT * FROM courses ORDER BY course_id')->fetchAll(PDO::FETCH_ASSOC), 'Package tests changed academic data');
    echo "PASS reapply/known definitions/no-op negative/runtime readiness/data preservation/history-safe rollback refusal\n"; exit;
}
if (in_array($command, ['read-races', 'stage-races'], true)) {
    $read = static function ($pipe): array {
        $until = microtime(true) + 40; $line = '';
        do { $part = fgets($pipe); if ($part !== false) { $line .= $part; if (str_ends_with($line, "\n")) return json_decode($line, true, flags: JSON_THROW_ON_ERROR); } usleep(10000); } while (microtime(true) < $until);
        throw new RuntimeException('Independent read/stage barrier timeout');
    };
    $spawn = function (array $job) use ($environment, $read): array {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['file', $environment->config['directory'].'/completion-worker.stderr', 'a']], $pipes);
        stream_set_blocking($pipes[1], false); $read($pipes[1]); fwrite($pipes[0], json_encode($job)."\n"); fflush($pipes[0]); $read($pipes[1]);
        return [$process, $pipes];
    };
    $finish = static function ($process, $pipes): void { fclose($pipes[0]); fclose($pipes[1]); proc_close($process); };
    $workflow = app(\App\Services\AcademicPlanWorkflow::class); $source = \App\Services\AcademicPlanContext::forProgram(40)->versionId;
    $copy = $workflow->copy($actor, 40, $source, ['revision' => $revision(), 'label' => 'Synthetic independent writer']);
    $alternate = (int) $copy['version']->getKey();
    $workflow->approve($actor, 40, $alternate, ['revision' => $revision(), 'confirmed' => true]);
    $setDefault = fn ($id) => $workflow->setDefault($actor, 40, $id, ['revision' => $revision(), 'confirmed' => true]);
    $assign = fn ($id) => \App\Services\AcademicPlanContext::forStudent(\App\Models\Student::findOrFail(1040))->versionId === $id ? null
        : $workflow->transfer($actor, 40, $id, ['revision' => $revision(), 'confirmed' => true, 'reason' => 'Synthetic independent stage/read race', 'student_ids' => [1040]]);
    if ($command === 'read-races') {
        $writers = [
            'catalog' => fn () => $p->exec("UPDATE courses SET description=CONCAT(COALESCE(description,''),'x') WHERE course_id=1"),
            'registration' => fn () => $p->exec('UPDATE student_course_registrations SET registration_status_id=registration_status_id WHERE student_course_registration_id=9001'),
            'grade' => fn () => $p->exec('UPDATE student_course_results SET final_mark=IF(final_mark=80,81,80) WHERE student_course_registration_id=9001'),
            'assignment' => fn () => $assign(\App\Services\AcademicPlanContext::forStudent(\App\Models\Student::findOrFail(1040))->versionId === $source ? $alternate : $source),
        ];
        foreach (['preview', 'verify'] as $kind) foreach ($writers as $name => $writer) foreach ([false, true] as $commitDuringRead) {
            // Hold the ordinary catalog-first writer lock while a distinct process reads its old committed snapshot.
            $db::beginTransaction(); app(\App\Services\AcademicCatalogTransaction::class)->revision(true); $writer();
            [$process, $pipes] = $spawn(['kind' => $kind, 'pause' => 'read_snapshot']);
            try {
                $check(($read($pipes[1])['paused'] ?? null) === 'read_snapshot', 'Read snapshot barrier missing');
                if ($commitDuringRead) $db::commit();
                fwrite($pipes[0], "continue\n"); fflush($pipes[0]); $result = $read($pipes[1]);
                if (!$commitDuringRead) { $check($result['ok'] && $result['result']['read_queries'] > 0, 'Read waited for writer or emitted a lock: '.json_encode($result)); $db::commit(); }
                else $check(!$result['ok'] && $result['code'] === 'academic_catalog_stale', 'Concurrent commit escaped the outer revision fence: '.json_encode($result));
                echo 'PASS independent '.$kind.'/'.$name.($commitDuringRead ? ' stale fence' : ' nonlocking consistent snapshot')."\n";
            } finally { if ($db::transactionLevel()) $db::rollBack(); $finish($process, $pipes); }
        }
        exit;
    }
    $service = app(\App\Services\CatalogCompletion\CatalogCompletionService::class);
    $counts = fn () => [$db::table('academic_plan_versions')->count(), $db::table('courses')->count(), $db::table('student_academic_plan_assignments')->count(), $db::table('academic_plan_events')->count()];
    foreach (['interrupted_default_change', 'before_assignments', 'after_assignment', 'unchanged_resume'] as $scenario) {
        $setDefault($source); $assign($alternate); // Explicit synthetic setup; the completion service must NEVER restore a default.
        $operation = (string) \Illuminate\Support\Str::uuid(); $reviewed = $revision();
        $job = ['kind' => 'apply', 'revision' => $reviewed, 'operation_id' => $operation,
            'pause' => $scenario === 'after_assignment' ? 'after_assignment' : 'before_assignments',
            'interrupt' => in_array($scenario, ['interrupted_default_change', 'unchanged_resume'], true)];
        [$process, $pipes] = $spawn($job);
        try {
            $message = $read($pipes[1]);
            if ($job['interrupt']) {
                $check(!$message['ok'] && $message['message'] === 'synthetic_interruption', 'Expected durable-stage interruption');
                if ($scenario !== 'unchanged_resume') $setDefault($alternate);
                $before = $counts();
                $result = $service->apply($actor, $manifest, $revision(), $operation, true);
                $check($before[0] === $counts()[0] && $before[1] === $counts()[1], 'Resume duplicated versions/origins');
                if ($scenario === 'unchanged_resume') {
                    $check(\App\Services\AcademicPlanContext::forStudent(\App\Models\Student::findOrFail(1040))->versionId === $source, 'Unchanged resume did not assign');
                    $again = $counts(); $result = $service->apply($actor, $manifest, $revision(), $operation, true);
                    $check($again === $counts(), 'Repeated unchanged resume duplicated academic events/assignments');
                } else {
                    $group = collect($result['groups'])->first(fn ($g) => in_array(40, $g['program_ids'], true));
                    $check($group['status'] === 'BLOCKED' && in_array('catalog_completion_default_changed', $group['blockers'], true), 'Saved stage accepted changed default');
                    $check($before === $counts(), 'Blocked saved stage performed dependent academic writes');
                }
            } else {
                $check(($message['paused'] ?? null) === $job['pause'], 'Publication/finalization barrier missing');
                $setDefault($alternate); // A separate committed connection/process between the child's stages.
                fwrite($pipes[0], "continue\n"); fflush($pipes[0]); $message = $read($pipes[1]);
                $check($message['ok'], 'Stage execution failed unexpectedly: '.json_encode($message)); $result = $message['result'];
                $check(in_array('catalog_completion_default_changed', $result['students'][40]['context_blockers'] ?? [], true), 'Final confirmation accepted changed default');
                if ($scenario === 'before_assignments') $check(\App\Services\AcademicPlanContext::forStudent(\App\Models\Student::findOrFail(1040))->versionId === $alternate, 'Stale target was assigned');
                else $check(\App\Services\AcademicPlanContext::forStudent(\App\Models\Student::findOrFail(1040))->versionId === $source, 'Last assignment barrier was not exercised');
            }
            if ($scenario !== 'unchanged_resume') {
                $check($result['status'] !== 'COMPLETE', 'Changed context reported complete');
                $check(\App\Services\AcademicPlanContext::forProgram(40)->versionId === $alternate, 'Another writer default was restored');
            }
            $before = $counts(); $logs = $db::table('user_activity_logs')->count();
            $check($result === $service->apply($actor, $manifest, '0', $operation), 'Pure stale-revision replay did not return original receipt');
            $check($before === $counts() && $logs === $db::table('user_activity_logs')->count(), 'Pure replay wrote data');
            echo 'PASS independent publication/assignment/final confirmation '.$scenario."\n";
        } finally { $finish($process, $pipes); }
    }
    $setDefault($source); $assign($source);
    exit;
}
if ($command === 'concurrency') {
    $monitor = $environment->connect(true);
    $read = static function ($pipe): array {
        $until = microtime(true) + 40; $line = '';
        do { $part = fgets($pipe); if ($part !== false) { $line .= $part; if (str_ends_with($line, "\n")) return json_decode($line, true, flags: JSON_THROW_ON_ERROR); } usleep(10000); } while (microtime(true) < $until);
        throw new RuntimeException('Independent connection barrier timeout');
    };
    $race = function (callable $first, array $job) use ($db, $p, $environment, $monitor, $check, $read) {
        $db::beginTransaction(); app(\App\Services\AcademicCatalogTransaction::class)->revision(true);
        $first();
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['file', $environment->config['directory'].'/completion-worker.stderr', 'a']], $pipes);
        stream_set_blocking($pipes[1], false);
        $connection = $read($pipes[1])['ready']; fwrite($pipes[0], json_encode($job)."\n"); fflush($pipes[0]);
        $read($pipes[1]); $until = microtime(true) + 15; $waiting = false;
        try {
            do {
                $query = $monitor->prepare('SELECT COUNT(*) FROM information_schema.innodb_lock_waits w JOIN information_schema.innodb_trx t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id=?');
                $query->execute([$connection]); $waiting = (int) $query->fetchColumn() > 0;
                if (!$waiting) {
                    $query = $monitor->prepare('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?'); $query->execute([$connection]); $row = $query->fetch(PDO::FETCH_ASSOC);
                    $waiting = $row && in_array($row['STATE'], ['Statistics', 'Updating', 'Sending data'], true) && str_contains(strtolower($row['INFO'] ?? ''), 'academic_catalog_control');
                }
                if (!$waiting) usleep(20000);
            } while (!$waiting && microtime(true) < $until);
            $check($waiting, 'No actual independent catalog lock wait observed');
            $db::commit(); return $read($pipes[1]);
        } catch (Throwable $e) { if ($db::transactionLevel()) $db::rollBack(); proc_terminate($process); throw $e; }
        finally { fclose($pipes[0]); fclose($pipes[1]); proc_close($process); }
    };
    $service = app(\App\Services\CatalogCompletion\CatalogCompletionService::class);
    $job = fn () => ['kind' => 'apply', 'revision' => $revision(), 'operation_id' => (string) \Illuminate\Support\Str::uuid()];
    foreach (['catalog' => fn () => $p->exec("UPDATE courses SET description='synthetic concurrent text' WHERE course_id=1"),
        'registration/admission' => fn () => $p->exec('INSERT INTO students(student_id,academic_program_id) VALUES(3040,40)'),
        'grade' => fn () => $p->exec('UPDATE student_course_results SET final_mark=81 WHERE student_course_registration_id=9001')] as $name => $writer) {
        $result = $race($writer, $job());
        $check(!$result['ok'] && $result['code'] === 'academic_catalog_stale', $name.' race failed: '.json_encode($result));
        echo 'PASS observed independent '.$name." writer vs reviewed completion revision\n";
    }
    $workflow = app(\App\Services\AcademicPlanWorkflow::class);
    $source = \App\Services\AcademicPlanContext::forProgram(40)->versionId;
    $draft = $workflow->copy($actor, 40, $source, ['revision' => $revision(), 'label' => 'Synthetic race target']);
    $alternate = (int) $draft['version']->getKey();
    $workflow->approve($actor, 40, $alternate, ['revision' => $revision(), 'confirmed' => true]);
    $result = $race(fn () => $workflow->transfer($actor, 40, $alternate, ['revision' => $revision(), 'confirmed' => true, 'reason' => 'Synthetic assignment race', 'student_ids' => [1040]]), $job());
    $check(!$result['ok'] && $result['code'] === 'academic_catalog_stale', 'Assignment race did not reject stale completion');
    echo "PASS observed independent audited assignment vs completion revision\n";
    $request = $job(); $beforeVersions = $db::table('academic_plan_versions')->count();
    $result = $race(fn () => $service->apply($actor, $manifest, $request['revision'], $request['operation_id']), $request);
    $check($result['ok'], 'Concurrent exact operation replay failed');
    $check($beforeVersions === $db::table('academic_plan_versions')->count(), 'Concurrent replay duplicated plans');
    $result = $race(fn () => $service->apply($actor, $manifest, $revision(), (string) \Illuminate\Support\Str::uuid()), ['kind' => 'student', 'student_id' => 4040]);
    $check($result['ok'] && $result['result']['version_id'] === $source, 'Admission after publication did not inherit the explicit default');
    echo "PASS same-operation concurrent replay/no duplicate plan and admission serialization under installed plan controls\n"; exit;
}
throw new RuntimeException('Unknown isolated test command');
