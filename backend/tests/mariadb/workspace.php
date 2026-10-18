<?php

/** Existing MariaDB binary, NEW guarded disposable datadir, synthetic rows only. Never reads project .env. */
require __DIR__.'/CatalogEnvironment.php';
$env = new CatalogEnvironment;
$p = $env->connect();
$app = $env->laravel();
$command = $argv[1] ?? '';
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$service = app(\App\Services\ScientificPlanChangeService::class);
$actor = \App\Models\User::findOrFail(1);
$revision = fn () => app(\App\Services\AcademicCatalogTransaction::class)->revision();

if ($command === 'prepare') {
    // Test-only readiness on this guarded synthetic fixture, NOT production package acceptance.
    $p->exec('UPDATE academic_plan_control SET is_ready=1 WHERE control_id=1');
    foreach ([\App\Support\ScientificProgramAccess::VIEW, \App\Support\ScientificProgramAccess::PLANS,
        \App\Support\ScientificProgramAccess::APPROVE, \App\Support\ScientificProgramAccess::ASSIGN] as $code) {
        $id = \Illuminate\Support\Facades\DB::table('permissions')->where('permission_code', $code)->value('permission_id');
        if (!$id) $id = \Illuminate\Support\Facades\DB::table('permissions')->insertGetId(['module_id' => 1, 'permission_code' => $code, 'is_active' => 1], 'permission_id');
        \Illuminate\Support\Facades\DB::table('role_permissions')->updateOrInsert(['role_id' => 1, 'permission_id' => $id]);
    }
    // Seed this pre-existing state BEFORE introducing student history; keep all production triggers enabled.
    $p->exec("UPDATE academic_requirement_groups SET is_active=0 WHERE requirement_scope='department' AND requirement_type='elective' AND academic_program_id=1");
    $p->exec("INSERT INTO students(student_id,academic_program_id) VALUES(2,1)");
    echo "Synthetic permissions/runtime prepared on guarded disposable server only\n"; exit;
}

if ($command === 'worker') {
    echo 'READY '.getmypid()."\n"; flush();
    try {
        $mode = $argv[2];
        if ($mode === 'save') {
            $payload = json_decode(file_get_contents($env->config['directory'].'/'.$argv[3]), true, flags: JSON_THROW_ON_ERROR);
            $result = $service->save($actor, $payload);
        } elseif ($mode === 'student') {
            $student = \App\Services\AcademicPlanContext::transaction(fn () => \App\Models\Student::create(['academic_program_id' => 1]));
            $result = ['student_id' => $student->getKey(), 'version_id' => \App\Services\AcademicPlanContext::forStudent($student)->versionId];
        } else throw new RuntimeException('Worker mode allowlist');
        echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $failure) {
        echo json_encode(['ok' => false, 'code' => $failure->errorCode ?? get_class($failure), 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR)."\n";
    }
    exit;
}
if ($command !== 'test') throw new RuntimeException('Test command allowlist');

$snapshot = function (int $id) use ($actor, $service) {
    $program = \App\Models\AcademicProgram::findOrFail($id);
    $plan = $service->source($actor, $program, $program->default_academic_plan_version_id);
    $values = $service->values($plan);
    return ['academic_program_id' => $id, 'source_version_id' => $program->default_academic_plan_version_id,
        'courses' => $values['courses'], 'requirements' => ['total_credit_hours' => $values['total_credit_hours'],
            'groups' => array_map(function ($group) { unset($group['is_active']); return $group; }, $values['groups'])]];
};
$payload = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'revision' => $revision(), 'confirmed' => true,
    'new_courses' => [['key' => 'new', 'course_code' => 'SYN-WORKSPACE', 'course_name' => 'مادة اصطناعية', 'credit_hours' => 3, 'theoretical_hours' => 3, 'practical_hours' => 0, 'is_active' => true]],
    'targets' => [$snapshot(1)]];
$payload['targets'][0]['courses'][] = ['course_id' => null, 'new_course_key' => 'new', 'requirement_scope' => 'university', 'course_type' => 'elective',
    'academic_level_id' => 1, 'recommended_semester_id' => 1, 'is_active' => true];

