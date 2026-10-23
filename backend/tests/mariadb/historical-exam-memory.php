<?php
/** Full-size synthetic acceptance. Runs ONLY on CatalogEnvironment's newly marked isolated server. */
require __DIR__.'/CatalogEnvironment.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use App\Models\{Student,Course,User};
use App\Services\{AcademicCatalogTransaction,AcademicPlanContext};
use App\Services\HistoricalExams\{ExamSources,ExamApprovedAuthority,ExamCompletionService,ExamComponentMapping};
use App\Services\StudentCompletion\{CompletionData,CompletionFiles,CompletionJson,CompletionReceipts};
use Illuminate\Support\Facades\{Artisan,DB};
use Illuminate\Support\Str;

$mode=$argv[1]??'run';$environment=new CatalogEnvironment;$directory=$environment->config['directory'].'/memory-acceptance';
if(!is_dir($directory))mkdir($directory,0700);
if(in_array($mode,['baseline','baseline-receipt'],true)) {
    $file=$environment->config['directory'].'/memory-baseline-receipts.php';$hash=getenv('HISTORICAL_EXAM_BASELINE_HASH');
    if(!is_file($file)||!is_string($hash)||!hash_equals($hash,hash_file('sha256',$file)))throw new RuntimeException('Pinned public baseline code required');
    require $file; // Exact reviewed public baseline, not source workbook/SQL/PHP from a handoff.
}
$check=fn($ok,$message)=>$ok?:throw new RuntimeException($message);
$smoke=getenv('HISTORICAL_EXAM_MEMORY_SMOKE')==='1';$representative=getenv('HISTORICAL_EXAM_MEMORY_ACTIONS')==='representative';
$total=$smoke?131:16461;$drafts=$smoke?2:($representative?32:1854);$stages=$smoke?2:($representative?32:1409);
$started=microtime(true);$reserve=str_repeat('r',1048576);
register_shutdown_function(function()use($mode,$directory,$started,&$reserve){$reserve='';$error=error_get_last();$rss=null;
    if(PHP_OS_FAMILY==='Windows'){$out=[];$exit=1;exec('powershell.exe -NoProfile -NonInteractive -Command "[Console]::Write((Get-Process -Id '.getmypid().').PeakWorkingSet64)"',$out,$exit);$v=trim(implode('',$out));if($exit===0&&ctype_digit($v))$rss=(int)$v;}
    else {$usage=getrusage();if(isset($usage['ru_maxrss']))$rss=(int)$usage['ru_maxrss']*(PHP_OS_FAMILY==='Darwin'?1:1024);}
    file_put_contents($directory.'/metrics-'.$mode.'.json',json_encode(['mode'=>$mode,'peak_php_bytes'=>memory_get_peak_usage(true),'duration_seconds'=>round(microtime(true)-$started,3),
        'os_peak_working_set_bytes'=>$rss,'php'=>PHP_VERSION,'memory_limit'=>ini_get('memory_limit'),'fatal_location'=>$error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)?basename($error['file']).':'.$error['line']:null,
        'fatal'=>$error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)?$error['message']:null],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));});
$app=$environment->laravel();$environment->guard(DB::connection()->getPdo());
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$fingerprint=fn($tables)=>CompletionData::hash(array_combine($tables,array_map(fn($table)=>DB::table($table)->orderByRaw('1')->get()->map(fn($r)=>(array)$r)->all(),$tables)));

