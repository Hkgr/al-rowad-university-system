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
    // Nonempty historical references for the three conflicting academic identities.
    // Fixture data only, before installing fixed-plan guards; no source SQL import.
    DB::table('academic_programs')->insert(['academic_program_id'=>901,'department_id'=>1,'program_code'=>'SYN-OLD-IDENTITIES','program_name'=>'Synthetic existing identity history','total_credit_hours'=>19]);
    $group=DB::table('academic_requirement_groups')->insertGetId(['academic_program_id'=>901,'group_code'=>'SYN-OLD-IDENTITY-G','group_name'=>'Synthetic existing identity requirement',
        'requirement_scope'=>'department','requirement_type'=>'mandatory','required_credit_hours'=>17],'requirement_group_id');
    $lifeGroup=DB::table('academic_requirement_groups')->insertGetId(['academic_program_id'=>901,'group_code'=>'SYN-OLD-LIFE-G','group_name'=>'Synthetic existing university elective',
        'requirement_scope'=>'university','requirement_type'=>'elective','required_credit_hours'=>2],'requirement_group_id');
    DB::table('course_prerequisites')->insert(['course_id'=>856,'prerequisite_course_id'=>857,'minimum_result_status_id'=>null]);
    DB::table('course_prerequisites')->insert(['course_id'=>778,'prerequisite_course_id'=>860,'minimum_result_status_id'=>null]);
    foreach([856,857,859,409,778,860] as $id) {
        $membership=DB::table('program_courses')->insertGetId(['academic_program_id'=>901,'course_id'=>$id,'course_type'=>$id===409?'elective':'mandatory','academic_level_id'=>2,'recommended_semester_id'=>1],'program_course_id');
        DB::table('program_course_requirement_groups')->insert(['program_course_id'=>$membership,'requirement_group_id'=>$id===409?$lifeGroup:$group]);
    }
    DB::table('students')->insert(['student_id'=>1901,'academic_program_id'=>901,'student_number'=>'SYN-OLD-IDENTITIES']);
    foreach([856=>['CE211','مقاومة المواد (1)',3],857=>['CE212','علم التحريك- ديناميك',4],859=>['CE222','تصميم وإنشاء مباني',4],
        409=>['RUS211','مدخل إلى الحياة الجامعية',2],778=>['CE213','مواد البناء',3],860=>['CE223','ميكانيك الموائع1 (هيدروليك1)',3]] as $id=>[$code,$name,$credits]) {
        $course=DB::table('courses')->where('course_id',$id)->sole();
        $check($course->course_code===$code&&$course->course_name===$name&&(int)$course->credit_hours===$credits,'Conflicting fixture identity differs');
        DB::table('course_offerings')->insert(['course_offering_id'=>11000+$id,'academic_program_id'=>901,'course_id'=>$id,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed','result_announced_at'=>'2025-04-03 12:00:00']);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>11000+$id,'student_id'=>1901,'course_offering_id'=>11000+$id,'registration_status_id'=>2]);
        DB::table('student_course_results')->insert(['student_course_registration_id'=>11000+$id,'result_status_id'=>1,'final_mark'=>80,'theoretical_total'=>80]);
        DB::table('grade_approvals')->insert(['course_offering_id'=>11000+$id,'approval_status_id'=>1]);
    }
    \Tests\Support\PersistedFollowupCatalogFixture::seed();
    echo "PASS synthetic completion fixture prepared before plan guards\n";exit;
}
if(($argv[1]??'')==='worker') {
    echo json_encode(['ready'=>(int)$p->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n";flush();
    $job=json_decode(fgets(STDIN),true,flags:JSON_THROW_ON_ERROR);echo "{\"entered\":true}\n";flush();
    if(isset($job['manifest']))$job['bundle']=app(\App\Services\StudentCompletion\CompletionSources::class)->load($job['manifest'],$job['manifest_hash']);
    if(isset($job['pause_after_stage'])) {
        $stages=0;
        DB::listen(function($query)use(&$stages,$job){
            if(str_starts_with(strtolower($query->sql),'insert into `user_activity_logs`')&&in_array('student_completion.followup_catalog_program',$query->bindings,true)&&++$stages===$job['pause_after_stage']) {
                DB::afterCommit(function()use($stages){echo json_encode(['stage_committed'=>$stages])."\n";flush();if(trim((string)fgets(STDIN))!=='continue')throw new RuntimeException('Synthetic process interrupted after durable stage');});
            }
        });
    }
    try {$writer=($job['kind']??null)==='catalog'?app(\App\Services\CatalogCompletion\FollowupCatalogService::class):$service;
        $r=$writer->apply($actor,$job['bundle'],$job['review'],$job['hash'],$job['id'],$job['revision']);echo json_encode(['ok'=>true,'result'=>$r])."\n";}
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
$approved=DB::table('approval_statuses')->where('status_code','approved')->value('approval_status_id')
    ?? DB::table('approval_statuses')->insertGetId(['status_code'=>'approved'],'approval_status_id');
DB::table('grade_approvals')->insert(['course_offering_id'=>10001,'approval_status_id'=>$approved]);
$p->exec('INSERT INTO student_course_results(student_course_registration_id,result_status_id,final_mark) SELECT 10001,result_status_id,NULL FROM result_statuses WHERE status_code=\'failed\'');
$p->exec('UPDATE student_course_registrations SET result_status_id=(SELECT result_status_id FROM result_statuses WHERE status_code=\'failed\') WHERE student_course_registration_id=10001');
$read=static function($pipe): array {$line=fgets($pipe);if($line===false)throw new RuntimeException('Worker ended unexpectedly');return json_decode($line,true,flags:JSON_THROW_ON_ERROR);};
$race=function(string $label,array $b,callable $mutate)use($environment,$p,$check,$read,$service,$actor,$revision): void {
    $preview=$service->preview($actor,$b);$check(count($preview['review']['proposals'])===1,'Expected one ready proposal: '.$label.' '.json_encode(collect($preview['students'])->firstWhere('number','S1')['status_change']??null));
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

// Administrative E authority bypasses examination proof, never genuine ongoing academic work.
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('students')->where('student_id',1)->update(['student_status_id'=>1]));
$admin=$bundle;$admin['config']['resolution_policy']='administration_e_then_roster_c';
$cells=['A'=>'S1','B'=>'Test Student','C'=>null,'D'=>'Synthetic review','E'=>'منقطع Test'];
$admin['sources']['conflicts']=['sha256'=>str_repeat('f',64)];
$admin['conflicts']=[['number'=>'S1','solution'=>$cells['E'],'cells'=>$cells,'reference'=>['file'=>'conflicts','sheet'=>'Synthetic','row'=>6]]];
$records=[];foreach([...$admin['identities'],...$admin['observations']] as $row)$records[]=['reference'=>$row['reference'],'number'=>$row['number'],
    'label'=>$row['full_name']??$row['name'],'program'=>$row['program'],'college'=>$row['college']??null,'father_name'=>$row['father_name']??null,'target'=>'S1'];
$admin['config']['resolutions']=[['review_row'=>6,'expected_cells'=>$cells,'targets'=>[['number'=>'S1','display_name'=>'Test Student','fields'=>[
    'status'=>['authority'=>'E','passage'=>'منقطع','value'=>'frozen'],'first_name'=>['authority'=>'E','passage'=>'Test','value'=>'Test']]]],'records'=>$records]];
$admin=(new \App\Services\StudentCompletion\CompletionResolutions)->compile($admin);
$race('administrative-ongoing-registration',$admin,function(): void {
    DB::table('course_offerings')->insert(['course_offering_id'=>20001,'course_id'=>1,'academic_program_id'=>900,'academic_year_id'=>1,'semester_id'=>1,'status'=>'OPEN']);
    DB::table('student_course_registrations')->insert(['student_course_registration_id'=>20001,'student_id'=>1,'course_offering_id'=>20001,'registration_status_id'=>2]);
});
$row=collect($service->preview($actor,$admin)['students'])->firstWhere('number','S1');
$check(!$row['status_change']['ready']&&$row['status_change']['registration_blockers']===[['registration_id'=>20001,'offering_id'=>20001,'reason'=>'offering_not_closed']],
    'Administrative authority bypassed the newly ongoing registration');
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('students')->where('student_id',1)->update(['first_name'=>'Wrong']));
$before=$snapshot();$review=$service->preview($actor,$admin);$proposal=collect($review['review']['proposals'])->firstWhere('number','S1');
$check($proposal['actions']===['correct_identity'],'Blocked status leaked into an identity-only proposal');
$result=$service->apply($actor,$admin,$review['review'],$review['review_hash'],(string)Str::uuid(),$revision());
$check(Student::findOrFail(1)->student_status_id===1&&Student::findOrFail(1)->first_name==='Test','Identity-only write changed status');
$check($before===$snapshot(),'Identity-only correction rewrote academic history');
echo "PASS administrative E / concurrent new open registration without result / explicit blocker references / identity-only history preservation\n";

// The new missing-only administrative defaults run through the installed path too.
$follow=\Tests\Support\StudentCompletionFixture::bundle('FOLLOWUP-NEW');
$follow['identities'][0]['status']=null;$follow['identities'][0]['enrollment_date']=null;
$decisions=[['decision_id'=>'status','field'=>'status_default','canonical_number'=>null,'approved_value'=>'active','authority'=>'USER_APPROVED','_row'=>2],
    ['decision_id'=>'date','field'=>'enrollment_date_fallback','canonical_number'=>null,'approved_value'=>'SEPTEMBER_1_OF_EARLIEST_EVIDENCED_EXAM_YEAR','authority'=>'USER_APPROVED','_row'=>3]];
$follow['sources']['handoff']=['sha256'=>str_repeat('a',64)];
$follow['config']['followup']=['policy'=>'approved_missing_students_2026_10','decision_ids'=>['status','date'],'owner_record_ids'=>[],
    'table_hashes'=>['Decisions'=>CompletionData::hash($decisions),'ExamOwners'=>CompletionData::hash([])]];
$follow=(new \App\Services\StudentCompletion\CompletionFollowup)->compile($follow,['Decisions'=>$decisions,'ExamOwners'=>[]]);
$raceDraft=$plans->copy($actor,900,$version,['revision'=>$revision(),'label'=>'Synthetic concurrent draft']);
$raceDraftId=(int)$raceDraft['version']->getKey();
$race('followup-plan-content',$follow,fn()=>DB::table('academic_requirement_groups')->where('academic_program_id',900)->where('academic_plan_version_id',$raceDraftId)->update(['group_name'=>'Concurrent editable draft label']));
$race('followup-create',$follow,fn()=>Student::create(['student_number'=>'FOLLOWUP-NEW','first_name'=>'Test','last_name'=>'Student','father_name'=>'Parent',
    'academic_program_id'=>900,'current_academic_level_id'=>1,'student_status_id'=>1,'enrollment_date'=>'2023-09-01']));
echo "PASS missing-only approved date/status / installed plan-content and student-creation races\n";

// Four synthetic new contexts; never import a source workbook or university snapshot.
$college=DB::table('colleges')->where('college_id',1)->value('college_code');$programs=[];
app(AcademicCatalogTransaction::class)->run(function()use(&$programs,$college) {
    foreach(['engineering_civil','engineering_survey','engineering_drawing','environment'] as $i=>$key) {
        $department=DB::table('departments')->insertGetId(['college_id'=>1,'department_code'=>'SYN-FOLLOWUP-'.($i+1),'department_name'=>'Synthetic follow-up '.($i+1),'is_active'=>1],'department_id');
        $groups=[];foreach(['university','college','department'] as $scope)foreach(['mandatory','elective'] as $type)$groups[]=['requirement_scope'=>$scope,'requirement_type'=>$type,
            'required_credit_hours'=>$scope==='college'&&$type==='mandatory'?3:0,'is_active'=>true];
        $programs[]=['key'=>$key,'department_id'=>$department,'college_code'=>$college,'program_code'=>'SYN-FOLLOWUP-'.($i+1),'program_name'=>'Synthetic approved '.($i+1),
            'degree_level'=>'Diploma','duration_years'=>2,'total_credit_hours'=>3,'blockers'=>[],'requirements'=>['total_credit_hours'=>3,'groups'=>$groups],
            'courses'=>[['record_id'=>'synthetic:'.$i,'system_code'=>'SYN-FU-SHARED','course_name'=>'Synthetic shared follow-up origin','credit_hours'=>3,'requirement_scope'=>'college','course_type'=>'mandatory',
                'academic_level_id'=>1,'recommended_semester_id'=>1,'prerequisite_codes'=>[],'existing_prerequisites'=>[]]]];
    }
});
$catalogBundle=['contract'=>'student-completion.sources.v3','catalog_followup'=>['contract'=>'approved-catalog.followup.v1','programs'=>$programs,'historical_approval_claimed'=>false]];
$catalogBundle['catalog_followup']['hash']=CompletionData::hash($catalogBundle['catalog_followup']);$catalog=app(\App\Services\CatalogCompletion\FollowupCatalogService::class);
$review=$catalog->preview($actor,$catalogBundle);$check($review['status']==='READY','Synthetic catalog follow-up not ready');
// A real independent connection waits behind the same catalog root; changing a plan invalidates its reviewed write.
DB::beginTransaction();DB::table('academic_catalog_control')->where('control_id',1)->lockForUpdate()->first();
DB::table('departments')->where('department_id',$programs[0]['department_id'])->update(['description'=>'Concurrent synthetic context']);
$process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/catalog-followup.stderr','a']],$pipes);
try {
    $identity=$read($pipes[1]);fwrite($pipes[0],json_encode(['kind'=>'catalog','bundle'=>$catalogBundle,'review'=>$review['review'],'hash'=>$review['review_hash'],'id'=>(string)Str::uuid(),'revision'=>$review['review']['revision']])."\n");fflush($pipes[0]);$check($read($pipes[1])['entered'],'Catalog worker did not enter');
    $monitor=$environment->connect(true);$waiting=false;$until=microtime(true)+20;
    do{$q=$monitor->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$identity['ready']]);$sql=strtolower((string)$q->fetchColumn());if(str_contains($sql,'academic_catalog_control')&&str_contains($sql,'for update')){$waiting=true;break;}usleep(20000);}while(microtime(true)<$until);
    $check($waiting,'Catalog follow-up worker did not wait');DB::commit();$r=$read($pipes[1]);$check(!$r['ok']&&$r['code']==='catalog_followup_stale','Catalog follow-up accepted a stale review');
}finally{if(DB::transactionLevel())DB::rollBack();fclose($pipes[0]);fclose($pipes[1]);proc_close($process);}
$r=$catalog->preview($actor,$catalogBundle);$op=(string)Str::uuid();$before=$snapshot();
$applied=$catalog->apply($actor,$catalogBundle,$r['review'],$r['review_hash'],$op,$r['review']['revision']);
$check($applied['status']==='COMPLETE','Four synthetic catalog contexts failed: '.json_encode($applied));
$check($catalog->verify($actor,$catalogBundle)['status']==='COMPLETE','Catalog publication verification failed');
$check($applied===$catalog->apply($actor,$catalogBundle,$r['review'],$r['review_hash'],$op,'0'),'Catalog replay differs');
$after=$snapshot();foreach(['academic_plan_versions','program_courses','academic_requirement_groups'] as $table)unset($before[$table],$after[$table]);$check($before===$after,'Catalog follow-up changed academic outcomes');
echo "PASS catalog follow-up / observed independent wait / stale rejected / four canonical approvals / shared origin / historical contents / pure replay\n";

$affairsId=DB::table('users')->insertGetId(['username'=>'synthetic-affairs-only','account_status_id'=>1],'user_id');
$roleId=DB::table('roles')->insertGetId(['role_code'=>'student_affairs'],'role_id');DB::table('user_roles')->insert(['user_id'=>$affairsId,'role_id'=>$roleId]);
foreach(['students.view','students.manage','grades.view'] as $code)DB::table('role_permissions')->insert(['role_id'=>$roleId,'permission_id'=>DB::table('permissions')->where('permission_code',$code)->value('permission_id')]);
$targetProgram=\App\Models\AcademicProgram::where('program_code',$programs[0]['program_code'])->sole();
DB::table('user_access_scopes')->insert(['user_id'=>$affairsId,'scope_type'=>'program','scope_id'=>$targetProgram->getKey()]);$affairs=User::findOrFail($affairsId);
$admit=\Tests\Support\StudentCompletionFixture::bundle('SYN-AFFAIRS-ADMISSION');$admit['contract']='student-completion.sources.v3';$admit['catalog_followup']=$catalogBundle['catalog_followup'];
$admit['config']['program_aliases']['Synthetic program']=$targetProgram->program_code;$admit['identities'][0]['college']=DB::table('colleges')->where('college_id',1)->value('college_name');
$check(!$affairs->isSuperAdmin()&&!$affairs->hasPermission(\App\Support\ScientificProgramAccess::VIEW),'Synthetic affairs operator has scientific authority');
$r=$service->preview($affairs,$admit);$check($r['counts']['ready_create']===1,'Scoped Student Affairs preview rejected publication');
$result=$service->apply($affairs,$admit,$r['review'],$r['review_hash'],(string)Str::uuid(),$r['review']['revision']);
$check($result['status']==='COMPLETE'&&Student::where('student_number','SYN-AFFAIRS-ADMISSION')->count()===1,'Scoped Student Affairs admission failed');
try{$plans->version($affairs,(int)$targetProgram->getKey(),(int)$targetProgram->default_academic_plan_version_id);throw new RuntimeException('Affairs acquired scientific browsing');}
catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Wrong scientific authorization denial');}
echo "PASS non-super-admin Student Affairs-only admission / installed assignment trigger / no scientific management capability\n";

// A barrier AFTER commit proves a different connection's epoch cannot be adopted
// as this operation's next revision. No production service contains a test hook.
$catalogCase=function(string $prefix)use($catalogBundle,$college): array {
    $copy=$catalogBundle;
    app(AcademicCatalogTransaction::class)->run(function()use(&$copy,$prefix,$college){
        foreach($copy['catalog_followup']['programs'] as $i=>&$target) {
            $target['department_id']=DB::table('departments')->insertGetId(['college_id'=>1,'department_code'=>$prefix.'-D'.$i,'department_name'=>'Synthetic '.$prefix.' '.$i,'is_active'=>1],'department_id');
            $target['program_code']=$prefix.'-P'.$i;$target['courses'][0]['system_code']=$prefix.'-COURSE';
        }unset($target);
    });
    unset($copy['catalog_followup']['hash']);$copy['catalog_followup']['hash']=CompletionData::hash($copy['catalog_followup']);return $copy;
};
$barrier=function(string $label,int $pause,callable $compete,string $signal='continue')use($catalogCase,$catalog,$actor,$read,$check,$environment,$revision): array {
    $b=$catalogCase($label);$r=$catalog->preview($actor,$b);$id=(string)Str::uuid();
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/catalog-barrier.stderr','a']],$pipes);
    try {
        $identity=$read($pipes[1]);$check($identity['ready']!==(int)DB::selectOne('SELECT CONNECTION_ID() id')->id,'Race reused one connection');
        fwrite($pipes[0],json_encode(['kind'=>'catalog','bundle'=>$b,'review'=>$r['review'],'hash'=>$r['review_hash'],'id'=>$id,'revision'=>$r['review']['revision'],'pause_after_stage'=>$pause])."\n");fflush($pipes[0]);$check($read($pipes[1])['entered'],'Worker missing');
        $check($read($pipes[1])['stage_committed']===$pause,'Worker did not pause after committed publication');
        $stages=DB::table('user_activity_logs')->where('action_code','student_completion.followup_catalog_program')->orderBy('activity_log_id')->get()->toJson();
        $compete($b);$epoch=$revision();fwrite($pipes[0],$signal."\n");fflush($pipes[0]);$out=$read($pipes[1]);
        $check(!$out['ok']&&$out['code']==='catalog_followup_stale','Concurrent post-stage commit silently adopted: '.json_encode($out));
        $check($stages===DB::table('user_activity_logs')->where('action_code','student_completion.followup_catalog_program')->orderBy('activity_log_id')->get()->toJson(),'Stale worker wrote dependent stage receipts');
        $check(app(\App\Services\StudentCompletion\CompletionReceipts::class)->find('followup_catalog_result',$id)===null,'False final result recorded');
        $check($revision()===$epoch,'Stale worker advanced catalog');
        echo 'PASS independent post-commit barrier / immutable receipts / no false COMPLETE: '.$label."\n";
        return [$b,$r,$id];
    }finally{fclose($pipes[0]);fclose($pipes[1]);proc_close($process);}
};
$changeDefault=function(array $b)use($plans,$actor,$revision): void {
    $program=\App\Models\AcademicProgram::where('program_code',$b['catalog_followup']['programs'][0]['program_code'])->sole();
    $new=$plans->copy($actor,(int)$program->getKey(),(int)$program->default_academic_plan_version_id,['revision'=>$revision(),'label'=>'Synthetic independent authorized replacement']);
    $v=(int)$new['version']->getKey();$plans->approve($actor,(int)$program->getKey(),$v,['revision'=>$revision(),'confirmed'=>true]);$plans->setDefault($actor,(int)$program->getKey(),$v,['revision'=>$revision(),'confirmed'=>true]);
};
[$interrupted,$reviewed,$interruptedId]=$barrier('SYN-BETWEEN',1,$changeDefault);
$preserved=$snapshot();$logs=DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson();
try{$catalog->apply($actor,$interrupted,$reviewed['review'],$reviewed['review_hash'],$interruptedId,$revision(),true);throw new RuntimeException('Changed publication accepted on explicit resume');}
catch(\App\Exceptions\AcademicPlanException $e){$check($e->errorCode==='catalog_completion_default_changed','Wrong resume conflict');}
$check($preserved===$snapshot()&&$logs===DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson(),'Resume repaired evidence or rewrote history');
$barrier('SYN-EPOCH',1,fn()=>app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('departments')->where('department_id',1)->update(['description'=>'Independent catalog commit'])));
$barrier('SYN-FINAL',4,$changeDefault);
echo "PASS explicit interrupted resume validates prior publication before any dependent work / final target change rejected\n";
[$unchanged,$original,$unchangedId]=$barrier('SYN-RESUME',1,fn()=>null,'interrupt');
$programId=\App\Models\AcademicProgram::where('program_code',$unchanged['catalog_followup']['programs'][0]['program_code'])->value('academic_program_id');
$source=DB::table('academic_plan_events')->where('academic_program_id',$programId)->orderBy('academic_plan_event_id')->get()->toJson();
$stagesBefore=DB::table('user_activity_logs')->where('action_code','student_completion.followup_catalog_program')->orderBy('activity_log_id')->get()->all();
$resumed=$catalog->apply($actor,$unchanged,$original['review'],$original['review_hash'],$unchangedId,$revision(),true);
$check($resumed['status']==='COMPLETE','Unchanged interruption could not explicitly resume');
$check($source===DB::table('academic_plan_events')->where('academic_program_id',$programId)->orderBy('academic_plan_event_id')->get()->toJson(),'Resume repeated an earlier program publication');
foreach($stagesBefore as $receipt)$check((array)$receipt===(array)DB::table('user_activity_logs')->where('activity_log_id',$receipt->activity_log_id)->sole(),'Resume rewrote an earlier receipt');
$check(DB::table('courses')->where('course_code','SYN-RESUME-COURSE')->count()===1,'Resume duplicated a shared origin');
$audit=DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson();$check($resumed===$catalog->apply($actor,$unchanged,$original['review'],$original['review_hash'],$unchangedId,'0'),'Unchanged replay differs');
$check($audit===DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson(),'Historical replay wrote');
echo "PASS real interrupted unchanged resume / same earlier publication and receipts / one shared origin / historical replay\n";

