<?php

/** Synthetic-only integration harness. Never imports a dump; refuses any non-empty database. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/AdministrativeGovernanceSchema.php';

use App\Models\User;
use App\Services\HrOfficeService;
use App\Support\AdministrativeGovernanceException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AdministrativeGovernanceSchema;

$database = getenv('HR_TEST_DATABASE') ?: 'codex_hr_office_test_20261009';
if (! preg_match('/^codex_hr_office_test_[a-z0-9_]+$/', $database)) {
    throw new RuntimeException('Only explicitly named isolated HR test databases are permitted.');
}
$port = (int) (getenv('HR_TEST_PORT') ?: 3307);
$password = getenv('HR_TEST_PASSWORD') ?: '';
if (file_exists(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing cached application configuration; isolated test settings must own bootstrap.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $port, 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => $password, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$pdo = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (($argv[1] ?? '') === 'setup') {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('h', 32)), 'database.default' => 'mysql', 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => $port, 'database.connections.mysql.database' => $database, 'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => $password, 'database.connections.mysql.url' => null, 'database.connections.mysql.engine' => 'InnoDB', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
DB::purge('mysql');
if (DB::getDatabaseName() !== $database) {
    throw new RuntimeException('Database isolation failed.');
}
$proposal = ['body' => 'educational', 'college_id' => 1, 'relationship_type' => 'temporary_contract', 'work_mode' => 'part', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'job_title' => 'Synthetic relationship'];
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    if (count(DB::select('SHOW TABLES')) !== 0) {
        throw new RuntimeException('Test database is not empty; refusing to overwrite it.');
    }
    $fixture = new class
    {
        use AdministrativeGovernanceSchema;

        public function install(): void
        {
            $this->createAdministrativeGovernanceSchema();
            $this->seedAdministrativeGovernanceFixture();
        }
    };
    $fixture->install();
    // SQLite fixture increments() creates unsigned MySQL keys; production legacy parents are signed INT.
    foreach (['employees' => 'employee_id', 'users' => 'user_id', 'positions' => 'position_id', 'colleges' => 'college_id', 'organizational_units' => 'organizational_unit_id'] as $table => $key) {
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$key}` INT NOT NULL AUTO_INCREMENT");
    }
    (require database_path('migrations/2026_10_08_000000_create_owner_payroll_tables.php'))->up();
    // Synthetic existing financial identity/amounts precede HR installation, just as in production.
    $body = DB::table('payroll_bodies')->insertGetId(['name' => 'Synthetic existing financial body']);
    $payroll = DB::table('payroll_employees')->insertGetId(['employee_number' => 'FIN-LEGACY', 'full_name' => 'Synthetic existing payroll identity', 'job_title' => 'Existing title', 'payroll_body_id' => $body, 'workplace' => 'afrin']);
    DB::table('payroll_entries')->insert(['payroll_employee_id' => $payroll, 'fixed_salary_cents' => 12345, 'deduction_cents' => 200]);
    (require database_path('migrations/2026_10_09_000000_add_payroll_columns_settings_and_syp_template.php'))->up();
    $beforeEmployees = DB::table('employees')->orderBy('employee_id')->get()->map(fn ($row) => (array) $row)->all();
    $beforePayroll = (array) DB::table('payroll_employees')->where('id', $payroll)->first();
    $beforeAmounts = DB::table('payroll_entry_values')->orderBy('payroll_column_id')->get()->toArray();
    $migration = require database_path('migrations/2026_10_09_100000_create_administrative_hr_office.php');
    $migration->up();
    $migration->up();
    $afterEmployees = DB::table('employees')->orderBy('employee_id')->get()->map(function ($row): array {
        $data = (array) $row;
        unset($data['hr_revision'], $data['hr_body']);

        return $data;
    })->all();
    $afterPayroll = (array) DB::table('payroll_employees')->where('id', $payroll)->first();
    if ($afterEmployees !== $beforeEmployees || array_intersect_key($afterPayroll, $beforePayroll) !== $beforePayroll || $afterPayroll['employee_id'] !== null || DB::table('payroll_entry_values')->orderBy('payroll_column_id')->get()->toArray() != $beforeAmounts) {
        throw new RuntimeException('HR migration changed existing personnel or financial data.');
    }
    Schema::create('personal_access_tokens', function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('tokenable_type', 150);
        $t->integer('tokenable_id');
        $t->index(['tokenable_type', 'tokenable_id']);
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });
    $token = User::findOrFail(1)->createToken('synthetic-local-hr')->plainTextToken;
    $output = dirname(__DIR__, 3).'/.superdesign/tmp/hr-office-test-token.json';
    // Generated test credential only; ignored artifact, never included in output/PR.
    file_put_contents($output, json_encode(['token' => $token, 'database' => $database], JSON_THROW_ON_ERROR));
    echo "SETUP PASS: existing personnel/financial data preserved, signed keys, migration reapplication, isolated token.\n";
} elseif ($mode === 'worker') {
    $intent = $argv[2] ?? '';
    $hold = in_array($intent, ['hold', 'submit-hold'], true);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    if (! $hold) {
        $announced = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$announced): void {
            if (! $announced && str_contains(strtolower($sql), 'for update')) {
                $announced = true;
                echo 'ATTEMPTING '.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
                flush();
            }
        });
    }
    $requestId = isset($argv[3]) ? (int) $argv[3] : null;
    try {
        DB::transaction(function () use ($proposal, $hold, $requestId, $intent): void {
            if ($hold) {
                if ($requestId) {
                    DB::table('hr_staffing_needs')->orderByDesc('id')->lockForUpdate()->first();
                } else {
                    DB::table('employees')->where('employee_id', 20)->lockForUpdate()->first();
                }
                echo "LOCKED\n";
                flush();
                fgets(STDIN);
            }
            if ($intent === 'submit-hold') {
                app(HrOfficeService::class)->submit(User::findOrFail(1), $requestId, ['revision' => 1]);
            } elseif ($intent === 'edit') {
                $r = DB::table('hr_relationship_requests')->where('id', $requestId)->first();
                $c = DB::table('hr_candidates')->where('id', $r->candidate_id)->first();
                app(HrOfficeService::class)->saveCandidate(User::findOrFail(1), ['revision' => 1, 'need_item_id' => $c->need_item_id, 'first_name' => $c->first_name, 'last_name' => 'Must not overwrite submitted candidate'], $c->id);
            } elseif ($requestId) {
                app(HrOfficeService::class)->decide(User::findOrFail(1), $requestId, ['revision' => 2, 'decision' => 'approve']);
            } else {
                app(HrOfficeService::class)->classify(User::findOrFail(1), 20, ['revision' => 1, 'reason' => 'Synthetic two-connection race', 'proposal' => $proposal]);
            }
        });
        echo "SUCCESS\n";
    } catch (AdministrativeGovernanceException $e) {
        echo 'CONFLICT '.$e->errorCode."\n";
    }
} elseif ($mode === 'concurrency') {
    $observeWait = function ($stream) use ($database): bool {
        $signal = trim(fgets($stream));
        if (! preg_match('/^ATTEMPTING (\d+)$/', $signal, $match)) {
            return false;
        }
        $until = microtime(true) + 5;
        do {
            // The signal is immediately before the owned-row FOR UPDATE. Observe ONLY
            // this worker's active query in the isolated DB; no foreign SQL/text is returned.
            if (DB::table('information_schema.PROCESSLIST')->where('ID', (int) $match[1])->where('DB', $database)->whereIn('COMMAND', ['Query', 'Execute'])->where(fn ($q) => $q->where('INFO', 'like', '%for update%')->orWhere(fn ($q) => $q->whereNull('INFO')->where('COMMAND', 'Execute')))->first(['ID'])) {
                return true;
            }
            usleep(10000);
        } while (microtime(true) < $until);

        return false;
    };
    $command = [PHP_BINARY, __FILE__, 'worker', 'hold'];
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $one = proc_open($command, $spec, $p1);
    if (! is_resource($one) || trim(fgets($p1[1])) !== 'LOCKED') {
        throw new RuntimeException('First independent connection did not acquire lock.');
    }
    $two = proc_open([PHP_BINARY, __FILE__, 'worker'], $spec, $p2);
    if (! is_resource($two)) {
        throw new RuntimeException('Second connection failed.');
    }
    // This bounded wait deliberately overlaps actual connections; no production connection is possible.
    $blocked = $observeWait($p2[1]);
    fwrite($p1[0], "release\n");
    fclose($p1[0]);
    fclose($p2[0]);
    $a = trim(stream_get_contents($p1[1]));
    $b = trim(stream_get_contents($p2[1]));
    $errors = stream_get_contents($p1[2]).stream_get_contents($p2[2]);
    foreach ([$p1[1], $p1[2], $p2[1], $p2[2]] as $stream) {
        fclose($stream);
    }
    $exit1 = proc_close($one);
    $exit2 = proc_close($two);
    if (! $blocked || $exit1 || $exit2 || $a !== 'SUCCESS' || $b !== 'CONFLICT hr_stale' || DB::table('hr_employment_relationships')->where('employee_id', 20)->count() !== 1 || DB::table('hr_workforce_events')->where('subject_type', 'employee')->where('subject_id', 20)->count() !== 1) {
        throw new RuntimeException('Independent connection race failed: '.$a.' / '.$b.' '.$errors);
    }
    echo "CONCURRENCY PASS: one canonical classification, one audit, second writer stale after real employee lock.\n";
    $hr = app(HrOfficeService::class);
    $actor = User::findOrFail(1);
    $need = $hr->saveNeed($actor, ['title' => 'Synthetic approval race', 'body' => 'educational', 'college_id' => 1, 'status' => 'open', 'items' => [['job_title' => 'Synthetic teacher', 'quantity' => 1, 'is_active' => true]]]);
    $item = DB::table('hr_staffing_need_items')->where('need_id', $need['id'])->value('id');
    $candidate = $hr->saveCandidate($actor, ['need_item_id' => $item, 'first_name' => 'Synthetic', 'last_name' => 'Candidate']);
    $draft = $hr->saveRequest($actor, ['candidate_id' => $candidate['id'], 'kind' => 'accept', 'reason' => 'Synthetic concurrency', 'proposal' => $proposal + ['employee_number' => 'HR-RACE-NEW', 'employee_type_id' => 1]]);
    $r = $hr->submit($actor, $draft['id'], ['revision' => $draft['revision']]);
    $one = proc_open([PHP_BINARY, __FILE__, 'worker', 'hold', (string) $r['id']], $spec, $p1);
    if (trim(fgets($p1[1])) !== 'LOCKED') {
        throw new RuntimeException('Approval first connection failed.');
    }
    $two = proc_open([PHP_BINARY, __FILE__, 'worker', 'normal', (string) $r['id']], $spec, $p2);
    $blocked = $observeWait($p2[1]);
    fwrite($p1[0], "release\n");
    fclose($p1[0]);
    fclose($p2[0]);
    $a = trim(stream_get_contents($p1[1]));
    $b = trim(stream_get_contents($p2[1]));
    $errors = stream_get_contents($p1[2]).stream_get_contents($p2[2]);
    foreach ([$p1[1], $p1[2], $p2[1], $p2[2]] as $stream) {
        fclose($stream);
    }
    $exit1 = proc_close($one);
    $exit2 = proc_close($two);
    if (! $blocked || $exit1 || $exit2 || $a !== 'SUCCESS' || $b !== 'CONFLICT hr_stale' || DB::table('hr_employment_relationships')->where('request_id', $r['id'])->count() !== 1 || DB::table('employees')->where('employee_number', 'HR-RACE-NEW')->count() !== 1 || DB::table('hr_workforce_events')->where('subject_type', 'request')->where('subject_id', $r['id'])->where('action', 'materialized')->count() !== 1) {
        throw new RuntimeException('Approval race failed: '.$a.' / '.$b.' '.$errors);
    }
    echo "CONCURRENCY PASS: two real approvals, one employee/relation/materialization event, no duplicate.\n";
    $candidate = $hr->saveCandidate($actor, ['need_item_id' => $item, 'first_name' => 'Synthetic', 'last_name' => 'Snapshot']);
    $r = $hr->saveRequest($actor, ['candidate_id' => $candidate['id'], 'kind' => 'accept', 'reason' => 'Synthetic submission race', 'proposal' => $proposal + ['employee_number' => 'HR-RACE-SNAPSHOT', 'employee_type_id' => 1]]);
    $one = proc_open([PHP_BINARY, __FILE__, 'worker', 'submit-hold', (string) $r['id']], $spec, $p1);
    if (trim(fgets($p1[1])) !== 'LOCKED') {
        throw new RuntimeException('Submission first connection failed.');
    }
    $two = proc_open([PHP_BINARY, __FILE__, 'worker', 'edit', (string) $r['id']], $spec, $p2);
    $blocked = $observeWait($p2[1]);
    fwrite($p1[0], "release\n");
    fclose($p1[0]);
    fclose($p2[0]);
    $a = trim(stream_get_contents($p1[1]));
    $b = trim(stream_get_contents($p2[1]));
    $errors = stream_get_contents($p1[2]).stream_get_contents($p2[2]);
    foreach ([$p1[1], $p1[2], $p2[1], $p2[2]] as $stream) {
        fclose($stream);
    }
    $exit1 = proc_close($one);
    $exit2 = proc_close($two);
    if (! $blocked || $exit1 || $exit2 || $a !== 'SUCCESS' || $b !== 'CONFLICT hr_candidate_locked' || DB::table('hr_candidates')->where('id', $candidate['id'])->value('last_name') !== 'Snapshot' || DB::table('hr_relationship_requests')->where('id', $r['id'])->value('status') !== 'submitted') {
        throw new RuntimeException('Repeatable-read submission race failed: '.$a.' / '.$b.' '.$errors);
    }
    echo "CONCURRENCY PASS: REPEATABLE READ stale snapshot cannot edit a newly submitted candidate.\n";
} elseif ($mode === 'actor-state') {
    if (! DB::table('personal_access_tokens')->where('name', 'synthetic-local-hr')->where('tokenable_id', 1)->exists()) {
        throw new RuntimeException('Not the synthetic HR fixture.');
    }
    $state = match ($argv[2] ?? '') {
        'disable' => 'disabled', 'restore' => 'active', default => throw new RuntimeException('Invalid synthetic actor state.')
    };
    $id = DB::table('account_statuses')->where('status_code', $state)->value('account_status_id');
    if (! $id) {
        throw new RuntimeException('Synthetic status unavailable.');
    }
    DB::table('users')->where('user_id', 1)->update(['account_status_id' => $id]);
    echo "SYNTHETIC ACTOR STATE UPDATED\n";
} else {
    throw new RuntimeException('Use setup, worker or concurrency.');
}
