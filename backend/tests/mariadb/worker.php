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
        foreach ($job['sql'] as $sql) Illuminate\Support\Facades\DB::statement($sql);
        if ($job['acyclic']??false) app(App\Services\AcademicCatalogTransaction::class)->assertAcyclic();
    };
    app(App\Services\AcademicCatalogTransaction::class)->run($work, $job['revision']??null);
    echo "{\"ok\":true}\n";
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'class'=>get_class($e), 'message'=>$e->getMessage(), 'code'=>$e instanceof App\Exceptions\AcademicCatalogException ? $e->errorCode : null], JSON_UNESCAPED_UNICODE)."\n";
}
