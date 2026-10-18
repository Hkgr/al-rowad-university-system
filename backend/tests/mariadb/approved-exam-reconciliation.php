<?php
/** Synthetic fixtures on CatalogEnvironment's newly marked disposable server only. */
require __DIR__.'/CatalogEnvironment.php';
use App\Models\{Course, Student, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext};
use App\Services\HistoricalExams\{ExamCompletionService, ExamSources};
use App\Support\HistoricalExamSchema as Contract;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

$environment=new CatalogEnvironment;$app=$environment->laravel();$pdo=DB::connection()->getPdo();$environment->guard($pdo);
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$check=fn($value,$message)=>$value?:throw new RuntimeException($message);
if(($argv[1]??'')==='catalog-fixture') {
    $check(!AcademicPlanContext::installed(),'Seed synthetic legacy students before installing plan protections, never disable installed guards');
    $sport=\App\Models\AcademicProgram::create(['program_code'=>'RU2-ES-TS','program_name'=>'Synthetic Sports','department_id'=>1,'degree_level'=>'bachelor','duration_years'=>4,'total_credit_hours'=>141,'is_active'=>true]);
    $groups=[];
    foreach(['university','college','department'] as $scope)foreach(['mandatory','elective'] as $type) {
        $groups[$scope.':'.$type]=\App\Models\AcademicRequirementGroup::create(['academic_program_id'=>$sport->getKey(),'group_code'=>'SYN-HXC-'.$scope.'-'.$type,'group_name'=>'Synthetic '.$scope.' '.$type,
            'requirement_scope'=>$scope,'requirement_type'=>$type,'required_credit_hours'=>$type==='elective'?0:match($scope){'university'=>3,'college'=>62,default=>76},'is_active'=>true]);
    }
    $makeMember=function($origin,$scope)use($sport,$groups){$m=\App\Models\ProgramCourse::create(['academic_program_id'=>$sport->getKey(),'course_id'=>$origin->getKey(),'course_type'=>'mandatory','academic_level_id'=>1,'recommended_semester_id'=>1,'is_active'=>true]);\App\Models\ProgramCourseRequirementGroup::create(['program_course_id'=>$m->getKey(),'requirement_group_id'=>$groups[$scope.':mandatory']->getKey()]);};
    $makeMember(Course::findOrFail(1),'university');
    foreach([['ES421',2,'college'],['SYN-HXC-1',30,'college'],['SYN-HXC-2',30,'college'],['SYN-HXC-3',30,'department'],['SYN-HXC-4',30,'department'],['SYN-HXC-5',16,'department']] as [$code,$hours,$scope]){
        $origin=Course::create(['course_code'=>$code,'course_name'=>$code,'credit_hours'=>$hours,'theoretical_hours'=>0,'practical_hours'=>0,'is_active'=>true]);$makeMember($origin,$scope);
    }
    Student::create(['student_number'=>'SYN-SPORTS-CREDIT','first_name'=>'Synthetic','last_name'=>'Sports','father_name'=>'Parent','academic_program_id'=>$sport->getKey(),'current_academic_level_id'=>1,'enrollment_date'=>'2023-12-10','student_status_id'=>1]);
    echo "PASS synthetic Sports/student seeded before plan installation; no installed guard disabled\n";exit;
}
if(($argv[1]??'')==='worker') {
    echo json_encode(['ready'=>(int)DB::selectOne('SELECT CONNECTION_ID() id')->id])."\n";flush();
    $job=json_decode(fgets(STDIN),true,flags:JSON_THROW_ON_ERROR);echo "{\"entered\":true}\n";flush();
    try {
        $actor=User::findOrFail(20000);
        $r=($job['mode']??'apply')==='adopt'
            ? app(\App\Services\HistoricalExams\HistoricalExamWorkService::class)->adopt($actor,$job['bundle'],$job['review'],$job['hash'],$job['id'],$job['revision'],'Synthetic race correction')
            : app(ExamCompletionService::class)->apply($actor,$job['bundle'],$job['review'],$job['hash'],$job['id'],$job['revision']);
        echo json_encode(['ok'=>true,'result'=>$r])."\n";
    } catch(Throwable $e) { echo json_encode(['ok'=>false,'code'=>$e->errorCode??get_class($e),'message'=>$e->getMessage()])."\n"; }
    exit;
}
$check(($argv[1]??'')==='run','Unknown isolated test mode');
echo 'APPROVED_EXAM_SERVER_VERSION='.$pdo->query('SELECT VERSION()')->fetchColumn()."\n";
foreach(['00_preflight.sql','01_apply.sql','02_verify.sql','01_apply.sql','02_verify.sql'] as $file) {
    $sets=$environment->package($pdo,$file,null,'historical-exam-approved-reconciliation');$last=end($sets);
    $check(in_array($last['result']??null,['READY','APPLIED_OR_ALREADY_COMPATIBLE','PASS'],true),'Historical evidence package did not validate: '.json_encode($last));
}
$check(Contract::ready(),'Runtime evidence contract is incompatible');
echo "PASS exact manual package / idempotent installation / runtime readiness\n";
// Run after creating a real synthetic staged item, not merely an empty table.
$negative=function()use($pdo,$environment,$check) {
$table=Contract::WORK;$before=$pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
$check(count($before)>0,'Populated negative schema coverage requires durable evidence');
$bad=[
    ["ALTER TABLE $table MODIFY created_at VARCHAR(40) NOT NULL","ALTER TABLE $table MODIFY created_at DATETIME NOT NULL"],
    ["ALTER TABLE $table MODIFY proposal TEXT NOT NULL","ALTER TABLE $table MODIFY proposal LONGTEXT NOT NULL"],
    ["ALTER TABLE $table DROP INDEX uq_hx_work_operation, ADD UNIQUE KEY uq_hx_work_operation(operation_id)","ALTER TABLE $table DROP INDEX uq_hx_work_operation, ADD UNIQUE KEY uq_hx_work_operation(operation_id,action_id)"],
    [["ALTER TABLE $table DROP FOREIGN KEY fk_hx_work_actor","ALTER TABLE $table ADD CONSTRAINT fk_hx_work_actor FOREIGN KEY(actor_user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE RESTRICT"],
     ["ALTER TABLE $table DROP FOREIGN KEY fk_hx_work_actor","ALTER TABLE $table ADD CONSTRAINT fk_hx_work_actor FOREIGN KEY(actor_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT"]],
    [["ALTER TABLE $table DROP CONSTRAINT ck_hx_work_json","ALTER TABLE $table ADD CONSTRAINT ck_hx_work_json CHECK(1)"],
     ["ALTER TABLE $table DROP CONSTRAINT ck_hx_work_json","ALTER TABLE $table ADD CONSTRAINT ck_hx_work_json CHECK(json_valid(proposal))"]],
    ['DROP TRIGGER hx_history_0_u',null],
];
foreach($bad as [$change,$restore]) {
    foreach((array)$change as $statement)$pdo->exec($statement);
    if($restore===null)$pdo->exec("CREATE TRIGGER hx_history_0_u BEFORE UPDATE ON $table FOR EACH ROW BEGIN SET @synthetic_noop=1; END");
    $check(!Contract::ready(),'Incompatible named definition was accepted');
    try{$environment->package($pdo,'01_apply.sql',null,'historical-exam-approved-reconciliation');throw new RuntimeException('Incompatible named package was overwritten');}
    catch(PDOException $e){$check(str_contains($e->getMessage(),'historical_exam_schema_incompatible'),'Unexpected rejection');}
    $check($before===$pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC),'Rejected installation changed data');
    if($restore!==null)foreach((array)$restore as $statement)$pdo->exec($statement);
    else {$pdo->exec('DROP TRIGGER hx_history_0_u');[$target,$event,$body]=Contract::triggers()['hx_history_0_u'];$pdo->exec("CREATE TRIGGER hx_history_0_u BEFORE $event ON $target FOR EACH ROW $body");}
    $check(Contract::ready(),'Recognized definition restoration failed');
}
echo "PASS populated negative named datetime/type, capacity, unique-sequence, FK rule, CHECK and no-op-trigger definitions / exact evidence preserved\n";
};
$actor=User::findOrFail(20000);$tx=app(AcademicCatalogTransaction::class);
$student=AcademicPlanContext::transaction(fn()=>Student::create(['student_number'=>'SYN-APPROVED-EXAM','first_name'=>'Synthetic','last_name'=>'Approved','father_name'=>'Parent',
    'academic_program_id'=>900,'current_academic_level_id'=>1,'enrollment_date'=>'2023-12-10','student_status_id'=>1]));
