<?php

// Real-engine concurrency check for the owner payroll sheet (independent PHP processes, independent connections).
// Run from backend/ against a DISPOSABLE MariaDB database that already holds the payroll tables (e.g. after
// owner_payroll_browser_fixture.php):
//   OWNER_PAYROLL_FIXTURE_CONFIRM=disposable DB_CONNECTION=mysql DB_DATABASE=payroll_browser ... php tests/Support/owner_payroll_concurrency.php
// Asserts: one winner for same-revision updates, one row for same employee number, no deadlocks for opposite-order batches.
use App\Exceptions\PayrollException;
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

$service = new PayrollSheetService;

if (($argv[1] ?? '') === 'worker') {
    [, , $scenario, $index, $barrier, $payload] = $argv;
    $payload = json_decode(base64_decode($payload), true);
    while (microtime(true) < (float) $barrier) {
        usleep(200);
    }
    try {
        $result = match ($scenario) {
            'same-revision' => [$service->updateAmounts([['employee_id' => $payload['employee'], 'expected_revision' => 1, 'deduction' => "{$index}.00"]], 1), 'ok'][1],
            'same-number' => [$service->createEmployee(['employee_number' => $payload['numbers'][$index % count($payload['numbers'])], 'full_name' => "Worker {$index}", 'job_title' => 'x', 'body_id' => $payload['body'], 'workplace' => 'afrin'], 1), 'ok'][1],
            'opposite-order' => [$service->updateAmounts(
                $index % 2 === 0
                    ? [['employee_id' => $payload['a'], 'expected_revision' => 1, 'fixed_salary' => '1'], ['employee_id' => $payload['b'], 'expected_revision' => 1, 'fixed_salary' => '2']]
                    : [['employee_id' => $payload['b'], 'expected_revision' => 1, 'fixed_salary' => '3'], ['employee_id' => $payload['a'], 'expected_revision' => 1, 'fixed_salary' => '4']],
                1,
            ), 'ok'][1],
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
    $barrier = microtime(true) + 2.5;
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
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
assertThat('the winner is stored once (revision 2) and nothing else changed', $row['entry_revision'] === 2 && $row['deduction'] !== null && $row['fixed_salary'] === null, $row);

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
assertThat('the winning batch is applied atomically to both rows', $rowC['entry_revision'] === 2 && $rowD['entry_revision'] === 2 && $rowC['fixed_salary'] !== null && $rowD['fixed_salary'] !== null, [$rowC['fixed_salary'], $rowD['fixed_salary']]);

echo $failed ? "\nCONCURRENCY CHECKS FAILED\n" : "\nAll concurrency checks passed.\n";
exit($failed ? 1 : 0);
