<?php

// Real-engine concurrency check for the owner payroll sheet (independent PHP processes, independent connections).
// Run from backend/ against a DISPOSABLE MariaDB database that already holds the payroll tables (e.g. after
// owner_payroll_browser_fixture.php):
//   OWNER_PAYROLL_FIXTURE_CONFIRM=disposable DB_CONNECTION=mysql DB_DATABASE=payroll_browser ... php tests/Support/owner_payroll_concurrency.php
// Asserts: one winner for same-revision updates, one row for same employee number, no deadlocks for opposite-order batches.
use App\Exceptions\PayrollException;
use App\Services\Payroll\PayrollConfigService;
use App\Services\Payroll\PayrollSheetService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

chdir(dirname(__DIR__, 2));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) DB::connection()->getDatabaseName();
if (getenv('OWNER_PAYROLL_FIXTURE_CONFIRM') !== 'disposable' || ! preg_match('/(browser|test|scratch|dev)/i', $database)) {
    fwrite(STDERR, "Refusing to run against '{$database}' (needs OWNER_PAYROLL_FIXTURE_CONFIRM=disposable and a throwaway database name).\n");
    exit(1);
}
if (DB::connection()->getDriverName() === 'sqlite') {
    fwrite(STDERR, "A server database (MariaDB) is required for real concurrency.\n");
    exit(1);
}

$service = $app->make(PayrollSheetService::class);
$configs = $app->make(PayrollConfigService::class);
$revision = fn () => (int) DB::table('payroll_config')->where('id', 1)->value('revision');

if (($argv[1] ?? '') === 'worker') {
    [, , $scenario, $index, $barrier, $payload] = $argv;
    $payload = json_decode(base64_decode($payload), true);
    while (microtime(true) < (float) $barrier) {
        usleep(200);
    }
    try {
        $result = match ($scenario) {
            'same-revision' => [$service->updateValues([['employee_id' => $payload['employee'], 'expected_revision' => 1, 'values' => ['other_deductions' => "{$index}.00"]]], $revision(), 1), 'ok'][1],
            'same-number' => [$service->createEmployee(['employee_number' => $payload['numbers'][$index % count($payload['numbers'])], 'full_name' => "Worker {$index}", 'job_title' => 'x', 'body_id' => $payload['body'], 'workplace' => 'afrin'], 1), 'ok'][1],
            'opposite-order' => [$service->updateValues(
                $index % 2 === 0
                    ? [['employee_id' => $payload['a'], 'expected_revision' => 1, 'values' => ['fixed_salary' => '1']], ['employee_id' => $payload['b'], 'expected_revision' => 1, 'values' => ['fixed_salary' => '2']]]
                    : [['employee_id' => $payload['b'], 'expected_revision' => 1, 'values' => ['fixed_salary' => '3']], ['employee_id' => $payload['a'], 'expected_revision' => 1, 'values' => ['fixed_salary' => '4']]],
                $revision(), 1,
            ), 'ok'][1],
            // 8 writers saving settings from the same configuration revision: exactly one may win.
            'same-config' => [$configs->updateSettings(['insurance_rate' => '0.0'.($index + 1)], $payload['revision'], 1), 'ok'][1],
            // A values save that raced with a settings change must be refused as a config conflict, never half-applied.
            'values-vs-config' => $index === 0
                ? [$configs->updateSettings(['income_tax_rate' => '0.20'], $payload['revision'], 1), 'ok'][1]
                : [$service->updateValues([['employee_id' => $payload['employee'], 'expected_revision' => 1, 'values' => ['fixed_salary' => "{$index}"]]], $payload['revision'], 1), 'ok'][1],
            // Simulates a body deactivation in flight: the body row is locked, held, then committed inactive.
            'deactivate-hold' => (function () use ($payload) {
                DB::transaction(function () use ($payload): void {
                    DB::table('payroll_bodies')->where('id', $payload['body'])->lockForUpdate()->first();
                    usleep(2_000_000);
                    DB::table('payroll_bodies')->where('id', $payload['body'])->update(['is_active' => 0, 'revision' => DB::raw('revision + 1')]);
                });

                return 'ok';
            })(),
            'create-into-body' => (function () use ($service, $payload, $index) {
                usleep(700_000); // starts while the deactivation transaction holds the body lock
                $service->createEmployee(['employee_number' => "RACE-{$payload['tag']}-{$index}", 'full_name' => 'Race', 'job_title' => 'x', 'body_id' => $payload['body'], 'workplace' => 'afrin'], 1);

                return 'ok';
            })(),
            'reassign-into-body' => (function () use ($service, $payload) {
                usleep(700_000);
                $service->updateEmployee($payload['employee'], ['employee_number' => $payload['number'], 'full_name' => 'Moved', 'job_title' => 'x', 'body_id' => $payload['body'], 'workplace' => 'afrin'], 1, 1);

                return 'ok';
            })(),
        };
        echo json_encode(['result' => $result]);
    } catch (PayrollException $e) {
        echo json_encode(['result' => $e->errorCode]);
    } catch (Throwable $e) {
        echo json_encode(['result' => 'ERROR: '.get_class($e).' '.substr($e->getMessage(), 0, 160)]);
    }
    exit(0);
}