$course=Course::findOrFail(1);$program=$student->academicProgram()->with('department.college')->first();
$year=DB::table('academic_years')->insertGetId(['year_name'=>'2190-2191','start_date'=>'2190-09-01','end_date'=>'2191-08-31','is_current'=>false],'academic_year_id');
$directory=$environment->config['directory'].'/approved-source-fixture';mkdir($directory,0700);
$fixture=\Tests\Support\SyntheticApprovedExamSources::write($directory,['number'=>$student->student_number,'name'=>'Synthetic Approved','college'=>$program->department->college->college_name,
    'program'=>$program->program_name,'code'=>$course->course_code,'course'=>$course->course_name,'target_course_name'=>$course->course_name,'credits'=>(string)$course->credit_hours,
    'year'=>'2190-2191','term'=>'1']);
$app->instance(ExamSources::class,$fixture['reader']);$bundle=$fixture['reader']->prepare($fixture['inputs']);
\App\Services\StudentCompletion\CompletionFiles::write($directory.'/bundle.json',$bundle);
$bundle=$fixture['reader']->load($directory.'/bundle.json',hash_file('sha256',$directory.'/bundle.json'));
$service=app(ExamCompletionService::class);$preview=$service->preview($actor,$bundle);
$check($preview['counts']['eligible']===1,'Persisted synthetic v4 draft not eligible: '.json_encode($preview['counts']));
$id=(string)Str::uuid();$result=$service->apply($actor,$bundle,$preview['review'],$preview['review_hash'],$id,$preview['review']['revision']);
$check($result['saved_pending_approval']===1,'Synthetic v4 draft did not save');
$check($result===$service->apply($actor,$bundle,$preview['review'],$preview['review_hash'],$id,'0'),'Replay changed the result');
$check($service->verify($actor,$bundle,$id)['saved_pending_approval']===1,'Persisted v4 verification failed');
echo "PASS persisted approved v4 source loader / canonical draft / applicable credits / exact replay / verification\n";

