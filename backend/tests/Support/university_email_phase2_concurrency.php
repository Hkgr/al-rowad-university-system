<?php
// Opt-in real two-process MariaDB exercise. Never reads application DB credentials.
// Requires a NEW synthetic SQLite export and an isolated localhost MariaDB on 3397.
declare(strict_types=1);

$directory = realpath((string) getenv('UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR'));
if (! $directory || ! str_starts_with(strtolower($directory), strtolower(realpath(sys_get_temp_dir())).DIRECTORY_SEPARATOR)
    || ! is_file($directory.'/email.sqlite')) throw new RuntimeException('Private temporary synthetic fixture required');
$database = 'ue_review_147_'.substr(hash('sha256', $directory), 0, 10);
$assert = function (bool $ok, string $why): void { if (! $ok) throw new RuntimeException($why); };
$worker = ($argv[1] ?? '') === 'worker';

if (! $worker) {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3397;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Existing DB is deliberately refused: no destructive setup or production access.
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $source = new PDO('sqlite:'.$directory.'/email.sqlite');
    foreach ($source->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if ($table === 'student_university_emails' || str_starts_with($table, 'university_email_')) continue;
        $assert((bool) preg_match('/\A[a-z_]+\z/D', $table), 'Invalid fixture table');
        $columns = $source->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);
        $definitions = [];
        foreach ($columns as $column) {
            $name = $column['name']; $assert((bool) preg_match('/\A[a-z_]+\z/D', $name), 'Invalid fixture column');
            $type = strtoupper($column['type']);
            $type = str_contains($type, 'INT') ? 'INT' : (preg_match('/\A(?:DECIMAL|NUMERIC)\([0-9, ]+\)\z/', $type) ? $type : 'VARCHAR(1000)');
            if ($name === 'description') $type = 'TEXT';
            $definition = "`$name` $type";
            if ($column['pk']) $definition .= ' PRIMARY KEY'.($type === 'INT' ? ' AUTO_INCREMENT' : '');
            else $definition .= ' NULL';
            if ($column['dflt_value'] !== null) $definition .= ' DEFAULT '.$column['dflt_value'];
            $definitions[] = $definition;
        }
        $pdo->exec("CREATE TABLE `$table` (".implode(',', $definitions).') ENGINE=InnoDB');
        foreach ($source->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statement = $pdo->prepare("INSERT INTO `$table` (`".implode('`,`', array_keys($row)).'`) VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
            $statement->execute(array_values($row));
        }
    }
}

foreach (['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3397',
    'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$assert(! $app->configurationIsCached(), 'Cached configuration is not safe for isolated test');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Http};
use App\Services\UniversityEmailProvisioningService;
use App\Models\User;
$assert($app->environment('testing') && config('database.connections.mysql.host') === '127.0.0.1'
    && (int) config('database.connections.mysql.port') === 3397 && DB::connection()->getDatabaseName() === $database, 'Isolated connection guard');
config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'mailcow.provisioning_enabled' => true,
    'mailcow.contract_verified' => true, 'mailcow.write_api_key' => 'synthetic-write-key']);

function checkpoint(string $directory, string $name): void
{
    file_put_contents($directory.'/'.$name.'.ready', 'ready');
    $until = microtime(true) + 15;
    while (! is_file($directory.'/'.$name.'.release')) {
        if (microtime(true) > $until) throw new RuntimeException('Test checkpoint timed out');
        usleep(10000);
    }
}