// Revised identity mappings use a new profile; no source snapshot is imported.
$departments=[];
app(AcademicCatalogTransaction::class)->run(function()use(&$departments){
    $units=[];foreach(['INST-ENG','INST-CD'] as $code)$units[$code]=DB::table('colleges')->where('college_code',$code)->value('college_id')
        ?? DB::table('colleges')->insertGetId(['college_code'=>$code,'college_name'=>'Synthetic identity institute '.$code,'is_active'=>true],'college_id');
    foreach(range(0,3) as $i)$departments[]=DB::table('departments')->insertGetId(['college_id'=>$units[$i===3?'INST-CD':'INST-ENG'],'department_code'=>'SYN-ID-D'.$i,'department_name'=>'Synthetic identity department '.$i,'is_active'=>true],'department_id');
});
$ids=[856,857,859,409,778,860];$oldOrigins=DB::table('courses')->whereIn('course_id',$ids)->orderBy('course_id')->get()->toJson();
$oldMemberships=DB::table('program_courses')->whereIn('course_id',$ids)->orderBy('program_course_id')->get()->toJson();
$oldMemberIds=DB::table('program_courses')->whereIn('course_id',$ids)->pluck('program_course_id');
$oldPrerequisites=DB::table('course_prerequisites')->whereIn('course_id',$ids)->orderBy('course_prerequisite_id')->get()->toJson();
$oldOfferings=DB::table('course_offerings')->whereIn('course_id',$ids)->orderBy('course_offering_id')->get()->toJson();
$oldReceipts=DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->all();
$check(DB::table('courses')->whereIn('course_id',$ids)->count()===6&&DB::table('program_courses')->where('academic_program_id',901)->whereIn('course_id',$ids)->count()===6
    &&DB::table('course_prerequisites')->whereIn('course_id',$ids)->exists(),'Historical identity assertions require nonempty fixtures');