$workDirectory=$environment->config['directory'].'/approved-ungraded-fixture';mkdir($workDirectory,0700);
$values=['number'=>$student->student_number,'name'=>'Synthetic Approved','college'=>$program->department->college->college_name,'program'=>$program->program_name,
    'code'=>$course->course_code,'course'=>$course->course_name,'target_course_name'=>$course->course_name,'credits'=>(string)$course->credit_hours,'year'=>'2190-2191','term'=>'1',
    'coursework'=>'-','exam'=>'-','total'=>'-','condition'=>'غير مكتمل'];
$fixture=\Tests\Support\SyntheticApprovedExamSources::write($workDirectory,$values);$app->instance(ExamSources::class,$fixture['reader']);
$ungraded=$fixture['reader']->prepare($fixture['inputs']);$p=$service->preview($actor,$ungraded);
$staged=$service->apply($actor,$ungraded,$p['review'],$p['review_hash'],(string)Str::uuid(),$p['review']['revision']);
$check($staged['staged_pending_review']===1,'Synthetic ungraded evidence was not staged');$negative();
try {$environment->package($pdo,'03_rollback.sql',null,'historical-exam-approved-reconciliation');throw new RuntimeException('Evidence-erasing rollback accepted');}
catch(PDOException $e){$check(str_contains($e->getMessage(),'historical_exam_rollback_would_erase_history'),'Unexpected rollback rejection: '.$e->getMessage());}
$check(DB::table(Contract::WORK)->count()===1,'Rollback deleted synthetic history');
echo "PASS unsafe rollback refused / ordinary ungraded work retained without I or marks\n";

$read=fn($pipe)=>json_decode(fgets($pipe)?:throw new RuntimeException('Worker ended'),true,flags:JSON_THROW_ON_ERROR);
$spawn=function(array $job)use($environment,$read){$process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/approved-worker.stderr','a']],$pipes);$ready=$read($pipes[1]);fwrite($pipes[0],json_encode($job)."\n");fflush($pipes[0]);$read($pipes[1]);return [$process,$pipes,$ready['ready']];};
$close=function($worker)use($read){$r=$read($worker[1][1]);fclose($worker[1][0]);fclose($worker[1][1]);proc_close($worker[0]);return $r;};
$wait=function(int $id)use($environment,$check){$monitor=$environment->connect(true);$until=microtime(true)+20;$waiting=false;do{$q=$monitor->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$id]);$sql=strtolower((string)$q->fetchColumn());if(str_contains($sql,'academic_catalog_control')&&str_contains($sql,'for update')){$waiting=true;break;}usleep(20000);}while(microtime(true)<$until);$check($waiting,'Independent approved writer did not wait on catalog-first lock');};

