<?php

namespace Tests\Support;

use App\Exceptions\GradeException;
use App\Models\CourseIncompleteCase;
use App\Services\IncompleteResultService;
use App\Support\{IncompleteOutcomeGuard as Guard, IncompleteSchemaContract as Contract};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Called only after the disposable harness's server/database identity checks. */
final class IncompleteMariaDbReviewChecks
{
    private static function rows():array
    {
        $out=[];
        foreach([Guard::CASES,Guard::EVENTS,'student_course_results','student_course_registrations','student_grade_components'] as $table)$out[$table]=DB::table($table)->orderByRaw('1')->get()->toArray();
        return $out;
    }
    private static function definitions():array
    {
        $out=[];
        foreach([Guard::CASES,Guard::EVENTS,'student_course_results'] as $table)$out[$table]=(array)DB::selectOne('SHOW CREATE TABLE '.$table);
        $out['triggers']=DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE',[Guard::CASES,Guard::EVENTS])
            ->orderBy('TRIGGER_NAME')->get(['TRIGGER_NAME','EVENT_OBJECT_TABLE','EVENT_MANIPULATION','ACTION_TIMING','ACTION_STATEMENT','CREATED'])->toArray();
        return $out;
    }
    public static function incompatibleDefinitions(int $caseId):void
    {
        IncompleteCatalogFixture::assertIsolated();
        $c=Guard::CASES; $e=Guard::EVENTS;
        $beforeResume=self::rows();
        $retained=DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE',[$c,$e])
            ->where('TRIGGER_NAME','<>','crs_case_history_d')->orderBy('TRIGGER_NAME')->get(['TRIGGER_NAME','ACTION_STATEMENT','CREATED'])->toArray();
        DB::unprepared('DROP TRIGGER crs_case_history_d');
        \check(!Guard::ready(),'Missing protection was reported ready.');
        $pre=\package('00_preflight.sql');\check(end($pre)['result']==='READY','Compatible missing trigger could not resume.');
        \check(\package('01_apply.sql')[0]['result']==='RESUMED','Compatible trigger-only installation did not report RESUMED.');
        \check(\package('01_apply.sql')[0]['result']==='ALREADY_APPLIED','Complete reapplication failed.');
        \check(self::rows()==$beforeResume,'Resume changed academic rows.');
        \check(DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE',[$c,$e])
            ->where('TRIGGER_NAME','<>','crs_case_history_d')->orderBy('TRIGGER_NAME')->get(['TRIGGER_NAME','ACTION_STATEMENT','CREATED'])->toArray()==$retained,'Resume replaced an existing recognized trigger.');
        \check(Guard::ready(),'Resumed protection is not ready.');
        echo "COMPATIBLE_RESUME | PASS | populated rows and existing trigger definitions/timestamps preserved\n";
        $restoreTrigger=function(string $name):void{$t=Contract::triggers()[$name];DB::unprepared('DROP TRIGGER IF EXISTS '.$name);DB::unprepared("CREATE TRIGGER $name BEFORE $t[1] ON $t[0] FOR EACH ROW $t[2]");};
        $foreignKey=function(string $target,string $rule='RESTRICT')use($c):void{
            // MariaDB cannot replace the same FK name inside one ALTER copy.
            DB::statement("ALTER TABLE $c DROP FOREIGN KEY fk_crs_actor");
            DB::statement("ALTER TABLE $c ADD CONSTRAINT fk_crs_actor FOREIGN KEY(granted_by_user_id) REFERENCES $target ON DELETE $rule ON UPDATE RESTRICT");
        };
        $cases=[
            'deadline-type'=>[fn()=>DB::statement("ALTER TABLE $c MODIFY deadline_at VARCHAR(19) NOT NULL"),fn()=>DB::statement("ALTER TABLE $c MODIFY deadline_at DATETIME NOT NULL")],
            'text-capacity'=>[fn()=>DB::statement("ALTER TABLE $c MODIFY reason VARCHAR(200) NOT NULL"),fn()=>DB::statement("ALTER TABLE $c MODIFY reason TEXT NOT NULL")],
            'nullability'=>[fn()=>DB::statement("ALTER TABLE $c MODIFY deadline_at DATETIME NULL"),fn()=>DB::statement("ALTER TABLE $c MODIFY deadline_at DATETIME NOT NULL")],
            'uuid-unique-sequence'=>[fn()=>DB::statement("ALTER TABLE $e DROP INDEX uq_crs_request, ADD UNIQUE KEY uq_crs_request(request_id,incomplete_case_id)"),fn()=>DB::statement("ALTER TABLE $e DROP INDEX uq_crs_request, ADD UNIQUE KEY uq_crs_request(request_id)")],
            'revision-index-order'=>[function()use($e):void{DB::statement("ALTER TABLE $e ADD KEY crs_test_fk_support(incomplete_case_id)");DB::statement("ALTER TABLE $e DROP INDEX uq_crs_event_revision, ADD UNIQUE KEY uq_crs_event_revision(revision,incomplete_case_id)");},function()use($e):void{DB::statement("ALTER TABLE $e DROP INDEX uq_crs_event_revision, ADD UNIQUE KEY uq_crs_event_revision(incomplete_case_id,revision)");DB::statement("ALTER TABLE $e DROP INDEX crs_test_fk_support");}],
            'fk-target'=>[fn()=>$foreignKey('students(student_id)'),fn()=>$foreignKey('users(user_id)')],
            'fk-rule'=>[fn()=>$foreignKey('users(user_id)','CASCADE'),fn()=>$foreignKey('users(user_id)')],
            'required-check'=>[fn()=>DB::statement("ALTER TABLE $c DROP CONSTRAINT ck_crs_revision, ADD CONSTRAINT ck_crs_revision CHECK(revision >= 0)"),fn()=>DB::statement("ALTER TABLE $c DROP CONSTRAINT ck_crs_revision, ADD CONSTRAINT ck_crs_revision CHECK(revision >= 1)")],
            'no-op-trigger'=>[fn()=>DB::unprepared("CREATE OR REPLACE TRIGGER crs_case_history_u BEFORE UPDATE ON $c FOR EACH ROW BEGIN SET @crs_test_noop=1; END"),fn()=>$restoreTrigger('crs_case_history_u')],
            'trigger-timing'=>[fn()=>DB::unprepared("CREATE OR REPLACE TRIGGER crs_case_history_d AFTER DELETE ON $c FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_case_history_immutable'; END"),fn()=>$restoreTrigger('crs_case_history_d')],
            'trigger-target'=>[function()use($e):void{DB::unprepared('DROP TRIGGER crs_case_history_d');DB::unprepared("CREATE TRIGGER crs_case_history_d BEFORE DELETE ON $e FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_case_history_immutable'; END");},fn()=>$restoreTrigger('crs_case_history_d')],
        ];
        foreach($cases as $name=>[$alter,$restore]){
            $before=self::rows(); $alter(); $altered=self::definitions();
            try{
                $pre=\package('00_preflight.sql');\check(end($pre)['result']==='BLOCKED',$name.' preflight accepted incompatible definition.');
                $verify=\package('02_verify.sql');\check(end($verify)['result']==='FAIL',$name.' verify accepted incompatible definition.');
                \check(!Guard::ready(),$name.' runtime accepted incompatible definition.');
                try{app(IncompleteResultService::class)->expire($caseId);throw new \RuntimeException($name.' operation bypassed readiness.');}catch(GradeException $error){\check($error->status===503,$name.' did not return controlled schema failure.');}
                try{\package('01_apply.sql');throw new \RuntimeException($name.' apply accepted incompatible definition.');}catch(\PDOException $error){\check(str_contains($error->getMessage(),'course_result_symbols_schema_incompatible'),$name.' uncontrolled installer failure.');}
                \check(self::definitions()==$altered,$name.' installer overwrote an unrecognized definition.');
                \check(self::rows()==$before,$name.' rejection changed academic data.');
            }finally{$restore();}
            \check(Guard::ready(),$name.' did not recover after explicit test-fixture restoration.');
            echo 'NEGATIVE_SCHEMA | '.$name." | BLOCKED/FAIL/503; installer and data unchanged\n";
        }
    }
    public static function expirationProgress(string $directory):void
    {
        IncompleteCatalogFixture::assertIsolated();
        if(realpath($directory)!==realpath(dirname(getenv('COURSE_SYMBOLS_MARIADB_CONFIG'))))throw new \RuntimeException('Isolated cache directory required.');
        CarbonImmutable::setTestNow('2026-10-18 12:00:00 UTC');
        foreach(CourseIncompleteCase::where('state','active')->where('deadline_at','<','2026-10-18 12:00:00')->pluck('incomplete_case_id') as $id)app(IncompleteResultService::class)->expire((int)$id);
        $blocked=\context(1900);$valid=\context(1901);
        DB::table('student_course_results')->where('student_course_registration_id',1900)->update(['final_mark'=>100]);
        $before=self::rows();
        $caseBefore=CourseIncompleteCase::findOrFail($blocked['case']['incomplete_case_id'])->toArray();
        $eventsBefore=DB::table(Guard::EVENTS)->where('incomplete_case_id',$blocked['case']['incomplete_case_id'])->get()->toArray();
        foreach([1,0,1] as $i=>$expectedExit){
            $tag='progress-'.$i;
            $process=proc_open([PHP_BINARY,dirname(__DIR__).'/mariadb/course-result-symbols.php','worker',$tag,'expire-batch','0','0','0'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            \check(is_resource($process),'Progress process unavailable.');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            \check(proc_close($process)===0,'Progress process failed: '.$out.$err);
            $result=json_decode(file_get_contents($directory.'/result-'.$tag),true,flags:JSON_THROW_ON_ERROR);
            \check($result['ok']&&$result['result']['exit']===$expectedExit,'Unexpected progress command outcome.');
            if($expectedExit===1)\check(str_contains($result['result']['output'],'incomplete_outcome_conflict'),'Blocked error was hidden.');
            if($i===0)\check(CourseIncompleteCase::findOrFail($valid['case']['incomplete_case_id'])->state==='active','Batch exceeded its bound.');
        }
        \check(CourseIncompleteCase::findOrFail($valid['case']['incomplete_case_id'])->state==='expired','Later valid case starved.');
        \check(CourseIncompleteCase::findOrFail($blocked['case']['incomplete_case_id'])->toArray()===$caseBefore,'Blocked case changed.');
        \check(DB::table(Guard::EVENTS)->where('incomplete_case_id',$blocked['case']['incomplete_case_id'])->get()->toArray()==$eventsBefore,'Blocked history changed.');
        $after=self::rows();
        \check(count($after[Guard::EVENTS])===count($before[Guard::EVENTS])+1,'Expiration did not append exactly one valid event.');
        \check(array_slice($after[Guard::EVENTS],0,count($before[Guard::EVENTS]))==$before[Guard::EVENTS],'Existing event history changed.');
        foreach($before as $table=>$rows){
            if($table===Guard::EVENTS)continue;
            $key=match($table){Guard::CASES=>'incomplete_case_id','student_course_results'=>'student_course_result_id','student_course_registrations'=>'student_course_registration_id',default=>'student_grade_component_id'};
            foreach($rows as $row){
                $belongsToValid=(int)($row->student_course_registration_id??0)===1901;
                if(!$belongsToValid)\check(collect($after[$table])->firstWhere($key,$row->{$key})==$row,'Unrelated row changed during expiration progress.');
            }
        }
        echo "EXPIRATION_PROGRESS | PASS | batch=1, three independent processes, persistent file cursor; blocked history unchanged\n";
    }
}