function race(string $scenario, array $payload, int $workers = 8): array
{
    return raceJobs(array_map(fn ($i) => [$scenario, $i, $payload], range(0, $workers - 1)));
}

/** @param list<array{0:string,1:int,2:array}> $jobs scenario, worker index, payload — all released at the same instant */
function raceJobs(array $jobs): array
{
    $barrier = microtime(true) + 2.5;
    $processes = [];
    foreach ($jobs as [$scenario, $i, $payload]) {
        $command = [PHP_BINARY, __FILE__, 'worker', $scenario, (string) $i, (string) $barrier, base64_encode(json_encode($payload))];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd(), null);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
        $results[] = json_decode($out, true)['result'] ?? 'NO OUTPUT: '.$out;
    }

    return $results;
}

function assertThat(string $label, bool $condition, mixed $detail = null): void
{
    echo ($condition ? 'PASS  ' : 'FAIL  ').$label.($condition ? '' : '  -> '.json_encode($detail))."\n";
    if (! $condition) {
        $GLOBALS['failed'] = true;
    }
}

$failed = false;
$body = $service->createBody('Concurrency '.bin2hex(random_bytes(3)), 1);
$suffix = bin2hex(random_bytes(3));
$a = $service->createEmployee(['employee_number' => "C-A-{$suffix}", 'full_name' => 'A', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'], 1);
$b = $service->createEmployee(['employee_number' => "C-B-{$suffix}", 'full_name' => 'B', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'], 1);

$results = race('same-revision', ['employee' => $a['id']]);
$counts = array_count_values($results);
assertThat('same revision, 8 writers: exactly one wins', ($counts['ok'] ?? 0) === 1 && ($counts['payroll_conflict'] ?? 0) === 7, $counts);
$row = $service->row($a['id']);
assertThat('the winner is stored once (revision 2) and nothing else changed', $row['entry_revision'] === 2 && $row['cells']['other_deductions']['v'] !== null && $row['cells']['fixed_salary']['v'] === null, $row['cells']['other_deductions']);

$numbers = ["DUP-{$suffix}", " dup-{$suffix} ", "Dup-{$suffix}"];
$results = race('same-number', ['numbers' => $numbers, 'body' => $body['id']]);
$counts = array_count_values($results);
assertThat('same employee number from 8 writers (case/space variants): exactly one row is created', ($counts['ok'] ?? 0) === 1 && ($counts['payroll_validation'] ?? 0) === 7, $counts);
assertThat('the database holds exactly one such employee', DB::table('payroll_employees')->whereRaw('LOWER(employee_number) = ?', ["dup-{$suffix}"])->count() === 1);

// Fresh employees (revision 1) for a clean race.
$c = $service->createEmployee(['employee_number' => "C-C-{$suffix}", 'full_name' => 'C', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'], 1);
$d = $service->createEmployee(['employee_number' => "C-D-{$suffix}", 'full_name' => 'D', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'], 1);
$results = race('opposite-order', ['a' => $c['id'], 'b' => $d['id']], 8);
$counts = array_count_values($results);
assertThat('opposite-order batches never deadlock or error', collect($results)->every(fn ($r) => in_array($r, ['ok', 'payroll_conflict'], true)), $counts);
assertThat('exactly one batch wins', ($counts['ok'] ?? 0) === 1, $counts);
$rowC = $service->row($c['id']);
$rowD = $service->row($d['id']);
assertThat('the winning batch is applied atomically to both rows', $rowC['entry_revision'] === 2 && $rowD['entry_revision'] === 2 && $rowC['cells']['fixed_salary']['v'] !== null && $rowD['cells']['fixed_salary']['v'] !== null, [$rowC['cells']['fixed_salary'], $rowD['cells']['fixed_salary']]);

// Configuration: 8 writers from the same revision -> one winner; the rest get an explicit conflict and nothing is half-written.
$rev = $revision();
$results = race('same-config', ['revision' => $rev]);
$counts = array_count_values($results);
assertThat('same configuration revision, 8 settings writers: exactly one wins', ($counts['ok'] ?? 0) === 1 && ($counts['payroll_config_conflict'] ?? 0) === 7, $counts);
assertThat('the configuration revision advanced exactly once', $revision() === $rev + 1, [$rev, $revision()]);

// A values save racing with a settings change: only the config writer (or the first values writer) can win per revision.
$e = $service->createEmployee(['employee_number' => "C-E-{$suffix}", 'full_name' => 'E', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'], 1);
$rev = $revision();
$results = race('values-vs-config', ['revision' => $rev, 'employee' => $e['id']]);
assertThat('values-vs-config: no errors besides explicit conflicts', collect($results)->every(fn ($r) => in_array($r, ['ok', 'payroll_config_conflict', 'payroll_conflict'], true)), array_count_values($results));
$entry = $service->row($e['id']);
assertThat('values-vs-config: at most one value writer committed and the row is consistent', $entry['entry_revision'] <= 2, $entry['entry_revision']);

// Review finding: a body deactivation committing between "is the body active?" and the employee insert/reassign.
$raceBody = $service->createBody('Race '.bin2hex(random_bytes(3)), 1);
$mover = $service->createEmployee(['employee_number' => "C-M-{$suffix}", 'full_name' => 'M', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'], 1);
$tag = bin2hex(random_bytes(2));
$results = raceJobs([
    ['deactivate-hold', 0, ['body' => $raceBody['id']]],
    ['create-into-body', 1, ['body' => $raceBody['id'], 'tag' => $tag]],
    ['create-into-body', 2, ['body' => $raceBody['id'], 'tag' => $tag]],
    ['reassign-into-body', 3, ['body' => $raceBody['id'], 'employee' => $mover['id'], 'number' => "C-M-{$suffix}"]],
]);
assertThat('deactivation in flight: the writer that waited on the body lock is refused (never inserted into an inactive body)', $results[1] === 'payroll_validation' && $results[2] === 'payroll_validation' && $results[3] === 'payroll_validation', $results);
assertThat('no employee ended up in the deactivated body', DB::table('payroll_employees')->where('payroll_body_id', $raceBody['id'])->count() === 0);
assertThat('the deactivation itself committed', DB::table('payroll_bodies')->where('id', $raceBody['id'])->value('is_active') == 0);

echo $failed ? "\nCONCURRENCY CHECKS FAILED\n" : "\nAll concurrency checks passed.\n";
exit($failed ? 1 : 0);