// The same bug regression on real MariaDB: an unrelated course cannot reactivate group 6.
$payload['revision'] = $revision();
try { $service->save($actor, $payload); $check(false, 'Missing activity choice silently reactivated a group'); }
catch (\Illuminate\Validation\ValidationException $error) { $check(isset($error->errors()['plan']), 'Must fail canonical complete-plan approval'); }
$check((int) $p->query('SELECT COUNT(*) FROM academic_plan_versions')->fetchColumn() === 0, 'Failed approval leaked plan fixation');
$check((int) $p->query('SELECT COUNT(*) FROM student_academic_plan_assignments')->fetchColumn() === 0, 'Failed approval leaked assignments');
$check((int) $p->query("SELECT COUNT(*) FROM courses WHERE course_code='SYN-WORKSPACE'")->fetchColumn() === 0, 'Failed approval leaked material origin');
$check((int) $p->query("SELECT is_active FROM academic_requirement_groups WHERE academic_program_id=1 AND requirement_scope='department' AND requirement_type='elective'")->fetchColumn() === 0, 'Old group changed on failed approval');
foreach ($payload['targets'][0]['requirements']['groups'] as &$group) if ($group['requirement_scope'] === 'department' && $group['requirement_type'] === 'elective') $group['is_active'] = true;
unset($group);

