<?php

namespace Tests\Feature;

use App\Models\{User,UserActivityLog};
use App\Services\{AcademicCatalogTransaction};
use App\Services\HistoricalExams\{ExamCompletionService,ExamSources};
use App\Services\StudentCompletion\{CompletionData,CompletionException,CompletionFiles,CompletionJson,CompletionReceipts,CompletionRecords};
use Illuminate\Support\Facades\{Artisan,DB};
use Illuminate\Support\Str;
use Tests\Support\SyntheticApprovedExamSources;

/** Synthetic SQLite service/command regressions; the large MariaDB runner is separate. */
class HistoricalExamMemoryRecoveryTest extends ApprovedHistoricalExamReconciliationTest
{
    private array $memoryDirectories=[];
    protected function tearDown(): void
    {
        parent::tearDown();foreach($this->memoryDirectories as $directory){foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}
    }
    private function directory(): string { $path=sys_get_temp_dir().'/exam-memory-'.bin2hex(random_bytes(8));mkdir($path,0700);$this->memoryDirectories[]=$path;return $path; }
    private function fixture(): array
    {
        $directory=$this->directory();$rows=[];
        for($i=0;$i<130;$i++){$student=$i%2+1;$rows[]=['college'=>'College','program'=>'Program','term'=>'1','code'=>'C1','course'=>'Course','credits'=>'3','number'=>'S'.$student,'name'=>'Student '.$student,
            'year'=>'2025/2026','coursework'=>'30','exam'=>'50','total'=>'80','condition'=>'T','notes'=>str_repeat('synthetic retained evidence ',15)];}
        $f=SyntheticApprovedExamSources::write($directory,['synthetic_rows'=>['exam1'=>$rows]]);$this->app->instance(ExamSources::class,$f['reader']);
        $bundle=$f['reader']->prepare($f['inputs']);CompletionFiles::write($directory.'/bundle.json',$bundle);
        return [$directory,$f];
    }
    private function args(string $directory): array {return ['--actor'=>'1','--bundle'=>$directory.'/bundle.json','--bundle-hash'=>hash_file('sha256',$directory.'/bundle.json')];}
    public function test_historical_approved_memory_persisted_commands_resume_committed_actions_without_duplicate_marks_or_a_new_uuid(): void
    {
        [$directory]=$this->fixture();$args=$this->args($directory);$id=(string)Str::uuid();
        self::assertSame(2,Artisan::call('historical-exams:preview',$args+['--output'=>$directory.'/preview.json']),Artisan::output());
        $review=CompletionJson::file($directory.'/preview.json.review.json');$hash=CompletionData::hash($review);self::assertInstanceOf(CompletionRecords::class,$review['actions']);self::assertCount(131,$review['actions']);
        $hashes=[hash_file('sha256',$directory.'/bundle.json'),hash_file('sha256',$directory.'/preview.json.review.json')];
        $write=$args+['--review'=>$directory.'/preview.json.review.json','--review-hash'=>$hash,'--expected-revision'=>$review['revision'],'--operation-id'=>$id];
        $armed=true;DB::listen(function($q)use(&$armed){if($armed&&str_starts_with(strtolower($q->sql),'insert into "user_activity_logs"')&&in_array('student_completion.exam_action',$q->bindings,true)){
            $armed=false;DB::afterCommit(fn()=>throw new \RuntimeException('Synthetic lost committed response'));}});
        self::assertSame(1,Artisan::call('historical-exams:apply',$write+['--output'=>$directory.'/lost.json']));self::assertFalse(file_exists($directory.'/lost.json'));
        self::assertSame(1,UserActivityLog::where('action_code','student_completion.exam_action')->count());self::assertSame(2,DB::table('student_grade_components')->count());
        $start=app(CompletionReceipts::class)->find('exam_start',$id);self::assertSame($hash,CompletionData::hash($start['value']['review']));self::assertInstanceOf(CompletionRecords::class,$start['value']['review']['actions']);
        self::assertSame(2,Artisan::call('historical-exams:status',['--actor'=>'1','--operation-id'=>$id,'--recovery-check'=>true,'--output'=>$directory.'/interrupted.json']));
        $diagnostic=CompletionJson::file($directory.'/interrupted.json');self::assertSame($hash,$diagnostic['review_hash']);self::assertSame(1,$diagnostic['exam_action_receipt_headers']);self::assertFalse($diagnostic['exam_result_present']);
        $write['--expected-revision']=app(AcademicCatalogTransaction::class)->revision();
        self::assertSame(1,Artisan::call('historical-exams:apply',$write+['--output'=>$directory.'/implicit.json']));self::assertStringContainsString('explicit_resume_required',Artisan::output());
        self::assertSame(0,Artisan::call('historical-exams:apply',$write+['--resume'=>true,'--output'=>$directory.'/resumed.json']),Artisan::output());
        self::assertSame(4,DB::table('student_grade_components')->count());self::assertSame(4,DB::table('grade_audit_logs')->count());
        self::assertSame(2,UserActivityLog::where('action_code','student_completion.exam_action')->count());self::assertSame(0,DB::table('student_course_results')->count());
        self::assertSame(0,Artisan::call('historical-exams:status',['--actor'=>'1','--operation-id'=>$id,'--output'=>$directory.'/status.json']));
        self::assertSame(0,Artisan::call('historical-exams:verify',$args+['--operation-id'=>$id,'--output'=>$directory.'/verify.json']),Artisan::output());
        $write['--expected-revision']='0';self::assertSame(0,Artisan::call('historical-exams:apply',$write+['--output'=>$directory.'/replay.json']),Artisan::output());
        self::assertSame(hash_file('sha256',$directory.'/resumed.json'),hash_file('sha256',$directory.'/status.json'));self::assertSame(hash_file('sha256',$directory.'/resumed.json'),hash_file('sha256',$directory.'/replay.json'));
        self::assertSame($hashes,[hash_file('sha256',$directory.'/bundle.json'),hash_file('sha256',$directory.'/preview.json.review.json')]);
        $count=UserActivityLog::count();DB::table('roles')->where('role_id',1)->update(['role_code'=>'employee']);
        self::assertSame(1,Artisan::call('historical-exams:status',['--actor'=>'1','--operation-id'=>$id,'--recovery-check'=>true,'--output'=>$directory.'/denied.json']));
        self::assertFalse(file_exists($directory.'/denied.json'));self::assertSame($count,UserActivityLog::count());
    }
    public function test_historical_approved_memory_legacy_chunk_integrity_order_count_and_hash_are_preserved(): void
    {
        $actor=User::findOrFail(1);$receipts=app(CompletionReceipts::class);
        foreach(['valid','count','order','hash','encoding','extra','missing','json','envelope'] as $case) {
            $id=(string)Str::uuid();$receipt=(string)Str::uuid();$value=['review'=>['actions'=>array_fill(0,131,['source'=>'Synthetic','evidence'=>str_repeat('x',500)])],'digest'=>str_repeat('a',64)];
            $data=['operation_id'=>$id,'actor_id'=>1,'value'=>$value];$json=match($case){'json'=>'{"value":invalid}','envelope'=>'[]',default=>CompletionData::json($data)};$chunks=str_split(base64_encode($json),32000);
            $log=function($stage,$payload){UserActivityLog::create(['user_id'=>1,'module_code'=>'students','action_code'=>'student_completion.'.$stage,'description'=>CompletionData::json($payload)]);};
            foreach($chunks as $index=>$text)if($case!=='missing'||$index!==1)$log('chunk',['receipt_id'=>$receipt,'index'=>$case==='order'?$index+1:$index,'text'=>$case==='encoding'?'!'.$text:$text]);
            if($case==='extra')$log('chunk',['receipt_id'=>$receipt,'index'=>count($chunks),'text'=>'']);
            $log('exam_start',['operation_id'=>$id,'actor_id'=>1,'chunks'=>['id'=>$receipt,'count'=>count($chunks)+($case==='count'?1:0),'sha256'=>$case==='hash'?str_repeat('0',64):hash('sha256',$json)]]);
            $before=UserActivityLog::count();
            try{$read=$receipts->find('exam_start',$id);self::assertSame('valid',$case);self::assertSame(CompletionData::hash($data),CompletionData::hash($read));}
            catch(CompletionException $e){self::assertNotSame('valid',$case);self::assertSame('student_completion_receipt_invalid',$e->errorCode);}
            self::assertSame($before,UserActivityLog::count());
        }
        $id=(string)Str::uuid();$value=['review'=>['actions'=>array_fill(0,131,['text'=>str_repeat('ع',400)])],'digest'=>'synthetic'];
        $receipts->put($actor,'exam_start',$id,$value);self::assertSame(CompletionData::hash(['actor_id'=>1,'operation_id'=>$id,'value'=>$value]),CompletionData::hash($receipts->find('exam_start',$id)));
        $expected=CompletionData::json(['actor_id'=>1,'operation_id'=>$id,'value'=>$value]);$encoded=base64_encode($expected);
        $header=json_decode(UserActivityLog::where('action_code','student_completion.exam_start')->where('description->operation_id',$id)->value('description'),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256',$expected),$header['chunks']['sha256']);self::assertSame(count(str_split($encoded,32000)),$header['chunks']['count']);
        foreach(UserActivityLog::where('action_code','student_completion.chunk')->where('description->receipt_id',$header['chunks']['id'])->orderBy('activity_log_id')->get() as $index=>$part) {
            $chunk=json_decode($part->description,true,flags:JSON_THROW_ON_ERROR);self::assertSame($index,$chunk['index']);self::assertSame(substr($encoded,$index*32000,32000),$chunk['text']);
        }
    }
    public function test_historical_approved_memory_resume_never_bypasses_revision_intent_or_preserved_history(): void
    {
        [$directory,$fixture]=$this->fixture();$bundle=$fixture['reader']->load($directory.'/bundle.json',hash_file('sha256',$directory.'/bundle.json'));
        $actor=User::findOrFail(1);$service=app(ExamCompletionService::class);$p=$service->preview($actor,$bundle);$id=(string)Str::uuid();
        $digest=CompletionData::hash([$p['review_hash'],$bundle['sources'],1]);
        app(CompletionReceipts::class)->put($actor,'exam_start',$id,['digest'=>$digest,'review'=>$p['review'],'preserved'=>app(\App\Services\HistoricalExams\ExamEvidence::class)->capture($actor,$p['review'])]);
        $logs=UserActivityLog::count();DB::table('student_course_registrations')->where('student_id',2)->update(['notes'=>'Independent reviewed-history change']);
        try{$service->apply($actor,$bundle,$p['review'],$p['review_hash'],$id,$p['review']['revision'],true);self::fail('Stale revision accepted');}
        catch(CompletionException $e){self::assertSame('historical_exam_stale',$e->errorCode);}
        try{$service->apply($actor,$bundle,$p['review'],$p['review_hash'],$id,app(AcademicCatalogTransaction::class)->revision(),true);self::fail('Changed history accepted');}
        catch(CompletionException $e){self::assertSame('historical_exam_preserved_evidence_changed',$e->errorCode);}
        $changed=$p['review'];$changed['revision']='999';
        try{$service->apply($actor,$bundle,$changed,CompletionData::hash($changed),$id,app(AcademicCatalogTransaction::class)->revision(),true);self::fail('Changed operation intent accepted');}
        catch(CompletionException $e){self::assertSame('historical_exam_operation_identity_changed',$e->errorCode);}
        self::assertSame($logs,UserActivityLog::count());self::assertSame(0,DB::table('student_grade_components')->count());
    }
    public function test_historical_approved_memory_invalid_inline_receipt_cannot_be_mistaken_for_no_prior_operation(): void
    {
        [$directory,$fixture]=$this->fixture();$bundle=$fixture['reader']->load($directory.'/bundle.json',hash_file('sha256',$directory.'/bundle.json'));
        $actor=User::findOrFail(1);$service=app(ExamCompletionService::class);$p=$service->preview($actor,$bundle);$id=(string)Str::uuid();
        $log=UserActivityLog::create(['user_id'=>1,'module_code'=>'students','action_code'=>'student_completion.exam_start',
            'description'=>json_encode(['operation_id'=>$id,'actor_id'=>1,'value'=>false],JSON_THROW_ON_ERROR)]);
        try{$service->apply($actor,$bundle,$p['review'],$p['review_hash'],$id,$p['review']['revision'],true);self::fail('Invalid receipt treated as absent');}
        catch(CompletionException $e){self::assertSame('student_completion_receipt_invalid',$e->errorCode);}
        self::assertSame($log->description,$log->fresh()->description);self::assertSame(1,UserActivityLog::count());self::assertSame(0,DB::table('student_grade_components')->count());
    }
}
