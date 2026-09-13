<?php
require __DIR__.'/CatalogEnvironment.php';
use Illuminate\Support\Facades\DB;
use App\Services\AcademicCatalogTransaction;

$env=new CatalogEnvironment;
$app=$env->laravel();
$p=DB::connection()->getPdo();
$initialIsolation=$p->query('SELECT @@tx_isolation')->fetchColumn();
$monitor=$env->connect(true); // Only observes this fresh instance; no global grants to the app account.
$results=[];
function check(bool $yes, string $message): void { if (!$yes) throw new RuntimeException($message); }
function scenario(string $name, callable $work): void {
    global $env,$p,$results;
    if (getenv('CATALOG_SCENARIO') && !str_contains($name,getenv('CATALOG_SCENARIO'))) return;
    $env->guard($p);
    try { $detail=$work(); $results[]=['scenario'=>$name,'passed'=>true,'detail'=>$detail]; }
    catch (Throwable $e) { if (DB::transactionLevel()) DB::rollBack(); elseif ($p->inTransaction()) $p->rollBack(); $results[]=['scenario'=>$name,'passed'=>false,'class'=>get_class($e),'error'=>$e->getMessage()]; }
    echo json_encode(end($results), JSON_UNESCAPED_UNICODE)."\n";
}
function message($stream): array {
    $line=''; $deadline=microtime(true)+15;
    while (microtime(true)<$deadline) { $part=fgets($stream); if ($part!==false) { $line.=$part; if (str_ends_with($line,"\n")) return json_decode($line,true,flags:JSON_THROW_ON_ERROR); } usleep(10000); }
    throw new RuntimeException('Worker barrier timeout');
}
function startWorker(array $job): array {
    $process=proc_open([PHP_BINARY,__DIR__.'/worker.php'], [['pipe','r'],['pipe','w'],['file',sys_get_temp_dir().'/pr132-worker-errors.log','a']], $pipes);
    stream_set_blocking($pipes[1],false);
    $id=message($pipes[1])['ready'];
    fwrite($pipes[0],json_encode($job)."\n"); fflush($pipes[0]);
    check(message($pipes[1])['entered']===true,'Worker entered barrier');
    return [$process,$pipes,$id];
}
function finishWorker(array $worker): array {
    [$process,$pipes]=$worker;
    try { return message($pipes[1]); } finally { fclose($pipes[0]); fclose($pipes[1]); proc_close($process); }
}
function waitBlocked(int $id, string $statement = 'academic_catalog_control'): void {
    global $env,$monitor;
    $env->guard($monitor,false);
    $deadline=microtime(true)+12;
    while (microtime(true)<$deadline) {
        $q=$monitor->prepare('SELECT COUNT(*) FROM information_schema.innodb_lock_waits w JOIN information_schema.innodb_trx t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id=?'); $q->execute([$id]);
        if ($q->fetchColumn()>0) return;
        // MariaDB can wait during optimizer statistics before exposing an InnoDB
        // transaction. Observe the exact worker's locking statement instead.
        $s=$monitor->prepare('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?'); $s->execute([$id]); $row=$s->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['STATE'],['Statistics','Updating','Sending data'],true) && str_contains(strtolower($row['INFO']??''),$statement)) return;
        usleep(20000); // Polls an observed lock barrier, not an assumed timing/order.
    }
    throw new RuntimeException('Second independent connection never reached the lock barrier: '.json_encode($monitor->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC)));
}
function race(string $first, array $second, bool $expected): array {
    global $p;
    DB::beginTransaction();
    app(AcademicCatalogTransaction::class)->revision(true);
    DB::statement($first);
    check($p->inTransaction(),'Parent transaction lost before worker');
    $worker=startWorker($second);
    try { waitBlocked($worker[2]); DB::commit(); $r=finishWorker($worker); check($r['ok']===$expected,json_encode($r)); return ['order'=>'A locked/wrote; B observed waiting at control locking statement; A committed; B completed','second'=>$r]; }
    catch(Throwable $e) { if (DB::transactionLevel()) DB::rollBack(); proc_terminate($worker[0]); throw $e; }
}
function freshContext(): int {
    global $p;
    $id=(int)$p->query('SELECT GREATEST((SELECT MAX(academic_program_id) FROM academic_programs),(SELECT MAX(course_id) FROM courses))+1')->fetchColumn();
    $p->exec("INSERT INTO academic_programs(academic_program_id,department_id,program_name,total_credit_hours) VALUES($id,1,'Race $id',3)");
    $p->exec("INSERT INTO courses(course_id,course_code,course_name,credit_hours) VALUES($id,'R$id','Race $id',3)");
    return $id;
}
function http(string $method,string $uri,array $payload=[]): array {
    global $app,$env,$p;
    $env->guard($p);
    Laravel\Sanctum\Sanctum::actingAs(App\Models\User::findOrFail(1));
    $request=Illuminate\Http\Request::create($uri,$method,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],json_encode($payload));
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $r=$kernel->handle($request); $kernel->terminate($request,$r);
    return ['status'=>$r->getStatusCode(),'body'=>json_decode($r->getContent(),true)];
}
$base='/api/v1/vice-presidency/scientific/course-management';
scenario('safety guard rejects wrong server port/version/marker before SQL',function()use($env,$p){
    foreach(['port'=>65500,'version'=>'0.0.0','marker'=>str_repeat('0',32),'hostname'=>'not-this-test-host'] as $key=>$wrong) {
        $invalid=clone $env; $invalid->config[$key]=$wrong;
        try { $invalid->guard($p); throw new LogicException('Unsafe connection accepted'); }
        catch(RuntimeException $e) { check(!($e instanceof LogicException),'Unsafe guard'); }
    }
});
scenario('definitions: all 57 trigger bodies and four routines match installed package',function () use($p) {
    $sql=file_get_contents(dirname(__DIR__,2).'/database/sql/scientific-course-management/01_apply.sql');
    preg_match_all('/CREATE OR REPLACE (TRIGGER|FUNCTION|PROCEDURE) (sc_(?:cat_|use_|ref_|catalog_)[a-z_]+)[\s\S]*?\n(BEGIN[\s\S]*?END)\/\//',$sql,$m,PREG_SET_ORDER);
    foreach($m as [, $kind,$name,$body]) {
        if ($kind==='TRIGGER') { $q=$p->prepare('SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?'); }
        else $q=$p->prepare('SELECT routine_definition FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_name=?');
        $q->execute([$name]); $actual=$q->fetchColumn();
        check($actual!==false && preg_replace('/\s+/',' ',trim($body))===preg_replace('/\s+/',' ',trim($actual)),"Definition mismatch $name");
    }
    check(count($m)===62,'57 triggers + 5 routines including installer'); return count($m);
});
scenario('real Laravel list/detail GET',function()use($base){ foreach([$base.'/courses',$base.'/courses/1',$base.'/programs/1'] as $url) { $r=http('GET',$url); check($r['status']===200,json_encode($r)); } });
scenario('old CRUD ABA invalidates editor',function()use($base){
    $r=http('GET',$base.'/courses/1'); $revision=$r['body']['data']['revision'];
    foreach(['Changed','مادة 1'] as $name) check(http('PUT','/api/v1/courses/1',['course_name'=>$name])['status']===200,'old CRUD failed');
    $r=http('PUT',$base.'/courses/1',['revision'=>$revision,'description'=>'stale']); check($r['status']===409,json_encode($r));
});
scenario('real trigger used course textual correction allowed; academic change blocked',function()use($p,$base){
    $p->exec("UPDATE courses SET course_name='تصحيح مادة 2' WHERE course_id=2");
    try { $p->exec('UPDATE courses SET credit_hours=4 WHERE course_id=2'); throw new RuntimeException('Accepted used mutation'); } catch(PDOException $e) { check(str_contains($e->getMessage(),'academic_catalog_history_locked'),$e->getMessage()); }
    check(http('PUT','/api/v1/courses/2',['credit_hours'=>4])['status']===409,'CRUD must be controlled');
});
foreach(['course','curriculum'] as $kind) foreach(['edit-first','student-first'] as $order) scenario("$kind vs first student: $order",function()use($p,$kind,$order){
    $n=freshContext();
    $p->exec("INSERT INTO program_courses(academic_program_id,course_id,course_type) VALUES($n,$n,'mandatory')");
    $edit=$kind==='course'?"UPDATE courses SET credit_hours=4 WHERE course_id=$n":"UPDATE program_courses SET course_type='elective' WHERE academic_program_id=$n";
    $use="INSERT INTO students(academic_program_id) VALUES($n)";
    $r=race($order==='edit-first'?$edit:$use,['sql'=>[$order==='edit-first'?$use:$edit]],$order==='edit-first');
    check((int)$p->query("SELECT COUNT(*) FROM students WHERE academic_program_id=$n")->fetchColumn()===1,'First student missing');
    $actual=$p->query($kind==='course'?"SELECT credit_hours FROM courses WHERE course_id=$n":"SELECT course_type FROM program_courses WHERE academic_program_id=$n")->fetchColumn();
    check((string)$actual===($kind==='course'?($order==='edit-first'?'4':'3'):($order==='edit-first'?'elective':'mandatory')),'Losing edit leaked'); return $r;
});
foreach(['course_offerings','supplementary_exam_offerings'] as $table) foreach(['edit-first','offering-first'] as $order) scenario("curriculum vs $table: $order",function()use($p,$table,$order){
    $n=freshContext();
    $p->exec("INSERT INTO program_courses(academic_program_id,course_id,course_type) VALUES($n,$n,'mandatory')");
    $edit="UPDATE program_courses SET course_type='elective' WHERE academic_program_id=$n"; $use="INSERT INTO $table(academic_program_id,course_id) VALUES($n,$n)";
    $r=race($order==='edit-first'?$edit:$use,['sql'=>[$order==='edit-first'?$use:$edit]],$order==='edit-first');
    check((int)$p->query("SELECT COUNT(*) FROM $table WHERE academic_program_id=$n")->fetchColumn()===1,'Offering missing');
    check($p->query("SELECT course_type FROM program_courses WHERE academic_program_id=$n")->fetchColumn()===($order==='edit-first'?'elective':'mandatory'),'Losing curriculum edit leaked'); return $r;
});
scenario('destination offering identity vs destination curriculum',function()use($p){
    $a=freshContext(); $b=freshContext();
    $p->exec("INSERT INTO program_courses(academic_program_id,course_id,course_type) VALUES($b,$b,'mandatory')");
    $p->exec("INSERT INTO course_offerings(academic_program_id,course_id) VALUES($a,$a)"); $offering=$p->lastInsertId();
    return race("UPDATE course_offerings SET academic_program_id=$b,course_id=$b WHERE course_offering_id=$offering",['sql'=>["UPDATE program_courses SET course_type='elective' WHERE academic_program_id=$b"]],false);
});
scenario('concurrent duplicate code: one winner, controlled validation loser',function()use($p){
    $code='DUP'.freshContext();
    $sql="INSERT INTO courses(course_code,course_name,credit_hours) VALUES('$code','duplicate',3)";
    $r=race($sql,['sql'=>[$sql]],false);
    check($r['second']['class']===Illuminate\Validation\ValidationException::class,'Expected controlled validation');
    check((int)$p->query("SELECT COUNT(*) FROM courses WHERE course_code='$code'")->fetchColumn()===1,'Duplicate code saved'); return $r;
});
scenario('concurrent opposite prerequisite edges: cycle rolled back',function()use($p){
    $a=freshContext(); $b=freshContext();
    $r=race("INSERT INTO course_prerequisites(course_id,prerequisite_course_id) VALUES($a,$b)",['sql'=>["INSERT INTO course_prerequisites(course_id,prerequisite_course_id) VALUES($b,$a)"],'acyclic'=>true],false);
    check((int)$p->query("SELECT COUNT(*) FROM course_prerequisites WHERE course_id IN($a,$b)")->fetchColumn()===1,'Cycle remains'); return $r;
});
scenario('failed multiwrite: course relationship and audit rolled back',function()use($p){
    $before=$p->query('SELECT COUNT(*) FROM user_activity_logs')->fetchColumn();
    try { app(AcademicCatalogTransaction::class)->run(function(){ DB::table('courses')->insert(['course_code'=>'ROLLBACK','course_name'=>'No']); DB::table('user_activity_logs')->insert(['action_code'=>'test.rollback']); DB::table('course_departments')->insert(['course_id'=>999999,'department_id'=>1]); }); throw new RuntimeException('Expected FK failure'); }
    catch(App\Exceptions\AcademicCatalogException $e) { check(!str_contains($e->getMessage(),'SQL'),'SQL leaked'); }
    check(!$p->query("SELECT COUNT(*) FROM courses WHERE course_code='ROLLBACK'")->fetchColumn(),'Partial course'); check($before===$p->query('SELECT COUNT(*) FROM user_activity_logs')->fetchColumn(),'Partial audit');
});
scenario('lock timeout: controlled conflict, no automatic retry or partial write',function()use($p){
    DB::beginTransaction(); app(AcademicCatalogTransaction::class)->revision(true);
    $worker=startWorker(['sql'=>["INSERT INTO courses(course_code) VALUES('TIMEOUT-MUST-NOT-EXIST')"],'timeout'=>1]);
    try { waitBlocked($worker[2]); $r=finishWorker($worker); check(!$r['ok'] && $r['code']==='academic_catalog_stale',json_encode($r)); DB::rollBack(); }
    finally { if(DB::transactionLevel()) DB::rollBack(); }
    check(!$p->query("SELECT COUNT(*) FROM courses WHERE course_code='TIMEOUT-MUST-NOT-EXIST'")->fetchColumn(),'Timeout wrote'); return $r;
});
scenario('forced row-first legacy deadlock: controlled victim and full rollback',function()use($p){
    $id=freshContext(); DB::beginTransaction();
    DB::select('SELECT course_id FROM courses WHERE course_id=? FOR UPDATE',[$id]);
    DB::table('user_activity_logs')->insert(['action_code'=>"deadlock.parent.$id"]);
    $worker=startWorker(['sql'=>["INSERT INTO user_activity_logs(action_code) VALUES('deadlock.worker.$id')", "UPDATE courses SET description='worker-winner' WHERE course_id=$id"], 'handshake'=>true]);
    check(message($worker[1][1])['locked']===true,'Worker owns catalog lock');
    fwrite($worker[1][0],"continue\n"); fflush($worker[1][0]);
    waitBlocked($worker[2],'update courses');
    $parentOk=false;
    try {
        app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('courses')->where('course_id',$id)->update(['description'=>'parent-winner']));
        DB::commit(); $parentOk=true;
    } catch(App\Exceptions\AcademicCatalogException $e) {
        check($e->errorCode==='academic_catalog_stale','Deadlock must be controlled');
        if(DB::transactionLevel()) DB::rollBack();
    }
    $r=finishWorker($worker);
    check($parentOk!==$r['ok'],'Exactly one deadlock participant wins');
    if (!$r['ok']) check($r['code']==='academic_catalog_stale','Worker victim leaked SQL');
    check($p->query("SELECT description FROM courses WHERE course_id=$id")->fetchColumn()===($parentOk?'parent-winner':'worker-winner'),'Wrong final deadlock state');
    $logs=$p->query("SELECT action_code FROM user_activity_logs WHERE action_code IN('deadlock.parent.$id','deadlock.worker.$id')")->fetchAll(PDO::FETCH_COLUMN);
    check($logs===[$parentOk?"deadlock.parent.$id":"deadlock.worker.$id"],'Deadlock victim left a provisional audit');
    return ['parent_committed'=>$parentOk,'worker'=>$r];
});
scenario('legacy CRUD Course/AcademicProgram/ProgramCourse/CourseDepartment/CoursePrerequisite',function()use($p){
    $a=freshContext(); $b=freshContext();
    $cases=[['PUT',"/api/v1/academic-programs/$a",['program_name'=>'Legacy text']],['POST','/api/v1/program-courses',['academic_program_id'=>$a,'course_id'=>$a,'academic_level_id'=>1,'recommended_semester_id'=>1,'course_type'=>'mandatory','is_active'=>true]],['POST','/api/v1/course-departments',['course_id'=>$a,'department_id'=>1,'is_primary'=>true]],['POST','/api/v1/course-prerequisites',['course_id'=>$a,'prerequisite_course_id'=>$b]],['PUT',"/api/v1/courses/$a",['course_name'=>'Legacy name']]];
    foreach($cases as [$method,$url,$data]) { $r=http($method,$url,$data); check(in_array($r['status'],[200,201],true),json_encode([$url,$r])); }
    $p->exec("INSERT INTO students(academic_program_id) VALUES($a)");
    $r=http('PUT',"/api/v1/academic-programs/$a",['total_credit_hours'=>6]); check($r['status']===409,json_encode($r));
    $pc=(int)$p->query("SELECT program_course_id FROM program_courses WHERE academic_program_id=$a")->fetchColumn();
    check(http('PUT',"/api/v1/program-courses/$pc",['course_type'=>'elective'])['status']===409,'Used legacy membership allowed');
    $cd=(int)$p->query("SELECT course_department_id FROM course_departments WHERE course_id=$a")->fetchColumn();
    check(http('DELETE',"/api/v1/course-departments/$cd")['status']===409,'Used origin deletion allowed');
    check(http('POST','/api/v1/course-prerequisites',['course_id'=>$a,'prerequisite_course_id'=>1])['status']===409,'Used prerequisite write allowed');
    foreach(["UPDATE academic_programs SET total_credit_hours=6 WHERE academic_program_id=$a", "UPDATE program_courses SET course_type='elective' WHERE program_course_id=$pc", "DELETE FROM course_departments WHERE course_department_id=$cd", "INSERT INTO course_prerequisites(course_id,prerequisite_course_id) VALUES($a,1)"] as $sql) {
        try { $p->exec($sql); throw new RuntimeException('Direct historical mutation accepted'); }
        catch(PDOException $e) { check(str_contains($e->getMessage(),'academic_catalog_history_locked'),$e->getMessage()); }
    }
});
scenario('canonical offering create and stale identity context proof',function()use($p){
    $a=freshContext();
    $p->exec("INSERT INTO program_courses(academic_program_id,course_id,course_type) VALUES($a,$a,'mandatory')");
    $service=app(App\Services\CourseOfferingContextService::class); $actor=App\Models\User::findOrFail(1);
    $context=$service->resolveContext($a,$a,1,1,actor:$actor);
    $p->exec("UPDATE academic_programs SET program_name='ABA first' WHERE academic_program_id=$a");
    foreach(['create','identity'] as $path) {
        try { DB::transaction(function()use($service,$context,$path){ $path==='create'?$service->createOffering($context):$service->retainCatalogProofWithinTransaction($context); }); throw new RuntimeException('Stale offering proof accepted'); }
        catch(App\Exceptions\AcademicCatalogException $e) { check($e->errorCode==='academic_catalog_stale','Wrong context error'); }
    }
    $offering=$service->createOffering($service->resolveContext($a,$a,1,1,actor:$actor));
    check(strtoupper($offering->status)==='CLOSED','Offering did not remain closed');
    check((int)$offering->academic_program_id===$a,'Identity changed');
    $b=freshContext();
    $p->exec("INSERT INTO program_courses(academic_program_id,course_id,course_type) VALUES($b,$b,'mandatory')");
    $destination=$service->resolveContext($b,$b,1,1,actor:$actor);
    DB::transaction(function()use($service,$destination,$offering){
        $service->retainCatalogProofWithinTransaction($destination);
        $service->updateOffering($offering,$destination->offeringAttributes());
    });
    check((int)$offering->fresh()->academic_program_id===$b,'Actual identity update failed');
});
scenario('real HTTP ordinary offering create and identity update with installed triggers',function()use($p){
    // Empty ancillary fixture tables only; never production DDL or substitute triggers.
    foreach(['student_course_registrations'=>'student_course_registration_id','attendance_sessions'=>'attendance_session_id','grade_approvals'=>'grade_approval_id','grade_part_approvals'=>'grade_part_approval_id','grade_components'=>'grade_component_id','course_offering_instructors'=>'course_offering_instructor_id'] as $table=>$key) {
        $p->exec("CREATE TABLE IF NOT EXISTS $table ($key INT NOT NULL AUTO_INCREMENT PRIMARY KEY, course_offering_id INT NULL, faculty_member_id INT NULL) ENGINE=InnoDB");
    }
    $p->exec('CREATE TABLE IF NOT EXISTS faculty_members (faculty_member_id INT NOT NULL PRIMARY KEY, employee_id INT NULL) ENGINE=InnoDB');
    $a=freshContext(); $b=freshContext();
    $p->exec("INSERT INTO program_courses(academic_program_id,course_id,course_type) VALUES($a,$a,'mandatory'),($b,$b,'mandatory')");
    $r=http('POST','/api/v1/course-offerings',['course_id'=>$a,'academic_program_id'=>$a,'academic_year_id'=>1,'semester_id'=>1,'capacity'=>30,'available_seats'=>30,'status'=>'closed']);
    check($r['status']===201,json_encode($r)); $id=$r['body']['data']['course_offering_id'];
    $r=http('PUT',"/api/v1/course-offerings/$id",['course_id'=>$b,'academic_program_id'=>$b]); check($r['status']===200,json_encode($r));
    check((int)$p->query("SELECT academic_program_id FROM course_offerings WHERE course_offering_id=$id")->fetchColumn()===$b,'HTTP identity not materialized');
});
foreach(['REPEATABLE READ','READ COMMITTED'] as $isolation) scenario("nonlocking list/detail read fence: $isolation",function()use($p,$isolation){
    $id=freshContext();
    $p->exec("SET SESSION TRANSACTION ISOLATION LEVEL $isolation");
    foreach(['listing','course'] as $method) {
        $armed=true; $worker=null;
        DB::listen(function($query)use(&$armed,&$worker,$id){
            if (!$armed || !str_contains($query->sql,'`courses`') || !str_starts_with($query->sql,'select')) return;
            $armed=false;
            $worker=startWorker(['sql'=>["UPDATE courses SET description=CONCAT(COALESCE(description,''),'x') WHERE course_id=$id"]]);
            // Writer must COMMIT while GET read transaction is still running: proves GET holds no control write lock.
            check(finishWorker($worker)['ok']===true,'GET blocked writer');
        });
        try { $s=app(App\Services\ScientificCourseManagementService::class); $u=App\Models\User::findOrFail(1); $method==='listing'?$s->listing($u,[]):$s->course($u,$id); throw new RuntimeException('Mixed revision response accepted'); }
        catch(App\Exceptions\AcademicCatalogException $e) { check($e->errorCode==='academic_catalog_stale','Expected read fence conflict'); }
    }
});
file_put_contents($env->config['directory'].'/verification.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents($env->config['directory'].'/tested-code.json',json_encode([
    'head'=>trim(shell_exec('git rev-parse HEAD')),
    'transaction_service_sha256'=>hash_file('sha256',dirname(__DIR__,2).'/app/Services/AcademicCatalogTransaction.php'),
    'mariadb'=>$p->query('SELECT @@version')->fetchColumn(),
    'initial_session_isolation'=>$initialIsolation,
    'final_session_isolation'=>$p->query('SELECT @@tx_isolation')->fetchColumn(),
],JSON_PRETTY_PRINT));
exit(count(array_filter($results,fn($r)=>!$r['passed']))?1:0);
