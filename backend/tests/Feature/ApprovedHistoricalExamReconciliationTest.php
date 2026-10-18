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

    public static function equivalentAggregateCases(): array
    {
        return ['selected-58'=>[32,26,58], 'selected-72'=>[34,38,72], 'sequence-50'=>[29,21,50],
            'sequence-51'=>[24,27,51], 'sequence-65'=>[19,46,65], 'sequence-57'=>[32,25,57],
            'sequence-75'=>[30,45,75], 'sequence-62'=>[23,39,62], 'sequence-78'=>[32,46,78]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('equivalentAggregateCases')]
    public function test_historical_approved_absent_definitions_official_agreement_is_exact_noop_not_a_fictional_activity_request(int $j,int $k,int $total): void
    {
        $this->official50();DB::table('student_grade_components')->delete();DB::table('grade_components')->delete();
        DB::table('student_course_results')->update(['theoretical_total'=>$j,'practical_total'=>$k,'final_mark'=>$total,'coursework_total'=>17,
            'created_at'=>'2024-10-01 10:00:00','updated_at'=>'2024-10-02 11:00:00','calculated_at'=>'2024-10-02 11:00:00']);
        $before=HistoricalOutcomeGuard::snapshot(1);$approval=DB::table('grade_approvals')->get()->toJson();
        $bundle=$this->approvedBundle(['coursework'=>(string)$j,'exam'=>(string)$k,'total'=>(string)$total]);
        $legacy=app(ExamCompletionService::class)->preview(User::findOrFail(1),$bundle,\App\Services\HistoricalExams\ExamReconciliationProjection::LEGACY_CONTRACT);
        self::assertSame(1,$legacy['counts']['classifications']['aggregate_incomplete_followup']);
        $p=$this->approvedPreview($bundle);$selected=collect($p['review']['actions'])->firstWhere('source_selection','selected_intact_record');
        self::assertSame('existing_equivalent',$selected['classification']);self::assertFalse($selected['eligible']);
        self::assertSame([], $selected['assessment_evidence']['definition_ids']);self::assertNotEmpty($selected['assessment_evidence']['configuration_guard_references']);
        $r=$this->approvedApply($bundle,$p);self::assertSame(0,$r['staged_pending_review']);self::assertSame(0,$r['saved_pending_approval']);
        self::assertSame($before,HistoricalOutcomeGuard::snapshot(1));self::assertSame($approval,DB::table('grade_approvals')->get()->toJson());
        self::assertSame(0,DB::table(HistoricalExamSchema::OUTCOMES)->count());self::assertSame(0,DB::table(HistoricalExamSchema::WORK)->count());
        if ($directory=getenv('HISTORICAL_EXAM_TEST_EVIDENCE')) \App\Services\StudentCompletion\CompletionFiles::write($directory.'/equivalent-'.$j.'-'.$k.'-'.$total.'.json',
            ['synthetic_only'=>true,'database_driver'=>DB::connection()->getDriverName(),'before'=>$before,'proposal'=>$selected,'operation_result'=>$r,
                'after'=>HistoricalOutcomeGuard::snapshot(1),'approval_history_unchanged'=>true,'result_and_all_timestamps_unchanged'=>true]);
    }

    public function test_historical_approved_absent_definition_adoption_preserves_raw_parts_and_is_effective_in_GPA_transcript_and_SQL(): void
    {
        DB::table('grade_components')->delete();DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $bundle=$this->approvedBundle();$preview=$this->approvedPreview($bundle);
        self::assertSame(1,$preview['counts']['classifications']['historical_aggregate_review']);
        $result=$this->approvedApply($bundle,$preview);self::assertSame(0,DB::table('student_course_results')->count());
        self::assertSame('correction_review',DB::table(HistoricalExamSchema::WORK)->value('kind'));
        $work=collect($result['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$actor=User::findOrFail(1);$service=app(HistoricalExamWorkService::class);
        $review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);$request=(string)Str::uuid();
        self::assertSame(3000,$review['review']['proposal']['assessment_representation']['coursework_cents']);
        self::assertNull($review['review']['proposal']['result']['theoretical_total']);
        $approval=DB::table('grade_approvals')->get()->toJson();
        $service->adopt($actor,$bundle,$review['review'],$review['review_hash'],$request,$review['review']['revision'],'Synthetic aggregate evidence reviewed');
        self::assertSame(0,DB::table('grade_components')->count());self::assertSame(0,DB::table('student_grade_components')->count());
        self::assertNull(DB::table('student_course_results')->value('theoretical_total'));self::assertNull(DB::table('student_course_results')->value('practical_total'));
        self::assertNull(DB::table('student_course_results')->value('result_announced_at'));self::assertSame($approval,DB::table('grade_approvals')->get()->toJson());
        $grades=app(GradeService::class);$gpa=$grades->calculateGpa(Student::findOrFail(1),1,1);
        self::assertSame(3.0,$gpa['gpa']);self::assertSame(3,$gpa['total_included_credit_hours']);
        $transcript=$grades->getTranscript(Student::findOrFail(1));$course=$transcript['terms'][0]['courses'][0];
        self::assertEquals(80,$course['final_mark']);self::assertEquals(30,$course['assessment_summary']['coursework_mark']);self::assertEquals(50,$course['assessment_summary']['examination_mark']);
        self::assertNull($course['theoretical_mark']);self::assertNull($course['practical_mark']);self::assertArrayNotHasKey('provenance',$course['assessment_summary']);
        $sql=HistoricalOutcomeGuard::aggregateEligibilitySql('r.student_course_registration_id','x.final_mark','s.status_code');
        $eligible=\App\Support\OfficialGradeScale::gpaEligibilitySql('s.status_code','x.final_mark','x.theoretical_total','x.practical_total','1','1',$sql);
        self::assertSame(1,DB::table('student_course_registrations as r')->join('student_course_results as x','x.student_course_registration_id','=','r.student_course_registration_id')
            ->join('result_statuses as s','s.result_status_id','=','x.result_status_id')->whereRaw($eligible)->count());
        $snapshot=HistoricalOutcomeGuard::snapshot(1);$beforeEvents=DB::table(HistoricalExamSchema::OUTCOMES)->get()->toJson();
        $evidence=json_decode(DB::table(HistoricalExamSchema::OUTCOMES)->value('after_values'),true,flags:JSON_THROW_ON_ERROR)['assessment_representation'];
        self::assertTrue(\App\Support\HistoricalAggregateAssessment::valid($evidence,$snapshot));
        foreach (['coursework_cents'=>3100,'canonical_outcome'=>'failed','caps_cents'=>['coursework'=>6000,'examination'=>4000]] as $key=>$value) {
            $tampered=$evidence;$tampered[$key]=$value;self::assertFalse(\App\Support\HistoricalAggregateAssessment::valid($tampered,$snapshot));
        }
        self::assertTrue($service->adopt($actor,$bundle,$review['review'],$review['review_hash'],$request,'0','Synthetic aggregate evidence reviewed')['replayed']);
        self::assertSame('ADOPTED',$service->verifyCorrection($actor,$bundle,$request)['status']);
        self::assertTrue(HistoricalOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(1)));
        try{$grades->calculateRegistrationResult(1,1);self::fail('Ordinary recalculation replaced aggregate adoption');}catch(GradeException $e){self::assertSame('historical_outcome_locked',$e->errorCode);}
        try{$grades->updateRegistrationGrades(1,['theoretical_mark'=>60],1);self::fail('Legacy write replaced aggregate adoption');}catch(GradeException $e){self::assertSame(409,$e->status);}
        // Undefined historical assessment cannot be ordinarily finalized under a made-up part configuration.
        try{app(\App\Services\AcademicCatalogTransaction::class)->run(fn()=>(new \ReflectionMethod(\App\Services\GradePartWorkflowService::class,'finalize'))->invoke(app(\App\Services\GradePartWorkflowService::class),1,1));self::fail('Undefined aggregate offering bypassed ordinary finalization configuration');}
        catch(GradeException $e){self::assertSame('grading_policy_incompatible',$e->errorCode);}
        self::assertSame($snapshot,HistoricalOutcomeGuard::snapshot(1));self::assertSame($beforeEvents,DB::table(HistoricalExamSchema::OUTCOMES)->get()->toJson());
        // A later credit-only adoption must not discard the already adopted assessment representation.
        $creditBundle=$this->approvedBundle(['credits'=>'4']);$p=$this->approvedPreview($creditBundle);
        self::assertSame(1,$p['counts']['classifications']['credit_context_review']);$saved=$this->approvedApply($creditBundle,$p);
        $creditWork=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$creditReview=$service->reviewCorrection($actor,$creditBundle,$creditWork['work_item_id']);
        self::assertNull($creditReview['review']['proposal']['result']);
        $service->adopt($actor,$creditBundle,$creditReview['review'],$creditReview['review_hash'],(string)Str::uuid(),$creditReview['review']['revision'],'Synthetic reviewed attempt credits only');
        self::assertSame($snapshot['results'],HistoricalOutcomeGuard::snapshot(1)['results']);
        self::assertTrue(HistoricalOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(1)));
        self::assertSame(4,$grades->calculateGpa(Student::findOrFail(1),1,1)['total_included_credit_hours']);
    }

    public function test_historical_approved_real_detailed_missing_activities_are_not_replaced_by_aggregate_adoption(): void
    {
        DB::table('grade_components')->where('grade_component_id',2)->update(['component_name'=>'Quiz','max_mark'=>20]);
        DB::table('grade_components')->insert(['course_offering_id'=>1,'component_name'=>'Assignments','component_type'=>'practical','max_mark'=>20,'weight_percentage'=>20,'is_required'=>true,'status'=>'active']);
        DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $definitions=DB::table('grade_components')->get()->toJson();$bundle=$this->approvedBundle();$p=$this->approvedPreview($bundle);
        $a=collect($p['review']['actions'])->firstWhere('source_selection','selected_intact_record');self::assertSame('aggregate_incomplete_followup',$a['classification']);
        self::assertContains('aggregate_known_component_breakdown_missing',$a['blockers']);self::assertContains(2,$a['assessment_evidence']['missing_activity_ids']);
        $saved=$this->approvedApply($bundle,$p);$work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');
        try{app(HistoricalExamWorkService::class)->reviewCorrection(User::findOrFail(1),$bundle,$work['work_item_id']);self::fail('Fictional activity marks were accepted');}
        catch(GradeException $e){self::assertSame('historical_exam_actual_component_evidence_required',$e->errorCode);}
        self::assertSame($definitions,DB::table('grade_components')->get()->toJson());self::assertSame(0,DB::table('student_grade_components')->count());self::assertSame(0,DB::table('student_course_results')->count());
    }

    public static function aggregateFailureCases(): array { return [[0,0,'F'],[20,17,'T'],[30,10,'F']]; }
    public static function aggregatePassingBoundaries(): array { return [[40,60,4.0],[10,60,2.5],[40,15,1.75]]; }
    public function test_historical_approved_conflicting_undefined_result_retains_legacy_parts_and_stale_coursework_without_adding_or_relabelling_them(): void
    {
        $this->official50();DB::table('student_grade_components')->delete();DB::table('grade_components')->delete();
        DB::table('student_course_results')->update(['theoretical_total'=>12,'practical_total'=>11,'coursework_total'=>73]);
        $bundle=$this->approvedBundle();$p=$this->approvedPreview($bundle);self::assertSame(1,$p['counts']['classifications']['historical_aggregate_review']);
        $saved=$this->approvedApply($bundle,$p);$work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$actor=User::findOrFail(1);$service=app(HistoricalExamWorkService::class);
        $review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);$service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic distinct source aggregates, retained legacy evidence');
        $r=DB::table('student_course_results')->first();self::assertEquals(80,$r->final_mark);self::assertEquals(12,$r->theoretical_total);self::assertEquals(11,$r->practical_total);self::assertEquals(73,$r->coursework_total);
        self::assertSame('2025-12-21 12:00:00',$r->result_announced_at);self::assertSame(0,DB::table('student_grade_components')->count());
        self::assertSame(3.0,app(GradeService::class)->calculateGpa(Student::findOrFail(1),1,1)['gpa']);self::assertTrue(HistoricalOutcomeGuard::preservesOutcome(StudentCourseRegistration::findOrFail(1)));
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('aggregatePassingBoundaries')]
    public function test_historical_approved_aggregate_SQL_and_PHP_compare_numeric_cents_at_minima_and_100(int $j,int $k,float $points): void
    {
        DB::table('grade_components')->delete();DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $bundle=$this->approvedBundle(['coursework'=>(string)$j,'exam'=>(string)$k,'total'=>(string)($j+$k)]);$saved=$this->approvedApply($bundle,$this->approvedPreview($bundle));
        $work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);$review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);
        $service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic aggregate numeric boundary');
        self::assertSame($points,app(GradeService::class)->calculateGpa(Student::findOrFail(1),1,1)['gpa']);
        $sql=HistoricalOutcomeGuard::aggregateEligibilitySql('r.student_course_registration_id','x.final_mark','s.status_code');
        $eligible=\App\Support\OfficialGradeScale::gpaEligibilitySql('s.status_code','x.final_mark','x.theoretical_total','x.practical_total','1','1',$sql);
        self::assertSame(1,DB::table('student_course_registrations as r')->join('student_course_results as x','x.student_course_registration_id','=','r.student_course_registration_id')
            ->join('result_statuses as s','s.result_status_id','=','x.result_status_id')->whereRaw($eligible)->count());
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('aggregateFailureCases')]
    public function test_historical_approved_aggregate_zero_and_T_use_the_canonical_failure_not_missing_or_a_pass(int $j,int $k,string $condition): void
    {
        DB::table('grade_components')->delete();DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        $bundle=$this->approvedBundle(['coursework'=>(string)$j,'exam'=>(string)$k,'total'=>(string)($j+$k),'condition'=>$condition]);
        $p=$this->approvedPreview($bundle);self::assertSame(1,$p['counts']['classifications']['historical_aggregate_review']);
        $saved=$this->approvedApply($bundle,$p);$work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);
        $review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);self::assertSame('failed',$review['review']['proposal']['outcome']);
        $service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic reviewed numeric failure');
        self::assertEquals($j+$k,DB::table('student_course_results')->value('final_mark'));
        self::assertNull(DB::table('student_course_results')->value('theoretical_total'));self::assertSame(0,DB::table('student_grade_components')->count());
        $gpa=app(GradeService::class)->calculateGpa(Student::findOrFail(1),1,1);self::assertSame(0.0,$gpa['gpa']);self::assertSame(3,$gpa['total_included_credit_hours']);
    }

    public function test_historical_approved_aggregate_adoption_rejects_stale_definitions_and_unauthorized_actor_without_mutation(): void
    {
        $definitions=DB::table('grade_components')->get()->map(fn($c)=>(array)$c)->all();DB::table('grade_components')->delete();
        DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);$bundle=$this->approvedBundle();
        $saved=$this->approvedApply($bundle,$this->approvedPreview($bundle));$work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);
        $review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);DB::table('grade_components')->insert($definitions);
        try{$service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic stale evidence');self::fail('Changed definitions were silently flattened');}
        catch(GradeException $e){self::assertSame('historical_exam_stale',$e->errorCode);}
        DB::table('roles')->where('role_id',1)->update(['role_code'=>'student']);
        try{$service->reviewCorrection($actor,$bundle,$work['work_item_id']);self::fail('Private aggregate review leaked');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
        self::assertSame(0,DB::table(HistoricalExamSchema::OUTCOMES)->count());self::assertSame(0,DB::table('student_course_results')->count());
    }

    public function test_historical_approved_issued_v4_review_remains_replayable_and_explicitly_resumable_without_new_semantics(): void
    {
        $bundle=$this->approvedBundle();$service=app(ExamCompletionService::class);$actor=User::findOrFail(1);
        $legacy=$service->preview($actor,$bundle,\App\Services\HistoricalExams\ExamReconciliationProjection::LEGACY_CONTRACT);
        self::assertArrayNotHasKey('assessment_evidence',$legacy['review']['actions'][0]);$id=(string)Str::uuid();
        $saved=$this->approvedApply($bundle,$legacy,$id);self::assertSame(1,$saved['saved_pending_approval']);
        self::assertSame($saved,$this->approvedApply($bundle,$legacy,$id,'0',true));self::assertSame(1,$service->verify($actor,$bundle,$id)['saved_pending_approval']);
    }

    public function test_historical_approved_v4_folded_absence_staging_is_reconstructed_while_v5_preserves_the_matching_official_outcome(): void
    {
        $this->official50();DB::table('student_grade_components')->where('grade_component_id',1)->update(['mark'=>17]);DB::table('student_grade_components')->where('grade_component_id',2)->update(['mark'=>20]);
        DB::table('student_course_results')->update(['theoretical_total'=>17,'practical_total'=>20,'final_mark'=>37,'result_status_id'=>2]);DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['result_status_id'=>2]);
        $bundle=$this->approvedBundle(['coursework'=>'20','exam'=>'-','total'=>'37','condition'=>'F']);foreach($bundle['rows'] as &$row)$row['values']['supplementary']='Synthetic traced sitting';unset($row);
        $sup=$bundle['rows'][0];$sup['id']='exam1:supplementary:2';$sup['reference']['sheet']='supplementary';$sup['kind']='undated_supplementary';
        $sup['values']=array_replace($sup['values'],['coursework'=>null,'exam'=>'17','total'=>null,'year'=>null,'term'=>null]);$bundle['rows'][]=$sup;
        $before=HistoricalOutcomeGuard::snapshot(1);$service=app(ExamCompletionService::class);$actor=User::findOrFail(1);
        $legacy=$service->preview($actor,$bundle,\App\Services\HistoricalExams\ExamReconciliationProjection::LEGACY_CONTRACT);
        self::assertSame('aggregate_incomplete_followup',collect($legacy['review']['actions'])->firstWhere('source_selection','selected_intact_record')['classification']);
        $fresh=$this->approvedPreview($bundle);$a=collect($fresh['review']['actions'])->firstWhere('source_selection','selected_intact_record');
        self::assertSame('existing_equivalent',$a['classification']);self::assertSame('17',$a['reconciled_values']['exam']);self::assertSame('-',$a['original_source']['values']['exam']);
        self::assertSame($before,HistoricalOutcomeGuard::snapshot(1));
    }

    public function test_historical_approved_unique_verified_attempt_retains_but_does_not_obey_a_conflicting_roster_program(): void
    {
        $this->official50();
        DB::table('student_grade_components')->where('grade_component_id',1)->update(['mark'=>0]);DB::table('student_grade_components')->where('grade_component_id',2)->update(['mark'=>29]);
        DB::table('student_course_results')->update(['theoretical_total'=>0,'practical_total'=>29,'final_mark'=>29,'result_status_id'=>2]);DB::table('student_course_registrations')->where('student_course_registration_id',1)->update(['result_status_id'=>2]);
        DB::table('academic_programs')->insert(['academic_program_id'=>2,'program_name'=>'Other program','program_code'=>'TEST-OTHER','department_id'=>1]);
        $bundle=$this->approvedBundle(['coursework'=>'29','exam'=>'30','total'=>'59']);foreach($bundle['rows'] as &$row)$row['values']['program']='';unset($row);
        $bundle['student_master'][0]['values']['program']='Other program';
        $before=DB::table('student_course_registrations')->select('student_course_registration_id','student_id','course_offering_id','academic_plan_version_id','plan_program_course_id')->get()->toJson();$student=DB::table('students')->get()->toJson();$beforeOutcome=HistoricalOutcomeGuard::snapshot(1);
        $old=app(ExamCompletionService::class)->preview(User::findOrFail(1),$bundle,\App\Services\HistoricalExams\ExamReconciliationProjection::LEGACY_CONTRACT);
        self::assertContains('student_master_existing_registration_context_conflict',$old['review']['actions'][0]['blockers']);
        $p=$this->approvedPreview($bundle);$a=collect($p['review']['actions'])->firstWhere('source_selection','selected_intact_record');
        self::assertSame('independently_verified_historical_attempt_with_retained_roster_conflict',$a['program_evidence']['basis']);
        self::assertSame(2,$a['program_evidence']['reviewed_data_program_id']);self::assertSame(1,$a['baseline']['program_id']);
        self::assertNotEmpty($a['program_evidence']['student_master_evidence']);self::assertTrue($a['eligible']);
        self::assertSame('correction_review',$a['classification']);$saved=$this->approvedApply($bundle,$p);self::assertSame(1,$saved['staged_pending_review']);
        $work=collect($saved['actions'])->firstWhere('status','STAGED_PENDING_REVIEW');$service=app(HistoricalExamWorkService::class);$actor=User::findOrFail(1);$review=$service->reviewCorrection($actor,$bundle,$work['work_item_id']);
        $service->adopt($actor,$bundle,$review['review'],$review['review_hash'],(string)Str::uuid(),$review['review']['revision'],'Synthetic independently verified historical attempt');
        self::assertSame($before,DB::table('student_course_registrations')->select('student_course_registration_id','student_id','course_offering_id','academic_plan_version_id','plan_program_course_id')->get()->toJson());self::assertSame($student,DB::table('students')->get()->toJson());
        self::assertEquals(59,DB::table('student_course_results')->value('final_mark'));self::assertSame('2025-12-21 12:00:00',DB::table('student_course_results')->value('result_announced_at'));
        if ($directory=getenv('HISTORICAL_EXAM_TEST_EVIDENCE')) \App\Services\StudentCompletion\CompletionFiles::write($directory.'/historical-roster-context-correction.json',
            ['synthetic_only'=>true,'database_driver'=>DB::connection()->getDriverName(),'before'=>$beforeOutcome,'context_evidence'=>$a['program_evidence'],'proposal'=>$review,'after'=>HistoricalOutcomeGuard::snapshot(1),'student_and_registration_context_unchanged'=>true]);
        // Another real attempt is not permission to pick one by id, source counter, or current program.
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1,'status'=>'closed']);
        DB::table('student_course_registrations')->insert(['student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>1]);
        $blocked=$this->approvedPreview($bundle);self::assertSame(0,$blocked['counts']['eligible']);
        self::assertContains('student_master_existing_registration_context_conflict',$blocked['review']['actions'][0]['blockers']);
    }

    public function test_historical_approved_persisted_sources_and_actual_command_path_are_read_only_and_do_not_reinterpret_v3(): void
    {
        $bundle=$this->approvedBundle();$path=$bundle['inputs']['handoff']['path'].'/bundle.json';$output=dirname($path).'/preview.json';$hash=hash_file('sha256',$path);
        $queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        self::assertSame(2,Artisan::call('historical-exams:preview',['--actor'=>'1','--bundle'=>$path,'--bundle-hash'=>$hash,'--output'=>$output]),Artisan::output());
        $p=json_decode(file_get_contents($output),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame('historical-exam-completion.review.v5',$p['review']['contract']);self::assertSame(2,$p['counts']['observations']);self::assertSame(1,$p['counts']['eligible']);
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