// Prepare independent real canonical registrations, then race a v4 reviewed draft against
// actual manual/legacy/I writes, and the applicable-credit writer. Every competitor uses the installed path.
foreach(['manual','legacy','incomplete','incomplete_resolve','incomplete_expire','credit'] as $index=>$kind) {
    $year=DB::table('academic_years')->insertGetId(['year_name'=>(2210+$index).'-'.(2211+$index),'start_date'=>(2210+$index).'-09-01','end_date'=>(2211+$index).'-08-31','is_current'=>false],'academic_year_id');
    $o=app(\App\Services\CourseOfferingContextService::class)->createManualRecordingOffering($student,$course,$year,1,$actor);
    if($kind!=='legacy')foreach(\App\Services\HistoricalExams\ExamComponentMapping::aggregateComponents() as $def){unset($def['grade_component_id']);\App\Models\GradeComponent::create(['course_offering_id'=>$o->getKey()]+$def);}
    $r=app(\App\Services\RegistrationService::class)->prepareManualGradeRegistration($student,$o,$actor,['confirmed'=>true,'reason'=>'Synthetic approved race','course_id'=>$course->getKey(),'academic_year_id'=>$year,'semester_id'=>1]);
    $directory=$environment->config['directory'].'/approved-race-'.$kind;mkdir($directory,0700);
    $f=\Tests\Support\SyntheticApprovedExamSources::write($directory,array_replace($values,['year'=>(2210+$index).'-'.(2211+$index),'coursework'=>'30','exam'=>'50','total'=>'80','condition'=>'T']));
    $app->instance(ExamSources::class,$f['reader']);$b=$f['reader']->prepare($f['inputs']);$p=$service->preview($actor,$b);$check($p['counts']['eligible']===1,'Race context not eligible: '.$kind);
    DB::beginTransaction();$tx->revision(true);
    if($kind==='manual') {
        $workflow=app(\App\Services\GradePartWorkflowService::class);$snapshot=$workflow->manualRegistration($r);
        $workflow->saveManualMarks($student,$r,['revision'=>$snapshot['revision'],'acknowledged'=>true,'components'=>[['grade_component_id'=>$o->gradeComponents()->where('component_type','theoretical')->value('grade_component_id'),'mark'=>40]]],$actor);
    } elseif($kind==='legacy') {
        // A genuinely undefined legacy offering, not a bypass of the disabled grade-parts writer.
        app(\App\Services\GradeService::class)->createRegistrationGrades((int)$r->getKey(),['theoretical_mark'=>40,'practical_mark'=>20],(int)$actor->getKey());
    } elseif(str_starts_with($kind,'incomplete')) {
        $life=app(\App\Services\IncompleteResultService::class);
        $case=$life->grant($actor,(int)$r->getKey(),['request_id'=>(string)Str::uuid(),'expected_revision'=>0,'reason'=>'Synthetic outstanding exam','requirements'=>'Synthetic actual exam required','expiry_outcome'=>'F','deadline_at'=>'2030-10-21'])['case'];
        if($kind==='incomplete_resolve')$life->resolve($actor,$case['incomplete_case_id'],['request_id'=>(string)Str::uuid(),'expected_revision'=>1,
            'components'=>$o->gradeComponents()->get()->map(fn($c)=>['grade_component_id'=>$c->getKey(),'mark'=>$c->component_type==='theoretical'?50:30])->all()]);
        if($kind==='incomplete_expire') {
            \Carbon\CarbonImmutable::setTestNow('2030-10-22 12:00:00 UTC');
            try{$check($life->expire($case['incomplete_case_id']),'Synthetic case did not expire');}finally{\Carbon\CarbonImmutable::setTestNow();}
        }
    } else app(\App\Services\HistoricalExams\ExamApprovedCompletionService::class)->recordCredits($actor,(int)$r->getKey(),4,(string)Str::uuid(),hash('sha256','synthetic-credit'),str_repeat('a',64));
    $before=$pdo->query('SELECT * FROM student_grade_components ORDER BY student_grade_component_id')->fetchAll(PDO::FETCH_ASSOC);
    $worker=$spawn(['bundle'=>$b,'review'=>$p['review'],'hash'=>$p['review_hash'],'id'=>(string)Str::uuid(),'revision'=>$p['review']['revision']]);$wait($worker[2]);DB::commit();$rejected=$close($worker);
    $check(!$rejected['ok']&&in_array($rejected['code'],['historical_exam_stale','historical_exam_review_changed'],true),'Competing '.$kind.' was silently overwritten: '.json_encode($rejected));
    $check($before===$pdo->query('SELECT * FROM student_grade_components ORDER BY student_grade_component_id')->fetchAll(PDO::FETCH_ASSOC),'Race changed component history');
    echo 'PASS approved v4 / independent '.$kind." / actual installed catalog lock / stale reviewed write rejected / exact components retained\n";
}

