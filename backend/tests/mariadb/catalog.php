<?php

require __DIR__.'/CatalogEnvironment.php';
require __DIR__.'/CatalogFixture.php';
$env = new CatalogEnvironment;
$command=$argv[1]??'';
if ($command==='init') { $env->bootstrap(); CatalogFixture::create($env, $env->connect()); echo "Isolated synthetic fixture initialized\n"; exit; }
$p=$env->connect();
if ($command==='shutdown') { $env->connect(true)->exec('SHUTDOWN'); echo "Stopped this verified disposable instance only\n"; exit; }
if ($command==='package-test') {
    $tables=['courses','academic_programs','program_courses','academic_requirement_groups','program_course_requirement_groups','course_departments','course_prerequisites','students','course_offerings','supplementary_exam_offerings'];
    $snapshot=static function()use($p,$tables){$r=[]; foreach($tables as $t) $r[$t]=$p->query("SELECT * FROM $t ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC); return hash('sha256',json_encode($r));};
    $before=$snapshot();
    $assert=static function($rows,$result){$last=end($rows); if (($last['result']??'')!==$result) throw new RuntimeException('Unexpected terminal '.json_encode($last));};
    $assert($env->package($p,'00_preflight.sql'),'READY');
    $env->package($p,'01_apply.sql','CREATE OR REPLACE TRIGGER sc_cat_courses_u');
    try { $p->exec("UPDATE courses SET course_name='must roll back' WHERE course_id=1"); throw new RuntimeException('Partial install permitted protected write'); }
    catch(PDOException $e) { if (!str_contains($e->getMessage(),'academic_catalog_schema_not_ready')) throw $e; }
    $app=$env->laravel();
    try { app(App\Services\AcademicCatalogTransaction::class)->run(fn()=>null); throw new RuntimeException('App permitted unready write'); }
    catch(App\Exceptions\AcademicCatalogException $e) { if ($e->errorCode!=='academic_catalog_schema_not_ready') throw $e; }
    $assert($env->package($p,'01_apply.sql'),'APPLIED');
    $assert($env->package($p,'02_verify.sql'),'PASS');
    $assert($env->package($p,'01_apply.sql'),'APPLIED');
    $assert($env->package($p,'02_verify.sql'),'PASS');
    if ($snapshot()!==$before) throw new RuntimeException('Package rewrote academic fixture');
    echo "PASS preflight / interrupted install / DB + application fail-closed / resume / verify / reapply / verify / unchanged academic rows\n"; exit;
}
if ($command==='diagnose') { $m=$env->connect(true); foreach (['SHOW PROCESSLIST','SELECT * FROM information_schema.innodb_lock_waits','SELECT trx_id,trx_state,trx_mysql_thread_id,trx_query FROM information_schema.innodb_trx'] as $sql) echo json_encode($m->query($sql)->fetchAll(PDO::FETCH_ASSOC))."\n"; exit; }
if (in_array($command, ['00_preflight.sql','01_apply.sql','02_verify.sql'], true)) {
    $rows=$env->package($p,$command,$argv[2]??null);
    foreach ($rows as $r) if (($r['report_section']??'')==='OVERALL' || in_array($r['result']??'', ['FAIL','BLOCKED'])) echo json_encode($r,JSON_UNESCAPED_UNICODE)."\n";
    exit;
}
throw new RuntimeException('Unknown test command');
