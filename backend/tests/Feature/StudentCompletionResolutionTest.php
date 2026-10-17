<?php

namespace Tests\Feature;

use App\Models\{Student,User};
use App\Services\StudentCompletion\{CompletionData,CompletionResolutions,StudentCompletionService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use Tests\Support\StudentCompletionFixture as Fixture;
use Tests\TestCase;

final class StudentCompletionResolutionTest extends TestCase
{
    protected function setUp(): void {parent::setUp();Fixture::initialize();}
    private function bundle(string $number='S1',string $solution=''): array
    {
        $b=Fixture::bundle($number);$cells=['A'=>$number,'B'=>'Old exam label','C'=>'Test Student','D'=>'Review','E'=>$solution?:null];
        $b['sources']['conflicts']=['sha256'=>str_repeat('f',64)];
        $b['conflicts']=[['number'=>$number,'solution'=>$cells['E'],'cells'=>$cells,'reference'=>['file'=>'conflicts','sheet'=>'Review','row'=>6]]];
        $fields=[];foreach(['first_name','last_name','father_name','program','enrollment_date'] as $field)$fields[$field]=['value'=>$b['identities'][0][$field],
            'authority'=>'C','roster_reference'=>$b['identities'][0]['reference']];
        $records=[];foreach([...$b['identities'],...$b['observations']] as $r)$records[]=['reference'=>$r['reference'],'number'=>$r['number'],'label'=>$r['full_name']??$r['name'],
            'program'=>$r['program'],'father_name'=>$r['father_name']??null,'college'=>$r['college']??null,'target'=>$number];
        $b['config']['resolution_policy']='administration_e_then_roster_c';
        $b['config']['resolutions']=[['review_row'=>6,'expected_cells'=>$cells,'targets'=>[['number'=>$number,'display_name'=>'Test Student','fields'=>$fields]],'records'=>$records]];
        return $b;
    }
    private function compile(array $b): array {return (new CompletionResolutions)->compile($b);}
    private function preview(array $b): array{return app(StudentCompletionService::class)->preview(User::findOrFail(1),$b);}
    private function apply(array $b,array $r): array{return app(StudentCompletionService::class)->apply(User::findOrFail(1),$b,$r['review'],$r['review_hash'],(string)Str::uuid(),$r['review']['revision']);}
    public function test_blank_e_uses_structured_c_and_scoped_exam_label_without_global_alias(): void
    {
        $b=$this->bundle();$b['observations'][0]['name']='Old exam label';$b['config']['resolutions'][0]['records'][1]['label']='Old exam label';
        $r=$this->preview($this->compile($b));self::assertSame([],$r['students'][0]['identity_blockers']);
        self::assertTrue($r['students'][0]['status_change']['ready']);self::assertSame(1,$r['resolution_controls']['fallback']);
        $b['observations'][0]['reference']['row']=99;
        $this->expectException(\InvalidArgumentException::class);$this->compile($b);
    }
    public function test_program_only_e_retains_c_name_and_name_correction_is_independent_of_transfer(): void
    {
        DB::table('students')->where('student_id',1)->update(['first_name'=>'Wrong']);$b=$this->bundle('S1','Program two');
        $b['config']['program_aliases']['Program two']='P2';$b['config']['resolutions'][0]['targets'][0]['fields']['program']=['authority'=>'E','passage'=>'Program two','value'=>'Program two'];
        $b=$this->compile($b);$r=$this->preview($b);$row=$r['students'][0];
        self::assertContains('existing_program_transfer_required',$row['status_change']['blockers']);self::assertTrue($row['identity_correction']['ready']);
        $result=$this->apply($b,$r);self::assertSame('identity_corrected',$result['students'][0]['action']);
        self::assertSame('Test',Student::findOrFail(1)->first_name);self::assertSame(1,Student::findOrFail(1)->academic_program_id);
        self::assertSame(['first_name'=>'Test'],$result['students'][0]['after']);
    }
    public function test_identity_and_status_actions_are_explicit_and_only_selected_reviewed_actions_are_written(): void
    {
        DB::table('students')->where('student_id',1)->update(['first_name'=>'Wrong']);$b=$this->compile($this->bundle());$r=$this->preview($b);
        self::assertSame(['correct_identity','record_discontinuation'],$r['review']['proposals'][0]['actions']);
        // Deliberately approve only the independently ready identity correction, with new reviewed content.
        $r['review']['proposals'][0]['actions']=['correct_identity'];$r['review_hash']=CompletionData::hash($r['review']);
        $result=$this->apply($b,$r);self::assertSame('identity_corrected',$result['students'][0]['action']);
        self::assertSame('Test',Student::findOrFail(1)->first_name);self::assertSame(1,Student::findOrFail(1)->student_status_id);
    }
    public function test_unchanged_structured_identity_is_noop_and_missing_or_ambiguous_c_cannot_supply_fields(): void
    {
        $b=$this->compile($this->bundle());$row=$this->preview($b)['students'][0];self::assertSame([],$row['identity_correction']['changes']);
        $b=$this->bundle();$b['conflicts'][0]['cells']['C']='غير وارد في ملف شؤون الطلاب';$b['config']['resolutions'][0]['expected_cells']=$b['conflicts'][0]['cells'];
        $this->expectException(\InvalidArgumentException::class);$this->compile($b);
    }
    public function test_explicit_discontinued_creation_overrides_old_active_without_inventing_absence(): void
    {
        $b=$this->bundle('NEW','منقطع');$b['config']['resolutions'][0]['targets'][0]['fields']['status']=['authority'=>'E','passage'=>'منقطع','value'=>'frozen'];
        $b=$this->compile($b);$r=$this->preview($b);$row=collect($r['students'])->firstWhere('number','NEW');
        self::assertSame(17,$row['creation']['fields']['student_status_id']);self::assertTrue($row['creation']['ready']);
        self::assertSame([],$row['periods']);self::assertSame('administrative',$row['status_change']['authority']);
        $this->apply($b,$r);self::assertSame(17,Student::where('student_number','NEW')->sole()->student_status_id);
    }
    public function test_administrative_creation_requires_an_active_installed_frozen_status(): void
    {
        Schema::table('student_statuses',fn(Blueprint $t)=>$t->boolean('is_active')->default(true));
        DB::table('student_statuses')->where('status_code','frozen')->update(['is_active'=>false]);
        $b=$this->bundle('NEW','منقطع');$b['config']['resolutions'][0]['targets'][0]['fields']['status']=['authority'=>'E','passage'=>'منقطع','value'=>'frozen'];
        $r=collect($this->preview($this->compile($b))['students'])->firstWhere('number','NEW');
        self::assertFalse($r['creation']['ready']);self::assertNull($r['creation']['fields']['student_status_id']);
        self::assertContains('discontinuation_status_unavailable',$r['creation']['blockers']);
    }
    public function test_two_explicit_people_are_separated_by_source_context_and_ambiguous_rows_stay_unassigned(): void
    {
        $b=$this->bundle('NEW','NEW and OTHER');$d=&$b['config']['resolutions'][0];$other=$d['targets'][0];$other['number']='OTHER';$other['roster_number']='NEW';$d['targets'][]=$other;
        $d['records'][1]['target']='OTHER';$b=$this->compile($b);
        self::assertSame('OTHER',$b['observations'][0]['ownership']['number']);self::assertSame('NEW',$b['identities'][0]['ownership']['number']);
        $b=$this->bundle('NEW','NEW and OTHER');$b['config']['resolutions'][0]['records'][1]['target']=null;$b=$this->compile($b);
        self::assertCount(0,$b['observations']);self::assertCount(1,$b['unassigned_source_records']);
    }
    public function test_changed_decision_cells_or_number_ownership_are_rejected(): void
    {
        $missing=$this->bundle();$missing['config']['resolutions']=[];
        try{$this->compile($missing);self::fail('Missing source interpretations accepted');}catch(\InvalidArgumentException $e){self::assertStringContainsString('Every review row',$e->getMessage());}
        $b=$this->bundle();$b['config']['resolutions'][0]['expected_cells']['E']='fabricated';
        $this->expectException(\InvalidArgumentException::class);$this->compile($b);
    }
    public function test_explicit_addition_can_reference_its_single_recorded_name_without_inventing_a_date(): void
    {
        $b=$this->bundle('NEW','إضافة الطالب');$b['conflicts'][0]['cells']['B']='Test Student';$b['conflicts'][0]['cells']['C']='غير وارد في ملف شؤون الطلاب';
        $d=&$b['config']['resolutions'][0];$d['expected_cells']=$b['conflicts'][0]['cells'];
        foreach(['first_name'=>'Test','last_name'=>'Student'] as $key=>$value)$d['targets'][0]['fields'][$key]=['authority'=>'E','passage'=>'إضافة الطالب','value'=>$value,'support_reference'=>$b['observations'][0]['reference']];
        foreach(['father_name','program','enrollment_date'] as $key)unset($d['targets'][0]['fields'][$key]);
        $b['identities'][0]['enrollment_date']=null;$b=$this->compile($b);$r=collect($this->preview($b)['students'])->firstWhere('number','NEW');
        self::assertSame('Test',$r['creation']['fields']['first_name']);self::assertContains('creation_field_missing:enrollment_date',$r['creation']['blockers']);
    }
    public function test_canonical_json_roundtrip_preserves_record_and_field_references(): void
    {
        $b=$this->bundle();$before=$this->compile($b);$roundtrip=json_decode(CompletionData::json($b),true);
        self::assertSame(CompletionData::hash($before),CompletionData::hash($this->compile($roundtrip)));
    }
    public function test_ambiguous_fallback_is_narrowly_blocked_and_never_chooses_one_number(): void
    {
        $b=$this->bundle();$b['conflicts'][0]['cells']['C']='R24000001 / R24000002';$b['config']['resolutions'][0]['expected_cells']=$b['conflicts'][0]['cells'];
        $compiled=$this->compile($b);self::assertSame([],$compiled['approved_identities']['S1']['fields']);
        self::assertContains('student_affairs_identity_ambiguous',$this->preview($compiled)['students'][0]['identity_blockers']);
    }
    public function test_admin_status_preserves_archive_and_protected_live_states_and_receipts_reject_changed_sources(): void
    {
        $b=$this->bundle('S1','منقطع');$b['config']['resolutions'][0]['targets'][0]['fields']['status']=['authority'=>'E','passage'=>'منقطع','value'=>'frozen'];$b=$this->compile($b);
        DB::table('students')->where('student_id',1)->update(['deleted_at'=>now()]);self::assertFalse($this->preview($b)['students'][0]['status_change']['ready']);
        DB::table('students')->where('student_id',1)->update(['deleted_at'=>null,'student_status_id'=>23]);self::assertFalse($this->preview($b)['students'][0]['status_change']['ready']);
        DB::table('students')->where('student_id',1)->update(['student_status_id'=>1]);$r=$this->preview($b);$id=(string)Str::uuid();$service=app(StudentCompletionService::class);$actor=User::findOrFail(1);
        $service->apply($actor,$b,$r['review'],$r['review_hash'],$id,$r['review']['revision']);
        $oldLogs=DB::table('user_activity_logs')->get()->toJson();$b['sources']['conflicts']['sha256']=str_repeat('a',64);$r=$this->preview($b);
        try{$service->apply($actor,$b,$r['review'],$r['review_hash'],$id,$r['review']['revision']);self::fail('Changed source replay accepted');}
        catch(\App\Services\StudentCompletion\CompletionException $e){self::assertSame('student_completion_request_mismatch',$e->errorCode);}
        self::assertSame($oldLogs,DB::table('user_activity_logs')->get()->toJson());
    }
    public function test_official_pass_for_same_attempt_conflicts_but_f_z_and_retained_marks_do_not_prove_attendance(): void
    {
        Schema::table('student_course_results',fn(Blueprint $t)=>$t->boolean('is_deprived')->default(false));
        DB::table('approval_statuses')->insert(['approval_status_id'=>99,'status_code'=>'approved']);DB::table('grade_approvals')->insert(['course_offering_id'=>1,'approval_status_id'=>99]);
        foreach(['passed'=>91,'failed'=>92,'deprived'=>93] as $code=>$id)DB::table('result_statuses')->insert(['result_status_id'=>$id,'status_code'=>$code]);
        DB::table('student_course_results')->insert(['student_course_registration_id'=>1,'result_status_id'=>91,'final_mark'=>80]);
        $r=$this->preview(Fixture::bundle())['students'][0];self::assertFalse($r['status_change']['ready']);
        self::assertContains('official_success_conflicts_with_absence',$r['periods'][0]['blockers']);self::assertSame(1,$r['periods'][0]['evidence_conflicts'][0]['official']['registration_id']);
        foreach([[92,null,0],[93,null,0],[93,95,1],[91,95,1]] as [$status,$mark,$z]) {
            DB::table('student_course_results')->update(['result_status_id'=>$status,'final_mark'=>$mark,'is_deprived'=>$z]);
            self::assertTrue($this->preview(Fixture::bundle())['students'][0]['status_change']['ready']);
        }
    }
    public function test_consecutive_fully_absent_semesters_are_current_absence_but_attendance_or_later_registration_blocks(): void
    {
        DB::table('course_offerings')->insert(['course_offering_id'=>2,'course_id'=>2,'academic_program_id'=>1,'academic_year_id'=>1,'semester_id'=>2]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id'=>2,'student_id'=>1,'course_offering_id'=>2,'registration_status_id'=>2]);
        DB::table('student_academic_terms')->insert(['student_id'=>1,'academic_year_id'=>1,'semester_id'=>2,'is_finalized'=>1,'total_registered_hours'=>3]);
        $b=Fixture::bundle();$o=$b['observations'][0];$o['course_code']='C2';$o['course_name']='مادة 2';$o['reference']['row']=3;$b['observations'][]=$o;
        $r=$this->preview($b)['students'][0];self::assertTrue($r['status_change']['ready']);self::assertSame('confirmed_full_absence',$r['exam_classification']);self::assertCount(2,$r['periods']);
        $b['observations'][1]['exam_mark']=0;self::assertFalse($this->preview($b)['students'][0]['status_change']['ready']);
        $b['observations'][1]['exam_mark']='-';DB::table('course_offerings')->insert(['course_offering_id'=>3,'course_id'=>2,'academic_program_id'=>1,'academic_year_id'=>2,'semester_id'=>1]);
        DB::table('student_course_registrations')->insert(['student_id'=>1,'course_offering_id'=>3,'registration_status_id'=>2]);
        self::assertFalse($this->preview($b)['students'][0]['status_change']['ready']);
    }
}
