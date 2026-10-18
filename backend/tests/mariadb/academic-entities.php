<?php

/** Domain-service concurrency on the guarded disposable MariaDB, NOT browser acceptance. */
require __DIR__.'/CatalogEnvironment.php';
$env = new CatalogEnvironment;
$env->laravel();
$db = \Illuminate\Support\Facades\DB::connection();
$pdo = $db->getPdo();
$actor = \App\Models\User::findOrFail(1);
$catalog = app(\App\Services\AcademicCatalogTransaction::class);
$entities = app(\App\Services\ScientificAcademicEntityService::class);
$legacy = app(\App\Services\AcademicStructureEntityService::class);
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

if (($argv[1] ?? '') === 'worker') {
    echo 'READY '.(int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()."\n"; flush();
    try {
        $result = $entities->save($actor, 'departments', 1, ['revision' => $argv[3], 'department_name' => $argv[2]]);
        echo json_encode(['ok' => true, 'name' => $result['entity']->department_name], JSON_THROW_ON_ERROR)."\n";
    } catch (\App\Exceptions\AcademicCatalogException $error) {
        echo json_encode(['ok' => false, 'code' => $error->errorCode], JSON_THROW_ON_ERROR)."\n";
    }
    exit;
}
if (($argv[1] ?? '') !== 'test') throw new RuntimeException('Command allowlist');

$spawn = static function (string $name, string $revision): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $name, $revision], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Independent PHP worker unavailable');
    fclose($pipes[0]); stream_set_timeout($pipes[1], 30);
    $line = trim(fgets($pipes[1]));
    if (!preg_match('/^READY (\d+)$/', $line, $match)) throw new RuntimeException('Worker did not establish a verified independent connection: '.stream_get_contents($pipes[2]));
    return [$process, $pipes, (int) $match[1]];
};
$finish = static function (array $worker): array {
    [$process, $pipes] = $worker;
    $line = fgets($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || !$line) throw new RuntimeException('Worker failed: '.$errors);
    return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
};
$diagnostic = $env->connect(true); // Guarded own-server root, never an existing instance.
$waiting = static function (array $workers) use ($diagnostic, $check): void {
    $ids = array_column($workers, 2);
    for ($i = 0; $i < 60; $i++) {
        $count = (int) $diagnostic->query('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state=\'LOCK WAIT\' AND trx_mysql_thread_id IN ('.implode(',', $ids).')')->fetchColumn();
        if ($count === count($ids)) return;
        usleep(250000); // InnoDB diagnostic cache is not a 25ms real-time view.
    }
    $check(false, 'Workers were not observed waiting on the canonical control lock');
};

$before = $catalog->revision();
$college = $entities->save($actor, 'colleges', null, ['revision' => $before, 'college_name' => 'معهد اصطناعي', 'college_code' => 'ENTITY-I', 'is_active' => true]);
$department = $entities->save($actor, 'departments', null, ['revision' => $catalog->revision(), 'college_id' => $college['entity']->getKey(), 'department_name' => 'قسم اصطناعي', 'department_code' => 'ENTITY-D', 'is_active' => true]);
$check($entities->listing($actor, 'departments', ['college_id' => $college['entity']->getKey()])['meta']['total'] === 1, 'Local hierarchy does not match actual rows');
$entities->delete($actor, 'departments', (int) $department['entity']->getKey(), ['revision' => $catalog->revision(), 'confirmed' => true]);
$check(!$db->table('departments')->where('department_id', $department['entity']->getKey())->exists(), 'Empty entity delete failed');

// Two actual connections with the same preview: exactly one can commit.
$revision = $catalog->revision(); $audit = $db->table('user_activity_logs')->count();
$db->beginTransaction(); $catalog->revision(true);
$workers = [$spawn('CONCURRENT-A', $revision), $spawn('CONCURRENT-B', $revision)];
$check($workers[0][2] !== $workers[1][2], 'Workers must have distinct connections');
$waiting($workers); $db->commit();
$outcomes = array_map($finish, $workers);
$check(count(array_filter($outcomes, fn ($o) => $o['ok'])) === 1, 'Exactly one same-preview edit must commit');
$check(count(array_filter($outcomes, fn ($o) => ($o['code'] ?? null) === 'academic_catalog_stale')) === 1, 'Losing edit must return a controlled stale conflict');
$check($db->table('user_activity_logs')->count() === $audit + 1, 'Failed/stale edit must not produce audit mutation');
echo "PASS MariaDB two independent Scientific editors: canonical lock wait, one commit, one controlled conflict, one audit\n";

// Existing generic CRUD persistence participates in the same lock/epoch.
$revision = $catalog->revision();
$db->beginTransaction(); $catalog->revision(true);
$legacy->save($actor, 'departments', 1, ['department_name' => 'LEGACY-CORRECTION']);
$worker = $spawn('STALE-SCIENTIFIC', $revision); $waiting([$worker]); $db->commit();
$result = $finish($worker);
$check(!$result['ok'] && $result['code'] === 'academic_catalog_stale', 'Generic mutation failed to invalidate the Scientific preview');
$check($db->table('departments')->where('department_id', 1)->value('department_name') === 'LEGACY-CORRECTION', 'Stale edit overwrote the legacy correction');
echo "PASS MariaDB legacy CRUD versus Scientific preview: shared first lock and reliable epoch; stale write rolled back\n";

$revision = $catalog->revision();
$legacy->save($actor, 'departments', 1, ['department_name' => 'ABA-NEW']);
$legacy->save($actor, 'departments', 1, ['department_name' => 'LEGACY-CORRECTION']);
try { $entities->save($actor, 'departments', 1, ['revision' => $revision, 'department_name' => 'OLD-PREVIEW']); $check(false, 'ABA edit accepted an old preview'); }
catch (\App\Exceptions\AcademicCatalogException $error) { $check($error->errorCode === 'academic_catalog_stale', 'Wrong ABA conflict'); }
echo "PASS MariaDB current structure CRUD, change/reversion conflict, no new schema or SQL package\n";