// A separately staged official correction: concurrent adoption is one audited outcome,
// preserves announcement/global approval evidence and cannot become an ordinary recalculation.
$year=DB::table('academic_years')->insertGetId(['year_name'=>'2197-2198','start_date'=>'2197-09-01','end_date'=>'2198-08-31','is_current'=>false],'academic_year_id');
$o=app(\App\Services\CourseOfferingContextService::class)->createManualRecordingOffering($student,$course,$year,1,$actor);
foreach(\App\Services\HistoricalExams\ExamComponentMapping::aggregateComponents() as $def){unset($def['grade_component_id']);$component=\App\Models\GradeComponent::create(['course_offering_id'=>$o->getKey()]+$def);}
$r=app(\App\Services\RegistrationService::class)->prepareManualGradeRegistration($student,$o,$actor,['confirmed'=>true,'reason'=>'Synthetic correction fixture','course_id'=>$course->getKey(),'academic_year_id'=>$year,'semester_id'=>1]);
$passed=DB::table('result_statuses')->where('status_code','passed')->value('result_status_id');$approved=DB::table('approval_statuses')->where('status_code','approved')->value('approval_status_id');
foreach($o->gradeComponents()->get() as $c)DB::table('student_grade_components')->insert(['student_course_registration_id'=>$r->getKey(),'grade_component_id'=>$c->getKey(),'mark'=>$c->component_type==='theoretical'?20:30,'grade_status'=>'approved']);
DB::table('student_course_results')->insert(['student_course_registration_id'=>$r->getKey(),'theoretical_total'=>20,'practical_total'=>30,'coursework_total'=>0,'final_mark'=>50,'result_status_id'=>$passed,'result_announced_at'=>'2197-10-01 12:00:00']);
DB::table('student_course_registrations')->where('student_course_registration_id',$r->getKey())->update(['result_status_id'=>$passed]);
DB::table('grade_approvals')->insert(['course_offering_id'=>$o->getKey(),'approval_status_id'=>$approved]);
$dir=$environment->config['directory'].'/approved-correction';mkdir($dir,0700);$f=\Tests\Support\SyntheticApprovedExamSources::write($dir,array_replace($values,['year'=>'2197-2198','coursework'=>'30','exam'=>'50','total'=>'80','condition'=>'T']));
$app->instance(ExamSources::class,$f['reader']);$correction=$f['reader']->prepare($f['inputs']);\App\Services\StudentCompletion\CompletionFiles::write($dir.'/bundle.json',$correction);
$correction=$f['reader']->load($dir.'/bundle.json',hash_file('sha256',$dir.'/bundle.json'));$preview=$service->preview($actor,$correction);$operation=(string)Str::uuid();
$check(($preview['counts']['classifications']['correction_review']??0)===1,'Official fixture did not produce a correction review');
$saved=$service->apply($actor,$correction,$preview['review'],$preview['review_hash'],$operation,$preview['review']['revision']);$work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');
$workService=app(\App\Services\HistoricalExams\HistoricalExamWorkService::class);$review=$workService->reviewCorrection($actor,$correction,$work['work_item_id']);$request=(string)Str::uuid();
$approvalHistory=$pdo->query('SELECT * FROM grade_approvals ORDER BY grade_approval_id')->fetchAll(PDO::FETCH_ASSOC);
DB::beginTransaction();$tx->revision(true);$job=['mode'=>'adopt','bundle'=>$correction,'review'=>$review['review'],'hash'=>$review['review_hash'],'id'=>$request,'revision'=>$review['review']['revision']];
$a=$spawn($job);$z=$spawn($job);$check($a[2]!==$z[2],'Correction workers share a connection');$wait($a[2]);$wait($z[2]);DB::commit();$ra=$close($a);$rz=$close($z);
$check($ra['ok']&&$rz['ok']&&$ra['result']['historical_outcome_id']===$rz['result']['historical_outcome_id'],'Concurrent adoption did not replay the same result');
$check(DB::table(Contract::OUTCOMES)->where('student_course_registration_id',$r->getKey())->count()===1,'Duplicate historical correction');
$check($approvalHistory===$pdo->query('SELECT * FROM grade_approvals ORDER BY grade_approval_id')->fetchAll(PDO::FETCH_ASSOC),'Correction republished approval history');
$check(DB::table('student_course_results')->where('student_course_registration_id',$r->getKey())->value('result_announced_at')==='2197-10-01 12:00:00','Correction reset announcement');
$check($service->verify($actor,$correction,$operation)['current_adopted_work_items']===1,'Correction receipt did not explain the exact before/after content');
$check(\App\Support\HistoricalOutcomeGuard::preservesOutcome($r->fresh()),'MariaDB settlement proof does not match');
try{app(\App\Services\GradeService::class)->calculateRegistrationResult((int)$r->getKey(),(int)$actor->getKey());throw new RuntimeException('Ordinary recalculation replaced correction');}
catch(\App\Exceptions\GradeException $e){$check($e->errorCode==='historical_outcome_locked','Unexpected recalculation rejection');}
$metrics=app(\App\Services\GradeService::class)->calculateGpa($student,$year,1);$check($metrics['total_included_credit_hours']===(int)$course->credit_hours,'Current GPA did not use recorded attempt credits');
echo "PASS two independent correction adopters / one outcome / exact replay / parent verification / preserved approval + announcement / ordinary recalculation locked / MariaDB credit reader\n";