$writePayload = function (string $name, array $value) use ($env): void {
    if (!preg_match('/^[a-z]+\.json$/', $name)) throw new RuntimeException('Payload artifact name');
    file_put_contents($env->config['directory'].'/'.$name, json_encode($value, JSON_THROW_ON_ERROR));
};
$jobs = [];
$start = function (array $arguments) use (&$jobs) {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Independent worker unavailable');
    fclose($pipes[0]);
    $ready = fgets($pipes[1]);
    if (!str_starts_with($ready ?: '', 'READY ')) throw new RuntimeException('Worker did not bootstrap: '.stream_get_contents($pipes[2]));
    $jobs[] = [$process, $pipes];
    return [$process, $pipes];
};
$finish = function (array $job) use ($check) {
    [$process, $pipes] = $job;
    $output = trim(stream_get_contents($pipes[1])); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    $check($exit === 0, 'Worker failed: '.$errors);
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
};
$waitBlocked = function (int $count) use ($env, $check, &$jobs) {
    $observer = $env->connect(true);
    for ($i = 0; $i < 32; $i++) {
        $n = count($observer->query("SELECT trx_mysql_thread_id FROM information_schema.innodb_trx WHERE trx_state='LOCK WAIT' AND trx_query LIKE '%academic_catalog_control%'")->fetchAll(PDO::FETCH_ASSOC));
        if ($n >= $count) return;
        // InnoDB's diagnostic snapshot is cached briefly; avoid continuously reusing it.
        usleep(250000);
    }
    $diagnostics = [];
    $diagnostics[] = json_encode($observer->query('SELECT trx_mysql_thread_id,trx_state,trx_query FROM information_schema.innodb_trx')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    $diagnostics[] = json_encode($observer->query("SELECT ID,STATE,INFO FROM information_schema.PROCESSLIST WHERE DB='alrowad_uni_rust'")->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    foreach ($jobs as [$process, $pipes]) if (is_resource($process) && !proc_get_status($process)['running']) {
        $diagnostics[] = trim(stream_get_contents($pipes[1])).' '.trim(stream_get_contents($pipes[2]));
    }
    $check(false, 'Independent workers did not reach real InnoDB lock waits. '.implode(' | ', $diagnostics));
};

// Hold the canonical FIRST lock so both independent saves contend before replay detection.
$writePayload('first.json', $payload);
$p->beginTransaction(); $p->query('SELECT revision FROM academic_catalog_control WHERE control_id=1 FOR UPDATE');
try { $a = $start(['save', 'first.json']); $b = $start(['save', 'first.json']); $waitBlocked(2); $firstAdmission = $start(['student']); $waitBlocked(3); }
finally { $p->commit(); }
$one = $finish($a); $two = $finish($b);
$admitted = $finish($firstAdmission);
$check($one['ok'] && $two['ok'] && $one['result'] === $two['result'], 'Concurrent replay must return ONE stored outcome');
$check($admitted['ok'] && $admitted['result']['version_id'] === (int) $p->query('SELECT default_academic_plan_version_id FROM academic_programs WHERE academic_program_id=1')->fetchColumn(), 'Admission racing initial fixation must receive the committed new default');
$check((int) $p->query("SELECT COUNT(*) FROM courses WHERE course_code='SYN-WORKSPACE'")->fetchColumn() === 1, 'Duplicate origin');
$check((int) $p->query('SELECT COUNT(*) FROM academic_plan_versions WHERE academic_program_id=1')->fetchColumn() === 2, 'Duplicate plan');
$check((int) $p->query("SELECT COUNT(*) FROM user_activity_logs WHERE action_code='academic_plan.workspace_result'")->fetchColumn() === 1, 'Duplicate outcome receipt');
$oldVersion = (int) $p->query('SELECT academic_plan_version_id FROM student_academic_plan_assignments WHERE student_id=2 AND current_slot=1')->fetchColumn();
$check($oldVersion > 0 && (int) $p->query("SELECT version_number FROM academic_plan_versions WHERE academic_plan_version_id=$oldVersion")->fetchColumn() === 1, 'Old student not pinned before publish');
$check((int) $p->query("SELECT is_active FROM academic_requirement_groups WHERE academic_plan_version_id=$oldVersion AND requirement_scope='department' AND requirement_type='elective'")->fetchColumn() === 0, 'Explicit activation rewrote old reference');
$published = (int) $p->query('SELECT default_academic_plan_version_id FROM academic_programs WHERE academic_program_id=1')->fetchColumn();
$check((int) $p->query("SELECT is_active FROM academic_requirement_groups WHERE academic_plan_version_id=$published AND requirement_scope='department' AND requirement_type='elective'")->fetchColumn() === 1, 'Explicit activation not saved to new plan');

// Different requests competing on the same preview: one commit, one controlled stale outcome.
$left = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'revision' => $revision(), 'confirmed' => true, 'new_courses' => [], 'targets' => [$snapshot(1)]];
$left['targets'][0]['courses'][1]['requirement_scope'] = 'college'; $right = $left;
$right['request_id'] = (string) \Illuminate\Support\Str::uuid(); $right['targets'][0]['courses'][1]['requirement_scope'] = 'department';
$writePayload('left.json', $left); $writePayload('right.json', $right);
$p->beginTransaction(); $p->query('SELECT revision FROM academic_catalog_control WHERE control_id=1 FOR UPDATE');
try { $a = $start(['save', 'left.json']); $waitBlocked(1); $b = $start(['save', 'right.json']); $waitBlocked(2); }
finally { $p->commit(); }
$leftResult = $finish($a); $rightResult = $finish($b);
$check((int) $leftResult['ok'] + (int) $rightResult['ok'] === 1, 'Different concurrent proposals must not both overwrite the preview');
$loser = $leftResult['ok'] ? $rightResult : $leftResult;
$check($loser['code'] === 'academic_catalog_stale', 'Losing proposal must be a controlled stale conflict');
$check((int) $p->query('SELECT COUNT(*) FROM academic_plan_versions WHERE academic_program_id=1')->fetchColumn() === 3, 'Concurrent stale save created an extra version');
$history = app(\App\Services\ScientificProgramWorkspaceService::class)->history($actor, 1, []);
$check(collect($history['data'])->contains(fn ($e) => $e['action'] === 'workspace_saved' && $e['details_available']), 'Actual MariaDB history projection must return immutable differences');

// Published curriculum transaction first, student writer second: no visible preparing/admission gap.
$next = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'revision' => $revision(), 'confirmed' => true, 'new_courses' => [], 'targets' => [$snapshot(1)]];
$next['targets'][0]['courses'][1]['requirement_scope'] = 'university';
$writePayload('next.json', $next);
$p->beginTransaction(); $p->query('SELECT revision FROM academic_catalog_control WHERE control_id=1 FOR UPDATE');
try { $save = $start(['save', 'next.json']); $waitBlocked(1); $student = $start(['student']); $waitBlocked(2); }
finally { $p->commit(); }
$saved = $finish($save); $created = $finish($student);
$check($saved['ok'] && $created['ok'], 'Save/admission workers failed');
$currentVersion = (int) $p->query('SELECT default_academic_plan_version_id FROM academic_programs WHERE academic_program_id=1')->fetchColumn();
$check($created['result']['version_id'] === $currentVersion, 'New student not atomically assigned to committed default');
$check($oldVersion === (int) $p->query('SELECT academic_plan_version_id FROM student_academic_plan_assignments WHERE student_id=2 AND current_slot=1')->fetchColumn(), 'Old student moved');

// Competing catalog edit commits first: stale request cannot silently adopt it.
$stale = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'revision' => $revision(), 'confirmed' => true, 'new_courses' => [], 'targets' => [$snapshot(1)]];
$stale['targets'][0]['courses'][1]['course_type'] = 'mandatory';
$writePayload('stale.json', $stale); $versions = (int) $p->query('SELECT COUNT(*) FROM academic_plan_versions')->fetchColumn();
$p->beginTransaction(); $p->exec("UPDATE courses SET description='Synthetic concurrent correction' WHERE course_id=1");
try { $job = $start(['save', 'stale.json']); $waitBlocked(1); }
finally { $p->commit(); }
$failed = $finish($job);
$check(!$failed['ok'] && $failed['code'] === 'academic_catalog_stale', 'Concurrent edit must invalidate revision');
$check((int) $p->query('SELECT COUNT(*) FROM academic_plan_versions')->fetchColumn() === $versions, 'Stale save wrote plan');
echo "PASS real MariaDB independent-connection saves/replay, one origin/plan/receipt, old-source pinning, atomic admission after publish, catalog edit vs stale save\n";
echo "PASS real MariaDB omitted group activity fails approval atomically; explicit activation affects only new plan\n";
