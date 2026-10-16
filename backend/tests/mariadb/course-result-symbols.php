<?php

/** Disposable synthetic database only. Never reads .env or imports production records. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
use App\Models\{CourseIncompleteCase, StudentCourseRegistration, User};
use App\Services\{GradePartWorkflowService, IncompleteResultService};
use App\Support\IncompleteOutcomeGuard;
use App\Support\IncompleteSchemaContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\Support\CourseResultSymbolsFixture;

$file=getenv('COURSE_SYMBOLS_MARIADB_CONFIG') ?: '';
$directory=realpath(dirname($file));
if(!$directory || strcasecmp(dirname($directory),realpath(sys_get_temp_dir()))!==0 || !preg_match('/^course-symbols-([a-f0-9]{32})$/',basename($directory),$match)) throw new RuntimeException('New isolated test directory required.');
$config=json_decode(ltrim(file_get_contents($file),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
if($config['marker']!==$match[1] || $config['host']!=='127.0.0.1' || $config['port']<20000 || $config['database']!=='course_symbols_'.$match[1]) throw new RuntimeException('Isolation identity mismatch.');
$pdo=new PDO("mysql:host=127.0.0.1;port={$config['port']};charset=utf8mb4",'root',$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$identity=$pdo->query('SELECT @@version version,@@port port,@@datadir datadir')->fetch(PDO::FETCH_ASSOC);
if(!str_contains($identity['version'],'MariaDB') || (int)$identity['port']!==(int)$config['port'] || realpath($identity['datadir'])!==realpath($directory.'/data')) throw new RuntimeException('Refusing a non-disposable database.');
if(($argv[1]??'')==='shutdown'){ $pdo->exec('SHUTDOWN'); echo "Guarded isolated shutdown.\n"; exit; }
$database=$config['database'];
if(($argv[1]??'')==='run'){
    if($pdo->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$database'")->fetchColumn()) throw new RuntimeException('Refusing existing database.');
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE `$database`.course_symbols_test_marker(marker CHAR(32) NOT NULL)");
    $pdo->prepare("INSERT INTO `$database`.course_symbols_test_marker VALUES (?)")->execute([$config['marker']]);
}
if($pdo->query("SELECT marker FROM `$database`.course_symbols_test_marker")->fetchColumn()!==$config['marker']) throw new RuntimeException('Missing isolation marker.');
// Ignore repository .env/config caches before Laravel providers can boot.
foreach(['APP_ENV'=>'testing','APP_CONFIG_CACHE'=>$directory.'/config-cache.php','APP_ROUTES_CACHE'=>$directory.'/routes-cache.php',
    'APP_KEY'=>'base64:'.base64_encode(hash('sha256',$config['marker'],true)),'DB_URL'=>'','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1',
    'DB_PORT'=>(string)$config['port'],'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>$config['password'],
    'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array'] as $name=>$value){putenv($name.'='.$value);$_ENV[$name]=$value;$_SERVER[$name]=$value;}
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory)->loadEnvironmentFrom('no-university-environment.env');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): never { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n"); exit(1); });
config(['app.env'=>'testing','database.default'=>'mysql','database.connections.mysql'=>[
    'driver'=>'mysql','host'=>'127.0.0.1','port'=>$config['port'],'database'=>$database,'username'=>'root','password'=>$config['password'],
    'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'engine'=>'InnoDB','timezone'=>'+00:00',
], 'cache.default'=>'array','session.driver'=>'array','logging.default'=>'single','logging.channels.single.path'=>$directory.'/laravel-test.log']);
DB::purge('mysql'); DB::connection()->getPdo();

function check(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
function package(string $name): array {
    $source=file_get_contents(dirname(__DIR__,2).'/database/sql/course-result-symbols-phase1/'.$name);
    $delimiter=';'; $buffer=''; $rows=[];
    foreach(explode("\n",$source) as $line){
        if(preg_match('/^DELIMITER\s+(\S+)/',trim($line),$m)){ $delimiter=$m[1];continue; }
        if(str_starts_with(trim($line),'--') || trim($line)==='')continue;
        $buffer.=$line."\n";
        if(!str_ends_with(rtrim($buffer),$delimiter))continue;
        $statement=substr(rtrim($buffer),0,-strlen($delimiter)); $buffer='';
        $q=DB::connection()->getPdo()->query($statement);
        do { if($q->columnCount())array_push($rows,...$q->fetchAll(PDO::FETCH_ASSOC)); } while($q->nextRowset());
        $q->closeCursor();
    }
    return $rows;
}
function context(int $id,bool $grant=true,string $outcome='F',?string $announcement=null): array {
    if(!StudentCourseRegistration::whereKey($id)->exists()){
    DB::table('students')->insert(['student_id'=>$id,'student_number'=>'SYNTHETIC-'.$id,'first_name'=>'Synthetic','last_name'=>'Case','academic_program_id'=>1,'current_academic_level_id'=>1]);
    DB::table('courses')->insert(['course_id'=>$id,'course_code'=>'SYN-'.$id,'course_name'=>'Synthetic course','credit_hours'=>3,'theoretical_hours'=>2,'practical_hours'=>1]);
    DB::table('course_offerings')->insert(['course_offering_id'=>$id,'course_id'=>$id,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1,'department_id'=>1,'status'=>'open']);
    DB::table('student_course_registrations')->insert(['student_course_registration_id'=>$id,'student_id'=>$id,'course_offering_id'=>$id,'registration_status_id'=>1]);
    foreach(['theoretical'=>60,'practical'=>40] as $part=>$max){
        $component=DB::table('grade_components')->insertGetId(['course_offering_id'=>$id,'component_type'=>$part,'component_name'=>$part,'is_required'=>1,'max_mark'=>$max]);
        if($part==='practical')DB::table('student_grade_components')->insert(['student_course_registration_id'=>$id,'grade_component_id'=>$component,'mark'=>30,'grade_status'=>'approved']);
    }
    }
    CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
    if(!$grant)return ['registration_id'=>$id];
    if($announcement!==null)DB::table('student_course_results')->insert(['student_course_registration_id'=>$id,'result_status_id'=>4,'practical_total'=>30,'result_announced_at'=>$announcement]);
    return app(IncompleteResultService::class)->grant(User::findOrFail(1),$id,['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'Synthetic documented grant','requirements'=>'Missing theory','expiry_outcome'=>$outcome,'deadline_at'=>'2026-10-17']);
}

if(($argv[1]??'')==='worker'){
    [$unused,$mode,$tag,$action,$registration,$case,$revision]=$argv;
    CarbonImmutable::setTestNow(in_array($action,['resolve','extend-before','grant','ordinary-save'],true)?'2026-10-16 12:00:00 UTC':'2026-10-18 12:00:00 UTC');
    file_put_contents($directory.'/ready-'.$tag,json_encode(['connection_id'=>(int)DB::selectOne('SELECT CONNECTION_ID() id')->id],JSON_THROW_ON_ERROR));
    if(str_starts_with($tag,'ordered-first-')){
        $gate=App\Services\AcademicPlanContext::installed()?'academic_catalog_control':'students'; $paused=false;
        // Test-only connection observation, no production bypass or extra schema trigger.
        DB::listen(function($query)use($directory,$tag,$gate,&$paused):void{
            if($paused || !str_contains(strtolower($query->sql),'for update') || !str_contains($query->sql,'from `'.$gate.'`'))return;
            $paused=true;file_put_contents($directory.'/holding-'.$tag,'held');$until=microtime(true)+45;
            while(!is_file($directory.'/release-'.$tag)){
                if(microtime(true)>$until)throw new RuntimeException('Ordered race release timed out.');
                usleep(50000);
            }
        });
    }
    try {
        if($action==='grant')$result=app(IncompleteResultService::class)->grant(User::findOrFail(1),(int)$registration,['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'Concurrent explicit grant','requirements'=>'Outstanding requirement','expiry_outcome'=>'F','deadline_at'=>'2026-10-17']);
        elseif(in_array($action,['drop','withdraw','cancel'],true)){
            $r=StudentCourseRegistration::findOrFail($registration); $service=app(App\Services\RegistrationService::class);
            if($action==='drop')$service->selfDrop($r->student,$r);
            else App\Services\AcademicPlanContext::transaction(function()use($service,$r,$action):void{
                $service->lockStudent((int)$r->student_id); $offering=$service->lockOffering((int)$r->course_offering_id); $locked=$service->lockRegistration((int)$r->getKey());
                if($action==='withdraw')$service->transitionRegisteredToWithdrawn($locked,$offering); else $service->transitionRegisteredToCancelled($locked,$offering);
            });
            $result=true;
        }
        elseif($action==='expire-batch'){
            config(['cache.default'=>'file','cache.stores.file.path'=>$directory.'/expiry-cache','cache.stores.file.lock_path'=>$directory.'/expiry-cache']);
            app('cache')->purge('file');
            $exit=Illuminate\Support\Facades\Artisan::call('course-results:expire-incomplete',['--batch'=>1]);
            $result=['exit'=>$exit,'output'=>Illuminate\Support\Facades\Artisan::output()];
        }
        elseif($action==='ordinary-save'){
            $r=StudentCourseRegistration::findOrFail($registration); $workflow=app(GradePartWorkflowService::class); $snapshot=$workflow->manualRegistration($r);
            $component=DB::table('grade_components')->where('course_offering_id',$registration)->where('component_type','theoretical')->value('grade_component_id');
            $result=$workflow->saveManualMarks($r->student,$r,['revision'=>$snapshot['revision'],'acknowledged'=>true,'components'=>[['grade_component_id'=>$component,'mark'=>55]]],User::findOrFail(1));
        }
        elseif($action==='expire')$result=app(IncompleteResultService::class)->expire((int)$case);
        elseif(str_starts_with($action,'extend'))$result=app(IncompleteResultService::class)->changeDeadline(User::findOrFail(1),(int)$case,['request_id'=>(string)Str::uuid(),'expected_revision'=>(int)$revision,'deadline_at'=>'2026-11-01']);
        elseif($action==='finalize')$result=app(GradePartWorkflowService::class)->review(User::findOrFail(1),(int)$case,'approve',null)->getKey();
        else {
            $component=DB::table('grade_components')->where('course_offering_id',$registration)->where('component_type','theoretical')->value('grade_component_id');
            $result=app(IncompleteResultService::class)->resolve(User::findOrFail(1),(int)$case,['request_id'=>(string)Str::uuid(),'expected_revision'=>(int)$revision,'components'=>[['grade_component_id'=>$component,'mark'=>50]]]);
        }
        file_put_contents($directory.'/result-'.$tag,json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR));
    } catch(App\Exceptions\GradeException $e){file_put_contents($directory.'/result-'.$tag,json_encode(['ok'=>false,'code'=>$e->errorCode,'status'=>$e->status],JSON_THROW_ON_ERROR));}
    exit;
}

function compete(string $tag,int $registration,int $case,string $a,string $b,string $gate='student'): array {
    global $directory;
    if(App\Services\AcademicPlanContext::installed())$gate='catalog';
    $connection=DB::connection(); $connection->beginTransaction();
    if($gate==='catalog')DB::table('academic_catalog_control')->where('control_id',1)->lockForUpdate()->first();
    else DB::table($gate==='student'?'students':'course_offerings')->where($gate==='student'?'student_id':'course_offering_id',$registration)->lockForUpdate()->first();
    $processes=[]; $pipes=[];
    foreach([$a,$b] as $i=>$action){
        $name=$tag.'-'.$i;
        $caseId=$action==='finalize'?(int)DB::table('grade_part_approvals')->where('course_offering_id',$registration)->where('component_type','theoretical')->value('grade_part_approval_id'):$case;
        $processes[$name]=proc_open([PHP_BINARY,__FILE__,'worker',$name,$action,(string)$registration,(string)$caseId,'1'],[1=>['pipe','w'],2=>['pipe','w']],$pipes[$name]);
        check(is_resource($processes[$name]),'Worker process unavailable.');
    }
    $waiting=false;
    try {
        $until=microtime(true)+30;
        while(microtime(true)<$until){
            $n=$gate==='catalog'?(int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE l.lock_table=?',['`'.DB::getDatabaseName().'`.`academic_catalog_control`'])->n
                :(int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS')->n;
            if($n>=2){$waiting=true;break;}
            usleep(100000);
        }
        check($waiting,'Did not observe two independent concurrent lock waiters.');
    } finally {$connection->commit();}
    $results=[];
    foreach($processes as $name=>$process){
        $out=stream_get_contents($pipes[$name][1]);$err=stream_get_contents($pipes[$name][2]);fclose($pipes[$name][1]);fclose($pipes[$name][2]);
        check(proc_close($process)===0,'Worker failed: '.$out.$err);
        check(is_file($directory.'/result-'.$name),'Worker produced no result.');
        $results[]=json_decode(file_get_contents($directory.'/result-'.$name),true,flags:JSON_THROW_ON_ERROR);
    }
    echo 'CONCURRENT | '.$tag.' | two independent '.$gate.' lock waiters observed | '.json_encode(array_map(fn($r)=>$r['ok']?['ok'=>true,'changed'=>is_bool($r['result'])?$r['result']:($r['result']['changed']??true)]:['ok'=>false,'code'=>$r['code']],$results))."\n";
    return $results;
}

function orderedDropRace(int $id,bool $grantFirst,string $label):void{
    global $directory;
    context($id,false);$tag='ordered-first-'.$id;$processes=[];$pipes=[];
    $actions=$grantFirst?['grant','drop']:['drop','grant'];
    $names=[$tag,'ordered-second-'.$id];
    $spawn=function(int $i)use(&$processes,&$pipes,$names,$actions,$id):void{
        $processes[$i]=proc_open([PHP_BINARY,__FILE__,'worker',$names[$i],$actions[$i],(string)$id,'0','0'],[1=>['pipe','w'],2=>['pipe','w']],$pipes[$i]);
        check(is_resource($processes[$i]),'Ordered worker unavailable.');
    };
    $spawn(0);
    $failure=null;
    try{
        $until=microtime(true)+30;while(!is_file($directory.'/holding-'.$tag)){check(microtime(true)<$until,'First worker did not acquire its domain lock.');usleep(50000);}
        $spawn(1);$waiting=false;$until=microtime(true)+15;
        $gate=App\Services\AcademicPlanContext::installed()?'academic_catalog_control':'students';
        while(microtime(true)<$until){
            if(is_file($directory.'/ready-'.$names[1])){
                $connection=json_decode(file_get_contents($directory.'/ready-'.$names[1]),true,flags:JSON_THROW_ON_ERROR)['connection_id'];
                $pending=DB::selectOne('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?',[$connection]);
                // MariaDB can expose the pending FOR UPDATE as Statistics before
                // it enters INNODB_LOCK_WAITS. Require the identified contender's
                // actual locking statement, not a sleep or a timing assumption.
                if($pending && str_contains(strtolower($pending->INFO??''),'for update') && str_contains($pending->INFO,'from `'.$gate.'`')){$waiting=true;break;}
            }
            usleep(50000);
        }
        if(!$waiting){
            $states=DB::select('SELECT ID,COMMAND,STATE,LEFT(INFO,200) AS statement_text FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID()');
            throw new RuntimeException('Ordered competing transition did not wait: '.json_encode($states));
        }
    }catch(Throwable $e){$failure=$e;
    }finally{file_put_contents($directory.'/release-'.$tag,'release');}
    $results=[];
    foreach($processes as $i=>$process){$out=stream_get_contents($pipes[$i][1]);$err=stream_get_contents($pipes[$i][2]);fclose($pipes[$i][1]);fclose($pipes[$i][2]);check(proc_close($process)===0,'Ordered worker failed: '.$out.$err.($failure?->getMessage()??''));$results[]=json_decode(file_get_contents($directory.'/result-'.$names[$i]),true,flags:JSON_THROW_ON_ERROR);}
    if($failure)throw $failure;
    check($results[0]['ok']&&!$results[1]['ok'],'Ordered transition race had incorrect winners.');
    $registration=StudentCourseRegistration::findOrFail($id);
    if($grantFirst){check($results[1]['code']==='incomplete_registration_transition_locked','Losing drop was not a controlled lifecycle conflict.');check(IncompleteOutcomeGuard::preservesOutcome($registration),'Grant-first evidence lost.');}
    else{check($results[1]['code']==='incomplete_registration_not_eligible','Waiting grant used an obsolete registration status.');check($registration->registrationStatus->status_code==='dropped','Drop-first status lost.');check(!CourseIncompleteCase::where('student_course_registration_id',$id)->exists(),'Drop-first left an orphaned case.');}
    echo 'ORDERED_CONCURRENT | '.$label.' | '.($grantFirst?'grant-first':'drop-first')." | independent held lock / in-flight FOR UPDATE PASS\n";
}

CourseResultSymbolsFixture::installLegacy();
Schema::create('student_registration_requests',fn($t)=>[$t->increments('student_registration_request_id'),$t->integer('student_id'),$t->integer('academic_year_id'),$t->integer('semester_id'),$t->string('status')]);
Schema::table('user_roles',fn($t)=>$t->dateTime('assigned_at')->nullable());
Schema::table('student_course_results',function($t){$t->dateTime('result_announced_at')->nullable();$t->unique('student_course_registration_id');});
DB::table('result_statuses')->insert([['result_status_id'=>4,'status_code'=>'incomplete','is_active'=>1],['result_status_id'=>5,'status_code'=>'pending_approval','is_active'=>1]]);
DB::table('registration_statuses')->insert([['registration_status_id'=>4,'status_code'=>'completed'],['registration_status_id'=>5,'status_code'=>'withdrawn']]);
foreach(['students'=>'student_id','users'=>'user_id','courses'=>'course_id','academic_years'=>'academic_year_id','semesters'=>'semester_id','student_course_registrations'=>'student_course_registration_id','student_course_results'=>'student_course_result_id'] as $table=>$key) DB::statement("ALTER TABLE $table MODIFY $key INT NOT NULL AUTO_INCREMENT");
DB::statement('ALTER TABLE grade_audit_logs MODIFY grade_audit_log_id BIGINT NOT NULL AUTO_INCREMENT');
DB::statement('ALTER TABLE student_course_registrations ADD UNIQUE KEY uq_fixture_registration(student_id,course_offering_id)');
DB::statement('ALTER TABLE student_course_results ADD CONSTRAINT fk_fixture_result_registration FOREIGN KEY(student_course_registration_id) REFERENCES student_course_registrations(student_course_registration_id)');
DB::statement('ALTER TABLE student_course_results MODIFY theoretical_total DECIMAL(5,2) NOT NULL DEFAULT 0, MODIFY practical_total DECIMAL(5,2) NOT NULL DEFAULT 0, MODIFY final_mark DECIMAL(5,2) NOT NULL DEFAULT 0');
DB::statement('CREATE TABLE crs_legacy_revision(revision INT NOT NULL)'); DB::table('crs_legacy_revision')->insert(['revision'=>1]);
DB::unprepared('CREATE PROCEDURE crs_legacy_touch() BEGIN UPDATE crs_legacy_revision SET revision=revision+1; END');
foreach(['INSERT'=>'i','UPDATE'=>'u','DELETE'=>'d'] as $operation=>$suffix)DB::unprepared("CREATE TRIGGER crs_legacy_result_$suffix BEFORE $operation ON student_course_results FOR EACH ROW BEGIN CALL crs_legacy_touch(); END");
for($i=1;$i<=5;$i++){
    DB::table('courses')->insert(['course_id'=>10+$i,'course_code'=>'HIST-'.$i,'course_name'=>'Synthetic historical course','credit_hours'=>3]);
    DB::table('course_offerings')->insert(['course_offering_id'=>10+$i,'course_id'=>10+$i,'academic_year_id'=>1,'semester_id'=>1,'academic_program_id'=>1,'department_id'=>1]);
    DB::table('student_course_registrations')->insert(['student_course_registration_id'=>$i+2,'student_id'=>1,'course_offering_id'=>10+$i,'registration_status_id'=>1]);
    DB::table('student_course_results')->insert(['student_course_registration_id'=>$i+2,'result_status_id'=>$i,'theoretical_total'=>0,'practical_total'=>0,'final_mark'=>0,'result_announced_at'=>'2026-01-01 00:00:00']);
}
$before=DB::table('student_course_results')->orderBy('student_course_result_id')->get()->toArray();
$triggers=DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',$database)->orderBy('TRIGGER_NAME')->get(['TRIGGER_NAME','ACTION_STATEMENT'])->toArray();
DB::table('result_statuses')->where('status_code','incomplete')->update(['is_active'=>0]);
$blocked=package('00_preflight.sql'); check(end($blocked)['result']==='BLOCKED','Inactive required status was not blocked.');
try { package('01_apply.sql'); throw new RuntimeException('Apply ignored the blocked status.'); } catch(PDOException $e){check(str_contains($e->getMessage(),'schema_incompatible'),'Unexpected preflight failure.');}
check(!Schema::hasTable(IncompleteOutcomeGuard::CASES),'Blocked apply changed lifecycle schema.');
DB::table('result_statuses')->where('status_code','incomplete')->update(['is_active'=>1]);
$preflight=package('00_preflight.sql'); check(end($preflight)['result']==='READY','Preflight failed.');
check(package('01_apply.sql')[0]['result']==='APPLIED','Apply failed.');
$verify=package('02_verify.sql'); check(end($verify)['result']==='PASS','Verify failed.');
check(DB::table(IncompleteOutcomeGuard::CASES)->count()===0 && DB::table(IncompleteOutcomeGuard::EVENTS)->count()===0,'Partial-apply fixture must be empty.');
DB::statement('DROP TABLE student_course_incomplete_events');
DB::statement('ALTER TABLE student_course_incomplete_cases MODIFY deadline_at VARCHAR(19) NOT NULL');
try{package('01_apply.sql');throw new RuntimeException('Incompatible partial table was accepted.');}catch(PDOException $e){check(str_contains($e->getMessage(),'schema_incompatible'),'Unexpected incompatible partial error.');}
check(!Schema::hasTable(IncompleteOutcomeGuard::EVENTS),'Installer created a missing object before inspecting the incompatible existing table.');
DB::statement('ALTER TABLE student_course_incomplete_cases MODIFY deadline_at DATETIME NOT NULL');
check(package('01_apply.sql')[0]['result']==='RESUMED','Compatible partial installation did not resume.');
check(package('01_apply.sql')[0]['result']==='ALREADY_APPLIED','Reapplication failed.');
DB::statement('ALTER TABLE student_course_incomplete_cases RENAME COLUMN requirements TO incompatible_requirements');
$incompatible=package('02_verify.sql'); check(end($incompatible)['result']==='FAIL','Verify accepted an incompatible owned layout.');
try { package('01_apply.sql'); throw new RuntimeException('Incompatible owned partial layout was accepted.'); } catch(PDOException $e){check(str_contains($e->getMessage(),'schema_incompatible'),'Unexpected partial-layout failure.');}
DB::statement('ALTER TABLE student_course_incomplete_cases RENAME COLUMN incompatible_requirements TO requirements');
check(package('01_apply.sql')[0]['result']==='ALREADY_APPLIED','Compatible layout did not recover.');
check($before==DB::table('student_course_results')->orderBy('student_course_result_id')->get()->toArray(),'Upgrade changed existing results or announcements.');
foreach($triggers as $t)check(DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',$database)->where('TRIGGER_NAME',$t->TRIGGER_NAME)->value('ACTION_STATEMENT')===$t->ACTION_STATEMENT,'Existing revision trigger changed.');
check(DB::table('student_course_incomplete_cases')->count()===0,'Upgrade inferred historic I grants.');
file_put_contents($directory.'/schema-metadata.json', json_encode([
    'columns'=>DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA',$database)->whereIn('TABLE_NAME',[IncompleteOutcomeGuard::CASES,IncompleteOutcomeGuard::EVENTS])->get()->toArray(),
    'checks'=>DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA',$database)->whereIn('TABLE_NAME',[IncompleteOutcomeGuard::CASES,IncompleteOutcomeGuard::EVENTS])->get()->toArray(),
    'triggers'=>DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',$database)->whereIn('EVENT_OBJECT_TABLE',[IncompleteOutcomeGuard::CASES,IncompleteOutcomeGuard::EVENTS])->get()->toArray(),
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$isolation=DB::selectOne('SELECT @@tx_isolation AS isolation_level')->isolation_level;
check($isolation==='REPEATABLE-READ','This harness run did not exercise REPEATABLE READ.');
echo 'DATABASE | '.$identity['version'].' | '.$isolation."\nUPGRADE | PASS | populated states preserved; original revision triggers preserved; idempotent\n";

function concurrencySuite(int $base,string $label):array {
$first=context(($base+100)); CarbonImmutable::setTestNow('2026-10-18 12:00:00 UTC');
$race=compete($label.'-expire-expire',($base+100),$first['case']['incomplete_case_id'],'expire','expire');
check(count(array_filter($race,fn($r)=>$r['ok']&&$r['result']===true))===1,'Expiry was duplicated.');
check(DB::table(IncompleteOutcomeGuard::EVENTS)->where('incomplete_case_id',$first['case']['incomplete_case_id'])->count()===2,'Duplicate lifecycle events.');
check(IncompleteOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(($base+100))),'Terminal F does not match evidence.');
try { DB::table('student_course_registrations')->where('student_course_registration_id',($base+100))->delete(); throw new RuntimeException('Restrictive source FK did not protect history.'); } catch(Illuminate\Database\QueryException $e){check((int)($e->errorInfo[1]??0)===1451,'Unexpected source FK failure.');}
$next=context(($base+200)); compete($label.'-extension-expiry',($base+200),$next['case']['incomplete_case_id'],'extend','expire');
check(IncompleteOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(($base+200))),'Extension/expiry mismatch.');
$next=context(($base+300)); $race=compete($label.'-resolution-extension',($base+300),$next['case']['incomplete_case_id'],'resolve','extend-before');
check(count(array_filter($race,fn($r)=>$r['ok']))===1,'Competing stale revision was accepted.');
check(IncompleteOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(($base+300))),'Resolution/extension mismatch.');
$next=context(($base+350)); $race=compete($label.'-resolution-expiry',($base+350),$next['case']['incomplete_case_id'],'resolve','expire');
check(count(array_filter($race,fn($r)=>$r['ok']&&$r['result']!==false))===1,'Resolution and expiration both settled the same case.');
check(DB::table(IncompleteOutcomeGuard::EVENTS)->where('incomplete_case_id',$next['case']['incomplete_case_id'])->count()===2,'Resolution/expiry produced duplicate settlement events.');
check(IncompleteOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(($base+350))),'Resolution/expiry mismatch.');
$next=context(($base+400));
foreach(['theoretical'=>'submitted','practical'=>'approved'] as $part=>$status)DB::table('grade_part_approvals')->insert(['course_offering_id'=>($base+400),'component_type'=>$part,'status'=>$status,'submission_version'=>1,'submitted_by_user_id'=>1,'submitted_at'=>now()]);
compete($label.'-finalization-expiry',($base+400),$next['case']['incomplete_case_id'],'finalize','expire','offering');
check(CourseIncompleteCase::findOrFail($next['case']['incomplete_case_id'])->state==='expired','Expiry lost to finalization.');
check(IncompleteOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(($base+400))),'Finalization overwrote F settlement.');
check((int)DB::table('student_grade_components')->where('student_course_registration_id',($base+400))->count()===1,'Finalization invented marks.');
check((float)app(App\Services\GradeService::class)->calculateGpa(App\Models\Student::findOrFail(($base+400)),1,1)['gpa']===0.0,'Expired F absent marks did not enter GPA.');
context(($base+500),false); compete($label.'-grant-ordinary-save',($base+500),0,'grant','ordinary-save','offering');
check(IncompleteOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(($base+500))),'Concurrent ordinary entry overwrote an I grant.');
check(DB::table(IncompleteOutcomeGuard::CASES)->where('student_course_registration_id',($base+500))->count()===1,'Concurrent grant duplicated.');
$z=context(($base+600),true,'Z','2026-10-01 10:00:00'); CarbonImmutable::setTestNow('2026-10-18 12:00:00 UTC');
app(IncompleteResultService::class)->expire($z['case']['incomplete_case_id']);
foreach(['theoretical'=>'submitted','practical'=>'approved']as$part=>$status)DB::table('grade_part_approvals')->insert(['course_offering_id'=>($base+600),'component_type'=>$part,'status'=>$status,'submission_version'=>1,'submitted_by_user_id'=>1,'submitted_at'=>now()]);
$approval=DB::table('grade_part_approvals')->where('course_offering_id',($base+600))->where('component_type','theoretical')->value('grade_part_approval_id');
app(GradePartWorkflowService::class)->review(User::findOrFail(1),(int)$approval,'approve',null);
check(IncompleteOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(($base+600))),'Finalization overwrote Z.');
check((int)DB::table('student_course_results')->where('student_course_registration_id',($base+600))->value('result_status_id')===3,'Z was not persisted.');
check(DB::table('student_course_results')->where('student_course_registration_id',($base+600))->value('result_announced_at')==='2026-10-01 10:00:00','Expiration restarted announcement timestamp.');
echo "Z_SETTLEMENT | $label | PASS | preserved announcement and completed practical; canonical finalization\n";

foreach(['drop','withdraw','cancel'] as $offset=>$transition){
    $id=$base+700+$offset; context($id,false);
    if($transition==='cancel')DB::table('course_offerings')->where('course_offering_id',$id)->update(['status'=>'closed']);
    $eventCount=DB::table(IncompleteOutcomeGuard::EVENTS)->count();
    $race=compete($label.'-grant-'.$transition,$id,0,'grant',$transition);
    check(count(array_filter($race,fn($r)=>$r['ok']))===1,'Grant and unsupported transition both succeeded.');
    $case=CourseIncompleteCase::where('student_course_registration_id',$id)->first();
    $registration=StudentCourseRegistration::findOrFail($id);
    if($case){
        check($registration->registrationStatus->status_code==='registered','Owned registration changed status.');
        check(DB::table(IncompleteOutcomeGuard::EVENTS)->count()===$eventCount+1,'Winning grant did not append exactly one event.');
        check(IncompleteOutcomeGuard::preservesOutcome($registration),'Grant/transition lost ownership evidence.');
    }else{
        check($registration->registrationStatus->status_code===(['drop'=>'dropped','withdraw'=>'withdrawn','cancel'=>'cancelled'][$transition]),'Unexpected transition winner.');
        check(DB::table(IncompleteOutcomeGuard::EVENTS)->count()===$eventCount,'Losing grant left an event.');
        check(!DB::table('student_course_results')->where('student_course_registration_id',$id)->exists(),'Losing grant left a result.');
    }
}

return $first;
}

if(($config['scenario']??'all')==='ordered'){
    orderedDropRace(720,true,'fallback');orderedDropRace(721,false,'fallback');
    context(1720,false);context(1721,false);Tests\Support\IncompleteCatalogFixture::install();
    orderedDropRace(1720,true,'installed');orderedDropRace(1721,false,'installed');
    echo "ORDERED_ONLY | PASS\n";file_put_contents($directory.'/verification-passed',$config['marker']);exit;
}
$first=concurrencySuite(0,'fallback');
orderedDropRace(720,true,'fallback');orderedDropRace(721,false,'fallback');
foreach([1100,1200,1300,1350,1400,1500,1600,1700,1701,1702,1720,1721,1900,1901] as $id)context($id,false);
Tests\Support\IncompleteCatalogFixture::install();
check(App\Services\AcademicPlanContext::installed(),'Installed path was not enabled.');
$revisionBefore=app(App\Services\AcademicCatalogTransaction::class)->revision();
concurrencySuite(1000,'installed');
orderedDropRace(1720,true,'installed');orderedDropRace(1721,false,'installed');
check((int)app(App\Services\AcademicCatalogTransaction::class)->revision()>(int)$revisionBefore,'Real plan/catalog triggers did not advance the revision.');
echo "INSTALLED_CATALOG | PASS | approved plan assignments, real reference revision triggers and catalog-row contention\n";
Tests\Support\IncompleteMariaDbReviewChecks::incompatibleDefinitions((int)$first['case']['incomplete_case_id']);
Tests\Support\IncompleteMariaDbReviewChecks::expirationProgress($directory);
try { package('03_rollback.sql'); throw new RuntimeException('Rollback did not refuse.'); } catch(PDOException $e){check(str_contains($e->getMessage(),'rollback_refused'),'Unexpected rollback failure.');}
$event=DB::table(IncompleteOutcomeGuard::EVENTS)->first();
try { DB::table(IncompleteOutcomeGuard::EVENTS)->where('incomplete_event_id',$event->incomplete_event_id)->update(['action'=>'resolved']); throw new RuntimeException('Event update succeeded.'); } catch(Illuminate\Database\QueryException $e){check(str_contains($e->getMessage(),'append_only'),'Unexpected event guard failure.');}
try { DB::table(IncompleteOutcomeGuard::CASES)->where('incomplete_case_id',$first['case']['incomplete_case_id'])->update(['state'=>'active','revision'=>3]); throw new RuntimeException('Terminal history was reopened.'); } catch(Illuminate\Database\QueryException $e){check(str_contains($e->getMessage(),'history_immutable'),'Unexpected case-history failure.');}
echo "CONSTRAINTS | PASS | append-only events; restrictive FKs; rollback refusal\nOVERALL | PASS\n";
file_put_contents($directory.'/verification-passed',$config['marker']);
