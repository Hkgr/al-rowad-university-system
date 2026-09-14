<?php

// Explicitly guarded, disposable MariaDB instance only. Never uses project .env or the production dump.
require __DIR__.'/CatalogEnvironment.php';
use Illuminate\Support\Facades\DB;
use App\Services\{AcademicCatalogTransaction, AcademicPlanWorkflow};

$environment = new CatalogEnvironment;
$app = $environment->laravel();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage()."\n"); exit(1); });
$pdo = DB::connection()->getPdo();
$monitor = $environment->connect(true);
$actor = App\Models\User::findOrFail(1);
$workflow = app(AcademicPlanWorkflow::class);
function ensure(bool $yes, string $reason): void { if (!$yes) throw new RuntimeException($reason); }
function receive($stream): array {
    $deadline = microtime(true) + 20; $line = '';
    while (microtime(true) < $deadline) {
        $part = fgets($stream);
        if ($part !== false) { $line .= $part; if (str_ends_with($line, "\n")) return json_decode($line, true, flags: JSON_THROW_ON_ERROR); }
        usleep(10000);
    }
    throw new RuntimeException('Independent connection barrier timeout');
}
function contender(array $job): array {
    $process = proc_open([PHP_BINARY, __DIR__.'/worker.php'], [['pipe','r'], ['pipe','w'], ['file',sys_get_temp_dir().'/academic-plan-worker-errors.log','a']], $pipes);
    stream_set_blocking($pipes[1], false);
    $id = receive($pipes[1])['ready']; fwrite($pipes[0], json_encode($job)."\n"); fflush($pipes[0]);
    ensure(receive($pipes[1])['entered'], 'Worker did not enter');
    return [$process, $pipes, $id];
}
function joined(array $worker): array {
    [$process, $pipes] = $worker;
    try { return receive($pipes[1]); } finally { fclose($pipes[0]); fclose($pipes[1]); proc_close($process); }
}
function race(callable $first, array $second): array {
    global $monitor, $environment;
    $environment->guard($monitor, false);
    DB::beginTransaction(); app(AcademicCatalogTransaction::class)->revision(true); $first();
    $worker = contender($second);
    try {
        $deadline = microtime(true) + 12; $waiting = false;
        do {
            $q = $monitor->prepare('SELECT COUNT(*) FROM information_schema.innodb_lock_waits w JOIN information_schema.innodb_trx t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id=?');
            $q->execute([$worker[2]]); $waiting = (int) $q->fetchColumn() > 0;
            if (!$waiting) {
                // On this MariaDB build, a current-read may wait for optimizer statistics
                // before appearing in innodb_trx. Observe the exact locked worker statement.
                $q = $monitor->prepare('SELECT STATE, INFO FROM information_schema.PROCESSLIST WHERE ID=?');
                $q->execute([$worker[2]]); $row = $q->fetch(PDO::FETCH_ASSOC);
                $waiting = $row && in_array($row['STATE'], ['Statistics', 'Updating', 'Sending data'], true)
                    && str_contains(strtolower($row['INFO'] ?? ''), 'academic_catalog_control');
            }
            if (!$waiting) usleep(20000);
        } while (!$waiting && microtime(true) < $deadline);
        ensure($waiting, 'No observed independent InnoDB lock wait');
        DB::commit(); return joined($worker);
    } catch (Throwable $e) { if (DB::transactionLevel()) DB::rollBack(); proc_terminate($worker[0]); throw $e; }
}
function confirm(): array { return ['revision' => app(AcademicCatalogTransaction::class)->revision(), 'confirmed' => true]; }
function failed(array $result, string $code): void { ensure(!$result['ok'] && str_contains(($result['code'] ?? '').' '.$result['message'], $code), json_encode($result)); }

// Program 2 is the still-legacy synthetic program from CatalogFixture. This runner uses a fresh fixture once.
if (($argv[1] ?? '') !== '--transfer-only') {
ensure(DB::table('academic_programs')->where('academic_program_id', 2)->value('plan_state') === 'legacy', 'Use a fresh disposable catalog fixture for this test');
$preview = confirm();
$r = race(fn () => DB::table('students')->insert(['academic_program_id' => 2]),
    ['plan_action' => 'begin', 'program_id' => 2, 'input' => $preview]);
failed($r, 'academic_catalog_stale');
$workflow->begin($actor, 2, confirm());
$preview = $workflow->previewTransition($actor, 2);
// A raw SQL textual correction advances the same epoch, even when the value returns to its old value (ABA).
$name = DB::table('courses')->where('course_id', 2)->value('course_name');
$r = race(function () use ($name) {
    DB::table('courses')->where('course_id', 2)->update(['course_name' => $name.' test']);
    DB::table('courses')->where('course_id', 2)->update(['course_name' => $name]);
}, ['plan_action' => 'fix', 'program_id' => 2, 'input' => ['revision' => $preview['revision'], 'confirmed' => true]]);
failed($r, 'academic_catalog_stale');
ensure(!DB::table('academic_plan_versions')->where('academic_program_id', 2)->exists(), 'Stale fixation leaked a version');
$r = race(fn () => $workflow->fixTransition($actor, 2, confirm()), ['raw' => true, 'sql' => ['INSERT INTO students(academic_program_id) VALUES(2)']]);
failed($r, 'academic_plan_initialization_incomplete');
ensure(!DB::table('students as s')->where('s.academic_program_id', 2)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('student_academic_plan_assignments as a')->whereColumn('a.student_id', 's.student_id')->where('a.current_slot', 1))->exists(), 'Fixation left an unassigned student');
echo "PASS independent MariaDB connections: student creation wins => stale initialization; raw ABA => stale fixation; fixation wins => admission denied; every existing student assigned\n";

