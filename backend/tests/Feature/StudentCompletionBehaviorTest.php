<?php

namespace Tests\Feature;

use App\Models\{Student,User};
use App\Services\AcademicCatalogTransaction;
use App\Services\StudentCompletion\{CompletionData,CompletionDatabase,CompletionException,CompletionProjection,StudentCompletionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StudentCompletionFixture as Fixture;
use Tests\TestCase;

final class StudentCompletionBehaviorTest extends TestCase
{
    protected function setUp(): void {parent::setUp();Fixture::initialize();}
    protected function tearDown(): void {\Carbon\CarbonImmutable::setTestNow();parent::tearDown();}
    private function service(): StudentCompletionService{return app(StudentCompletionService::class);}
    private function actor(): User{return User::findOrFail(1);}
    private function report(?array $bundle=null): array{return $this->service()->preview($this->actor(),$bundle??Fixture::bundle());}
    private function row(?array $bundle=null,string $number='S1'): array{return collect($this->report($bundle)['students'])->firstWhere('number',$number);}
    private function apply(array $bundle,array $report,string $id,?string $revision=null,bool $resume=false): array
    {return $this->service()->apply($this->actor(),$bundle,$report['review'],$report['review_hash'],$id,$revision??$report['review']['revision'],$resume);}

    public function test_full_authoritative_roster_absence_is_ready_but_preview_never_writes(): void
    {
        $before=DB::table('students')->get()->toJson();$revision=app(AcademicCatalogTransaction::class)->revision();$logs=DB::table('user_activity_logs')->count();
        $row=$this->row();self::assertTrue($row['status_change']['ready']);self::assertSame(17,$row['status_change']['to_status_id']);
        self::assertSame('confirmed_full_absence',$row['classification']);self::assertSame(1,$row['derived_level']['value']);
        self::assertSame(1,$row['periods'][0]['semester_id']); // Source plan term 7 is not the actual semester.
        self::assertSame($before,DB::table('students')->get()->toJson());self::assertSame($revision,app(AcademicCatalogTransaction::class)->revision());self::assertSame($logs,DB::table('user_activity_logs')->count());
    }
    public function test_one_source_row_without_roster_certification_is_not_a_semester_absence(): void
    {
        DB::table('student_academic_terms')->update(['is_finalized'=>0]);$r=$this->row();
        self::assertFalse($r['status_change']['ready']);self::assertSame('possible_absence_incomplete_evidence',$r['classification']);
        self::assertContains('expected_roster_completeness_unproven',$r['periods'][0]['blockers']);
    }
    public function test_zero_missing_marks_partial_absence_duplicates_and_conflicts(): void
    {
        $b=Fixture::bundle();$b['observations'][0]['exam_mark']=0;
        self::assertFalse($this->row($b)['status_change']['ready']);self::assertSame(1,$this->row($b)['periods'][0]['nonabsence_examinations']);
        $b['observations'][0]['exam_mark']=null;self::assertContains('examination_outcome_unknown',$this->row($b)['periods'][0]['blockers']);
        $b=Fixture::bundle();$copy=$b['observations'][0];$copy['reference']['row']=9;$b['observations'][]=$copy;
        self::assertTrue($this->row($b)['status_change']['ready']);self::assertSame(2,$this->row($b)['source_observation_count']);
        $counts=$this->report($b)['record_counts'];
        self::assertSame(1,$counts['students']);self::assertSame(1,$counts['registrations']);
        self::assertSame(2,$counts['source_observation_rows']);self::assertSame(1,$counts['deduplicated_observations']);
        $b['observations'][1]['exam_mark']=0;self::assertContains('conflicting_examination_copies',$this->row($b)['periods'][0]['blockers']);
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>2,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>2,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>2]);
        DB::table('student_academic_terms')->update(['total_registered_hours'=>6]);
        $b=Fixture::bundle();$copy=$b['observations'][0];$copy['course_code']='C2';$copy['course_name']='مادة 2';$copy['exam_mark']=0;$copy['reference']['row']=3;$b['observations'][]=$copy;
        self::assertSame('partial_absence',$this->row($b)['classification']);
    }
    public function test_later_exam_or_registration_and_undated_supplementary_prevent_current_status_mutation(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>2,'semester_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>2,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>2]);
        self::assertSame('historical_absence_with_later_activity',$this->row()['classification']);self::assertFalse($this->row()['status_change']['ready']);
        $b=Fixture::bundle();$copy=$b['observations'][0];$copy['year']='2025/2024';$copy['exam_mark']=65;$copy['reference']['row']=3;$b['observations'][]=$copy;
        self::assertSame('historical_absence_with_later_activity',$this->row($b)['classification']);
        DB::table('student_course_registrations')->where('student_course_registration_id',2)->delete();
        $b=Fixture::bundle();$copy=$b['observations'][0];$copy['kind']='undated_supplementary';$copy['year']=null;$copy['exam_mark']=55;$b['observations'][]=$copy;
        self::assertContains('supplementary_chronology_unknown',$this->row($b)['status_change']['blockers']);
    }
    public function test_ambiguous_semesters_and_missing_year_never_use_plan_term_or_file_year(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>2]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>2,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>2]);
        self::assertFalse($this->row()['status_change']['ready']);
        $b=Fixture::bundle();$b['observations'][0]['year']=null;self::assertNull($this->row($b)['derived_level']['value']);
        self::assertContains('examination_year_missing',$this->row($b)['status_change']['blockers']);
    }
    public function test_existing_protected_statuses_archival_and_pending_operations_remain_unchanged(): void
    {
        foreach([17,21,23,24,25,26] as $id) {DB::table('students')->where('student_id',1)->update(['student_status_id'=>$id]);self::assertFalse($this->row()['status_change']['ready']);}
        DB::table('students')->where('student_id',1)->update(['student_status_id'=>1,'deleted_at'=>now()]);self::assertTrue($this->row()['archived']);self::assertFalse($this->row()['status_change']['ready']);
        DB::table('students')->where('student_id',1)->update(['deleted_at'=>null]);
        DB::table('student_registration_requests')->insert(['student_id'=>1,'status'=>'submitted']);self::assertContains('pending_academic_operation',$this->row()['status_change']['blockers']);
    }
    public function test_approved_number_and_name_alias_is_not_a_global_swap_or_program_transfer(): void
    {
        $b=Fixture::bundle();$b['config']['identity_decisions']=[['source_number'=>'OLD','canonical_number'=>'S1','names'=>['Test Student']]];
        $b['observations'][0]['number']='OLD';self::assertTrue($this->row($b)['status_change']['ready']);
        $b['observations'][0]['name']='Different Person';self::assertFalse($this->row($b)['status_change']['ready']);
        self::assertNotNull(collect($this->report($b)['students'])->firstWhere('number','OLD'));
        $b=Fixture::bundle();$b['config']['approved_students']['S1']=['program_code'=>'P2','names'=>['Test Student']];
        self::assertContains('existing_program_transfer_required',$this->row($b)['identity_blockers']);
    }
    public function test_creation_requires_exact_date_status_and_known_program_while_level_can_use_cohort(): void
    {
        $b=Fixture::bundle('NEW');$b['identities'][0]['enrollment_date']=null;$b['identities'][0]['status']=null;
        $r=$this->row($b,'NEW');self::assertSame(1,$r['derived_level']['value']);self::assertFalse($r['creation']['ready']);
        self::assertContains('creation_field_missing:enrollment_date',$r['creation']['blockers']);self::assertContains('creation_field_missing:student_status_id',$r['creation']['blockers']);
        $b['identities'][0]['program']='Missing program';self::assertContains('program_missing_or_ambiguous',$this->row($b,'NEW')['identity_blockers']);
        $b=Fixture::bundle('NEW');$b['observations'][0]['cohort']='2025/2024';$b['identities'][0]['enrollment_date']=null;
        self::assertNull($this->row($b,'NEW')['derived_level']['value']);
    }
    public function test_exact_enrollment_boundaries_override_cohort_noise_without_promoting_existing_student(): void
    {
        $b=Fixture::bundle();$b['observations'][0]['year']='2025/2024';$b['observations'][0]['cohort']='2025/2024';
        self::assertSame(2,$this->row($b)['derived_level']['value']);self::assertSame(1,Student::findOrFail(1)->current_academic_level_id);
        self::assertSame(2023,CompletionData::year('2024/2023'));self::assertNull(CompletionData::year('2025/2023'));
    }
    public function test_status_write_is_audited_idempotent_and_preserves_material_history(): void
    {
        $b=Fixture::bundle();$r=$this->report($b);$id=(string)Str::uuid();
        $before=app(CompletionDatabase::class)->evidence([1],[1,2]);$result=$this->apply($b,$r,$id);
        self::assertSame('COMPLETE',$result['status']);self::assertSame(17,Student::findOrFail(1)->student_status_id);
        self::assertSame($before,app(CompletionDatabase::class)->evidence([1],[1,2]));
        $logs=DB::table('user_activity_logs')->count();self::assertSame($result,$this->apply($b,$r,$id,'0'));self::assertSame($logs,DB::table('user_activity_logs')->count());
        $verify=$this->service()->verify($this->actor(),$b,$id);self::assertTrue($verify['existing_academic_evidence_matches']);self::assertTrue($verify['students'][0]['current_state_matches']);
        self::assertSame($result,$this->service()->status($this->actor(),$id));
    }
    public function test_ready_missing_student_is_created_once_and_existing_personal_fields_are_never_overwritten(): void
    {
        $b=Fixture::bundle('NEW');$r=$this->report($b);$id=(string)Str::uuid();self::assertTrue($this->row($b,'NEW')['creation']['ready']);
        $result=$this->apply($b,$r,$id);self::assertSame('COMPLETE',$result['status']);self::assertSame(1,Student::where('student_number','NEW')->count());
        $this->apply($b,$r,$id,'0');self::assertSame(1,Student::where('student_number','NEW')->count());self::assertSame('Test',Student::findOrFail(1)->first_name);
        self::assertTrue($this->service()->verify($this->actor(),$b,$id)['existing_academic_evidence_matches']);
    }
    public function test_stale_preview_rejects_without_academic_write_and_changed_uuid_content_is_rejected(): void
    {
        $b=Fixture::bundle();$r=$this->report($b);DB::table('students')->where('student_id',1)->update(['first_name'=>'Changed']);
        try{$this->apply($b,$r,(string)Str::uuid());self::fail('Stale accepted');}catch(CompletionException $e){self::assertSame('student_completion_stale',$e->errorCode);}
        self::assertSame(1,Student::findOrFail(1)->student_status_id);
        DB::table('students')->where('student_id',1)->update(['first_name'=>'Test']);$r=$this->report($b);$id=(string)Str::uuid();$this->apply($b,$r,$id);
        $r['review']['blocked'][]=['number'=>'different'];$r['review_hash']=CompletionData::hash($r['review']);
        $this->expectException(CompletionException::class);$this->apply($b,$r,$id,'0');
    }
    public function test_authorization_scope_and_receipt_owner_are_rechecked(): void
    {
        $b=Fixture::bundle();$r=$this->report($b);$id=(string)Str::uuid();$this->apply($b,$r,$id);
        DB::table('role_permissions')->where('permission_id',DB::table('permissions')->where('permission_code','students.view')->value('permission_id'))->delete();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$this->service()->status($this->actor(),$id);
    }

    public function test_historical_creation_keeps_trigger_assignment_history_and_pins_fixed_reference_not_current_default(): void
    {
        $plans=app(\App\Services\AcademicPlanWorkflow::class);$confirm=fn()=>['revision'=>app(AcademicCatalogTransaction::class)->revision(),'confirmed'=>true];
        $source=$plans->fixTransition($this->actor(),1,$confirm())['current_version_id'];
        $copy=$plans->copy($this->actor(),1,$source,['revision'=>app(AcademicCatalogTransaction::class)->revision(),'label'=>'New admission plan']);
        $version=(int)DB::table('academic_plan_versions')->where('status','draft')->value('academic_plan_version_id');
        $plans->approve($this->actor(),1,$version,$confirm());$plans->setDefault($this->actor(),1,$version,$confirm());
        $b=Fixture::bundle('NEW');$r=$this->report($b);$result=$this->apply($b,$r,(string)Str::uuid());
        self::assertSame('COMPLETE',$result['status']);$student=Student::where('student_number','NEW')->sole();
        self::assertSame((int)$source,\App\Services\AcademicPlanContext::forStudent($student)->versionId);
        self::assertSame(2,DB::table('student_academic_plan_assignments')->where('student_id',$student->getKey())->count());
        self::assertNotNull(DB::table('student_academic_plan_assignments')->where('student_id',$student->getKey())->where('academic_plan_version_id',$version)->value('ended_at'));
        self::assertSame((int)$source,\App\Services\AcademicPlanContext::forStudent(Student::findOrFail(1))->versionId);
    }

    public function test_failure_after_creation_rolls_back_entire_student_but_independent_ready_creation_proceeds(): void
    {
        $b=Fixture::bundle('BAD');$other=Fixture::bundle('GOOD');$b['identities']=[...$b['identities'],...$other['identities']];$b['observations']=[...$b['observations'],...$other['observations']];
        Student::created(function(Student $student) {if($student->student_number==='BAD')throw \Illuminate\Validation\ValidationException::withMessages(['synthetic'=>'Fault after persistence']);});
        $r=$this->report($b);$result=$this->apply($b,$r,(string)Str::uuid());
        self::assertSame('PARTIAL',$result['status']);self::assertFalse(Student::where('student_number','BAD')->exists());self::assertTrue(Student::where('student_number','GOOD')->exists());
        self::assertSame(0,DB::table('student_academic_plan_assignments')->count());
    }

    public function test_actual_program_scope_denial_and_another_actor_cannot_read_operation_receipt(): void
    {
        $b=Fixture::bundle();$r=$this->report($b);$id=(string)Str::uuid();$this->apply($b,$r,$id);
        DB::table('user_access_scopes')->where('user_id',1)->update(['scope_type'=>'program','scope_id'=>2]);
        try{$this->service()->status($this->actor(),$id);self::fail('Scope loss accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
        DB::table('users')->insert(['user_id'=>3,'username'=>'Other authorized reader','account_status_id'=>1]);
        DB::table('user_roles')->insert(['user_id'=>3,'role_id'=>1]);DB::table('user_access_scopes')->insert(['user_id'=>3,'scope_type'=>'university','scope_id'=>1]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$this->service()->status(User::findOrFail(3),$id);
    }

    public function test_super_admin_without_extra_role_permissions_or_scope_uses_existing_central_bypass(): void
    {
        DB::table('users')->insert(['user_id'=>2,'username'=>'Synthetic admin','account_status_id'=>1]);DB::table('roles')->insert(['role_id'=>2,'role_code'=>'super_admin']);
        DB::table('user_roles')->insert(['user_id'=>2,'role_id'=>2]);$actor=User::findOrFail(2);$b=Fixture::bundle();$r=$this->service()->preview($actor,$b);
        $result=$this->service()->apply($actor,$b,$r['review'],$r['review_hash'],(string)Str::uuid(),$r['review']['revision']);
        self::assertSame('COMPLETE',$result['status']);self::assertSame(17,Student::findOrFail(1)->student_status_id);
    }

    public function test_missing_write_permission_does_not_change_data_or_write_receipts(): void
    {
        $b=Fixture::bundle();$r=$this->report($b);$logs=DB::table('user_activity_logs')->count();
        DB::table('role_permissions')->where('permission_id',DB::table('permissions')->where('permission_code','students.manage')->value('permission_id'))->delete();
        try{$this->apply($b,$r,(string)Str::uuid());self::fail('Missing permission accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
        self::assertSame($logs,DB::table('user_activity_logs')->count());self::assertSame(1,Student::findOrFail(1)->student_status_id);
    }

    public function test_actual_incomplete_lifecycle_blocks_status_change_and_its_evidence_is_preserved(): void
    {
        \Tests\Support\CompletionRegistrationFixture::prepare();\Tests\Support\CompletionRegistrationFixture::sqliteLifecycle();
        // The grant requires an unresolved attempt, not the fixture's already settled historical F.
        DB::table('student_course_results')->where('student_course_registration_id',1)->delete();
        DB::table('grade_components')->insert(['grade_component_id'=>1,'course_offering_id'=>1,'component_name'=>'Synthetic theory','component_type'=>'theoretical','max_mark'=>100,'is_required'=>1]);
        DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>1]);
        \Tests\Support\CompletionRegistrationFixture::lifecycle(1);
        $before=DB::table('student_course_incomplete_cases')->get()->toJson();$events=DB::table('student_course_incomplete_events')->get()->toJson();
        $row=$this->row();self::assertContains('incomplete_active',$row['status_change']['blockers']);self::assertFalse($row['status_change']['ready']);
        self::assertSame($before,DB::table('student_course_incomplete_cases')->get()->toJson());self::assertSame($events,DB::table('student_course_incomplete_events')->get()->toJson());
        \Carbon\CarbonImmutable::setTestNow();
    }

    public function test_interrupted_final_response_reports_committed_students_and_resume_never_duplicates_them(): void
    {
        $b=Fixture::bundle('NEW');$r=$this->report($b);$id=(string)Str::uuid();$this->apply($b,$r,$id);
        // Simulate a missing aggregate response/receipt, not rollback of its committed per-student transaction.
        DB::table('user_activity_logs')->where('action_code','student_completion.result')->delete();
        $status=$this->service()->status($this->actor(),$id);self::assertSame('INTERRUPTED',$status['status']);self::assertCount(1,$status['confirmed_students']);
        self::assertSame([],$status['unconfirmed_numbers']);
        $this->apply($b,$r,$id,app(AcademicCatalogTransaction::class)->revision(),true);self::assertSame(1,Student::where('student_number','NEW')->count());
    }

    public function test_out_of_scope_existence_is_unavailable_not_misreported_as_missing(): void
    {
        DB::table('user_access_scopes')->where('user_id',1)->update(['scope_type'=>'program','scope_id'=>2]);
        $r=$this->report();$row=collect($r['students'])->firstWhere('number','S1');
        self::assertNull($row['exists']);self::assertSame(0,$r['counts']['missing']);self::assertSame(1,$r['counts']['existence_unavailable']);
        self::assertFalse($row['creation']['ready']);
    }

    public function test_enrollment_at_academic_year_boundary_does_not_use_calendar_year_subtraction(): void
    {
        $b=Fixture::bundle();$b['observations'][0]['year']='2025/2024';
        foreach(['2024-08-31'=>2,'2024-09-01'=>1] as $date=>$level) {
            DB::table('students')->where('student_id',1)->update(['enrollment_date'=>$date]);$b['identities'][0]['enrollment_date']=$date;
            self::assertSame($level,$this->row($b)['derived_level']['value']);
        }
    }

    public function test_unknown_name_override_does_not_become_a_ready_creation_field(): void
    {
        $b=Fixture::bundle('NEW');$b['config']['approved_students']['NEW']=['first_name'=>'Invented','last_name'=>'Name'];
        self::assertContains('creation_name_not_supported_by_evidence',$this->row($b,'NEW')['creation']['blockers']);
    }

    public function test_registration_reads_are_batched_instead_of_querying_each_registration(): void
    {
        $count=function(): int {DB::enableQueryLog();DB::flushQueryLog();$this->report();$n=count(DB::getQueryLog());DB::disableQueryLog();return $n;};
        $one=$count();
        foreach(range(2,80) as $id) {
            DB::table('course_offerings')->insert(['course_offering_id'=>$id,'course_id'=>1,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>1]);
            DB::table('student_course_registrations')->insert(['student_course_registration_id'=>$id,'student_id'=>1,'course_offering_id'=>$id,'registration_status_id'=>2]);
        }
        self::assertLessThanOrEqual($one+2,$count());self::assertFalse($this->row()['status_change']['ready']);
    }

    public function test_student_moving_out_of_original_program_scope_revokes_current_receipt_access(): void
    {
        DB::table('user_access_scopes')->where('user_id',1)->update(['scope_type'=>'program','scope_id'=>1]);
        $b=Fixture::bundle();$r=$this->report($b);$id=(string)Str::uuid();$this->apply($b,$r,$id);
        DB::table('students')->where('student_id',1)->update(['academic_program_id'=>2]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$this->service()->status($this->actor(),$id);
    }
}