// New v5 representation: existing offering evidence, but genuinely no definitions or marks.
// The two independent workers adopt the same immutable review/UUID once under the installed catalog lock.
$year=DB::table('academic_years')->insertGetId(['year_name'=>'2245-2246','start_date'=>'2245-09-01','end_date'=>'2246-08-31','is_current'=>false],'academic_year_id');
$aggregateOffering=app(\App\Services\CourseOfferingContextService::class)->createManualRecordingOffering($student,$course,$year,1,$actor);
$aggregateRegistration=app(\App\Services\RegistrationService::class)->prepareManualGradeRegistration($student,$aggregateOffering,$actor,['confirmed'=>true,'reason'=>'Synthetic absent definitions aggregate','course_id'=>$course->getKey(),'academic_year_id'=>$year,'semester_id'=>1]);
DB::table('grade_approvals')->insert(['course_offering_id'=>$aggregateOffering->getKey(),'approval_status_id'=>$approved]);
$dir=$environment->config['directory'].'/approved-historical-aggregate';mkdir($dir,0700);
$fixture=\Tests\Support\SyntheticApprovedExamSources::write($dir,array_replace($values,['year'=>'2245-2246','coursework'=>'40','exam'=>'60','total'=>'100','condition'=>'T']));
$app->instance(ExamSources::class,$fixture['reader']);$aggregate=$fixture['reader']->prepare($fixture['inputs']);
\App\Services\StudentCompletion\CompletionFiles::write($dir.'/bundle.json',$aggregate);$aggregate=$fixture['reader']->load($dir.'/bundle.json',hash_file('sha256',$dir.'/bundle.json'));
$preview=$service->preview($actor,$aggregate);$check(($preview['counts']['classifications']['historical_aggregate_review']??0)===1,'Absent definitions were confused with missing detailed activities');
$parentId=(string)Str::uuid();$saved=$service->apply($actor,$aggregate,$preview['review'],$preview['review_hash'],$parentId,$preview['review']['revision']);
$work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$check($work!==null,'Aggregate staging did not succeed: '.json_encode($saved));$review=$workService->reviewCorrection($actor,$aggregate,$work['work_item_id']);
$before=\App\Support\HistoricalOutcomeGuard::snapshot((int)$aggregateRegistration->getKey());$approvalHistory=$pdo->query('SELECT * FROM grade_approvals ORDER BY grade_approval_id')->fetchAll(PDO::FETCH_ASSOC);
$request=(string)Str::uuid();$job=['mode'=>'adopt','bundle'=>$aggregate,'review'=>$review['review'],'hash'=>$review['review_hash'],'id'=>$request,'revision'=>$review['review']['revision']];
DB::beginTransaction();$tx->revision(true);$left=$spawn($job);$right=$spawn($job);$check($left[2]!==$right[2],'Aggregate adopters shared a connection');$wait($left[2]);$wait($right[2]);DB::commit();
$one=$close($left);$two=$close($right);$check($one['ok']&&$two['ok']&&$one['result']['historical_outcome_id']===$two['result']['historical_outcome_id'],'Concurrent aggregate adoption was not exactly once: '.json_encode([$one,$two]));
$check(DB::table(Contract::OUTCOMES)->where('student_course_registration_id',$aggregateRegistration->getKey())->count()===1,'Aggregate outcomes duplicated');
$after=\App\Support\HistoricalOutcomeGuard::snapshot((int)$aggregateRegistration->getKey());
$check($before['marks']===[]&&$after['marks']===[]&&!DB::table('grade_components')->where('course_offering_id',$aggregateOffering->getKey())->exists(),'Aggregate adoption fabricated activities');
$check($after['results'][0]['theoretical_total']===null&&$after['results'][0]['practical_total']===null&&$after['results'][0]['result_announced_at']===null,'Aggregate adoption relabelled legacy parts or invented publication');
$check($approvalHistory===$pdo->query('SELECT * FROM grade_approvals ORDER BY grade_approval_id')->fetchAll(PDO::FETCH_ASSOC),'Aggregate adoption changed approvals');
$gpa=app(\App\Services\GradeService::class)->calculateGpa($student,$year,1);$check($gpa['gpa']===4.0&&$gpa['total_included_credit_hours']===(int)$course->credit_hours,'MariaDB aggregate GPA reader disagrees');
$aggregateSql=\App\Support\HistoricalOutcomeGuard::aggregateEligibilitySql('r.student_course_registration_id','x.final_mark','s.status_code');
$eligibility=\App\Support\OfficialGradeScale::gpaEligibilitySql('s.status_code','x.final_mark','x.theoretical_total','x.practical_total','1','1',$aggregateSql);
$check(DB::table('student_course_registrations as r')->join('student_course_results as x','x.student_course_registration_id','=','r.student_course_registration_id')->join('result_statuses as s','s.result_status_id','=','x.result_status_id')
    ->where('r.student_course_registration_id',$aggregateRegistration->getKey())->whereRaw($eligibility)->count()===1,'MariaDB SQL aggregate eligibility disagrees with PHP');