$academicBefore=app(\App\Services\CatalogCompletion\CatalogCompletionService::class)->academicFingerprint();
$corrected=\Tests\Support\FollowupIdentityFixture::bundle($departments,['INST-ENG','INST-ENG','INST-ENG','INST-CD'],'SYN-ID',true);$r=$catalog->preview($actor,$corrected);$check($r['status']==='READY','Corrected identity preview blocked: '.json_encode($r));$id=(string)Str::uuid();
$check(json_encode($oldReceipts)===DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson(),'Identity preview wrote audit');
$process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/catalog-identity.stderr','a']],$pipes);
try {
    $connection=$read($pipes[1]);$check($connection['ready']!==(int)DB::selectOne('SELECT CONNECTION_ID() id')->id,'Identity worker reused parent connection');
    fwrite($pipes[0],json_encode(['kind'=>'catalog','bundle'=>$corrected,'review'=>$r['review'],'hash'=>$r['review_hash'],'id'=>$id,'revision'=>$r['review']['revision'],'pause_after_stage'=>1])."\n");fflush($pipes[0]);$check($read($pipes[1])['entered'],'Identity worker not entered');$check($read($pipes[1])['stage_committed']===1,'Corrected first publication not committed');
    app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('departments')->where('department_id',1)->update(['description'=>'Independent identity revision barrier']));
    fwrite($pipes[0],"continue\n");fflush($pipes[0]);$out=$read($pipes[1]);$check(!$out['ok']&&$out['code']==='catalog_followup_stale','Identity operation adopted a concurrent revision');
}finally{fclose($pipes[0]);fclose($pipes[1]);proc_close($process);}
$civilProgram=\App\Models\AcademicProgram::where('program_code','SYN-ID-P0')->sole();$prior=DB::table('academic_plan_events')->where('academic_program_id',$civilProgram->getKey())->orderBy('academic_plan_event_id')->get()->toJson();
$done=$catalog->apply($actor,$corrected,$r['review'],$r['review_hash'],$id,$revision(),true);$check($done['status']==='COMPLETE','Corrected identity resume did not complete: '.json_encode($done));
$check($prior===DB::table('academic_plan_events')->where('academic_program_id',$civilProgram->getKey())->orderBy('academic_plan_event_id')->get()->toJson(),'Corrected first stage republished');
$check($oldOrigins===DB::table('courses')->whereIn('course_id',$ids)->orderBy('course_id')->get()->toJson(),'Conflicting origins changed');
$check($oldMemberships===DB::table('program_courses')->whereIn('program_course_id',$oldMemberIds)->orderBy('program_course_id')->get()->toJson(),'Existing membership contents changed');
$check($oldPrerequisites===DB::table('course_prerequisites')->whereIn('course_id',$ids)->orderBy('course_prerequisite_id')->get()->toJson(),'Conflicting prerequisites changed');
$check($oldOfferings===DB::table('course_offerings')->whereIn('course_id',$ids)->orderBy('course_offering_id')->get()->toJson(),'Old offering identity/announcement changed');
foreach($oldReceipts as $receipt)$check((array)$receipt===(array)DB::table('user_activity_logs')->where('activity_log_id',$receipt->activity_log_id)->sole(),'Earlier operation receipt changed');
$check($academicBefore===app(\App\Services\CatalogCompletion\CatalogCompletionService::class)->academicFingerprint(),'Identity repair changed academic history');
foreach(['TI-CE-211'=>4,'TI-CE-212'=>3,'TI-CE-222'=>3,'TI-CE-RES211'=>2,'TI-CE-213'=>3,'TI-CE-223'=>3] as $code=>$hours){$course=DB::table('courses')->where('course_code',$code)->sole();$check((int)$course->credit_hours===$hours,'Wrong new identity credits');$check(!DB::table('course_prerequisites')->where('course_id',$course->course_id)->exists(),'Prerequisites leaked from source-code collision');}
$versions=\App\Models\AcademicProgram::whereIn('program_code',['SYN-ID-P0','SYN-ID-P1','SYN-ID-P2'])->pluck('default_academic_plan_version_id');
$check(DB::table('program_courses')->whereIn('academic_plan_version_id',$versions)->where('course_id',409)->count()===3,'Three university-life memberships do not share 409');
$check(DB::table('courses')->where('course_code','RUS211')->count()===1&&!DB::table('courses')->where('course_code','RUS111')->exists(),'University-life origin duplicated');
$research=DB::table('courses')->where('course_code','TI-CE-RES211')->sole();$check((int)$research->course_id!==409&&$research->course_name==='أصول البحث العلمي','Research was confused with university-life');
$check($catalog->verify($actor,$corrected)['status']==='COMPLETE','Corrected publication verification failed');$logs=DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson();
$check($done===$catalog->apply($actor,$corrected,$r['review'],$r['review_hash'],$id,'0'),'Corrected historical replay differs');$check($logs===DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson(),'Corrected replay wrote');
echo "PASS all approved identities / three memberships reuse 409 / research stays distinct / installed guards / independent stage revision conflict / explicit resume / publication verify / exact old origin, prerequisite, membership, offering, receipt and academic history preservation\n";