$source = (int) DB::table('academic_plan_versions')->where('academic_program_id', 1)->where('status', 'approved')->value('academic_plan_version_id');
$draft = $workflow->copy($actor, 1, $source, ['revision' => confirm()['revision'], 'label' => 'Concurrency draft'])['version'];
$id = (int) $draft->getKey();
$preview = confirm();
$r = race(fn () => DB::table('academic_requirement_groups')->where('academic_plan_version_id', $id)->where('requirement_scope', 'college')->where('requirement_type', 'elective')->update(['group_name' => 'Concurrent draft edit']),
    ['plan_action' => 'approve', 'program_id' => 1, 'version_id' => $id, 'input' => $preview]);
failed($r, 'academic_catalog_stale');
$r = race(fn () => $workflow->approve($actor, 1, $id, confirm()), ['raw' => true, 'sql' => ["UPDATE program_courses SET course_type='elective' WHERE academic_plan_version_id=$id"]]);
failed($r, 'academic_plan_locked');
$before = (int) DB::table('students')->max('student_id');
$r = race(fn () => $workflow->setDefault($actor, 1, $id, confirm()), ['raw' => true, 'sql' => ['INSERT INTO students(academic_program_id) VALUES(1)']]);
ensure($r['ok'], json_encode($r));
$student = DB::table('students')->where('student_id', '>', $before)->sole();
ensure((int) DB::table('student_academic_plan_assignments')->where('student_id', $student->student_id)->where('current_slot', 1)->value('academic_plan_version_id') === $id, 'Student assigned a default from before the locked decision');
echo "PASS independent MariaDB connections: edit invalidates approval; approved membership rejects raw edit; new student waits for explicit default and receives exactly that plan\n";
}

require __DIR__.'/AcademicPlanRuntimeFixture.php';
AcademicPlanRuntimeFixture::complete($environment, $pdo);
$source = (int) DB::table('academic_plan_versions')->where('academic_program_id', 1)->where('status', 'approved')->value('academic_plan_version_id');
$target = (int) $workflow->copy($actor, 1, $source, ['revision' => confirm()['revision'], 'label' => 'Transfer race target'])['version']->getKey();
$workflow->approve($actor, 1, $target, confirm());
$studentId = (int) DB::table('students')->insertGetId(['academic_program_id' => 1], 'student_id');
$offeringId = (int) DB::table('course_offerings')->insertGetId(['academic_program_id' => 1, 'course_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'open'], 'course_offering_id');
$preview = $workflow->previewTransfer($actor, 1, $target, ['student_ids' => [$studentId]]);
ensure($preview['can_transfer'], 'Synthetic settled student should be transferable');
$input = ['revision' => $preview['revision'], 'confirmed' => true, 'reason' => 'Independent connection regression', 'student_ids' => [$studentId]];
$r = race(fn () => DB::table('student_course_registrations')->insert(['student_id' => $studentId, 'course_offering_id' => $offeringId, 'registration_status_id' => 2]),
    ['plan_action' => 'transfer', 'program_id' => 1, 'version_id' => $target, 'input' => $input]);
failed($r, 'academic_catalog_stale');
ensure((int) DB::table('student_academic_plan_assignments')->where('student_id', $studentId)->where('current_slot', 1)->value('academic_plan_version_id') !== $target, 'Transfer overwrote an intervening registration context');
ensure(!$workflow->previewTransfer($actor, 1, $target, ['student_ids' => [$studentId]])['can_transfer'], 'Fresh preview missed current registration');
$secondStudent = (int) DB::table('students')->insertGetId(['academic_program_id' => 1], 'student_id');
$secondPreview = $workflow->previewTransfer($actor, 1, $target, ['student_ids' => [$secondStudent]]);
$r = race(fn () => $workflow->transfer($actor, 1, $target, ['revision' => $secondPreview['revision'], 'confirmed' => true, 'reason' => 'Transfer first', 'student_ids' => [$secondStudent]]),
    ['raw' => true, 'sql' => ["INSERT INTO student_course_registrations(student_id,course_offering_id,registration_status_id) VALUES($secondStudent,$offeringId,2)"]]);
ensure($r['ok'], json_encode($r));
$registration = DB::table('student_course_registrations')->where('student_id', $secondStudent)->sole();
ensure((int) $registration->academic_plan_version_id === $target, 'Raw registration used an assignment from before the transfer lock');
ensure((int) DB::table('program_courses')->where('program_course_id', $registration->plan_program_course_id)->value('academic_plan_version_id') === $target, 'Registration selected arbitrary course membership');
echo "PASS independent MariaDB connections: new registration invalidates transfer; transfer first pins subsequent raw registration to exact target membership\n";
