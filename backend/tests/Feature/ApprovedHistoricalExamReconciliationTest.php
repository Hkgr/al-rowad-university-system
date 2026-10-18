<?php

namespace Tests\Feature;

use App\Exceptions\GradeException;
use App\Models\{Student, StudentCourseRegistration, User};
use App\Services\{GradeService};
use App\Services\HistoricalExams\{ExamCompletionService, ExamSources, HistoricalExamWorkService};
use App\Support\{ApplicableCourseCredits, HistoricalExamSchema, HistoricalOutcomeGuard};
use Illuminate\Support\Facades\{Artisan, DB, Schema};
use Illuminate\Support\Str;
use Tests\Support\SyntheticApprovedExamSources;

class ApprovedHistoricalExamReconciliationTest extends HistoricalExamCompletionTest
{
    private array $directories=[];
    protected function setUp(): void
    {
        parent::setUp();
        if(str_starts_with($this->name(),'test_historical_approved_')) {
            Schema::table('student_course_results',fn(\Illuminate\Database\Schema\Blueprint $t)=>$t->dateTime('result_announced_at')->nullable());
            Schema::table('student_course_registrations',function(\Illuminate\Database\Schema\Blueprint $t){$t->integer('academic_plan_version_id')->nullable();$t->integer('plan_program_course_id')->nullable();});
            SyntheticApprovedExamSources::schema();
        }
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach($this->directories as $directory){foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}
    }
    private function approvedBundle(array $values=[]): array
    {
        $directory=sys_get_temp_dir().'/approved-exams-'.bin2hex(random_bytes(8));mkdir($directory,0700);$this->directories[]=$directory;
        $fixture=SyntheticApprovedExamSources::write($directory,$values);$this->app->instance(ExamSources::class,$fixture['reader']);
        $bundle=$fixture['reader']->prepare($fixture['inputs']);
        \App\Services\StudentCompletion\CompletionFiles::write($directory.'/bundle.json',$bundle);
        return $fixture['reader']->load($directory.'/bundle.json',hash_file('sha256',$directory.'/bundle.json'));
    }
    private function approvedPreview(array $bundle): array {return app(ExamCompletionService::class)->preview(User::findOrFail(1),$bundle);}
    private function approvedApply(array $bundle,array $preview,?string $id=null,?string $expected=null,bool $resume=false): array
    {
        return app(ExamCompletionService::class)->apply(User::findOrFail(1),$bundle,$preview['review'],$preview['review_hash'],$id??(string)Str::uuid(),$expected??$preview['review']['revision'],$resume);
    }
    private function official50(): void
    {
        foreach([1=>20,2=>30] as $id=>$mark)DB::table('student_grade_components')->insert(['student_course_registration_id'=>1,'grade_component_id'=>$id,'mark'=>$mark,'grade_status'=>'approved']);
        DB::table('student_course_results')->insert(['student_course_registration_id'=>1,'theoretical_total'=>20,'practical_total'=>30,'coursework_total'=>0,'final_mark'=>50,'result_status_id'=>1,'result_announced_at'=>'2025-12-21 12:00:00']);
        DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['result_status_id'=>1]);
        DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
    }

    public function test_historical_approved_persisted_sources_and_actual_command_path_are_read_only_and_do_not_reinterpret_v3(): void
    {
        $bundle=$this->approvedBundle();$path=$bundle['inputs']['handoff']['path'].'/bundle.json';$output=dirname($path).'/preview.json';$hash=hash_file('sha256',$path);
        $queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        self::assertSame(2,Artisan::call('historical-exams:preview',['--actor'=>'1','--bundle'=>$path,'--bundle-hash'=>$hash,'--output'=>$output]),Artisan::output());
        $p=json_decode(file_get_contents($output),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame('historical-exam-completion.review.v4',$p['review']['contract']);self::assertSame(2,$p['counts']['observations']);self::assertSame(1,$p['counts']['eligible']);
        self::assertSame(1,$p['counts']['classifications']['retained_source_history']);
        foreach($queries as $sql)self::assertDoesNotMatchRegularExpression('/for update|^\s*(insert|update|delete|create|alter|drop)\b/i',$sql);
        self::assertSame($hash,hash_file('sha256',$path));self::assertSame('historical-exam-completion.sources.v3',ExamSources::CONTRACT);
    }

    public function test_historical_approved_numeric_drafts_record_attempt_hours_without_an_official_result_and_replay_before_stale_revision(): void
    {
        $bundle=$this->approvedBundle();$preview=$this->approvedPreview($bundle);$id=(string)Str::uuid();$result=$this->approvedApply($bundle,$preview,$id);
        self::assertSame(1,$result['saved_pending_approval']);self::assertSame(0,DB::table('student_course_results')->count());
        self::assertSame(1,DB::table(HistoricalExamSchema::CREDITS)->count());self::assertSame(3,ApplicableCourseCredits::hours(StudentCourseRegistration::findOrFail(1)));
        self::assertSame($result,$this->approvedApply($bundle,$preview,$id,'0'));self::assertSame(1,DB::table(HistoricalExamSchema::CREDITS)->count());
        self::assertSame(1,app(ExamCompletionService::class)->verify(User::findOrFail(1),$bundle,$id)['saved_pending_approval']);
    }

    public function test_historical_approved_official_correction_is_staged_then_explicitly_reviewed_without_republication_or_ordinary_recalculation(): void
    {
        $this->official50();$before=DB::table('student_course_results')->get()->toJson();$approvals=DB::table('grade_approvals')->get()->toJson();
        $bundle=$this->approvedBundle();$preview=$this->approvedPreview($bundle);self::assertSame(1,$preview['counts']['classifications']['correction_review']);
        $id=(string)Str::uuid();$result=$this->approvedApply($bundle,$preview,$id);self::assertSame(1,$result['staged_pending_review']);self::assertSame($before,DB::table('student_course_results')->get()->toJson());
        $work=collect($result['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);
        $review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);self::assertTrue($review['official_result_unchanged']);
        $path=$bundle['inputs']['handoff']['path'].'/bundle.json';$output=dirname($path).'/correction-review.json';$bundleHash=hash_file('sha256',$path);
        self::assertSame(0,Artisan::call('historical-exams:review-correction',['--actor'=>'1','--bundle'=>$path,'--bundle-hash'=>$bundleHash,'--work-item'=>$work['work_item_id'],'--output'=>$output]),Artisan::output());
        self::assertSame(\App\Services\StudentCompletion\CompletionData::canonical($review),json_decode(file_get_contents($output),true,flags:JSON_THROW_ON_ERROR));
        $request=(string)Str::uuid();$savedPath=dirname($path).'/correction-result.json';
        self::assertSame(0,Artisan::call('historical-exams:adopt-correction',['--actor'=>'1','--bundle'=>$path,'--bundle-hash'=>$bundleHash,'--review'=>$output.'.review.json','--review-hash'=>$review['review_hash'],
            '--operation-id'=>$request,'--expected-revision'=>$review['review']['revision'],'--reason'=>'Synthetic checked source correction','--confirmed'=>true,'--output'=>$savedPath]),Artisan::output());
        $saved=json_decode(file_get_contents($savedPath),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame('ADOPTED',$saved['status']);self::assertSame(80.0,(float)DB::table('student_course_results')->value('final_mark'));
        self::assertSame('2025-12-21 12:00:00',DB::table('student_course_results')->value('result_announced_at'));self::assertSame($approvals,DB::table('grade_approvals')->get()->toJson());
        self::assertTrue(HistoricalOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(1)));
        $replay=$service->adopt($actor,$bundle,$review['review'],$review['review_hash'],$request,'0','Synthetic checked source correction');self::assertTrue($replay['replayed']);
        self::assertSame(1,DB::table(HistoricalExamSchema::OUTCOMES)->count());
        try{app(GradeService::class)->calculateRegistrationResult(1,1);self::fail('Ordinary recalculation replaced an adopted result');}catch(GradeException $e){self::assertSame('historical_outcome_locked',$e->errorCode);}
        self::assertSame(80.0,(float)DB::table('student_course_results')->value('final_mark'));
        $verified=app(ExamCompletionService::class)->verify($actor,$bundle,$id);
        self::assertSame(1,$verified['current_adopted_work_items']);
    }

    public function test_historical_approved_absent_final_preserves_numeric_work_as_evidence_without_an_observed_zero_or_I_grant(): void
    {
        $bundle=$this->approvedBundle(['coursework'=>'20','exam'=>'-','total'=>'-','condition'=>'F']);$p=$this->approvedPreview($bundle);
        self::assertSame(1,$p['counts']['classifications']['final_absence_review']);$result=$this->approvedApply($bundle,$p);
        self::assertSame(0,DB::table('student_course_results')->count());self::assertSame(0,DB::table('student_grade_components')->count());
        $work=collect($result['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);
        $review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);$service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic approved explicit absence');
        self::assertNull(DB::table('student_course_results')->value('final_mark'));self::assertNull(DB::table('student_course_results')->value('theoretical_total'));
        self::assertSame(0,DB::table('student_grade_components')->count());self::assertSame(0,Schema::hasTable('student_course_incomplete_cases')?DB::table('student_course_incomplete_cases')->count():0);
        DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $gpa=app(GradeService::class)->calculateGpa(Student::findOrFail(1),1,1);self::assertSame(0.0,$gpa['gpa']);self::assertSame(3,$gpa['total_included_credit_hours']);
    }

    public function test_historical_approved_all_dash_source_is_scoped_ungraded_work_not_a_formal_I_or_a_financial_zero(): void
    {
        $bundle=$this->approvedBundle(['coursework'=>'-','exam'=>'-','total'=>'-','condition'=>'غير مكتمل']);$p=$this->approvedPreview($bundle);
        self::assertSame(1,$p['counts']['classifications']['ungraded_followup']);$result=$this->approvedApply($bundle,$p);
        self::assertSame(1,$result['staged_pending_review']);self::assertSame(0,DB::table('student_course_results')->count());self::assertSame(0,DB::table('student_grade_components')->count());
        $actor=User::findOrFail(1);$service=app(HistoricalExamWorkService::class);$work=collect($result['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');
        $queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});$list=$service->listing($actor,[]);$detail=$service->details($actor,$work['work_item_id']);
        self::assertSame(1,$list['meta']['total']);self::assertTrue($detail['ordinary_ungraded']);self::assertFalse($detail['capabilities']['can_grant_I_automatically']);
        foreach($queries as $sql)self::assertDoesNotMatchRegularExpression('/for update|^\s*(insert|update|delete|create|alter|drop)\b/i',$sql);
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'employee']);
        try{$service->details($actor,$work['work_item_id']);self::fail('Private source work leaked');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
    }

    public function test_historical_approved_absence_keeps_an_observed_numeric_total_without_inventing_an_exam_zero(): void
    {
        $bundle=$this->approvedBundle(['coursework'=>'20','exam'=>'-','total'=>'20','condition'=>'F']);
        $result=$this->approvedApply($bundle,$this->approvedPreview($bundle));$work=collect($result['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');
        $service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);$review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);
        $service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic source absence and observed total');
        self::assertSame(20.0,(float)DB::table('student_course_results')->value('final_mark'));
        self::assertNull(DB::table('student_course_results')->value('theoretical_total'));self::assertSame(0,DB::table('student_grade_components')->count());
    }

    public function test_historical_approved_imported_work_HTTP_reads_are_scoped_paginated_bounded_and_never_create_I_or_write(): void
    {
        $bundle=$this->approvedBundle(['coursework'=>'-','exam'=>'-','total'=>'-','condition'=>'غير مكتمل']);
        $result=$this->approvedApply($bundle,$this->approvedPreview($bundle));$work=collect($result['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');
        // Offering approval alone must not invent an official result for an ungraded student.
        DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $path='/api/v1/exams/historical-work';$queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        $this->getJson($path)->assertOk()->assertJsonPath('data.meta.total',1)->assertJsonPath('data.items.0.source_reference','exam1:OutcomeofMater:2')->assertJsonPath('data.items.0.is_official',false);
        $one=count($queries);$record=(array)DB::table(HistoricalExamSchema::WORK)->first();
        for($i=1;$i<=19;$i++)DB::table(HistoricalExamSchema::WORK)->insert(array_replace($record,['work_item_id'=>(string)Str::uuid(),'action_id'=>hash('sha256','synthetic-work-'.$i),'source_reference'=>'synthetic:'.$i]));
        $queries=[];
        $this->getJson($path.'?per_page=20')->assertOk()->assertJsonPath('data.meta.total',20)->assertJsonCount(20,'data.items');
        self::assertLessThanOrEqual($one+1,count($queries));
        $this->getJson($path.'/'.$work['work_item_id'])->assertOk()->assertJsonPath('data.ordinary_ungraded',true)->assertJsonPath('data.capabilities.can_grant_I_automatically',false)->assertJsonPath('data.is_official',false);
        foreach($queries as $sql)self::assertDoesNotMatchRegularExpression('/for update|^\s*(insert|update|delete|create|alter|drop)\b/i',$sql);
        $this->getJson($path.'?unexpected=true')->assertUnprocessable();
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'student']);
        $this->getJson($path)->assertForbidden();$this->getJson($path.'/'.$work['work_item_id'])->assertForbidden();
    }

    public function test_historical_approved_highest_intact_record_is_not_a_component_maximum_and_distinct_years_remain_distinct(): void
    {
        $bundle=$this->approvedBundle();$bundle['rows'][0]['values']=array_replace($bundle['rows'][0]['values'],['coursework'=>'20','exam'=>'60','total'=>'80']);
        $bundle['rows'][1]['values']=array_replace($bundle['rows'][1]['values'],['coursework'=>'39','exam'=>'40','total'=>'79']);
        $p=$this->approvedPreview($bundle);$selected=collect($p['review']['actions'])->firstWhere('source_selection','selected_intact_record');
        self::assertSame('exam1:OutcomeofMater:2',$selected['source_id']);self::assertSame('20',$selected['reconciled_values']['coursework']);self::assertSame('60',$selected['reconciled_values']['exam']);
        self::assertSame(1,$p['counts']['classifications']['retained_source_history']);
        DB::table('academic_years')->insert(['academic_year_id'=>2,'year_name'=>'2024-2025','start_date'=>'2024-09-01','end_date'=>'2025-08-31','is_active'=>true]);
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>2,'semester_id'=>1,'status'=>'CLOSED']);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>3,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]);
        $bundle['rows'][1]['values']['year']='2024-2025';$p=$this->approvedPreview($bundle);
        self::assertSame(2,collect($p['review']['actions'])->where('source_selection','selected_intact_record')->count());
        self::assertCount(2,array_unique(array_column($p['review']['actions'],'attempt_key')));
    }

    public function test_historical_approved_folded_supplementary_uses_a_real_independent_exam_cell_not_total_subtraction_or_an_invented_sitting(): void
    {
        $bundle=$this->approvedBundle();$regular=$bundle['rows'][0];
        $bundle['rows']=[$regular];$bundle['rows'][0]['values']=array_replace($regular['values'],['coursework'=>'30','exam'=>'10','total'=>'80','supplementary'=>'تكميلية']);
        $sup=$regular;$sup['id']='exam1:supplementary:2';$sup['kind']='undated_supplementary';$sup['reference']['sheet']='supplementary';
        $sup['values']=array_replace($regular['values'],['coursework'=>null,'exam'=>'50','total'=>null,'year'=>null,'term'=>null]);$bundle['rows'][]=$sup;
        $p=$this->approvedPreview($bundle);$action=collect($p['review']['actions'])->firstWhere('source_id',$regular['id']);
        self::assertSame('50',$action['reconciled_values']['exam']);self::assertSame('10',$action['original_source']['values']['exam']);
        self::assertSame('80',$action['reconciled_values']['total']);self::assertNull($p['review']['actions'][1]['supplementary_sitting']['academic_year']);
        self::assertFalse(collect($action['source_decisions'])->last()['sitting_date_inferred']);
        $bundle['rows'][1]['values']['exam']='49';$p=$this->approvedPreview($bundle);$a=collect($p['review']['actions'])->firstWhere('source_id',$regular['id']);
        self::assertSame('10',$a['reconciled_values']['exam']);self::assertFalse($a['eligible']);
        self::assertContains('cached_total_inconsistent_or_folded_supplementary',$a['blockers']);
    }

    public function test_historical_approved_absence_finalizes_other_students_through_actual_approval_routes_and_remains_locked_afterwards(): void
    {
        $bundle=$this->approvedBundle(['coursework'=>'20','exam'=>'-','total'=>'20','condition'=>'F']);
        $result=$this->approvedApply($bundle,$this->approvedPreview($bundle));$work=collect($result['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');
        $service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);$review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);
        $service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic final absence approved');
        $owned=HistoricalOutcomeGuard::snapshot(1);$outcomes=DB::table(HistoricalExamSchema::OUTCOMES)->get()->toJson();
        foreach([1=>50,2=>30] as $id=>$mark)DB::table('student_grade_components')->insert(['student_course_registration_id'=>2,'grade_component_id'=>$id,'mark'=>$mark,'grade_status'=>'draft']);
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'super_admin']);
        $approved=[];
        foreach(['theoretical','practical'] as $part) {
            $path='/api/v1/exams/manual-grade-entry/students/2/registrations/2/parts/'.$part;
            $readiness=$this->getJson($path.'/submission-readiness')->assertOk()->json('data');self::assertTrue($readiness['can_submit'],json_encode($readiness));
            $this->postJson($path.'/submit',['revision'=>$readiness['revision'],'confirmed'=>true])->assertOk();
            $id=DB::table('grade_part_approvals')->where('component_type',$part)->value('grade_part_approval_id');
            $this->postJson('/api/v1/grade-part-approvals/'.$id.'/approve')->assertOk();$approved[]=$id;
        }
        self::assertSame($owned,HistoricalOutcomeGuard::snapshot(1));self::assertSame($outcomes,DB::table(HistoricalExamSchema::OUTCOMES)->get()->toJson());
        self::assertSame(80.0,(float)DB::table('student_course_results')->where('student_course_registration_id',2)->value('final_mark'));
        $history=DB::table('grade_approvals')->get()->toJson();$after=DB::table('student_course_results')->get()->toJson();
        foreach($approved as $id)$this->postJson('/api/v1/grade-part-approvals/'.$id.'/approve')->assertOk();
        self::assertSame($history,DB::table('grade_approvals')->get()->toJson());self::assertSame($after,DB::table('student_course_results')->get()->toJson());
        self::assertSame(0.0,app(GradeService::class)->calculateGpa(Student::findOrFail(1),1,1)['gpa']);
        self::assertSame(3,app(GradeService::class)->calculateCgpa(Student::findOrFail(1))['total_included_credit_hours']);
        $this->putJson('/api/v1/exams/manual-grade-entry/students/1/registrations/1/marks',['revision'=>str_repeat('0',64),'acknowledged'=>true,'components'=>[['grade_component_id'=>1,'mark'=>60]]])->assertConflict();
        try{app(GradeService::class)->updateRegistrationGrades(1,['theoretical_mark'=>60],1);self::fail('Legacy writer changed adopted outcome');}catch(GradeException $e){self::assertSame(409,$e->status);}
        self::assertSame($owned,HistoricalOutcomeGuard::snapshot(1));
    }

    public function test_historical_approved_program_codes_and_qualified_subject_context_are_revalidated_not_selected_by_old_snapshot_ids(): void
    {
        $bundle=$this->approvedBundle();DB::table('academic_programs')->where('academic_program_id',1)->update(['program_code'=>'RU2-FBER-CE']);
        DB::table('courses')->where('course_id',1)->update(['credit_hours'=>2]);
        $bundle['approved_authority']['documents']['course-identity-review.json']['groups'][0]['required_program_id']=28;
        $bundle['snapshot']['academic_programs'][]=['academic_program_id'=>28,'program_code'=>'OTHER-REUSED-SNAPSHOT-ID'];
        $state=(new \App\Services\HistoricalExams\ExamReconciliationProjection)->state($bundle,app(\App\Services\HistoricalExams\ExamDatabase::class)->read(User::findOrFail(1)));
        $identity=new \App\Services\HistoricalExams\ExamApprovedIdentity;$source=$bundle['rows'][0];$resolved=$identity->course($source,1,$state,1);
        self::assertSame(1,(int)$resolved['course']['course_id']);self::assertSame(3,$resolved['mapping']['applicable_attempt_credit_hours']);
        self::assertSame(2,(int)$resolved['course']['credit_hours']);
        $state['_exam_approved']['inventory'][$source['id']]['required_program_id']=30;
        self::assertSame(['approved_course_program_context_changed'],$identity->course($source,1,$state,1)['blockers']);
        $state['_exam_approved']['inventory'][$source['id']]['required_program_id']=28;
        $state['_exam_approved']['inventory'][$source['id']]['required_college_code']='UNRELATED';
        self::assertSame(['approved_course_college_context_changed'],$identity->course($source,1,$state,1)['blockers']);
        $state['_exam_approved']['inventory'][$source['id']]['required_college_code']=null;
        $state['courses'][0]['course_name']='Different subject';
        self::assertSame(['reviewed_course_target_changed_or_scope_unproven'],$identity->course($source,1,$state,1)['blockers']);
    }

    public function test_historical_approved_unproven_number_owner_cannot_compete_with_or_replace_a_resolved_persons_source_result(): void
    {
        $bundle=$this->approvedBundle();$bundle['rows'][1]['values']=array_replace($bundle['rows'][1]['values'],['name'=>'Different source person','coursework'=>'40','exam'=>'59','total'=>'99']);
        $p=$this->approvedPreview($bundle);$good=collect($p['review']['actions'])->firstWhere('source_id','exam1:OutcomeofMater:2');$unknown=collect($p['review']['actions'])->firstWhere('source_id','exam2:علامات:2');
        self::assertSame('selected_intact_record',$good['source_selection']);self::assertSame('80',$good['reconciled_values']['total']);self::assertTrue($good['eligible']);
        self::assertSame('identity_not_independently_resolved',$unknown['source_selection']);self::assertFalse($unknown['eligible']);self::assertNull($unknown['selected_source_id']);
        self::assertContains('student_number_person_conflict',$unknown['owner']['blockers']);
    }

    public function test_historical_approved_applicable_hours_are_identical_in_current_gpa_transcript_requirements_workload_and_SQL_without_changing_origin(): void
    {
        $this->official50();$actor=User::findOrFail(1);$before=DB::table('student_course_results')->get()->toJson();
        app(\App\Services\AcademicCatalogTransaction::class)->run(fn()=>app(\App\Services\HistoricalExams\ExamApprovedCompletionService::class)->recordCredits($actor,1,4,(string)Str::uuid(),hash('sha256','synthetic-hours'),str_repeat('a',64)));
        $student=Student::findOrFail(1);$grades=app(GradeService::class);
        self::assertSame(4,$grades->calculateGpa($student,1,1)['total_included_credit_hours']);
        $transcript=$grades->getTranscript($student);self::assertSame(4,$transcript['terms'][0]['courses'][0]['credit_hours']);
        self::assertSame(4,$transcript['terms'][0]['included_credit_hours']);
        self::assertSame(4,app(\App\Services\AcademicRequirementService::class)->getStudentRequirementProgress($student)['earned_curriculum_hours']);
        self::assertSame(4,app(\App\Services\RegistrationService::class)->hoursSnapshot($student,1,1)['registered_hours']);
        $sql=ApplicableCourseCredits::sql('r','c');$actual=DB::table('student_course_registrations as r')->join('course_offerings as o','o.course_offering_id','=','r.course_offering_id')->join('courses as c','c.course_id','=','o.course_id')->where('r.student_course_registration_id',1)->selectRaw($sql.' as hours')->value('hours');
        self::assertSame(4,(int)$actual);self::assertSame(3,(int)DB::table('courses')->value('credit_hours'));
        self::assertSame($before,DB::table('student_course_results')->get()->toJson());
        self::assertSame('recorded_attempt_credit',ApplicableCourseCredits::fields(StudentCourseRegistration::findOrFail(1))['credit_hours_reference']);
        self::assertSame('current_origin_fallback_no_historical_credit_snapshot',ApplicableCourseCredits::fields(StudentCourseRegistration::findOrFail(2))['credit_hours_reference']);
    }

    public function test_historical_approved_exam2_supplementary_year_is_separate_from_parent_context_and_does_not_date_exam1_or_create_a_sitting(): void
    {
        $bundle=$this->approvedBundle();$source=$bundle['rows'][0];$rows=[];
        foreach(['exam1','exam2'] as $file) {
            $r=$source;$r['id']=$file.':'.($file==='exam1'?'supplementary':'تكميلي').':2';$r['reference']['file']=$file;$r['kind']='undated_supplementary';
            $r['values']=array_replace($r['values'],['year'=>null,'term'=>null,'coursework'=>null,'total'=>null,'exam'=>'50']);$rows[]=$r;
        }
        $bundle['rows']=$rows;$bundle['approved_authority']['documents']['approved-decisions.json']['decisions'][16]['source_references']=[$rows[1]['id']];
        $before=DB::table('student_course_registrations')->get()->toJson();$p=$this->approvedPreview($bundle);$a=collect($p['review']['actions'])->keyBy('source_id');
        self::assertNull($a[$rows[0]['id']]['supplementary_sitting']['academic_year']);
        self::assertSame('2025-2026',$a[$rows[1]['id']]['supplementary_sitting']['academic_year']);self::assertSame(1,$a[$rows[1]['id']]['supplementary_sitting']['academic_year_id']);
        self::assertNull($a[$rows[1]['id']]['period']['academic_year_id']);self::assertNull($a[$rows[1]['id']]['baseline']['registration']);
        self::assertContains('supplementary_semester_and_parent_not_proven',$a[$rows[1]['id']]['blockers']);self::assertFalse($a[$rows[1]['id']]['eligible']);
        self::assertSame($before,DB::table('student_course_registrations')->get()->toJson());
    }
}
