<?php
/** Synthetic fixtures only, on CatalogEnvironment's marked disposable server. */
require __DIR__.'/CatalogEnvironment.php';
use App\Models\{Course,CourseOffering,GradeComponent,Student,StudentCourseRegistration,User};
use App\Services\{AcademicCatalogTransaction,AcademicPlanContext,CourseOfferingContextService,GradePartWorkflowService,RegistrationService};
use App\Services\HistoricalExams\{ExamCompletionService,ExamDatabase,ExamProjection,ExamSources};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;

$environment=new CatalogEnvironment;$app=$environment->laravel();$pdo=DB::connection()->getPdo();$environment->guard($pdo);
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$check=fn($v,$message)=>$v?:throw new RuntimeException($message);$service=app(ExamCompletionService::class);$tx=app(AcademicCatalogTransaction::class);
if(($argv[1]??'')==='worker') {
    echo json_encode(['ready'=>(int)DB::selectOne('SELECT CONNECTION_ID() id')->id])."\n";flush();$job=json_decode(fgets(STDIN),true,flags:JSON_THROW_ON_ERROR);echo "{\"entered\":true}\n";flush();
    if($job['pause']??false)DB::listen(function($q){if(str_starts_with(strtolower($q->sql),'insert into `user_activity_logs`')&&in_array('student_completion.exam_action',$q->bindings,true))DB::afterCommit(function(){echo "{\"committed\":true}\n";flush();if(trim((string)fgets(STDIN))!=='continue')throw new RuntimeException('Synthetic lost response after action commit');});});
    try{$r=$service->apply(User::findOrFail(20000),$job['bundle'],$job['review'],$job['hash'],$job['id'],$job['revision']);echo json_encode(['ok'=>true,'result'=>$r])."\n";}
    catch(Throwable $e){echo json_encode(['ok'=>false,'code'=>$e->errorCode??get_class($e),'message'=>$e->getMessage()])."\n";}exit;
}
$check(($argv[1]??'')==='run','Unknown guarded test mode');echo 'HISTORICAL_EXAM_SERVER_VERSION='.$pdo->query('SELECT VERSION()')->fetchColumn()."\n";
if(!Schema::hasTable('grading_policies'))\Tests\Support\CompletionRegistrationFixture::prepare();
if(!\App\Support\IncompleteOutcomeGuard::installed())foreach(['00_preflight.sql','01_apply.sql','02_verify.sql'] as $file){$r=$environment->package($pdo,$file,null,'course-result-symbols-phase1');$last=end($r);$check(in_array($last['result']??null,['READY','APPLIED','PASS'],true),'Incomplete lifecycle guard installation failed');}
$check(\App\Support\IncompleteOutcomeGuard::ready(),'Installed Incomplete schema/trigger contract is incompatible');
if(DB::table('academic_programs')->where('academic_program_id',900)->value('plan_state')==='legacy') {
    $scientific=User::findOrFail(1);$plans=app(\App\Services\AcademicPlanWorkflow::class);$confirm=fn()=>['revision'=>$tx->revision(),'confirmed'=>true];
    $plans->begin($scientific,900,$confirm());$source=$plans->fixTransition($scientific,900,$confirm())['current_version_id'];$copy=$plans->copy($scientific,900,$source,['revision'=>$tx->revision(),'label'=>'Synthetic historical workflow target']);$version=(int)$copy['version']->getKey();$plans->approve($scientific,900,$version,$confirm());$plans->setDefault($scientific,900,$version,$confirm());
}
foreach(['grade_components'=>['status'=>'string','created_at'=>'dateTime','updated_at'=>'dateTime'],
    'student_grade_components'=>['notes'=>'text'],'student_course_registrations'=>['registered_by_user_id'=>'integer','advisor_user_id'=>'integer','notes'=>'text'],
    'grade_part_approvals'=>['submission_version'=>'integer','submitted_by_user_id'=>'integer','submitted_at'=>'dateTime','reviewed_by_user_id'=>'integer','reviewed_at'=>'dateTime','review_notes'=>'text','created_at'=>'dateTime','updated_at'=>'dateTime']] as $table=>$columns)
    foreach($columns as $name=>$type)if(!Schema::hasColumn($table,$name))Schema::table($table,fn(Blueprint $t)=>$t->{$type}($name)->nullable());
