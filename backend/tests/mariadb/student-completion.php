<?php

require __DIR__.'/CatalogEnvironment.php';
use App\Models\{Student,User};
use App\Services\{AcademicCatalogTransaction,AcademicPlanContext,GradeService};
use App\Services\StudentCompletion\{CompletionData,CompletionProjection,StudentCompletionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$environment=new CatalogEnvironment;$app=$environment->laravel();$p=DB::connection()->getPdo();
set_exception_handler(function(Throwable $e): void {fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$check=static function($ok,$message): void {if(!$ok)throw new RuntimeException($message);};
$revision=fn()=>app(AcademicCatalogTransaction::class)->revision();$actor=User::findOrFail(1);$service=app(StudentCompletionService::class);
if(($argv[1]??'')==='fixture') {
    $p->exec('ALTER TABLE students ADD COLUMN father_name VARCHAR(100) NULL, ADD COLUMN enrollment_date DATE NULL, ADD UNIQUE(student_number)');
    // Create a new synthetic program instead of bypassing a used program's real identity lock.
    $p->exec("INSERT INTO academic_programs(academic_program_id,department_id,program_code,program_name,total_credit_hours) VALUES(900,1,'P1','Synthetic completion program',3)");
    foreach(['university','college','department'] as $scope)foreach(['mandatory','elective'] as $type)
        DB::table('academic_requirement_groups')->insert(['academic_program_id'=>900,'group_code'=>'SYN-'.$scope.'-'.$type,'group_name'=>'Synthetic requirement',
            'requirement_scope'=>$scope,'requirement_type'=>$type,'required_credit_hours'=>$scope==='university'&&$type==='mandatory'?3:0]);
    $membership=DB::table('program_courses')->insertGetId(['academic_program_id'=>900,'course_id'=>1,'course_type'=>'mandatory','academic_level_id'=>1,'recommended_semester_id'=>1],'program_course_id');
    $group=DB::table('academic_requirement_groups')->where('academic_program_id',900)->where('requirement_scope','university')->where('requirement_type','mandatory')->value('requirement_group_id');
    DB::table('program_course_requirement_groups')->insert(['program_course_id'=>$membership,'requirement_group_id'=>$group]);
    $p->exec("UPDATE students SET student_number=CONCAT('SYN-',student_id),first_name='Synthetic',last_name='Person',enrollment_date='2023-12-10',student_status_id=1,current_academic_level_id=1");
    $p->exec("UPDATE students SET student_number='S1',first_name='Test',last_name='Student',father_name='Parent',academic_program_id=900 WHERE student_id=1");
    $p->exec("INSERT INTO student_statuses(student_status_id,status_code,status_name) VALUES(1,'active','Active'),(17,'frozen','Discontinued')");
    $p->exec("UPDATE academic_years SET year_name='2023-2024',start_date='2023-09-01',end_date='2024-08-31' WHERE academic_year_id=1");
    $p->exec('CREATE TABLE student_academic_terms(student_academic_term_id INT AUTO_INCREMENT PRIMARY KEY,student_id INT,academic_year_id INT,semester_id INT,is_finalized TINYINT,total_registered_hours INT,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
    foreach(['students.view','students.manage','grades.view'] as $code) {
        $id=DB::table('permissions')->insertGetId(['module_id'=>1,'permission_code'=>$code],'permission_id');DB::table('role_permissions')->insert(['role_id'=>1,'permission_id'=>$id]);
    }
    echo "PASS synthetic completion fixture prepared before plan guards\n";exit;
}
if(($argv[1]??'')==='worker') {
    echo json_encode(['ready'=>(int)$p->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n";flush();
    $job=json_decode(fgets(STDIN),true,flags:JSON_THROW_ON_ERROR);echo "{\"entered\":true}\n";flush();
    try {$r=$service->apply($actor,$job['bundle'],$job['review'],$job['hash'],$job['id'],$job['revision']);echo json_encode(['ok'=>true,'result'=>$r])."\n";}
    catch(Throwable $e){echo json_encode(['ok'=>false,'code'=>$e->errorCode??get_class($e),'message'=>$e->getMessage()])."\n";}
    exit;
}
$check(($argv[1]??'')==='run','Unknown isolated test action');echo 'SERVER_VERSION='.$p->query('SELECT VERSION()')->fetchColumn()."\n";
\Tests\Support\CompletionRegistrationFixture::prepare();
foreach(['00_preflight.sql','01_apply.sql','02_verify.sql'] as $file) {$r=$environment->package($p,$file,null,'course-result-symbols-phase1');$last=end($r);$check(in_array($last['result']??null,['READY','APPLIED','PASS'],true),json_encode($r));}
$bundle=\Tests\Support\StudentCompletionFixture::bundle();
$p->exec("INSERT INTO course_offerings(course_offering_id,course_id,academic_program_id,academic_year_id,semester_id,status) VALUES(10001,1,900,1,1,'closed')");
$p->exec('INSERT INTO student_course_registrations(student_course_registration_id,student_id,course_offering_id,registration_status_id) VALUES(10001,1,10001,2)');
$p->exec('INSERT INTO student_academic_terms(student_id,academic_year_id,semester_id,is_finalized,total_registered_hours) VALUES(1,1,1,1,3)');
$p->exec("INSERT INTO grade_approvals(course_offering_id,approval_status_id) VALUES(10001,1)");
$p->exec('INSERT INTO student_course_results(student_course_registration_id,result_status_id,final_mark) SELECT 10001,result_status_id,NULL FROM result_statuses WHERE status_code=\'failed\'');
$p->exec('UPDATE student_course_registrations SET result_status_id=(SELECT result_status_id FROM result_statuses WHERE status_code=\'failed\') WHERE student_course_registration_id=10001');
$read=static function($pipe): array {$line=fgets($pipe);if($line===false)throw new RuntimeException('Worker ended unexpectedly');return json_decode($line,true,flags:JSON_THROW_ON_ERROR);};
$race=function(string $label,array $b,callable $mutate)use($environment,$p,$check,$read,$service,$actor,$revision): void {
    $preview=$service->preview($actor,$b);$check(count($preview['review']['proposals'])===1,'Expected one ready proposal: '.$label);
    DB::beginTransaction();DB::table('academic_catalog_control')->where('control_id',1)->lockForUpdate()->first();$mutate();
    $monitor=$environment->connect(true);$process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/student-worker.stderr','a']],$pipes);
    $failure=null;
    try {
        $worker=$read($pipes[1]);fwrite($pipes[0],json_encode(['bundle'=>$b,'review'=>$preview['review'],'hash'=>$preview['review_hash'],'id'=>(string)Str::uuid(),'revision'=>$preview['review']['revision']])."\n");fflush($pipes[0]);$check($read($pipes[1])['entered'],'Worker did not enter');
        $waiting=false;$until=microtime(true)+20;
        do {$q=$monitor->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$worker['ready']]);$sql=strtolower((string)$q->fetchColumn());if(str_contains($sql,'academic_catalog_control')&&str_contains($sql,'for update')){$waiting=true;break;}usleep(20000);}while(microtime(true)<$until);
        $check($waiting,'Independent worker did not wait for the real catalog lock: '.$label);DB::commit();$r=$read($pipes[1]);
        $check(!$r['ok']&&$r['code']==='student_completion_stale','Stale concurrent '.$label.' accepted: '.json_encode($r));
        echo 'PASS observed independent catalog wait / controlled stale / no completion write: '.$label."\n";
    }catch(Throwable $e){$failure=$e;}finally{if(DB::transactionLevel())DB::rollBack();fclose($pipes[0]);fclose($pipes[1]);$exit=proc_close($process);}
    if($failure)throw $failure;$check($exit===0,'Worker failed');
};
$race('status',$bundle,fn()=>DB::table('students')->where('student_id',1)->update(['student_status_id'=>17]));
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('students')->where('student_id',1)->update(['student_status_id'=>1]));
$race('identity-field',$bundle,fn()=>DB::table('students')->where('student_id',1)->update(['father_name'=>'Changed concurrent parent']));
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('students')->where('student_id',1)->update(['father_name'=>'Parent']));
$race('program',$bundle,fn()=>DB::table('students')->where('student_id',1)->update(['academic_program_id'=>2]));
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('students')->where('student_id',1)->update(['academic_program_id'=>900]));
$race('registration',$bundle,fn()=>DB::table('student_course_registrations')->where('student_course_registration_id',10001)->update(['registration_date'=>'2023-12-11']));
$race('result-evidence',$bundle,fn()=>DB::table('student_course_results')->where('student_course_registration_id',10001)->update(['calculated_at'=>now()]));
$race('curriculum',$bundle,fn()=>DB::table('academic_requirement_groups')->where('requirement_group_id',1)->update(['group_name'=>'Reviewed label changed']));
$new=\Tests\Support\StudentCompletionFixture::bundle('NEW');
$race('concurrent-student-creation',$new,fn()=>Student::create(['student_number'=>'NEW','first_name'=>'Test','last_name'=>'Student','father_name'=>'Parent','academic_program_id'=>900,'current_academic_level_id'=>1,'student_status_id'=>1,'enrollment_date'=>'2023-12-10']));
$check(Student::where('student_number','NEW')->count()===1,'Concurrent create duplicated student');
$snapshot=function()use($p): array {$out=[];foreach(['student_course_registrations','student_course_results','student_grade_components','grade_components','grade_approvals','grade_part_approvals','grade_audit_logs','student_course_incomplete_cases','student_course_incomplete_events','student_progression_decisions','student_graduation_decisions','academic_plan_versions','program_courses','academic_requirement_groups'] as $t)$out[$t]=$p->query("SELECT * FROM $t ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);return $out;};
$before=$snapshot();$grades=app(GradeService::class);$student=Student::findOrFail(1);$gpa=$grades->calculateGpa($student,1,1);$transcript=$grades->getTranscript($student);
$preview=$service->preview($actor,$bundle);$id=(string)Str::uuid();$result=$service->apply($actor,$bundle,$preview['review'],$preview['review_hash'],$id,$revision());
$check($result['status']==='COMPLETE'&&Student::findOrFail(1)->student_status_id===17,'Ready status not applied');
$logs=DB::table('user_activity_logs')->count();$check($result===$service->apply($actor,$bundle,$preview['review'],$preview['review_hash'],$id,'0'),'Receipt replay differs');$check($logs===DB::table('user_activity_logs')->count(),'Pure replay wrote audit');
$check($before===$snapshot(),'Historical contents/pins/announcements/approvals/I/plan changed');
$check($gpa===$grades->calculateGpa($student,1,1)&&$transcript===$grades->getTranscript($student),'Canonical GPA/transcript changed');
$verified=$service->verify($actor,$bundle,$id);$check($verified['existing_academic_evidence_matches']&&$verified['students'][0]['current_state_matches'],'Current verification failed');
echo "PASS ready status / durable pure replay / exact history / real GPA + transcript / read-only verification\n";
$plans=app(\App\Services\AcademicPlanWorkflow::class);$confirm=fn()=>['revision'=>$revision(),'confirmed'=>true];
$source=$plans->fixTransition($actor,900,$confirm())['current_version_id'];
$plans->copy($actor,900,(int)$source,['revision'=>$revision(),'label'=>'Synthetic new admissions']);
$version=(int)DB::table('academic_plan_versions')->where('academic_program_id',900)->where('status','draft')->value('academic_plan_version_id');
$plans->approve($actor,900,$version,$confirm());$plans->setDefault($actor,900,$version,$confirm());
$historical=\Tests\Support\StudentCompletionFixture::bundle('HISTORICAL');$r=$service->preview($actor,$historical);$operation=(string)Str::uuid();
$result=$service->apply($actor,$historical,$r['review'],$r['review_hash'],$operation,$revision());$created=Student::where('student_number','HISTORICAL')->sole();
$check($result['status']==='COMPLETE'&&AcademicPlanContext::forStudent($created)->versionId===(int)$source,'Historical reference not pinned');
$check(DB::table('student_academic_plan_assignments')->where('student_id',$created->getKey())->count()===2,'DB-trigger assignment history was removed');
$check((int)DB::table('academic_programs')->where('academic_program_id',900)->value('default_academic_plan_version_id')===$version,'Default was changed');
$check($service->verify($actor,$historical,$operation)['existing_academic_evidence_matches'],'Historical admission rewrote existing material');
echo "PASS installed real admission trigger / atomic historical reference / retained default assignment history / default unchanged\n";
// Two independent executions of ONE immutable UUID: neither is allowed to create a duplicate.
$same=\Tests\Support\StudentCompletionFixture::bundle('TWO-WORKERS');$review=$service->preview($actor,$same);$sameId=(string)Str::uuid();
DB::beginTransaction();DB::table('academic_catalog_control')->where('control_id',1)->lockForUpdate()->first();$workers=[];$monitor=$environment->connect(true);
try {
    foreach([1,2] as $index) {
        $process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/same-uuid.stderr','a']],$pipes);
        $identity=$read($pipes[1]);fwrite($pipes[0],json_encode(['bundle'=>$same,'review'=>$review['review'],'hash'=>$review['review_hash'],'id'=>$sameId,'revision'=>$review['review']['revision']])."\n");fflush($pipes[0]);$check($read($pipes[1])['entered'],'Same-UUID worker did not enter');
        $workers[]=['process'=>$process,'pipes'=>$pipes,'connection'=>$identity['ready']];
    }
    $until=microtime(true)+20;$waiting=[];
    do {foreach($workers as $i=>$w){$q=$monitor->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$w['connection']]);$sql=strtolower((string)$q->fetchColumn());if(str_contains($sql,'academic_catalog_control')&&str_contains($sql,'for update'))$waiting[$i]=true;}if(count($waiting)===2)break;usleep(20000);}while(microtime(true)<$until);
    $check(count($waiting)===2,'Two real catalog lock waiters were not observed');DB::commit();
    $results=[];foreach($workers as $w){$response=$read($w['pipes'][1]);$check($response['ok'],'Same UUID execution failed: '.json_encode($response));$results[]=$response['result']['status'];}
    $check(in_array('COMPLETE',$results,true),'Neither execution completed');$check(Student::where('student_number','TWO-WORKERS')->count()===1,'Concurrent UUID created duplicate student');
} finally {
    if(DB::transactionLevel())DB::rollBack();foreach($workers as $w){fclose($w['pipes'][0]);fclose($w['pipes'][1]);proc_close($w['process']);}
}
echo "PASS same UUID / two independent observed catalog waiters / one student and one canonical admission\n";
