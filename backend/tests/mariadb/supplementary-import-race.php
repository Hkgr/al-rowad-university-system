<?php

/** Actual Phase-6 materializer / v4 importer, marked disposable MariaDB only. */
require __DIR__.'/CatalogEnvironment.php';

use App\Models\{Course, Student, SupplementaryExamOffering, User};
use App\Services\{AcademicPlanContext, SupplementaryExamMaterializationService};
use App\Services\HistoricalExams\{ExamApprovedAuthority, ExamCompletionService, ExamSources};
use App\Services\StudentCompletion\{CompletionData, CompletionFiles, CompletionReceipts};
use App\Support\{HistoricalExamSchema, SupplementaryExamMaterializationGovernance};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\{SyntheticApprovedExamSources, SyntheticSupplementaryRace};

$environment=new CatalogEnvironment; $app=$environment->laravel(); $pdo=DB::connection()->getPdo(); $environment->guard($pdo);
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$check=fn($v,$m)=>$v?:throw new RuntimeException($m);
$read=fn($pipe)=>json_decode(fgets($pipe)?:throw new RuntimeException('Worker ended before acknowledgement'),true,flags:JSON_THROW_ON_ERROR);

if(($argv[1]??'')==='worker') {
    DB::statement('SET SESSION innodb_lock_wait_timeout=60');
    echo json_encode(['ready'=>(int)DB::selectOne('SELECT CONNECTION_ID() id')->id])."\n";flush();
    $job=$read(STDIN); $locks=0; $paused=false;
    DB::listen(function($query)use(&$locks,&$paused,$job,$read){
        if(str_contains(strtolower($query->sql),'academic_catalog_control')&&str_contains(strtolower($query->sql),'for update')) {
            $locks++;
        }
        $write=$job['mode']==='import'
            ? str_starts_with(strtolower($query->sql),'insert into `user_activity_logs`')&&in_array('student_completion.exam_result',$query->bindings,true)
            : str_starts_with(strtolower($query->sql),'update `student_course_results`');
        if(($job['pause_at']??0)>0&&!$paused&&$write) {
            $paused=true;
            if($locks<1||DB::transactionLevel()<1)throw new RuntimeException('Real write did not hold the canonical catalog transaction');
            echo json_encode(['boundary'=>'catalog_lock_acquired','mode'=>$job['mode'],'connection'=>(int)$query->connection->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),'lock_number'=>$locks,'transaction_level'=>DB::transactionLevel(),'uncommitted_write_boundary'=>$query->sql])."\n";flush();
            if(($read(STDIN)['release']??false)!==true)throw new RuntimeException('Explicit release required');
        }
    });
    try {
        $actor=User::findOrFail(20000);
        if($job['mode']==='import') {
            $reader=new ExamSources(new ExamApprovedAuthority($job['pins']));$app->instance(ExamSources::class,$reader);
            $bundle=$reader->load($job['bundle_path'],$job['bundle_hash']);
            $result=app(ExamCompletionService::class)->apply($actor,$bundle,$job['review'],$job['hash'],$job['id'],$job['revision']);
        } else $result=app(SupplementaryExamMaterializationService::class)->materializeOffering($actor,SupplementaryExamOffering::findOrFail($job['offering']));
        echo json_encode(['ok'=>true,'result'=>$result,'catalog_locks'=>$locks])."\n";
    } catch(Throwable $e) {echo json_encode(['ok'=>false,'code'=>$e->errorCode??get_class($e),'message'=>$e->getMessage(),'catalog_locks'=>$locks])."\n";}
    exit;
}
$check(($argv[1]??'')==='run','Unknown test mode');
$check(AcademicPlanContext::installed(),'Installed catalog path is required');
$version=$pdo->query('SELECT VERSION()')->fetchColumn();$isolation=$pdo->query('SELECT @@tx_isolation')->fetchColumn();
$check(str_starts_with($version,'10.11.18-MariaDB'),'This acceptance requires the actual MariaDB 10.11.18 server');
SyntheticSupplementaryRace::schema();
if(!SupplementaryExamMaterializationGovernance::schemaReady()) {
    $diagnostic=[];
    foreach(['grade_approvals','approval_statuses','registration_statuses','grading_policies','system_modules','supplementary_exam_materializations'] as $table)$diagnostic[$table]=\Illuminate\Support\Facades\Schema::getColumnListing($table);
    throw new RuntimeException('Real Phase-6 readiness failed: '.json_encode($diagnostic));
}
$actor=User::findOrFail(20000);$student=Student::where('student_number','SYN-APPROVED-EXAM')->sole();$course=Course::findOrFail(1);
$program=$student->academicProgram()->with('department.college')->first();
$directory=$environment->config['directory'].'/supplementary-import-race-'.Str::uuid();mkdir($directory,0700);
$snapshot=function()use($pdo){
    $tables=['courses','program_courses','academic_plan_versions','student_academic_plan_assignments','student_course_registrations','student_course_results','student_grade_components','grade_components','grade_approvals','grade_part_approvals','grade_part_approval_events','student_course_incomplete_cases','student_course_incomplete_events',HistoricalExamSchema::CREDITS,HistoricalExamSchema::OUTCOMES,HistoricalExamSchema::WORK,'supplementary_exam_grade_events','supplementary_exam_grade_results','supplementary_exam_grade_submissions'];
    $out=[];foreach($tables as $table)$out[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);return $out;
};
$spawn=function(array $job)use($read,$environment){
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/supplementary-import.stderr','a']],$pipes);
    $ready=$read($pipes[1]);fwrite($pipes[0],json_encode($job)."\n");fflush($pipes[0]);return ['process'=>$process,'pipes'=>$pipes,'connection'=>$ready['ready']];
};
$close=function(array $worker)use($read){$result=$read($worker['pipes'][1]);foreach($worker['pipes'] as $p)fclose($p);$exit=proc_close($worker['process']);if($exit!==0)throw new RuntimeException('Worker process failed');return $result;};
$evidence=['server_version'=>$version,'transaction_isolation'=>$isolation,'installed_catalog'=>true,'synchronization'=>'pipe barrier at the real uncommitted result/receipt write AFTER catalog FOR UPDATE, plus independent INNODB_LOCK_WAITS proof before release','races'=>[]];
foreach(['import_first','materializer_first'] as $ordinal=>$order) {
    $target=SyntheticSupplementaryRace::candidate($actor,$student,$course,$ordinal);
    AcademicPlanContext::transaction(fn()=>app(\App\Services\HistoricalExams\ExamApprovedCompletionService::class)->recordCredits($actor,$target['registration_id'],(int)$course->credit_hours,(string)Str::uuid(),hash('sha256',$order),str_repeat('a',64)));
    $folder=$directory.'/'.$order;mkdir($folder,0700);
    $fixture=SyntheticApprovedExamSources::write($folder,['number'=>$student->student_number,'name'=>'Synthetic Approved','college'=>$program->department->college->college_name,'program'=>$program->program_name,'code'=>$course->course_code,'course'=>$course->course_name,'target_course_name'=>$course->course_name,'credits'=>(string)$course->credit_hours,'year'=>$target['year_name'],'term'=>'1','coursework'=>'30','exam'=>'50','total'=>'80','condition'=>'T']);
    $app->instance(ExamSources::class,$fixture['reader']);$bundle=$fixture['reader']->prepare($fixture['inputs']);$path=$folder.'/bundle.json';CompletionFiles::write($path,$bundle);$hash=hash_file('sha256',$path);$bundle=$fixture['reader']->load($path,$hash);
    $service=app(ExamCompletionService::class);$preview=$service->preview($actor,$bundle);$action=$preview['review']['actions'][0];
    $check((int)$action['baseline']['registration']['student_course_registration_id']===$target['registration_id'],'Import did not target the supplementary registration');
    $check(!$action['eligible']&&in_array('supplementary_registration_requires_review',$action['blockers'],true),'Dedicated supplementary ownership must block the ordinary historical intent before the race');
    $id=(string)Str::uuid();
    $import=['mode'=>'import','pause_at'=>$order==='import_first'?3:0,'bundle_path'=>$path,'bundle_hash'=>$hash,'pins'=>$fixture['pins'],'review'=>$preview['review'],'hash'=>$preview['review_hash'],'id'=>$id,'revision'=>$preview['review']['revision']];
    $materialize=['mode'=>'materialize','pause_at'=>$order==='materializer_first'?1:0,'offering'=>$target['supplementary_offering_id']];
    $before=$snapshot();$first=$spawn($order==='import_first'?$import:$materialize);$boundary=$read($first['pipes'][1]);
    $check(($boundary['boundary']??null)==='catalog_lock_acquired'&&$boundary['transaction_level']>0,'First real service did not reach its locked transaction boundary');
    $check($boundary['connection']===$first['connection'],'Worker connection changed before its actual write boundary');
    $second=$spawn($order==='import_first'?$materialize:$import);$check($first['connection']!==$second['connection'],'Workers must use independent connections');
    $monitor=$environment->connect(true);$wait=null;$until=microtime(true)+25;
    file_put_contents($directory.'/barrier-connections.json',json_encode(['first'=>$first['connection'],'second'=>$second['connection'],'boundary'=>$boundary]));
    do {
        // A fresh guarded observer avoids retaining a connection-local metadata
        // snapshot from before the contender entered its InnoDB transaction.
        $monitor=$environment->connect(true);
        // Retain the engine's transaction IDs and wait edge independently;
        // correlate the observed connections without relying on a metadata self-join.
        $transactions=array_column($monitor->query('SELECT trx_id,trx_mysql_thread_id,trx_query FROM information_schema.INNODB_TRX')->fetchAll(PDO::FETCH_ASSOC),null,'trx_id');
        $edges=$monitor->query('SELECT * FROM information_schema.INNODB_LOCK_WAITS')->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($directory.'/barrier-observed.json',json_encode(['transactions'=>$transactions,'edges'=>$edges]));
        foreach($edges as $edge) {
            $requester=$transactions[$edge['requesting_trx_id']]??null;$blocker=$transactions[$edge['blocking_trx_id']]??null;
            if((int)($requester['trx_mysql_thread_id']??0)===$second['connection']&&(int)($blocker['trx_mysql_thread_id']??0)===$first['connection']) {
                $wait=$edge+['requester'=>$requester['trx_mysql_thread_id'],'blocker'=>$blocker['trx_mysql_thread_id'],'trx_query'=>$requester['trx_query']];break;
            }
        }
        if($wait)break;
        // InnoDB's information-schema cache is not refreshed on every read.
        // Leave a refresh interval; elapsed time alone NEVER releases the barrier.
        usleep(250000);
    } while(microtime(true)<$until);
    if(!$wait) {
        stream_set_blocking($second['pipes'][1],false);$early=stream_get_contents($second['pipes'][1]);
        $queries=$monitor->query('SELECT ID,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID IN ('.(int)$first['connection'].','.(int)$second['connection'].')')->fetchAll(PDO::FETCH_ASSOC);
        throw new RuntimeException('Competing real service did not reach the canonical catalog lock: '.json_encode(['first_connection'=>$first['connection'],'second_connection'=>$second['connection'],'early_response'=>$early,'boundary'=>$boundary,'queries'=>$queries,'transactions'=>$monitor->query('SELECT trx_id,trx_state,trx_mysql_thread_id,trx_query FROM information_schema.INNODB_TRX')->fetchAll(PDO::FETCH_ASSOC)]));
    }
    $check(str_contains(strtolower($wait['trx_query']),'academic_catalog_control')&&str_contains(strtolower($wait['trx_query']),'for update'),'Wrong competing lock boundary');
    fwrite($first['pipes'][0],"{\"release\":true}\n");fflush($first['pipes'][0]);
    $a=$close($first);$b=$close($second);$sup=$order==='import_first'?$b:$a;$imp=$order==='import_first'?$a:$b;
    $check($sup['ok']&&$sup['result']['status']==='materialized','Actual supplementary service did not commit: '.json_encode($sup));
    $receipts=app(CompletionReceipts::class);
    if($order==='import_first') {
        $check($imp['ok']&&$imp['result']['status']==='PARTIAL'&&$imp['result']['actions'][0]['status']==='BLOCKED','Owned import must commit only an accurately blocked operational receipt');
        $check($service->status($actor,$id)===$imp['result'],'Lost-response status did not return the exact committed receipt');
        $check($service->apply($actor,$bundle,$preview['review'],$preview['review_hash'],$id,'0')===$imp['result'],'Replay after supplementary mutation did not return the original receipt before stale checks');
        foreach(['exam_start','exam_result'] as $stage)$check(DB::table('user_activity_logs')->where('action_code','student_completion.'.$stage)->where('description->operation_id',$id)->count()===1,'Duplicate import operational receipt');
        try{$service->verify($actor,$bundle,$id);throw new RuntimeException('Verification silently accepted subsequently changed academic evidence');}
        catch(\App\Services\StudentCompletion\CompletionException $e){$check($e->errorCode==='historical_exam_preserved_evidence_changed','Verification must expose the dedicated later mutation, not fabricate a historical adoption chain');}
    } else {
        $check(!$imp['ok']&&in_array($imp['code'],['historical_exam_stale','historical_exam_review_changed'],true),'Stale import committed after supplementary outcome: '.json_encode($imp));
        $check($receipts->find('exam_start',$id)===null&&$receipts->find('exam_result',$id)===null,'Rejected import left a partial receipt');
    }
    $after=$snapshot();$reg=$target['registration_id'];
    $row=DB::table('student_course_results')->where('student_course_registration_id',$reg)->sole();
    $check(\App\Services\HistoricalExams\ExamProjection::cents($row->theoretical_total)===4000&&\App\Services\HistoricalExams\ExamProjection::cents($row->practical_total)===3000&&\App\Services\HistoricalExams\ExamProjection::cents($row->coursework_total)===700&&\App\Services\HistoricalExams\ExamProjection::cents($row->final_mark)===7000,'Canonical result did not preserve practical/coursework or use the published theoretical mark');
    $check($row->result_announced_at==='2026-01-01 10:00:00','Materialization changed announcement history');
    $priorResult=collect($before['student_course_results'])->firstWhere('student_course_registration_id',$reg);
    foreach($priorResult as $key=>$value)if(!in_array($key,['theoretical_total','final_mark','result_status_id','calculated_at','calculated_by_user_id','updated_at'],true))$check($row->$key===$value,'Retained canonical outcome field changed: '.$key);
    $theory=(array)DB::table('student_grade_components')->where('student_course_registration_id',$reg)->where('grade_component_id',$target['components']['theoretical'][0])->sole();
    $priorTheory=collect($before['student_grade_components'])->first(fn($m)=>(int)$m['student_course_registration_id']===$reg&&(int)$m['grade_component_id']===$target['components']['theoretical'][0]);
    $check((string)$theory['mark']==='40.00','Published theoretical mark not materialized');
    foreach($priorTheory as $key=>$value)if(!in_array($key,['mark','updated_at'],true))$check($theory[$key]===$value,'Theoretical completion/approval metadata changed: '.$key);
    $calculation=app(\App\Services\GradeService::class)->buildCalculationForRequiredParts(40,30,true,true,60,40);
    $expectedStatus=(int)DB::table('result_statuses')->where('status_code',$calculation['result_status_code'])->value('result_status_id');
    $registration=(array)DB::table('student_course_registrations')->where('student_course_registration_id',$reg)->sole();
    $priorRegistration=collect($before['student_course_registrations'])->firstWhere('student_course_registration_id',$reg);
    $check((int)$row->result_status_id===$expectedStatus&&(int)$registration['result_status_id']===$expectedStatus,'Materialized outcome differs from the existing official calculator');
    foreach($priorRegistration as $key=>$value)if(!in_array($key,['result_status_id','updated_at'],true))$check($registration[$key]===$value,'Registration identity/status/plan context changed: '.$key);
    foreach($before as $table=>$rows) {
        if(in_array($table,['student_course_registrations','student_course_results','student_grade_components'],true)) {
            foreach($rows as $old) {
                $key=array_key_first($old);$current=collect($after[$table])->firstWhere($key,$old[$key]);
                $targetId=$table==='student_course_registrations'?$old[$key]:$old['student_course_registration_id'];
                if((int)$targetId!==$reg||($table==='student_grade_components'&&(int)$old['grade_component_id']===$target['components']['practical'][0])) $check($old===$current,'Unrelated/retained academic row changed: '.$table);
            }
            $check(count($rows)===count($after[$table]),'Duplicate canonical academic row: '.$table);
        } else $check($rows===$after[$table],'Lifecycle, credits, approvals, source evidence or work history changed: '.$table);
    }
    $materialization=DB::table('supplementary_exam_materializations')->where('student_course_registration_id',$reg)->sole();
    $check((string)$materialization->before_final_mark==='50.00'&&(string)$materialization->after_final_mark==='70.00'&&(int)$materialization->materialized_by_user_id===(int)$actor->getKey(),'Durable before/after materialization evidence does not match');
    $check(DB::table('supplementary_exam_materialization_events')->where('supplementary_exam_materialization_id',$materialization->supplementary_exam_materialization_id)->count()===1,'Missing or duplicate materialization audit');
    $check(DB::table('supplementary_exam_period_events')->where('supplementary_exam_period_id',$target['period_id'])->count()===1,'Missing or duplicate terminal-period audit');
    $stable=$snapshot();$again=app(SupplementaryExamMaterializationService::class)->materializeOffering($actor,SupplementaryExamOffering::findOrFail($target['supplementary_offering_id']));
    $check($again['already_materialized_count']===1&&$snapshot()===$stable,'Lost-response materializer replay changed academic evidence');
    $check(DB::table('supplementary_exam_materializations')->where('student_course_registration_id',$reg)->count()===1,'Duplicate materialization receipt');
    $check(hash_file('sha256',$path)===$hash,'Persisted bundle was changed');
    $evidence['races'][]=['order'=>$order,'target'=>$target,'first_connection'=>$first['connection'],'second_connection'=>$second['connection'],'boundary'=>$boundary,'observed_lock_wait'=>$wait,'import_result'=>$imp,'materialization_result'=>$sup,'before_content_hash'=>CompletionData::hash($before),'after_content_hash'=>CompletionData::hash($after),'materialization_receipt'=>(array)$materialization,'lost_response_replay_safe'=>true,'unrelated_rows_exact'=>true];
    echo 'PASS actual supplementary/import '.$order.' / observed independent INNODB catalog wait / dedicated ownership / exact preservation / receipt + replay safety'."\n";
}
file_put_contents($directory.'/concurrency-evidence.json',json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'SUPPLEMENTARY_RACE_EVIDENCE='.$directory.'/concurrency-evidence.json'."\n";