Http::preventStrayRequests();
Http::fake(function ($request) use ($directory, $worker, $argv) {
    if (DB::transactionLevel() !== 0) throw new RuntimeException('Remote call under transaction');
    $stage = $worker ? ($argv[2] ?? '') : '';
    if ($request->method() === 'GET' && str_contains($request->url(), '/get/mailbox/')) {
        if ($stage === 'before-write' && ! is_file($directory.'/before-write.ready')) checkpoint($directory, 'before-write');
        if ($stage === 'after-remote' && is_file($directory.'/after-remote.sent') && ! is_file($directory.'/after-remote.ready')) checkpoint($directory, 'after-remote');
    }
    $file = fopen($directory.'/fake-mailcow.json', 'c+'); flock($file, LOCK_EX);
    $contents = stream_get_contents($file);
    $data = $contents ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : ['boxes' => [], 'posts' => []];
    try {
        if (str_contains($request->url(), '/get/alias/all')) return Http::response([]);
        if (str_contains($request->url(), '/get/mailbox/')) return Http::response(array_values($data['boxes']));
        $reset = str_contains($request->url(), '/edit/mailbox');
        $address = $reset ? $request['items'][0] : $request['local_part'].'@'.$request['domain'];
        $data['posts'][] = ['address' => $address, 'reset' => $reset]; // Never store request/password.
        $data['boxes'][$address] = ['username' => $address, 'domain' => 'alrowaduni.edu.sy', 'quota' => 50 * 1048576,
            'active_int' => 1, 'attributes' => ['force_pw_update' => 1], 'tags' => $reset ? $request['attr']['tags'] : $request['tags']];
        rewind($file); ftruncate($file, 0); fwrite($file, json_encode($data, JSON_THROW_ON_ERROR)); fflush($file);
        if ($stage === 'after-remote') file_put_contents($directory.'/after-remote.sent', 'sent');
        return Http::response([['type' => 'success', 'msg' => [$reset ? 'mailbox_modified' : 'mailbox_added', $address]]]);
    } finally { flock($file, LOCK_UN); fclose($file); }
});
$service = app(UniversityEmailProvisioningService::class);
$user = User::findOrFail(8);

