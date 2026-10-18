<?php
require __DIR__.'/CatalogEnvironment.php';
$env=new CatalogEnvironment;
$app=$env->laravel();
$p=Illuminate\Support\Facades\DB::connection()->getPdo();
echo json_encode(['ready'=>(int)$p->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n"; flush();
$job=json_decode(trim(fgets(STDIN)), true, flags:JSON_THROW_ON_ERROR);
$env->guard($p);
if (isset($job['timeout'])) $p->exec('SET SESSION innodb_lock_wait_timeout='.(int)$job['timeout']);
try {
    echo "{\"entered\":true}\n"; flush();
    $work=function () use ($job) {
        if ($job['handshake']??false) { echo "{\"locked\":true}\n"; flush(); fgets(STDIN); }
        if (isset($job['plan_action'])) {
            $workflow = app(App\Services\AcademicPlanWorkflow::class);
            $actor = App\Models\User::findOrFail(1);
            $input = $job['input'];
            match ($job['plan_action']) {
                'begin' => $workflow->begin($actor, $job['program_id'], $input),
                'fix' => $workflow->fixTransition($actor, $job['program_id'], $input),
                'approve' => $workflow->approve($actor, $job['program_id'], $job['version_id'], $input),
                'default' => $workflow->setDefault($actor, $job['program_id'], $job['version_id'], $input),
                'transfer' => $workflow->transfer($actor, $job['program_id'], $job['version_id'], $input),
                default => throw new RuntimeException('Unsupported test workflow'),
            };
        } else foreach ($job['sql'] as $sql) Illuminate\Support\Facades\DB::statement($sql);
        if ($job['acyclic']??false) app(App\Services\AcademicCatalogTransaction::class)->assertAcyclic();
    };
    if ($job['raw'] ?? false) Illuminate\Support\Facades\DB::transaction($work);
    else app(App\Services\AcademicCatalogTransaction::class)->run($work, $job['revision']??null);
    echo "{\"ok\":true}\n";
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'class'=>get_class($e), 'message'=>$e->getMessage(), 'code'=>($e instanceof App\Exceptions\AcademicCatalogException || $e instanceof App\Exceptions\AcademicPlanException) ? $e->errorCode : null], JSON_UNESCAPED_UNICODE)."\n";
}