if(!Schema::hasTable('grade_part_approval_events'))Schema::create('grade_part_approval_events',function(Blueprint $t){$t->increments('grade_part_approval_event_id');foreach(['grade_part_approval_id','submission_version','performed_by_user_id'] as $c)$t->integer($c)->nullable();foreach(['action','old_values','new_values','performed_at'] as $c)$t->text($c)->nullable();});
foreach(['grade_part_approval_id'=>'integer','submission_version'=>'integer','performed_by_user_id'=>'integer','action'=>'string','old_values'=>'text','new_values'=>'text','performed_at'=>'dateTime'] as $column=>$type)if(!Schema::hasColumn('grade_part_approval_events',$column))Schema::table('grade_part_approval_events',fn(Blueprint $t)=>$t->{$type}($column)->nullable());
DB::statement('ALTER TABLE grade_part_approvals MODIFY grade_part_approval_id INT NOT NULL AUTO_INCREMENT'); // Test fixture matches the existing production identity contract.
DB::statement('ALTER TABLE grade_part_approval_events MODIFY grade_part_approval_event_id INT NOT NULL AUTO_INCREMENT');
DB::table('roles')->insert(['role_id'=>20000,'role_code'=>'exam_officer','is_active'=>1]);DB::table('users')->insert(['user_id'=>20000,'username'=>'synthetic-historical-officer','account_status_id'=>1]);DB::table('user_roles')->insert(['user_id'=>20000,'role_id'=>20000,'is_active'=>1]);
foreach(['exams.manage','grades.manage','students.view','grades.view'] as $code){$id=DB::table('permissions')->where('permission_code',$code)->value('permission_id')??DB::table('permissions')->insertGetId(['module_id'=>1,'permission_code'=>$code,'is_active'=>1],'permission_id');DB::table('role_permissions')->insert(['role_id'=>20000,'permission_id'=>$id]);}
$unit=DB::table('organizational_units')->where('unit_code','PRES')->value('organizational_unit_id');DB::table('user_access_scopes')->insert(['user_id'=>20000,'scope_type'=>'university','scope_id'=>$unit,'is_active'=>1]);$actor=User::findOrFail(20000);
$year=DB::table('academic_years')->insertGetId(['year_name'=>'2097-2098','start_date'=>'2097-09-01','end_date'=>'2098-08-31','is_current'=>false],'academic_year_id');
$case=function(string $suffix,bool $materialize=true)use($actor,&$year){
    return AcademicPlanContext::transaction(function()use($actor,$year,$suffix,$materialize){
        $student=Student::create(['student_number'=>'SYN-HIST-'.$suffix,'first_name'=>'Synthetic','last_name'=>$suffix,'father_name'=>'Parent','academic_program_id'=>900,'current_academic_level_id'=>1,'enrollment_date'=>'2023-12-10','student_status_id'=>1]);
        $course=Course::findOrFail(1);$r=null;
        if($materialize){$o=app(CourseOfferingContextService::class)->createManualRecordingOffering($student,$course,$year,1,$actor);
            GradeComponent::create(['course_offering_id'=>$o->getKey(),'component_name'=>'الامتحان النهائي','component_type'=>'theoretical','max_mark'=>100,'is_required'=>true,'status'=>'active']);
            $r=app(RegistrationService::class)->prepareManualGradeRegistration($student,$o,$actor,['confirmed'=>true,'reason'=>'Synthetic historical race fixture','course_id'=>1,'academic_year_id'=>$year,'semester_id'=>1]);}
        $program=$student->academicProgram()->with('department.college')->first();$values=['number'=>$student->student_number,'name'=>'Synthetic '.$suffix,'college'=>$program->department->college->college_name,'program'=>$program->program_name,'code'=>$course->course_code,'course'=>$course->course_name,'credits'=>(string)$course->credit_hours,'term'=>'1','year'=>DB::table('academic_years')->where('academic_year_id',$year)->value('year_name'),'exam'=>'80','coursework'=>null,'total'=>'80','condition'=>'T','supplementary'=>null];
        $b=['contract'=>ExamSources::CONTRACT,'sources'=>['synthetic'=>['sha256'=>str_repeat('a',64)]],'rows'=>[['id'=>'synthetic:'.$suffix,'reference'=>['file'=>'synthetic','sheet'=>'Regular','row'=>2],'kind'=>'regular','values'=>$values,'cells'=>[],'row_hash'=>\App\Services\StudentCompletion\CompletionData::hash($values)]]];return [$b,$r];
    });
};
// Separate synthetic years keep each race independent, with one legitimate identity per offering.
$first=true;$cases=[];
foreach(['same','grade','approval','registration','catalog','lost'] as $name) {
    if($first){$cases[$name]=$case($name);$first=false;}
    else {
        $year=DB::table('academic_years')->insertGetId(['year_name'=>(2098+count($cases)).'-'.(2099+count($cases)),'start_date'=>(2098+count($cases)).'-09-01','end_date'=>(2099+count($cases)).'-08-31','is_current'=>false],'academic_year_id');
        $cases[$name]=$case($name);
    }
}
$read=fn($pipe)=>json_decode(fgets($pipe)?:throw new RuntimeException('Worker ended'),true,flags:JSON_THROW_ON_ERROR);
$spawn=function(array $job)use($environment,$read){$process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/historical-worker.stderr','a']],$pipes);$ready=$read($pipes[1]);fwrite($pipes[0],json_encode($job)."\n");fflush($pipes[0]);$read($pipes[1]);return [$process,$pipes,$ready['ready']];};
$wait=function(int $id)use($environment,$check){$monitor=$environment->connect(true);$until=microtime(true)+20;$waiting=false;do{$q=$monitor->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$id]);$sql=strtolower((string)$q->fetchColumn());if(str_contains($sql,'academic_catalog_control')&&str_contains($sql,'for update')){$waiting=true;break;}usleep(20000);}while(microtime(true)<$until);$check($waiting,'Independent writer did not wait on real catalog lock');};
$close=function($w){fclose($w[1][0]);fclose($w[1][1]);proc_close($w[0]);};
[$b,$reg]=$cases['same'];$preview=$service->preview($actor,$b);$check($preview['counts']['eligible']===1,'Synthetic historical case not eligible: '.json_encode($preview['review']['actions'][0]['blockers']));$id=(string)Str::uuid();$job=['bundle'=>$b,'review'=>$preview['review'],'hash'=>$preview['review_hash'],'id'=>$id,'revision'=>$preview['review']['revision']];
DB::beginTransaction();$tx->revision(true);$a=$spawn($job);$z=$spawn($job);$check($a[2]!==$z[2],'Workers share connection');$wait($a[2]);$wait($z[2]);DB::commit();$ra=$read($a[1][1]);$rz=$read($z[1][1]);$close($a);$close($z);
$winner=$ra['ok']?$ra:$rz;$other=$ra['ok']?$rz:$ra;$check($winner['ok']&&(($other['ok']&&$other['result']===$winner['result'])||($other['code']??null)==='historical_exam_explicit_resume_required'),'Concurrent same UUID did not produce one result or a controlled still-running response');
$check($winner['result']===$service->apply($actor,$b,$preview['review'],$preview['review_hash'],$id,'0'),'Completed exact replay differs');
$check(DB::table('student_grade_components')->where('student_course_registration_id',$reg->getKey())->count()===1,'Duplicate historical marks');$service->verify($actor,$b,$id);echo "PASS historical same UUID / independent connections / one draft / pure replay / read-only verification\n";
foreach(['grade','approval','registration','catalog'] as $label) {
    [$b,$reg]=$cases[$label];$p=$service->preview($actor,$b);$check($p['counts']['eligible']===1,'Race source not eligible: '.$label);DB::beginTransaction();$tx->revision(true);
    if(in_array($label,['grade','approval'],true)) {
        $workflow=app(GradePartWorkflowService::class);$snapshot=$workflow->manualRegistration($reg);$workflow->saveManualMarks($reg->student,$reg,['revision'=>$snapshot['revision'],'acknowledged'=>true,'components'=>[['grade_component_id'=>DB::table('grade_components')->where('course_offering_id',$reg->course_offering_id)->value('grade_component_id'),'mark'=>70]]],$actor);
        if($label==='approval'){$ready=$workflow->manualSubmissionReadiness($reg,'theoretical');$workflow->submitManualPart($reg->student,$reg,'theoretical',['revision'=>$ready['revision'],'confirmed'=>true],$actor);}
    } elseif($label==='registration'){$registrations=app(RegistrationService::class);$registrations->lockStudent((int)$reg->student_id);$offering=$registrations->lockOffering((int)$reg->course_offering_id);$locked=$registrations->lockRegistration((int)$reg->getKey());$registrations->transitionRegisteredToWithdrawn($locked,$offering);}
    else DB::table('departments')->where('department_id',1)->update(['description'=>'Synthetic concurrent catalog change']);
    $worker=$spawn(['bundle'=>$b,'review'=>$p['review'],'hash'=>$p['review_hash'],'id'=>(string)Str::uuid(),'revision'=>$p['review']['revision']]);$wait($worker[2]);DB::commit();$r=$read($worker[1][1]);$close($worker);
    $check(!$r['ok']&&in_array($r['code'],['historical_exam_stale','historical_exam_review_changed'],true),'Concurrent '.$label.' was adopted: '.json_encode($r));echo 'PASS historical independent '.$label." wait / reviewed write rejected\n";
}
[$b,$reg]=$cases['lost'];$p=$service->preview($actor,$b);$id=(string)Str::uuid();$worker=$spawn(['bundle'=>$b,'review'=>$p['review'],'hash'=>$p['review_hash'],'id'=>$id,'revision'=>$p['review']['revision'],'pause'=>true]);$check($read($worker[1][1])['committed'],'Action not committed');fwrite($worker[1][0],"disconnect\n");fflush($worker[1][0]);$read($worker[1][1]);$close($worker);
$check($service->status($actor,$id)['status']==='INTERRUPTED','Lost response falsely complete');$done=$service->apply($actor,$b,$p['review'],$p['review_hash'],$id,$tx->revision(),true);$check($done['saved_pending_approval']===1,'Explicit resume not restored');$service->verify($actor,$b,$id);
echo "PASS historical committed lost response / immutable UUID / explicit resume / exact content verification / pending approval\n";
$year=DB::table('academic_years')->insertGetId(['year_name'=>'2119-2120','start_date'=>'2119-09-01','end_date'=>'2120-08-31','is_current'=>false],'academic_year_id');
[$b]=$case('missing',false);$p=$service->preview($actor,$b);$check($p['counts']['eligible']===1,'Missing context not eligible: '.json_encode($p['review']['actions'][0]['blockers']));$id=(string)Str::uuid();$saved=$service->apply($actor,$b,$p['review'],$p['review_hash'],$id,$p['review']['revision']);$check($saved['saved_pending_approval']===1,'Missing context not atomically saved');
$r=StudentCourseRegistration::findOrFail($saved['actions'][0]['registration_id']);$check($r->academic_plan_version_id!==null&&$r->plan_program_course_id!==null,'Canonical plan links absent');$check(!DB::table('student_course_results')->where('student_course_registration_id',$r->getKey())->exists(),'Draft backfill invented result');$check(!DB::table('grade_approvals')->where('course_offering_id',$r->course_offering_id)->exists(),'Draft backfill invented approval');$service->verify($actor,$b,$id);
echo "PASS historical missing offering + registration + component / atomic canonical preparation / retained plan links / guidance provenance / pending approval\n";