if($mode==='progress') {
    $operation=is_file($directory.'/source-job.json')?CompletionJson::file($directory.'/source-job.json',[])['operation_id']:null;
    echo json_encode(['isolated_only'=>true,'writes_performed'=>false,'synthetic_students'=>DB::table('students')->where('student_number','like','SYN-MEM-%')->count(),
        'synthetic_registrations'=>DB::table('student_course_registrations')->whereIn('student_id',DB::table('students')->where('student_number','like','SYN-MEM-%')->select('student_id'))->count(),
        'operation_credit_rows'=>$operation?DB::table('registration_credit_evidence')->where('operation_id',$operation)->count():0,
        'operation_work_items'=>$operation?DB::table('historical_exam_work_items')->where('operation_id',$operation)->count():0,
        'current_revision'=>app(AcademicCatalogTransaction::class)->revision(),'bundle_exists'=>is_file($directory.'/bundle.json'),
        'preview_exists'=>is_file($directory.'/preview.json'),'resumed_result_exists'=>is_file($directory.'/resumed.json')],JSON_THROW_ON_ERROR)."\n";exit;
}

if($mode==='run') {
    $phases=['prepare','preview','start','baseline','baseline-receipt','status-start','interrupt','resume','status-result','verify','replay'];$results=[];
    foreach($phases as $phase) {
        if(str_starts_with($phase,'baseline')&&($smoke||!is_file($environment->config['directory'].'/memory-baseline-receipts.php')))continue;
        $log=$directory.'/phase-'.$phase.'.stdout';$err=$directory.'/phase-'.$phase.'.stderr';
        $p=proc_open([PHP_BINARY,'-d','memory_limit=2048M',__FILE__,$phase],[['pipe','r'],['file',$log,'w'],['file',$err,'w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Cannot spawn isolated memory phase');fclose($pipes[0]);$exit=proc_close($p);
        $metrics=json_decode(file_get_contents($directory.'/metrics-'.$phase.'.json'),true,flags:JSON_THROW_ON_ERROR);$metrics['exit']=$exit;$results[]=$metrics;
        echo json_encode($metrics,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";flush();
        if(str_starts_with($phase,'baseline')){$check($exit!==0&&str_contains($metrics['fatal']??'','Allowed memory size'),'Baseline must reproduce bounded-memory failure');}
        else {$check($exit===0&&$metrics['fatal']===null,'Memory phase failed: '.$phase.'; inspect private synthetic stderr');
            $check($metrics['peak_php_bytes']<2147483648&&($metrics['os_peak_working_set_bytes']===null||$metrics['os_peak_working_set_bytes']<2147483648),'2 GiB memory budget exceeded');}
    }
    $job=CompletionJson::file($directory.'/apply-job.json',[]);$p=CompletionJson::file($directory.'/preview.json',['review.actions']);
    $summary=['synthetic_only'=>true,'scale'=>$smoke?'SMOKE':($representative?'PRODUCTION_SIZED_ARTIFACTS_REPRESENTATIVE_WRITES':'PRODUCTION_SIZED_ARTIFACTS_FULL_CANDIDATE_COUNTS'),
        'expected_draft_writes'=>$drafts,'expected_staging_writes'=>$stages,'server_version'=>DB::selectOne('SELECT VERSION() version')->version,'php'=>PHP_VERSION,'observations'=>$total,
        'bundle_bytes'=>filesize($directory.'/bundle.json'),'report_bytes'=>filesize($directory.'/preview.json'),'review_bytes'=>filesize($directory.'/preview.json.review.json'),
        'review_counts'=>$p['counts'],'phases'=>$results,'production_connected'=>false,'real_data_applied'=>false,'baseline_code_sha256'=>getenv('HISTORICAL_EXAM_BASELINE_HASH')?:null];
    if(!$smoke){$check($summary['bundle_bytes']>=38000000,'Bundle smaller than production incident');$check($summary['review_bytes']>=243000000,'Review smaller than production incident');}
    CompletionFiles::write($directory.'/verification-summary.json',$summary);echo 'PASS '.$summary['scale']." bounded historical-exams CLI, immutable legacy chunks, interruption, same UUID resume/replay, official history\n";exit;
}

if($mode==='prepare') {
    $actor=User::findOrFail(20000);$course=Course::findOrFail(1);$people=[];
    for($i=0;$i<$drafts+$stages+30;$i++) {
        $student=AcademicPlanContext::transaction(fn()=>Student::create(['student_number'=>'SYN-MEM-'.($i+1),'first_name'=>'Synthetic','last_name'=>'Memory '.($i+1),'father_name'=>'Synthetic Parent',
            'academic_program_id'=>900,'current_academic_level_id'=>1,'enrollment_date'=>'2023-12-10','student_status_id'=>1]));
        $people[]=['student_id'=>$student->getKey(),'number'=>$student->student_number,'name'=>'Synthetic Memory '.($i+1)];
    }
    $student=Student::findOrFail($people[0]['student_id']);$program=$student->academicProgram()->with('department.college')->first();
    $year=DB::table('academic_years')->insertGetId(['year_name'=>'2390-2391','start_date'=>'2390-09-01','end_date'=>'2391-08-31','is_current'=>false],'academic_year_id');
    $offering=app(\App\Services\CourseOfferingContextService::class)->createManualRecordingOffering($student,$course,$year,1,$actor);
    foreach(ExamComponentMapping::aggregateComponents() as $def){unset($def['grade_component_id']);\App\Models\GradeComponent::create(['course_offering_id'=>$offering->getKey()]+$def);}
    foreach($people as $i=>$person) {
        $r=app(\App\Services\RegistrationService::class)->prepareManualGradeRegistration(Student::findOrFail($person['student_id']),$offering,$actor,
            ['confirmed'=>true,'reason'=>'Synthetic memory acceptance only','course_id'=>$course->getKey(),'academic_year_id'=>$year,'semester_id'=>1]);
        if($i>=$drafts+$stages) {
            foreach($offering->gradeComponents as $component)DB::table('student_grade_components')->insert(['student_course_registration_id'=>$r->getKey(),'grade_component_id'=>$component->getKey(),'mark'=>$component->component_type==='theoretical'?50:30,'grade_status'=>'draft']);
        }
    }
    $values=['college'=>$program->department->college->college_name,'program'=>$program->program_name,'term'=>'1','code'=>$course->course_code,'course'=>$course->course_name,'target_course_name'=>$course->course_name,
        'credits'=>(string)$course->credit_hours,'year'=>'2390-2391'];
    $rows=function(int $count,int $offset=0)use($people,$drafts,$stages,$values){for($i=0;$i<$count;$i++){$index=($i+$offset)%($drafts+$stages);$p=$people[$index];$ungraded=$index>=$drafts;
        yield $values+['number'=>$p['number'],'name'=>$p['name'],'coursework'=>$ungraded?'-':'30','exam'=>$ungraded?'-':'50','total'=>$ungraded?'-':'80','condition'=>$ungraded?'غير مكتمل':'T',
            'notes'=>str_repeat('Synthetic preserved source observation. ',12)];}};
    $fixture=\Tests\Support\SyntheticApprovedExamSources::write($directory,$values+['synthetic_rows'=>['exam1'=>$rows($total-1),'exam2'=>$rows(1)]]);
    $app->instance(ExamSources::class,$fixture['reader']);$bundle=$fixture['reader']->prepare($fixture['inputs']);CompletionFiles::write($directory.'/bundle.json',$bundle);
    $preserved=['student_course_results','grade_approvals','grade_part_approvals','grade_part_approval_events','historical_exam_outcomes',
        'student_course_registrations','grade_components','student_academic_plan_assignments','academic_plan_versions','academic_plan_events'];
    $unrelatedIds=DB::table('student_course_registrations')->whereIn('student_id',array_column(array_slice($people,$drafts+$stages),'student_id'))->pluck('student_course_registration_id')->all();
    $unrelatedMarks=DB::table('student_grade_components')->whereIn('student_course_registration_id',$unrelatedIds)->orderBy('student_grade_component_id')->get()->map(fn($r)=>(array)$r)->all();
    CompletionFiles::write($directory.'/source-job.json',['pins'=>$fixture['pins'],'operation_id'=>(string)Str::uuid(),'actor_id'=>20000,'preserved_tables'=>$preserved,'preserved_hash'=>$fingerprint($preserved),
        'preserved_row_counts'=>array_combine($preserved,array_map(fn($table)=>DB::table($table)->count(),$preserved)),
        'unrelated_registration_ids'=>$unrelatedIds,'unrelated_marks_hash'=>CompletionData::hash($unrelatedMarks),'unrelated_marks_count'=>count($unrelatedMarks),
        'bundle_hash'=>hash_file('sha256',$directory.'/bundle.json'),'source_hashes'=>$bundle['sources']]);
    echo "PASS prepared synthetic workbooks and existing-row context\n";exit;
}

$job=CompletionJson::file($directory.'/source-job.json',[]);$app->instance(ExamSources::class,new ExamSources(new ExamApprovedAuthority($job['pins'])));
$args=['--actor'=>(string)$job['actor_id'],'--bundle'=>$directory.'/bundle.json','--bundle-hash'=>$job['bundle_hash']];
if($mode==='preview') {
    $exit=Artisan::call('historical-exams:preview',$args+['--output'=>$directory.'/preview.json']);$check($exit===2,'Expected review with drafts/staging');
    $p=CompletionJson::file($directory.'/preview.json',['review.actions']);$check($p['counts']['observations']===$total,'Observation count changed');
    $classes=$p['counts']['classifications'];$check(($classes['canonical_draft']??$classes['draft_candidate']??$classes['missing_components']??-1)===$drafts||$p['counts']['eligible']===$drafts+$stages,'Unexpected synthetic readiness distribution');
    CompletionFiles::write($directory.'/apply-job.json',['review_hash'=>$p['review_hash'],'revision'=>$p['review']['revision'],'bundle_hash'=>$job['bundle_hash'],
        'review_file_hash'=>hash_file('sha256',$directory.'/preview.json.review.json')]);echo Artisan::output();exit;
}
$apply=CompletionJson::file($directory.'/apply-job.json',[]);$id=$job['operation_id'];
$write=$args+['--review'=>$directory.'/preview.json.review.json','--review-hash'=>$apply['review_hash'],'--operation-id'=>$id,'--expected-revision'=>$apply['revision']];
if(in_array($mode,['baseline','baseline-receipt'],true)) {
    $b=$mode==='baseline'?json_decode(file_get_contents($directory.'/bundle.json'),true,flags:JSON_THROW_ON_ERROR):app(ExamSources::class)->load($directory.'/bundle.json',$job['bundle_hash']);
    $r=$mode==='baseline'?json_decode(file_get_contents($directory.'/preview.json.review.json'),true,flags:JSON_THROW_ON_ERROR):CompletionJson::file($directory.'/preview.json.review.json');
    app(ExamCompletionService::class)->authorizeReview(User::findOrFail($job['actor_id']),$r);
    $start=app(CompletionReceipts::class)->find('exam_start',$id);
    echo CompletionData::hash([$b['sources'],$r['contract'],$start['value']['digest']]);exit;
}
if(in_array($mode,['start','interrupt'],true)) {
    $armed=true;$action='student_completion.'.($mode==='start'?'exam_start':'exam_action');
    DB::listen(function($q)use(&$armed,$action){if($armed&&str_starts_with(strtolower($q->sql),'insert into `user_activity_logs`')&&in_array($action,$q->bindings,true)){
        $armed=false;DB::afterCommit(fn()=>throw new RuntimeException('Synthetic process interruption after durable commit'));}});
    $exit=Artisan::call('historical-exams:apply',$write+($mode==='interrupt'?['--resume'=>true]:[])+['--output'=>$directory.'/lost-'.$mode.'.json']);
    $check($exit===1&&!$armed,'Expected durable interruption');$check(!file_exists($directory.'/lost-'.$mode.'.json'),'Lost response falsely reported success');
    $check(!DB::table('user_activity_logs')->where('action_code','student_completion.exam_result')->where('description->operation_id',$id)->exists(),'Interrupted operation has final receipt');
    if($mode==='start')$check(app(AcademicCatalogTransaction::class)->revision()===$apply['revision'],'Start-only failure advanced academic revision');
    else $check(app(ExamCompletionService::class)->recoveryCheck(User::findOrFail($job['actor_id']),$id)['exam_action_receipt_headers']===1,'First action not durable');
    echo Artisan::output();exit;
}
if($mode==='resume') {
    $write['--expected-revision']=app(AcademicCatalogTransaction::class)->revision();
    $exit=Artisan::call('historical-exams:apply',$write+['--resume'=>true,'--output'=>$directory.'/resumed.json']);$check(in_array($exit,[0,2],true),'Explicit resume failed');
    $result=CompletionJson::file($directory.'/resumed.json');$check($result['saved_pending_approval']===$drafts&&$result['staged_pending_review']===$stages,'Not all expected synthetic actions completed: '.CompletionData::json([$result['saved_pending_approval'],$result['staged_pending_review']]));
    CompletionFiles::write($directory.'/post-apply.json',['logs'=>DB::table('user_activity_logs')->count(),'marks'=>DB::table('student_grade_components')->count(),
        'work'=>DB::table('historical_exam_work_items')->count(),'credits'=>DB::table('registration_credit_evidence')->count(),'result_file_hash'=>hash_file('sha256',$directory.'/resumed.json')]);
} elseif($mode==='status-start'||$mode==='status-result') {
    $exit=Artisan::call('historical-exams:status',['--actor'=>(string)$job['actor_id'],'--operation-id'=>$id,'--recovery-check'=>true,'--output'=>$directory.'/'.$mode.'.json']);
    $check($mode==='status-start'?$exit===2:in_array($exit,[0,2],true),'Status failed');
    if($mode==='status-start'){$r=CompletionJson::file($directory.'/'.$mode.'.json',[]);$check($r['current_revision']===$apply['revision']&&$r['exam_action_receipt_headers']===0&&$r['review_hash']===$apply['review_hash'],'Start-only recovery diagnostic differs');}
} elseif($mode==='verify') {
    $exit=Artisan::call('historical-exams:verify',$args+['--operation-id'=>$id,'--output'=>$directory.'/verified.json']);$check(in_array($exit,[0,2],true),'Verification failed');
    $verified=CompletionJson::file($directory.'/verified.json',[]);$check($verified['writes_performed']===false&&$verified['current_adopted_work_items']===0
        &&$verified['saved_pending_approval']===$drafts&&$verified['staged_pending_review']===$stages,'Verification lost counts or adopted work automatically');
} elseif($mode==='replay') {
    $write['--expected-revision']='0';$exit=Artisan::call('historical-exams:apply',$write+['--output'=>$directory.'/replayed.json']);$check(in_array($exit,[0,2],true),'Exact saved operation replay failed');
    $after=CompletionJson::file($directory.'/post-apply.json',[]);$check($after['result_file_hash']===hash_file('sha256',$directory.'/replayed.json'),'Replay result changed');
    foreach(['logs'=>'user_activity_logs','marks'=>'student_grade_components','work'=>'historical_exam_work_items','credits'=>'registration_credit_evidence'] as $key=>$table)$check($after[$key]===DB::table($table)->count(),'Replay duplicated '.$key);
} else throw new RuntimeException('Unknown memory phase');
$check($job['preserved_hash']===$fingerprint($job['preserved_tables']),'Official results/approvals/timestamps/history changed');
$unrelatedMarks=DB::table('student_grade_components')->whereIn('student_course_registration_id',$job['unrelated_registration_ids'])->orderBy('student_grade_component_id')->get()->map(fn($r)=>(array)$r)->all();
$check($job['unrelated_marks_hash']===CompletionData::hash($unrelatedMarks),'Unrelated completed component content changed');
$check($job['bundle_hash']===hash_file('sha256',$directory.'/bundle.json')&&$apply['review_file_hash']===hash_file('sha256',$directory.'/preview.json.review.json'),'Immutable source/review file changed');
echo Artisan::output();