$check(\App\Support\HistoricalOutcomeGuard::preservesOutcome($aggregateRegistration->fresh()),'Aggregate proof failed');
$check($workService->verifyCorrection($actor,$aggregate,$request)['status']==='ADOPTED','Aggregate receipt failed verification');
$check($service->verify($actor,$aggregate,$parentId)['current_adopted_work_items']===1,'Aggregate parent receipt failed verification');
\App\Services\StudentCompletion\CompletionFiles::write($dir.'/before-proposal-after.json',['server_version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),
    'synthetic_only'=>true,'before'=>$before,'proposal'=>$review,'after'=>$after,'connections'=>[$left[2],$right[2]],'GPA'=>$gpa,'SQL_eligible'=>true]);
echo "PASS persisted v5 absent-definition aggregate / independently blocked adopters / one outcome + exact replay / no fake components or legacy parts / MariaDB PHP-GPA + SQL parity / receipts verified\n";

// Fully synthetic Sports plan, including an existing student. Publication uses the
// canonical fixation/copy/approval/default services and races another reviewed import.
$scientific=User::findOrFail(1);$sport=\App\Models\AcademicProgram::where('program_code','RU2-ES-TS')->sole();$oldStudent=Student::where('student_number','SYN-SPORTS-CREDIT')->sole();
$dir=$environment->config['directory'].'/approved-catalog-source';mkdir($dir,0700);$f=\Tests\Support\SyntheticApprovedExamSources::write($dir,$values+['catalog_snapshot'=>Course::where('course_code','ES421')->get()->map->getAttributes()->all()]);$app->instance(ExamSources::class,$f['reader']);
$catalogBundle=$f['reader']->prepare($f['inputs']);\App\Services\StudentCompletion\CompletionFiles::write($dir.'/bundle.json',$catalogBundle);$bundleHash=hash_file('sha256',$dir.'/bundle.json');
$catalogBundle=$f['reader']->load($dir.'/bundle.json',$bundleHash);$catalog=app(\App\Services\HistoricalExams\ExamCatalogCreditService::class);$planPreview=$catalog->preview($scientific,$catalogBundle,['RU2-ES-TS']);$check($planPreview['status']==='READY','Synthetic Sports plan not reviewable');
// The credit-race source is still an ordinary, unapplied target. A fresh review precedes publication.
$beforeImport=$service->preview($actor,$b);$check($beforeImport['counts']['eligible']===1,'Publication race import not eligible');
$origins=$pdo->query('SELECT * FROM courses ORDER BY course_id')->fetchAll(PDO::FETCH_ASSOC);
DB::beginTransaction();$tx->revision(true);$catalogOperation=(string)Str::uuid();$publication=$catalog->apply($scientific,$catalogBundle,$planPreview['review'],$planPreview['review_hash'],$catalogOperation,$planPreview['review']['revision'],false);
$worker=$spawn(['bundle'=>$b,'review'=>$beforeImport['review'],'hash'=>$beforeImport['review_hash'],'id'=>(string)Str::uuid(),'revision'=>$beforeImport['review']['revision']]);$wait($worker[2]);DB::commit();$rejected=$close($worker);
$check(!$rejected['ok']&&$rejected['code']==='historical_exam_stale','Catalog publication was not serialized against import');
$check($origins===$pdo->query('SELECT * FROM courses ORDER BY course_id')->fetchAll(PDO::FETCH_ASSOC),'Publication changed shared course origins');
$published=$publication['programs'][0];$check((int)DB::table('academic_plan_versions')->where('academic_plan_version_id',$published['published_version_id'])->value('total_credit_hours')===142,'Sports target total differs');
$check(AcademicPlanContext::forStudent($oldStudent)->versionId===$published['source_version_id'],'Existing Sports student transferred');
$check($catalog->status($scientific,$catalogOperation,$catalogBundle)['publication_verified'],'Published version verification failed');
$check($publication===$catalog->apply($scientific,$catalogBundle,$planPreview['review'],$planPreview['review_hash'],$catalogOperation,'0',true),'Publication replay changed');
$check(hash_file('sha256',$dir.'/bundle.json')===$bundleHash,'Persisted catalog bundle changed');
echo "PASS persisted catalog artifact / canonical atomic Sports 141->142 publication / retained old student + origins / independent import wait / replay + publication verification\n";

