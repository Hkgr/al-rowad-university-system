<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\HistoricalExams\{ExamCompletionService,ExamDatabase,ExamIdentity,ExamProjection,ExamSources,ExamVbaEvidence};
use App\Services\StudentCompletion\{CompletionData,CompletionException};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Illuminate\Support\Str;

/** Synthetic database only. Retains the inherited real canonical grade-cycle coverage unchanged. */
class HistoricalExamCompletionTest extends ExamManualGradeGridBehaviorTest
{
    protected function setUp(): void
    {
        parent::setUp();if(!str_starts_with($this->name(),'test_historical_'))return;
        Schema::table('students',fn(Blueprint $t)=>$t->text('father_name')->nullable());
        Schema::table('colleges',fn(Blueprint $t)=>$t->string('college_code')->nullable());
        Schema::table('academic_programs',function(Blueprint $t){$t->string('program_code')->nullable();$t->integer('default_academic_plan_version_id')->nullable();});
        Schema::table('academic_levels',fn(Blueprint $t)=>$t->integer('level_order')->nullable());
        Schema::table('program_courses',fn(Blueprint $t)=>$t->integer('academic_plan_version_id')->nullable());
        Schema::create('academic_plan_versions',function(Blueprint $t){$t->increments('academic_plan_version_id');$t->integer('academic_program_id');$t->string('status');$t->dateTime('fixed_at')->nullable();});
        Schema::create('academic_catalog_control',function(Blueprint $t){$t->integer('control_id')->primary();$t->integer('schema_version');$t->unsignedBigInteger('revision');$t->boolean('is_ready');});
        DB::table('academic_catalog_control')->insert(['control_id'=>1,'schema_version'=>1,'revision'=>1,'is_ready'=>true]);
        foreach(['student_course_registrations','student_grade_components','grade_components','grade_approvals','grade_part_approvals','courses'] as $table)foreach(['INSERT','UPDATE','DELETE'] as $event)
            DB::unprepared("CREATE TRIGGER historical_test_{$table}_{$event} AFTER {$event} ON {$table} BEGIN UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1; END");
        DB::table('academic_years')->where('academic_year_id',1)->update(['year_name'=>'2025-2026']);
        DB::table('colleges')->update(['college_code'=>'TEST']);DB::table('academic_programs')->update(['program_code'=>'TEST-P']);DB::table('academic_levels')->update(['level_order'=>1]);
        DB::table('grade_components')->where('grade_component_id',1)->update(['component_name'=>'الامتحان النهائي']);DB::table('grade_components')->where('grade_component_id',2)->update(['component_name'=>'أعمال الفصل']);
    }
    private function bundle(array $overrides=[]): array
    {
        $values=array_replace(['number'=>'S1','name'=>'Student 1','college'=>'College','program'=>'Program','code'=>'C1','course'=>'Course','credits'=>'3','term'=>'1','year'=>'2025/2026','exam'=>'50','coursework'=>'30','total'=>'80','condition'=>'T','supplementary'=>null],$overrides);
        return ['contract'=>ExamSources::CONTRACT,'sources'=>['synthetic'=>['sha256'=>str_repeat('a',64)]],'rows'=>[['id'=>'exam1:OutcomeofMater:2','reference'=>['file'=>'exam1','sheet'=>'OutcomeofMater','row'=>2],'kind'=>'regular','values'=>$values,'cells'=>[],'row_hash'=>CompletionData::hash($values)]]];
    }
    private function service(): ExamCompletionService {return app(ExamCompletionService::class);}
    private function preview(array $b): array {return $this->service()->preview(User::findOrFail(1),$b);}
    private function apply(array $b,array $p,?string $id=null,?string $revision=null,bool $resume=false): array {return $this->service()->apply(User::findOrFail(1),$b,$p['review'],$p['review_hash'],$id??(string)Str::uuid(),$revision??$p['review']['revision'],$resume);}
    public function test_historical_missing_components_save_as_drafts_and_original_uuid_replay_precedes_new_revision_check(): void
    {
        $b=$this->bundle();$p=$this->preview($b);self::assertTrue($p['review']['actions'][0]['eligible']);$id=(string)Str::uuid();$r=$this->apply($b,$p,$id);
        self::assertSame('COMPLETE',$r['status']);self::assertSame(1,$r['saved_pending_approval']);self::assertSame(2,DB::table('student_grade_components')->count());self::assertSame(0,DB::table('student_course_results')->count());self::assertSame(0,DB::table('grade_approvals')->count());
        $logs=DB::table('user_activity_logs')->count();self::assertSame($r,$this->apply($b,$p,$id,'0'));self::assertSame($logs,DB::table('user_activity_logs')->count());
        $queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});$verified=$this->service()->verify(User::findOrFail(1),$b,$id);self::assertSame(1,$verified['saved_pending_approval']);
        foreach($queries as $sql)self::assertDoesNotMatchRegularExpression('/for update|^\s*(insert|update|delete|create|alter|drop)\b/i',$sql);
    }
    public function test_historical_existing_equivalent_is_content_noop_and_condition_F_with_equal_total_is_not_equivalent(): void
    {
        foreach([1=>50,2=>30] as $id=>$mark)DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>$id,'mark'=>$mark,'grade_status'=>'approved']);
        DB::table('student_course_results')->insert(['student_course_registration_id'=>1,'theoretical_total'=>50,'practical_total'=>30,'coursework_total'=>30,'final_mark'=>80,'result_status_id'=>1]);DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $before=DB::table('student_course_results')->get()->toJson();$p=$this->preview($this->bundle());self::assertSame(1,$p['counts']['no_op']);self::assertFalse($p['review']['actions'][0]['eligible']);self::assertSame($before,DB::table('student_course_results')->get()->toJson());
        $p=$this->preview($this->bundle(['condition'=>'F']));self::assertSame('existing_value_conflict',$p['review']['actions'][0]['classification']);self::assertFalse($p['review']['actions'][0]['eligible']);self::assertSame($before,DB::table('student_course_results')->get()->toJson());
    }
    public function test_historical_conflicting_duplicates_and_undated_supplementary_do_not_block_independent_regular_action(): void
    {
        $b=$this->bundle();$duplicate=$b['rows'][0];$duplicate['id']='exam2:علامات:2';$duplicate['reference']=['file'=>'exam2','sheet'=>'علامات','row'=>2];$b['rows'][]=$duplicate;
        $p=$this->preview($b);self::assertCount(1,$p['review']['actions']);self::assertCount(2,$p['review']['actions'][0]['references']);self::assertSame(1,$p['counts']['eligible']);
        $b['rows'][1]['values']['exam']='51';$p=$this->preview($b);self::assertSame(0,$p['counts']['eligible']);self::assertSame(2,$p['counts']['conflict']);
        $b=$this->bundle();$sup=$duplicate;$sup['kind']='undated_supplementary';$sup['values']['year']=null;$b['rows'][]=$sup;$p=$this->preview($b);self::assertSame(1,$p['counts']['eligible']);$r=$this->apply($b,$p);self::assertSame('PARTIAL',$r['status']);self::assertSame(1,$r['saved_pending_approval']);
    }
    public function test_historical_null_zero_symbols_limits_and_folded_totals_are_distinct(): void
    {
        foreach([['exam'=>null],['exam'=>'-'],['exam'=>'I'],['exam'=>'W'],['exam'=>'Z'],['coursework'=>'معادلة'],['exam'=>'61'],['total'=>'90'],['supplementary'=>'تكميلي']] as $v)self::assertSame(0,$this->preview($this->bundle($v))['counts']['eligible']);
        $p=$this->preview($this->bundle(['exam'=>'0','coursework'=>'0','total'=>'0','condition'=>'F']));self::assertSame(1,$p['counts']['eligible']);$this->apply($this->bundle(['exam'=>'0','coursework'=>'0','total'=>'0','condition'=>'F']),$p);self::assertSame(2,DB::table('student_grade_components')->where('mark',0)->count());
    }
    public function test_historical_stale_grade_or_approval_change_rejects_review_and_changed_uuid_intent_is_rejected(): void
    {
        $b=$this->bundle();$p=$this->preview($b);DB::table('grade_part_approvals')->insert(['course_offering_id'=>1,'component_type'=>'theoretical','status'=>'submitted']);
        try{$this->apply($b,$p);self::fail('Stale review accepted');}catch(CompletionException $e){self::assertSame('historical_exam_stale',$e->errorCode);}self::assertSame(0,DB::table('student_grade_components')->count());
        DB::table('grade_part_approvals')->delete();$p=$this->preview($b);$id=(string)Str::uuid();$this->apply($b,$p,$id);$other=$this->bundle(['exam'=>'51','total'=>'81']);$p2=$this->preview($other);
        try{$this->apply($other,$p2,$id);self::fail('UUID reused');}catch(CompletionException $e){self::assertSame('historical_exam_operation_identity_changed',$e->errorCode);}
    }
    public function test_historical_audit_failure_rolls_back_the_attempt_and_preserves_other_students(): void
    {
        $b=$this->bundle();$p=$this->preview($b);DB::unprepared("CREATE TRIGGER historical_audit_fail BEFORE INSERT ON grade_audit_logs BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");$before=DB::table('student_course_registrations')->get()->toJson();$r=$this->apply($b,$p);
        self::assertSame('PARTIAL',$r['status']);self::assertSame(0,DB::table('student_grade_components')->count());self::assertSame(0,DB::table('grade_audit_logs')->count());self::assertSame($before,DB::table('student_course_registrations')->get()->toJson());
    }
    public function test_historical_preview_is_nonlocking_and_permissions_and_both_resource_scopes_remain_required(): void
    {
        $queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});$this->preview($this->bundle());foreach($queries as $sql)self::assertDoesNotMatchRegularExpression('/for update|^\s*(insert|update|delete|create|alter|drop)\b/i',$sql);
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'employee']);try{$this->preview($this->bundle());self::fail('Non-officer accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'exam_officer']);DB::table('user_access_scopes')->delete();self::assertSame(0,$this->preview($this->bundle())['counts']['eligible']);
    }
    public function test_historical_existing_period_wins_and_repeated_attempts_never_use_row_counter_as_chronology(): void
    {
        $p=$this->preview($this->bundle(['term'=>'2']));self::assertSame('existing_registration_actual_period',$p['review']['actions'][0]['period']['basis']);self::assertSame(1,$p['review']['actions'][0]['period']['semester_id']);
        DB::table('semesters')->insert(['semester_id'=>17,'semester_name'=>'Second','semester_order'=>2]);DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_program_id'=>1,'department_id'=>1,'academic_year_id'=>1,'semester_id'=>17,'status'=>'closed']);DB::table('student_course_registrations')->insert(['student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]);
        $p=$this->preview($this->bundle());self::assertSame(0,$p['counts']['eligible']);self::assertContains('repeated_attempt_semester_not_proven',$p['review']['actions'][0]['blockers']);
    }
    public function test_historical_all_I_lifecycle_states_remain_protected_without_changing_history(): void
    {
        Schema::create('student_course_incomplete_cases',function(Blueprint $t){$t->increments('incomplete_case_id');$t->integer('student_course_registration_id');$t->string('state');});
        foreach(['active','resolved','expired'] as $state){DB::table('student_course_incomplete_cases')->delete();DB::table('student_course_incomplete_cases')->insert(['student_course_registration_id'=>1,'state'=>$state]);$p=$this->preview($this->bundle());self::assertSame(0,$p['counts']['eligible']);self::assertContains('incomplete_lifecycle_owned_all_states',$p['review']['actions'][0]['blockers']);}
        self::assertSame(0,DB::table('student_grade_components')->count());
    }
    public function test_historical_new_registration_uses_reviewed_guidance_and_saves_atomically_without_approval(): void
    {
        DB::table('student_course_registrations')->where('student_id',1)->delete();DB::table('academic_plan_versions')->insert(['academic_plan_version_id'=>1,'academic_program_id'=>1,'status'=>'approved','fixed_at'=>'2026-01-01']);DB::table('academic_programs')->update(['default_academic_plan_version_id'=>1]);DB::table('program_courses')->update(['academic_plan_version_id'=>1,'academic_level_id'=>1,'recommended_semester_id'=>1]);
        $b=$this->bundle();$p=$this->preview($b);self::assertSame(1,$p['counts']['eligible']);self::assertSame('administrative_guidance_fallback_not_verified_exam_date',$p['review']['actions'][0]['period']['basis']);$r=$this->apply($b,$p);self::assertSame(1,$r['saved_pending_approval']);self::assertSame(2,DB::table('student_course_registrations')->count());self::assertSame(0,DB::table('student_course_results')->count());self::assertSame(0,DB::table('grade_approvals')->count());
    }
    public function test_historical_source_snapshot_parser_extension_does_not_change_old_table_contract(): void
    {
        self::assertSame(0,ExamProjection::cents('0'));self::assertNull(ExamProjection::cents(null));self::assertNull(ExamProjection::cents('-'));self::assertNull(ExamProjection::cents('1.001'));self::assertSame(1501,ExamProjection::cents('15.01'));
        self::assertSame('Attribute VB_Name = "Synthetic"',(new ExamVbaEvidence)->decode("\x01".pack('v',0x3000+strlen('Attribute VB_Name = "Synthetic"')-1).'Attribute VB_Name = "Synthetic"'));
    }
    public function test_historical_actual_prepare_command_and_persisted_reconstruction_do_not_connect_or_execute_sources(): void
    {
        $dir=sys_get_temp_dir().'/historical-source-'.bin2hex(random_bytes(8));mkdir($dir,0700);$default=config('database.default');
        try {
            $inputs=\Tests\Support\HistoricalExamSourceFixture::write($dir);$args=['--output'=>$dir.'/bundle.json'];foreach($inputs as $id=>$in){$args['--'.$id]=$in['path'];$args['--'.$id.'-hash']=$in['sha256'];}
            $queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});config(['database.default'=>'never_connect_during_source_preparation']);
            self::assertSame(0,Artisan::call('historical-exams:prepare',$args),Artisan::output());self::assertSame([],$queries);
            $hash=hash_file('sha256',$dir.'/bundle.json');$bundle=(new ExamSources)->load($dir.'/bundle.json',$hash);self::assertCount(2,$bundle['rows']);self::assertSame('80',$bundle['rows'][0]['values']['total']);self::assertSame('J2+K2',$bundle['rows'][0]['cells']['L']['formula']);self::assertSame('50',$bundle['rows'][0]['values']['exam']);self::assertSame($hash,hash_file('sha256',$dir.'/bundle.json'));
            self::assertSame(1,Artisan::call('historical-exams:prepare',$args)); // Never overwrite existing evidence.
            config(['database.default'=>$default]);$queries=[];
            $args=['--actor'=>'1','--bundle'=>$dir.'/bundle.json','--bundle-hash'=>$hash,'--output'=>$dir.'/live.json'];
            self::assertSame(2,Artisan::call('historical-exams:preview',$args),Artisan::output());$report=json_decode(file_get_contents($dir.'/live.json'),true);self::assertSame(1,$report['counts']['eligible']);
            foreach($queries as $sql)self::assertDoesNotMatchRegularExpression('/for update|^\s*(insert|update|delete|create|alter|drop)\b/i',$sql);
            $operation=(string)Str::uuid();$args['--output']=$dir.'/saved.json';
            self::assertSame(0,Artisan::call('historical-exams:apply',$args+['--review'=>$dir.'/live.json.review.json','--review-hash'=>$report['review_hash'],'--expected-revision'=>$report['review']['revision'],'--operation-id'=>$operation]),Artisan::output());
            self::assertSame(0,Artisan::call('historical-exams:status',['--actor'=>'1','--operation-id'=>$operation,'--output'=>$dir.'/status.json']));
            $args['--output']=$dir.'/verified.json';self::assertSame(0,Artisan::call('historical-exams:verify',$args+['--operation-id'=>$operation]),Artisan::output());
            self::assertSame($hash,hash_file('sha256',$dir.'/bundle.json'));foreach($inputs as $in)self::assertSame($in['sha256'],hash_file('sha256',$in['path']));
        } finally {config(['database.default'=>$default]);foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
    }
    public static function owners(): array
    {
        $out=[];foreach(range(0,5) as $i)$out[]=['number',$i];foreach(range(0,7) as $i)$out[]=['row',$i];$out[]=['separation',0];return $out;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('owners')]
    public function test_historical_approved_ownership_is_person_and_context_bound(string $kind,int $index): void
    {
        $f=\Tests\Support\SyntheticExamOwnership::fixture();$case=$f['cases'][$kind][$index];$policy=$f['policy'];$r=$this->bundle()['rows'][0];$s=$case['target'];$rows=[2];$labels=$case['labels'];
        if($kind==='row'){$rule=$policy['row_rules'][$index];$r['reference']=['file'=>$rule['file'],'sheet'=>$rule['sheet'],'row'=>$rule['rows'][0]];$rows=$rule['rows'];}
        if($kind==='separation'){$r['reference']=['file'=>'exam2','sheet'=>'علامات','row'=>4062];$rows=[4062];}
        foreach($rows as $row)foreach($labels as $label) {
            $r['reference']['row']=$row;$r['id']=$r['reference']['file'].':'.$r['reference']['sheet'].':'.$row;
            $r['values']=array_replace($r['values'],['number'=>$case['source'],'name'=>$label,'college'=>'المعهد التقاني الهندسي','program'=>'المدني والإنشاءات العامة']);
            if(isset($rule['course_code']))$r['values']=array_replace($r['values'],['code'=>$rule['course_code'],'course'=>$rule['course_name']]);
            $owner=$f['resolver']->student($r,['students'=>[$s]]);self::assertSame($s['student_number'],$owner['number']);self::assertSame([],$owner['blockers']);self::assertNotNull($owner['decision']);
            $wrong=$r;$wrong['values']['name']='Another synthetic person';self::assertNotSame([],$f['resolver']->student($wrong,['students'=>[$s]])['blockers']);
        }
    }
    public function test_historical_bounded_missing_number_requires_unique_program_context_and_explicit_deferrals_stay_deferred(): void
    {
        $f=\Tests\Support\SyntheticExamOwnership::fixture();$r=$this->bundle()['rows'][0];$r['id']='exam1:OutcomeofMater:4885';$r['reference']['row']=4885;$r['values']['number']='';$r['values']['name']='Synthetic missing candidate';
        $s=['students'=>[$f['cases']['missing']['target']],'academic_programs'=>[['academic_program_id'=>1,'program_code'=>$f['policy']['missing_number']['program_code']]]];
        self::assertSame([],$f['resolver']->student($r,$s)['blockers']);$s['academic_programs'][0]['program_code']='OTHER';self::assertContains('approved_target_program_context_changed',$f['resolver']->student($r,$s)['blockers']);
        foreach([4884,5299,5669] as $row){$r['id']='exam1:OutcomeofMater:'.$row;$r['reference']['row']=$row;self::assertNull($f['resolver']->student($r,$s)['student']);}
        $production=ExamIdentity::approvals();self::assertCount(6,$production['number_person_rules']);self::assertCount(8,$production['row_rules']);
        self::assertDoesNotMatchRegularExpression('/R[0-9]{8}/',CompletionData::json($production));
    }
    public function test_historical_all_seven_investigation_references_reject_equal_total_with_F_versus_passed(): void
    {
        DB::table('student_course_results')->insert(['student_course_registration_id'=>1,'theoretical_total'=>50,'practical_total'=>30,'coursework_total'=>30,'final_mark'=>80,'result_status_id'=>1]);
        foreach(['exam1:OutcomeofMater:4910','exam1:OutcomeofMater:5035','exam1:OutcomeofMater:5107','exam1:OutcomeofMater:5115','exam1:OutcomeofMater:6198','exam2:علامات:2006','exam2:علامات:2018'] as $id) {
            $b=$this->bundle(['condition'=>'F']);[$file,$sheet,$row]=explode(':',$id);$b['rows'][0]['id']=$id;$b['rows'][0]['reference']=['file'=>$file,'sheet'=>$sheet,'row'=>(int)$row];$p=$this->preview($b);
            self::assertSame('existing_value_conflict',$p['review']['actions'][0]['classification']);self::assertSame(0,$p['counts']['eligible']);self::assertCount(1,$p['review']['actions'][0]['correction_proposal']['candidate_conflicts']);
        }
    }
    public function test_historical_lost_action_response_requires_explicit_resume_and_revalidates_the_committed_action(): void
    {
        $b=$this->bundle();$p=$this->preview($b);$id=(string)Str::uuid();$armed=true;
        DB::listen(function($q)use(&$armed){if($armed&&str_starts_with(strtolower($q->sql),'insert into "user_activity_logs"')&&in_array('student_completion.exam_action',$q->bindings,true)){$armed=false;DB::afterCommit(fn()=>throw new \RuntimeException('synthetic lost committed response'));}});
        try{$this->apply($b,$p,$id);self::fail('Lost response must not report success');}catch(CompletionException $e){self::assertSame('historical_exam_stale',$e->errorCode);}
        self::assertSame('INTERRUPTED',$this->service()->status(User::findOrFail(1),$id)['status']);$revision=app(\App\Services\AcademicCatalogTransaction::class)->revision();
        try{$this->apply($b,$p,$id,$revision);self::fail('Implicit resume accepted');}catch(CompletionException $e){self::assertSame('historical_exam_explicit_resume_required',$e->errorCode);}
        $r=$this->apply($b,$p,$id,$revision,true);self::assertSame(1,$r['saved_pending_approval']);self::assertSame(2,DB::table('student_grade_components')->count());self::assertSame(2,DB::table('grade_audit_logs')->count());
    }
    public function test_historical_one_undated_supplementary_mark_has_multiple_possible_years_and_never_creates_an_attempt(): void
    {
        DB::table('academic_years')->insert(['academic_year_id'=>2,'year_name'=>'2024-2025']);DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_program_id'=>1,'department_id'=>1,'academic_year_id'=>2,'semester_id'=>1,'status'=>'closed']);DB::table('student_course_registrations')->insert(['student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]);
        $b=$this->bundle(['year'=>null]);$b['rows'][0]['kind']='undated_supplementary';$p=$this->preview($b);$a=$p['review']['actions'][0];self::assertCount(2,$a['possible_parent_contexts']);self::assertNull($a['period']['academic_year_id']);self::assertFalse($a['eligible']);self::assertContains('supplementary_sitting_undated',$a['blockers']);self::assertSame(0,DB::table('student_grade_components')->count());
    }
    public function test_historical_verification_detects_changes_to_preserved_unrelated_evidence_without_repair(): void
    {
        $b=$this->bundle();$p=$this->preview($b);$id=(string)Str::uuid();$this->apply($b,$p,$id);DB::table('student_course_registrations')->where('student_id',2)->update(['notes'=>'Synthetic independently changed evidence']);
        try{$this->service()->verify(User::findOrFail(1),$b,$id);self::fail('Unrelated old evidence was ignored');}catch(CompletionException $e){self::assertSame('historical_exam_preserved_evidence_changed',$e->errorCode);}self::assertSame('Synthetic independently changed evidence',DB::table('student_course_registrations')->where('student_id',2)->value('notes'));
    }
    public function test_historical_source_conflicts_are_detected_even_before_a_course_identity_is_resolved(): void
    {
        $b=$this->bundle(['code'=>'UNKNOWN']);$copy=$b['rows'][0];$copy['id']='exam2:علامات:2';$copy['reference']=['file'=>'exam2','sheet'=>'علامات','row'=>2];$b['rows'][]=$copy;$p=$this->preview($b);self::assertSame(1,$p['counts']['actions']);self::assertCount(2,$p['review']['actions'][0]['references']);
        $b['rows'][1]['values']['exam']='51';$p=$this->preview($b);self::assertSame(2,$p['counts']['conflict']);self::assertSame(0,$p['counts']['eligible']);
    }
    public function test_historical_shared_course_does_not_convert_another_programs_registration(): void
    {
        DB::table('academic_programs')->insert(['academic_program_id'=>2,'department_id'=>1,'program_code'=>'OTHER-P','program_name'=>'Other program']);DB::table('course_offerings')->where('course_offering_id',1)->update(['academic_program_id'=>2]);
        $p=$this->preview($this->bundle());self::assertSame(0,$p['counts']['eligible']);self::assertContains('existing_registration_other_program_requires_review',$p['review']['actions'][0]['blockers']);self::assertNull($p['review']['actions'][0]['baseline']['registration']);self::assertSame(0,DB::table('student_grade_components')->count());
    }
    public function test_historical_guidance_uses_installed_semester_order_not_an_assumed_id_and_conflicts_block(): void
    {
        DB::table('student_course_registrations')->where('student_id',1)->delete();DB::table('semesters')->insert(['semester_id'=>17,'semester_name'=>'Second','semester_order'=>2]);DB::table('academic_plan_versions')->insert(['academic_plan_version_id'=>1,'academic_program_id'=>1,'status'=>'approved','fixed_at'=>'2026-01-01']);DB::table('academic_programs')->update(['default_academic_plan_version_id'=>1]);DB::table('program_courses')->update(['academic_plan_version_id'=>1,'academic_level_id'=>1,'recommended_semester_id'=>17]);
        $a=$this->preview($this->bundle(['term'=>'2']))['review']['actions'][0];self::assertSame(17,$a['period']['semester_id']);self::assertSame('2',$a['period']['source_plan_term']);
        self::assertContains('source_and_approved_guidance_terms_conflict',$this->preview($this->bundle(['term'=>'1']))['review']['actions'][0]['blockers']);
    }
    public function test_historical_orphan_approved_missing_mark_is_not_downgraded_to_a_draft(): void
    {
        DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>1,'mark'=>null,'grade_status'=>'approved']);$p=$this->preview($this->bundle());self::assertSame(0,$p['counts']['eligible']);self::assertContains('existing_component_submission_or_approval_protected',$p['review']['actions'][0]['blockers']);self::assertSame('approved',DB::table('student_grade_components')->value('grade_status'));
    }
    public function test_historical_catalog_approved_life_and_research_are_distinct_and_university_reuse_cannot_lose_its_scope(): void
    {
        $s=app(ExamDatabase::class)->read(User::findOrFail(1));$s['academic_programs'][0]['program_code']='RU1-TI-CE';
        $s['courses']=[['course_id'=>409,'course_code'=>'RUS211','course_name'=>'مدخل إلى الحياة الجامعية','credit_hours'=>2,'is_active'=>true],['course_id'=>410,'course_code'=>'TI-CE-RES211','course_name'=>'أصول البحث العلمي','credit_hours'=>2,'is_active'=>true]];
        $s['academic_requirement_groups']=[['requirement_group_id'=>1,'requirement_scope'=>'university','is_active'=>true]];
        $s['program_courses']=[['program_course_id'=>1,'academic_program_id'=>1,'course_id'=>409,'is_active'=>true]];$s['program_course_requirement_groups']=[['program_course_id'=>1,'requirement_group_id'=>1]];
        $s['course_departments']=[['course_department_id'=>1,'course_id'=>410,'department_id'=>1],['course_department_id'=>2,'course_id'=>409,'department_id'=>1]];
        $identity=new ExamIdentity;$v=$this->bundle(['code'=>'RUS111','course'=>'مدخل إلى الحياة الجامعية','credits'=>'2'])['rows'][0]['values'];
        self::assertSame(409,$identity->course($v,1,$s)['course']['course_id']);self::assertSame(410,$identity->course(array_replace($v,['code'=>'RUS211','course'=>'أصول البحث العلمي']),1,$s)['course']['course_id']);
        $s['course_offerings'][0]['course_id']=409;$s['program_course_requirement_groups']=[];
        self::assertNull($identity->course($v,1,$s,1)['course']); // A same-department or old-registration fallback cannot replace university scope.
        $s['program_course_requirement_groups']=[['program_course_id'=>1,'requirement_group_id'=>1]];$s['courses'][0]['is_active']=false;self::assertNull($identity->course($v,1,$s)['course']);
        $s['courses'][0]['is_active']=true;$s['courses'][0]['credit_hours']=3;self::assertNull($identity->course($v,1,$s)['course']);
    }
    public function test_historical_verifier_requires_each_completed_receipt_and_drafts_do_not_enter_official_readers(): void
    {
        $b=$this->bundle();$p=$this->preview($b);$id=(string)Str::uuid();$this->apply($b,$p,$id);$student=\App\Models\Student::findOrFail(1);$grades=app(\App\Services\GradeService::class);$transcript=$grades->getTranscript($student);self::assertSame([],$transcript['terms']);self::assertNull($grades->calculateGpa($student,1,1)['gpa']);
        DB::table('user_activity_logs')->where('action_code','student_completion.exam_action')->delete(); // Deliberately tamper only with this synthetic receipt.
        try{$this->service()->verify(User::findOrFail(1),$b,$id);self::fail('Missing action receipt accepted');}catch(CompletionException $e){self::assertSame('historical_exam_completed_receipt_missing',$e->errorCode);}
    }
}
