<?php

require __DIR__.'/CatalogEnvironment.php';
use App\Models\{Student, User};
use App\Services\{AcademicCatalogTransaction, AcademicPlanContext, AcademicPlanWorkflow, GradeService};
use App\Services\CatalogCompletion\{ApprovedCatalogManifest, CatalogCompletionService, CompletionRegistrationReadiness};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CompletionRegistrationFixture as Fixture;

$environment=new CatalogEnvironment; $app=$environment->laravel(); $p=DB::connection()->getPdo();
set_exception_handler(function(Throwable $e): void {fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$check=static function($yes,string $message): void {if(!$yes)throw new RuntimeException($message);};
$revision=fn()=>app(AcademicCatalogTransaction::class)->revision();
$actor=User::findOrFail(1); $workflow=app(AcademicPlanWorkflow::class); $service=app(CatalogCompletionService::class); $manifest=new ApprovedCatalogManifest;
if (($argv[1]??'')==='worker') {
    echo json_encode(['ready'=>(int)$p->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n"; flush();
    $job=json_decode(fgets(STDIN),true,flags:JSON_THROW_ON_ERROR); echo "{\"entered\":true}\n"; flush();
    try {
        $result=$workflow->transferCatalogCompletion($actor,40,$job['version'],['request_id'=>$job['request_id'],'revision'=>$job['revision'],
            'manifest_hash'=>$manifest->hash,'student_ids'=>[1040]]);
        echo json_encode(['ok'=>true,'result'=>$result])."\n";
    } catch(Throwable $e) {echo json_encode(['ok'=>false,'code'=>$e->errorCode??get_class($e),'message'=>$e->getMessage()])."\n";}
    exit;
}
$check(($argv[1]??'')==='run','Unknown isolated test command');
echo 'SERVER_VERSION='.$p->query('SELECT VERSION()')->fetchColumn()."\n";
Fixture::prepare();
foreach (['00_preflight.sql','01_apply.sql','02_verify.sql'] as $file) {
    $rows=$environment->package($p,$file,null,'course-result-symbols-phase1');
    $last=end($rows); $check(in_array($last['result']??null,['READY','APPLIED','PASS'],true),json_encode($rows));
}
$check(\App\Support\IncompleteOutcomeGuard::ready(),'Real installed lifecycle schema not ready');
Fixture::registration(10001,1040,'open');
Fixture::registration(10002,1040,'closed',null); Fixture::lifecycle(10002,'expired','F');
Fixture::registration(10003,1040,'closed',null); Fixture::lifecycle(10003,'expired','Z');
Fixture::registration(10004,1040,'closed',null); Fixture::lifecycle(10004,'resolved');
// Nine incident-shaped synthetic publications, not a production execution list or live IDs.
app(AcademicCatalogTransaction::class)->run(function() use($manifest) {
    foreach($manifest->programs() as $program) if(in_array((int)$program['program_id'],[19,20,32,33,34,38],true))
        DB::table('academic_programs')->where('academic_program_id',$program['program_id'])->update(['program_name'=>$program['current_name']]);
});
$operation=(string)Str::uuid(); // Never the live incident operation UUID.
$first=$service->apply($actor,$manifest,$revision(),$operation);
$check($first['status']==='PARTIAL' && count($first['students'][40]['blocked'])===1,'Initial ongoing registration was not blocked');
$version=AcademicPlanContext::forProgram(40)->versionId; $source=AcademicPlanContext::forStudent(Student::findOrFail(1040))->versionId;
$check($source!==$version,'Initial partial operation silently assigned ongoing student');
$counts=fn()=>[DB::table('academic_plan_versions')->get()->all(),DB::table('courses')->get()->all(),DB::table('academic_plan_events')->where('action','catalog_completion_published')->get()->all()];
$published=$counts();
$check(count($published[2])===9,'Synthetic partial fixture did not preserve nine publications');
$priorAssignments=DB::table('student_academic_plan_assignments')->where('student_id','<>',1040)->orderBy('student_academic_plan_assignment_id')->get()->all();
// Genuine concurrent registrations and lifecycle writers advance/check the installed epoch.
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('course_offerings')->where('course_offering_id',10001)->update(['status'=>'closed']));
$ready=app(CompletionRegistrationReadiness::class)->forStudents([1040])[1040];
$check($ready['historical_settled_registrations']===4 && $ready['blocking_registrations']===0,'Closed settled registered/I F/Z/resolved did not qualify: '.json_encode($ready));
$read=function($pipe): array { $line=fgets($pipe); if($line===false) throw new RuntimeException('Independent worker closed unexpectedly'); return json_decode($line,true,flags:JSON_THROW_ON_ERROR); };
$monitor=$environment->connect();
$race=function(string $label,callable $mutation) use($revision,$workflow,$actor,$version,$manifest,$environment,$read,$monitor,$check,$source): void {
    $preview=$workflow->previewCatalogCompletion($actor,40,$version,[1040],$manifest->hash); $check($preview['can_transfer'],'Race baseline was not eligible: '.$label);
    DB::beginTransaction(); app(AcademicCatalogTransaction::class)->revision(true); $mutation();
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['file',$environment->config['directory'].'/historical-worker.stderr','a']],$pipes);
    $failure=null;
    try {
        $identity=$read($pipes[1]); fwrite($pipes[0],json_encode(['version'=>$version,'request_id'=>(string)Str::uuid(),'revision'=>$preview['revision']])."\n"); fflush($pipes[0]); $check($read($pipes[1])['entered'],'Worker did not enter');
        $waiting=false; $until=microtime(true)+20;
        do {
            $q=$monitor->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?'); $q->execute([$identity['ready']]);
            $statement=strtolower((string)$q->fetchColumn());
            if(str_contains($statement,'academic_catalog_control') && str_contains($statement,'for update')) { $waiting=true;break; } usleep(20000);
        } while(microtime(true)<$until);
        $check($waiting,'Independent assignment did not wait for the installed catalog lock: '.$label);
        DB::commit(); $result=$read($pipes[1]);
        $check(!$result['ok'] && $result['code']==='academic_catalog_stale','Changed '.$label.' accepted an old assignment review: '.json_encode($result));
        $check(AcademicPlanContext::forStudent(Student::findOrFail(1040))->versionId===$source,'A stale race assigned a student');
        echo 'PASS independent '.$label.' vs assignment, observed catalog lock wait, controlled stale, no assignment' ."\n";
    } catch(Throwable $e) {$failure=$e;}
    finally {if(DB::transactionLevel())DB::rollBack();fclose($pipes[0]);fclose($pipes[1]);$exit=proc_close($process);}
    if($failure)throw $failure; $check($exit===0,'Independent worker failed');
};
$race('new-registration',fn()=>Fixture::registration(10005,1040,'open',null));
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('student_course_registrations')->where('student_course_registration_id',10005)->update(['registration_status_id'=>DB::table('registration_statuses')->where('status_code','dropped')->value('registration_status_id')]));
$race('offering-reopen',fn()=>DB::table('course_offerings')->where('course_offering_id',10001)->update(['status'=>'open']));
app(AcademicCatalogTransaction::class)->run(fn()=>DB::table('course_offerings')->where('course_offering_id',10001)->update(['status'=>'closed']));
$race('authoritative-approval',fn()=>Fixture::approval(10001,'revoked'));
app(AcademicCatalogTransaction::class)->run(fn()=>Fixture::approval(10001,'approved'));
// A completed registration can legitimately receive I; it must then block completion as well.
app(AcademicCatalogTransaction::class)->run(function() {Fixture::registration(10006,1040,'closed',null);DB::table('student_course_registrations')->where('student_course_registration_id',10006)->update(['registration_status_id'=>1]);});
$race('canonical-I-grant',fn()=>Fixture::lifecycle(10006));
$blocked=app(CompletionRegistrationReadiness::class)->forStudents([1040])[1040]; $check(($blocked['blocking_reasons']['incomplete_active']??0)===1,'Active completed I disappeared from readiness');
$case=(int)DB::table('student_course_incomplete_cases')->where('student_course_registration_id',10006)->value('incomplete_case_id');
\Carbon\CarbonImmutable::setTestNow('2026-10-20 12:00:00 UTC'); app(\App\Services\IncompleteResultService::class)->expire($case);
$snapshot=function()use($p): array {
    $r=[]; foreach(['student_course_registrations','student_course_results','student_grade_components','grade_approvals','grade_part_approvals','grade_part_approval_events',
        'grade_audit_logs','student_course_incomplete_cases','student_course_incomplete_events','student_progression_decisions','student_graduation_decisions'] as $table)
        $r[$table]=$p->query("SELECT * FROM $table ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
    $r['offerings']=$p->query('SELECT * FROM course_offerings ORDER BY course_offering_id')->fetchAll(PDO::FETCH_ASSOC); return $r;
};
$history=$snapshot(); $grades=app(GradeService::class);$student=Student::findOrFail(1040);$gpa=$grades->calculateGpa($student,1,1);$transcript=$grades->getTranscript($student);
$resumed=$service->apply($actor,$manifest,$revision(),$operation,true);
$check(in_array(1040,$resumed['students'][40]['transferred'],true),'Same-UUID resume did not assign the newly eligible student: '.json_encode($resumed['students'][40]));
$check($published==$counts(),'Resume duplicated/rewrote published plans or origins');
$check($priorAssignments==DB::table('student_academic_plan_assignments')->where('student_id','<>',1040)->orderBy('student_academic_plan_assignment_id')->get()->all(),'Resume rewrote prior other-student assignments');
$check($history===$snapshot(),'Resume changed historical identity/pins/marks/approvals/I/events/decisions/announcements');
$check($gpa===$grades->calculateGpa($student,1,1) && $transcript===$grades->getTranscript($student),'Canonical GPA or transcript changed');
$assignments=DB::table('student_academic_plan_assignments')->get()->all();$events=DB::table('academic_plan_events')->get()->all();$logs=DB::table('user_activity_logs')->count();
$check($resumed===$service->apply($actor,$manifest,'0',$operation),'Pure stale-revision replay changed the receipt');
$check($logs===DB::table('user_activity_logs')->count(),'Pure replay wrote an operation receipt');
$service->apply($actor,$manifest,$revision(),$operation,true);
$verification=$service->verify($actor,$manifest);
$check(($verification['programs'][40]['assignment_readiness']['already_assigned_students']??null)===1,'Read-only target eligibility summary is missing or incorrect');
$check($assignments==DB::table('student_academic_plan_assignments')->get()->all() && $events==DB::table('academic_plan_events')->get()->all(),'Repeated resume duplicated assignments/academic events');
$check($history===$snapshot(),'Repeated resume changed historical evidence');
echo "PASS PARTIAL existing-publications/prior-assignments/same-UUID explicit resume/pinned contents/I F/Z missing marks/result projections/GPA/transcript/no duplicate writes\n";