// The merged proposal service is not called by reconciliation. Its competing real
// offering mutation must nevertheless take the same installed catalog-first lock.
if(!Schema::hasTable('offering_proposals'))Schema::create('offering_proposals',function(\Illuminate\Database\Schema\Blueprint $t){
    $t->increments('offering_proposal_id');foreach(['academic_year_id','semester_id','department_id','submitted_by_user_id','decided_by_user_id'] as $c)$t->integer($c)->nullable();
    $t->string('status');$t->text('decision_note')->nullable();$t->dateTime('submitted_at')->nullable();$t->dateTime('decided_at')->nullable();$t->timestamps();
});
if(!Schema::hasTable('offering_proposal_items'))Schema::create('offering_proposal_items',function(\Illuminate\Database\Schema\Blueprint $t){
    $t->increments('offering_proposal_item_id');foreach(['offering_proposal_id','course_id','academic_program_id','faculty_member_id','capacity'] as $c)$t->integer($c)->nullable();
    $t->string('course_type');$t->boolean('instructor_exception')->default(false);$t->text('exception_reason')->nullable();$t->timestamps();
});
$year=DB::table('academic_years')->insertGetId(['year_name'=>'2201-2202','start_date'=>'2201-09-01','end_date'=>'2202-08-31','is_current'=>false],'academic_year_id');
$o=app(\App\Services\CourseOfferingContextService::class)->createManualRecordingOffering($student,$course,$year,1,$actor);
foreach(\App\Services\HistoricalExams\ExamComponentMapping::aggregateComponents() as $def){unset($def['grade_component_id']);\App\Models\GradeComponent::create(['course_offering_id'=>$o->getKey()]+$def);}
app(\App\Services\RegistrationService::class)->prepareManualGradeRegistration($student,$o,$actor,['confirmed'=>true,'reason'=>'Synthetic competing proposal fixture, no historical result','course_id'=>$course->getKey(),'academic_year_id'=>$year,'semester_id'=>1]);
$dir=$environment->config['directory'].'/approved-offering-proposal';mkdir($dir,0700);$f=\Tests\Support\SyntheticApprovedExamSources::write($dir,array_replace($values,['year'=>'2201-2202','coursework'=>'30','exam'=>'50','total'=>'80','condition'=>'T']));
$app->instance(ExamSources::class,$f['reader']);$b=$f['reader']->prepare($f['inputs']);$p=$service->preview($actor,$b);$check($p['counts']['eligible']===1,'Proposal race context not eligible');
$proposal=\App\Models\OfferingProposal::create(['academic_year_id'=>$year,'semester_id'=>1,'department_id'=>$o->department_id,'submitted_by_user_id'=>$scientific->getKey(),'status'=>'submitted','submitted_at'=>now()]);
\App\Models\OfferingProposalItem::create(['offering_proposal_id'=>$proposal->getKey(),'course_id'=>$course->getKey(),'academic_program_id'=>$student->academic_program_id,'course_type'=>'mandatory','instructor_exception'=>true,'exception_reason'=>'Synthetic no-instructor test','capacity'=>40]);
$gradeHistory=$pdo->query('SELECT * FROM student_course_results ORDER BY student_course_result_id')->fetchAll(PDO::FETCH_ASSOC);
DB::beginTransaction();$tx->revision(true);app(\App\Services\OfferingProposalService::class)->approve($scientific,(int)$proposal->getKey());
$worker=$spawn(['bundle'=>$b,'review'=>$p['review'],'hash'=>$p['review_hash'],'id'=>(string)Str::uuid(),'revision'=>$p['review']['revision']]);$wait($worker[2]);DB::commit();$rejected=$close($worker);
$check(!$rejected['ok']&&$rejected['code']==='historical_exam_stale','Proposal approval did not invalidate the historical review');
$check($gradeHistory===$pdo->query('SELECT * FROM student_course_results ORDER BY student_course_result_id')->fetchAll(PDO::FETCH_ASSOC),'Proposal approval changed academic results');
echo "PASS independent merged offering-proposal approval / installed catalog serialization / stale import rejected / no result or teaching assignment invented by reconciliation\n";