// A real persisted v6 artifact, loader and authenticated Artisan command boundary.
$private=$environment->config['directory'].'/persisted-artifacts';mkdir($private,0700);
$a=\Tests\Support\PersistedFollowupCatalogFixture::write($private);$bundle=$a['bundle'];$profile=$bundle['catalog_followup']['hash'];
$immutable=[];foreach(glob($private.'/*') as $file)$immutable[$file]=hash_file('sha256',$file);
$academic=app(\App\Services\CatalogCompletion\CatalogCompletionService::class)->academicFingerprint();$audit=DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson();
$args=['--actor'=>1,'--manifest'=>$a['path'],'--manifest-hash'=>$a['hash'],'--output'=>$private.'/preview.json'];
$exit=\Illuminate\Support\Facades\Artisan::call('academic-catalog:complete',$args);$check($exit===0,'Persisted command preview failed: '.\Illuminate\Support\Facades\Artisan::output());
$preview=json_decode(file_get_contents($private.'/preview.json'),true,flags:JSON_THROW_ON_ERROR);$check($preview['status']==='READY'&&!$preview['writes_performed'],'Persisted contexts not ready');
$check($audit===DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson(),'Persisted preview wrote audit');$id=(string)Str::uuid();
$process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/persisted-worker.stderr','a']],$pipes);
try {
    $connection=$read($pipes[1]);$check($connection['ready']!==(int)DB::selectOne('SELECT CONNECTION_ID() id')->id,'Persisted worker shares connection');
    fwrite($pipes[0],json_encode(['kind'=>'catalog','manifest'=>$a['path'],'manifest_hash'=>$a['hash'],'review'=>$preview['review'],'hash'=>$preview['review_hash'],'id'=>$id,'revision'=>$preview['review']['revision'],'pause_after_stage'=>1])."\n");fflush($pipes[0]);
    $check($read($pipes[1])['entered'],'Persisted worker not entered');$check($read($pipes[1])['stage_committed']===1,'Persisted stage not committed');
    app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('departments')->where('department_id',1)->update(['description'=>'Persisted artifact independent epoch barrier']));
    fwrite($pipes[0],"continue\n");fflush($pipes[0]);$out=$read($pipes[1]);$check(!$out['ok']&&$out['code']==='catalog_followup_stale','Persisted write adopted a concurrent epoch');
} finally {fclose($pipes[0]);fclose($pipes[1]);proc_close($process);}
$prior=DB::table('academic_plan_events')->where('context->manifest_hash',$profile)->orderBy('academic_plan_event_id')->get()->all();
$args=['--actor'=>1,'--manifest'=>$a['path'],'--manifest-hash'=>$a['hash'],'--apply'=>true,'--resume'=>true,'--review'=>$private.'/preview.json.review.json','--review-hash'=>$preview['review_hash'],'--expected-revision'=>$revision(),'--operation-id'=>$id,'--output'=>$private.'/resumed.json'];
$exit=\Illuminate\Support\Facades\Artisan::call('academic-catalog:complete',$args);$check($exit===0,'Persisted resume failed: '.\Illuminate\Support\Facades\Artisan::output());
foreach($prior as $event)$check((array)$event===(array)DB::table('academic_plan_events')->where('academic_plan_event_id',$event->academic_plan_event_id)->sole(),'Persisted resume rewrote a committed stage');
$result=json_decode(file_get_contents($private.'/resumed.json'),true,flags:JSON_THROW_ON_ERROR);$check($result['status']==='COMPLETE','Persisted resume incomplete');
$exit=\Illuminate\Support\Facades\Artisan::call('academic-catalog:verify-completion',['--actor'=>1,'--manifest'=>$a['path'],'--manifest-hash'=>$a['hash'],'--output'=>$private.'/verification.json']);
$check($exit===0,'Persisted verification command failed');$check(json_decode(file_get_contents($private.'/verification.json'),true)['status']==='COMPLETE','Persisted verification incomplete');
$logs=DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson();$check($result===$catalog->apply($actor,$bundle,$preview['review'],$preview['review_hash'],$id,'0'),'Persisted historical replay differs');
$check($logs===DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->toJson(),'Persisted replay wrote');
$check($academic===app(\App\Services\CatalogCompletion\CatalogCompletionService::class)->academicFingerprint(),'Persisted publication changed academic evidence');
foreach($immutable as $file=>$hash)$check($hash===hash_file('sha256',$file),'Persisted source artifact changed');
echo "PASS persisted v6 / real writer + loader / authenticated Artisan preview / independent epoch conflict / explicit command resume / publication verification / pure replay / immutable artifacts and academic content\n";