if ($worker) {
    // Credentials are piped, never written to an artifact or process command line.
    $job = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $student = $job['student'] ?? 1;
    try {
        if ($job['action'] === 'execute') $service->execute($user, $student, $job['credentials']);
        elseif ($job['action'] === 'cancel') $service->cancel($user, $student, $job['id'], $job['generation']);
        elseif ($job['action'] === 'receipt') $service->receipt($user, $student, $job['id'], $job['generation']);
        else throw new RuntimeException('Unknown test action');
        echo json_encode(['result' => 'ok', 'connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
    } catch (\App\Exceptions\UniversityEmailException $failure) {
        echo json_encode(['result' => $failure->errorCode, 'connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
    }
    exit;
}

foreach (['000000_create_student_university_emails', '000001_add_university_email_provisioning', '000002_add_university_email_operation_cancellation'] as $migration) {
    (require dirname(__DIR__, 2).'/database/migrations/2026_10_01_'.$migration.'.php')->up();
}
$start = function (array $job, string $stage = '') use ($directory): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $stage], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (! is_resource($process)) throw new RuntimeException('Unable to launch test worker');
    fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    return [$process, $pipes];
};
$finish = function (array $running) use ($assert): array {
    [$process, $pipes] = $running;
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $assert(proc_close($process) === 0 && $error === '', 'Worker failed (no credentials logged)');
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
};
$await = function (string $name) use ($directory): void {
    $until = microtime(true) + 15;
    while (! is_file($directory.'/'.$name.'.ready')) { if (microtime(true) > $until) throw new RuntimeException('Worker not ready'); usleep(10000); }
};
$release = fn (string $name) => file_put_contents($directory.'/'.$name.'.release', 'release');
$posts = fn () => count(json_decode(file_get_contents($directory.'/fake-mailcow.json'), true, flags: JSON_THROW_ON_ERROR)['posts']);
app(\App\Services\UniversityEmailService::class)->save($user, 1, ['english_first_name' => 'Ahmad', 'revision' => 0]);
$c = $service->password($user, 1, 1, 'create');
$job = ['action' => 'execute', 'credentials' => $c];
$a = $start($job, 'before-write'); $await('before-write');
$b = $finish($start($job));
$assert($b['result'] === 'university_email_operation_stale', 'Second execute must lose authority');
$release('before-write'); $one = $finish($a);
$assert($one['result'] === 'ok' && $one['connection'] !== $b['connection'] && $posts() === 1, 'Two connections, exactly one remote POST');

// Reset holds the operation/draft authority; concurrent receipt cannot issue old credentials.
$reset = $service->password($user, 1, 1, 'reset');
$a = $start(['action' => 'execute', 'credentials' => $reset], 'after-remote'); $await('after-remote');
$b = $finish($start(['action' => 'receipt', 'id' => $c['operation_id'], 'generation' => 1]));
$assert($b['result'] === 'university_email_credentials_unavailable', 'Receipt loses to active reset');
$b = $finish($start(['action' => 'cancel', 'id' => $reset['operation_id'], 'generation' => 1]));
$assert($b['result'] === 'university_email_operation_not_cancellable', 'Cancellation loses to started write');
$release('after-remote'); $assert($finish($a)['result'] === 'ok', 'Reset confirmed');

// Separate student/draft for cancellation-wins and post-remote local-commit failure.
DB::table('students')->where('student_id', 2)->update(['student_number' => 'SYNTHETIC2']);
app(\App\Services\UniversityEmailService::class)->save($user, 2, ['english_first_name' => 'Other', 'revision' => 0]);
$c = $service->password($user, 2, 1, 'create');
// Different checkpoint names are unnecessary: remove only this test's own marker files.
foreach (['before-write.ready', 'before-write.release'] as $marker) unlink($directory.'/'.$marker);
$a = $start(['action' => 'execute', 'student' => 2, 'credentials' => $c], 'before-write'); $await('before-write');
$b = $finish($start(['action' => 'cancel', 'student' => 2, 'id' => $c['operation_id'], 'generation' => 1]));
$assert($b['result'] === 'ok', 'Pre-write cancellation must succeed');
$release('before-write'); $assert($finish($a)['result'] === 'university_email_operation_stale' && $posts() === 2, 'Cancelled worker never posts');
$assert(DB::table('university_email_operations')->where('operation_id', $c['operation_id'])->value('status') === 'cancelled', 'Old failure cannot overwrite cancellation');

$c = $service->password($user, 2, 1, 'create');
DB::unprepared("CREATE TRIGGER synthetic_confirmation_failure BEFORE INSERT ON user_activity_logs FOR EACH ROW BEGIN IF NEW.action_code = 'university_email.create_confirmed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic failure'; END IF; END");
$a = $finish($start(['action' => 'execute', 'student' => 2, 'credentials' => $c]));
$assert($a['result'] === 'university_email_local_confirmation_failed' && $posts() === 3, 'Remote success/local failure keeps one POST');
$b = $finish($start(['action' => 'execute', 'student' => 2, 'credentials' => $c]));
$assert($b['result'] === 'university_email_operation_stale' && $posts() === 3, 'Uncertain operation is not re-posted');
DB::unprepared('DROP TRIGGER synthetic_confirmation_failure');
DB::table('university_email_operations')->where('operation_id', $c['operation_id'])->update(['updated_at' => now()->subMinutes(2)]);
$service->reconcile($user, 2, $c['operation_id']);
$assert($posts() === 3, 'Reconciliation never posts');
$assert(DB::table('user_activity_logs')->where('action_code', 'university_email.operation_cancelled')->count() === 1, 'Exactly one cancellation audit');
$assert(DB::table('student_university_emails')->where('handover_status', '!=', 'not_delivered')->count() === 0, 'No delivery mutation');
echo json_encode(['passed' => true, 'server' => DB::selectOne('SELECT VERSION() AS version')->version,
    'scenarios' => 4, 'remote_posts' => $posts(), 'verification' => 'independent PHP processes and MariaDB connections, isolated synthetic data; no production'], JSON_THROW_ON_ERROR).PHP_EOL;
