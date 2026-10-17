<?php

namespace Tests\Feature;

use App\Models\{Student,User};
use App\Services\StudentCompletion\{CompletionData,CompletionFollowup,StudentCompletionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StudentCompletionFixture as Fixture;
use Tests\TestCase;

final class StudentCompletionFollowupTest extends TestCase
{
    protected function setUp(): void {parent::setUp();Fixture::initialize();}
    private function compile(array $bundle,array $decisions,array $owners=[]): array
    {
        $handoff=['Decisions'=>$decisions,'ExamOwners'=>$owners];$bundle['sources']['handoff']=['sha256'=>str_repeat('a',64)];
        $bundle['config']['followup']=['policy'=>'approved_missing_students_2026_10','decision_ids'=>array_column($decisions,'decision_id'),
            'owner_record_ids'=>array_column($owners,'record_id'),'table_hashes'=>array_map(CompletionData::hash(...),$handoff)];
        return (new CompletionFollowup)->compile($bundle,$handoff);
    }
    private function decision(string $field,mixed $value,?string $number=null,string $id='approval'): array
    {return ['decision_id'=>$id,'canonical_number'=>$number,'field'=>$field,'approved_value'=>$value,'authority'=>'USER_APPROVED','_row'=>2];}
    private function preview(array $b): array {return app(StudentCompletionService::class)->preview(User::findOrFail(1),$b);}
    public function test_old_receipt_distinguishes_a_later_audited_correction_from_an_unexplained_change(): void
    {
        $a=User::findOrFail(1);$s=app(StudentCompletionService::class);$b=Fixture::bundle('NEW');$r=$s->preview($a,$b);$id=(string)Str::uuid();
        $s->apply($a,$b,$r['review'],$r['review_hash'],$id,$r['review']['revision']);
        $next=$b;$next['approved_identities']['NEW']=['fields'=>['first_name'=>['value'=>'Reviewed','authority'=>'C']],
            'accepted_records'=>[$b['identities'][0]['reference'],$b['observations'][0]['reference']],'blockers'=>[],'unresolved_fields'=>[]];
        $r=$s->preview($a,$next);$s->apply($a,$next,$r['review'],$r['review_hash'],(string)Str::uuid(),$r['review']['revision']);
        $verified=$s->verify($a,$b,$id);self::assertFalse($verified['students'][0]['current_state_matches']);
        self::assertSame('subsequent_completion_audit_explains_current_fields',$verified['students'][0]['current_state_verdict']);
        self::assertCount(1,$verified['students'][0]['subsequent_audit']['receipts']);
        DB::table('students')->where('student_number','NEW')->update(['first_name'=>'Unexplained']);
        $verified=$s->verify($a,$b,$id);self::assertNull($verified['students'][0]['subsequent_audit']);self::assertFalse($verified['students'][0]['current_state_matches']);
        self::assertSame('diverged_requires_audit_review_no_automatic_damage_inference',$verified['students'][0]['current_state_verdict']);
    }
    public function test_default_active_and_estimated_date_are_missing_only_with_separate_audited_provenance(): void
    {
        $b=Fixture::bundle('NEW');$b['identities'][0]['status']=null;$b['identities'][0]['enrollment_date']=null;
        $b=$this->compile($b,[$this->decision('status_default','active',null,'status'),$this->decision('enrollment_date_fallback','SEPTEMBER_1_OF_EARLIEST_EVIDENCED_EXAM_YEAR',null,'date')]);
        $r=$this->preview($b);$row=collect($r['students'])->firstWhere('number','NEW');self::assertTrue($row['creation']['ready']);
        self::assertSame('2023-09-01',$row['creation']['fields']['enrollment_date']);self::assertSame(1,$row['creation']['fields']['student_status_id']);
        self::assertSame('administratively_approved_estimate',$row['creation']['provenance']['enrollment_date']['kind']);
        self::assertSame('administratively_approved_missing_default',$row['creation']['provenance']['status']['kind']);
        self::assertNull($b['identities'][0]['enrollment_date']);$id=(string)Str::uuid();$service=app(StudentCompletionService::class);$actor=User::findOrFail(1);
        $result=$service->apply($actor,$b,$r['review'],$r['review_hash'],$id,$r['review']['revision']);
        self::assertSame('2023-09-01',CompletionData::date(Student::where('student_number','NEW')->sole()->getRawOriginal('enrollment_date')));
        self::assertSame(CompletionData::hash($row['creation']['provenance']),CompletionData::hash($result['students'][0]['completion_provenance']));
        $logs=DB::table('user_activity_logs')->get()->toJson();self::assertSame($result,$service->apply($actor,$b,$r['review'],$r['review_hash'],$id,'0'));
        self::assertSame($logs,DB::table('user_activity_logs')->get()->toJson());
        $current=collect($this->preview($b)['students'])->firstWhere('number','S1');self::assertSame('2023-12-10',$current['creation']['fields']['enrollment_date']);
        self::assertSame(1,Student::findOrFail(1)->student_status_id);
    }
    public function test_documented_dates_and_named_frozen_override_defaults_but_ambiguous_or_undated_years_do_not_estimate(): void
    {
        $b=Fixture::bundle('NEW');$b['identities'][0]['status']=null;
        $rules=[$this->decision('status_default','active',null,'status'),$this->decision('enrollment_date_fallback','SEPTEMBER_1_OF_EARLIEST_EVIDENCED_EXAM_YEAR',null,'date'),
            $this->decision('student_status','frozen','NEW','frozen'),$this->decision('enrollment_date','2023-09-01','NEW','explicit_date')];
        $row=collect($this->preview($this->compile($b,$rules))['students'])->firstWhere('number','NEW');
        self::assertSame('2023-12-10',$row['creation']['fields']['enrollment_date']);self::assertSame(17,$row['creation']['fields']['student_status_id']);
        $b['identities'][0]['enrollment_date']=null;unset($rules[3]);$b['observations'][0]['year']='2023/2025';
        $row=collect($this->preview($this->compile($b,array_values($rules)))['students'])->firstWhere('number','NEW');self::assertNull($row['creation']['fields']['enrollment_date']);
        self::assertContains('enrollment_estimate_exam_year_ambiguous',$row['creation']['blockers']);
        $b['observations'][0]['kind']='undated_supplementary';$b['observations'][0]['year']='2024/2023';
        $row=collect($this->preview($this->compile($b,array_values($rules)))['students'])->firstWhere('number','NEW');self::assertNull($row['creation']['fields']['enrollment_date']);
        self::assertContains('enrollment_estimate_exam_year_missing',$row['creation']['blockers']);
    }
    public function test_all_four_source_owner_groups_are_exact_row_bound_without_replacing_competing_numbers(): void
    {
        $b=Fixture::bundle();$template=$b['observations'][0];$b['observations']=[];$b['unassigned_source_records']=[];$owners=[];$decisions=[];$rowNumber=10;
        foreach([13,7,4,4] as $group=>$count) {
            $target='TARGET-'.$group;$original='SOURCE-'.$group;$label='Approved label '.$group;$id='owner-'.$group;
            $decisions[]=$this->decision('source_owner',$label,$target,$id);
            for($i=0;$i<$count;$i++) {
                $row=$template;$row['number']=$original;$row['name']=$label;$row['course_code']='C'.($i+1);
                $row['reference']=['file'=>$i%2?'exam2':'exam1','sheet'=>'Synthetic','row'=>$rowNumber++];$row['blocker']='source_ownership_unproven';
                $b['unassigned_source_records'][]=$row;$ref=$row['reference'];
                $owners[]=['record_id'=>$ref['file'].':'.$ref['sheet'].':'.$ref['row'],'source_id'=>$ref['file'],'source_sheet'=>$ref['sheet'],'source_row'=>$ref['row'],
                    'original_number'=>$original,'original_name'=>$label,'original_course_code'=>$row['course_code'],'original_course_name'=>$row['course_name'],
                    'canonical_number'=>$target,'decision_id'=>$id,'approval'=>'APPROVED'];
            }
            $other=$template;$other['number']=$original;$other['name']='Actual other owner';$other['reference']['row']=$rowNumber++;$b['observations'][]=$other;
        }
        $compiled=$this->compile($b,$decisions,$owners);self::assertCount(32,$compiled['observations']);self::assertCount(0,$compiled['unassigned_source_records']);
        self::assertSame(28,$compiled['followup_controls']['owner_observations']);
        foreach($compiled['observations'] as $row)if(isset($row['approved_owner_mapping'])) {
            self::assertSame($row['approved_owner_mapping']['canonical_number'],$row['ownership']['number']);self::assertSame($row['number'],$row['ownership']['original_number']);
        } else self::assertArrayNotHasKey('ownership',$row);
        $bad=$owners;$bad[0]['original_course_code']='CHANGED';
        $this->expectException(\InvalidArgumentException::class);$this->compile($b,$decisions,$bad);
    }
    public function test_explicit_compound_identity_and_qualified_program_are_not_inferred_from_a_triplet_or_numeric_hint(): void
    {
        $b=Fixture::bundle('NEW');$b['observations'][0]['name']='Compound first Parent Family';
        $value=['first_name'=>'Compound first','last_name'=>'Family','father_name'=>'Parent','accepted_labels'=>['Test Student','Compound first Parent Family']];
        $b=$this->compile($b,[$this->decision('identity',json_encode($value),'NEW','identity'),
            $this->decision('academic_program',json_encode(['college'=>'كلية 1','program'=>'Synthetic program','program_id_snapshot'=>999]),'NEW','program')]);
        $r=collect($this->preview($b)['students'])->firstWhere('number','NEW');self::assertSame('Compound first',$r['creation']['fields']['first_name']);
        self::assertSame('Family',$r['creation']['fields']['last_name']);self::assertSame(1,$r['program_id']);self::assertSame([],$r['identity_blockers']);
    }
    public function test_identical_source_copies_do_not_become_distinct_attempts_and_supplementary_remains_separate(): void
    {
        $b=Fixture::bundle();$copy=$b['observations'][0];$copy['reference']['file']='exam2';$copy['ownership_history']=[['previous_blocker'=>'reviewed']];
        $copy['approved_owner_mapping']=['record_id'=>'approved'];$b['observations'][]=$copy;
        $row=$this->preview($b)['students'][0];self::assertSame(2,$row['source_observation_count']);self::assertSame(1,$row['observation_count']);
        self::assertCount(2,$row['source_observations'][0]['references']);
        $copy['kind']='undated_supplementary';$copy['year']=null;$copy['reference']['row']=7;$b['observations'][]=$copy;
        $row=$this->preview($b)['students'][0];self::assertSame(2,$row['observation_count']);self::assertSame(1,$row['undated_supplementary_count']);
    }
    public function test_missing_students_without_observations_remain_visible_and_prior_completed_operation_is_verifiable(): void
    {
        $actor=User::findOrFail(1);$service=app(StudentCompletionService::class);$prior=Fixture::bundle('PREVIOUS');$review=$this->preview($prior);$uuid=(string)Str::uuid();
        $service->apply($actor,$prior,$review['review'],$review['review_hash'],$uuid,$review['review']['revision']);
        $logs=DB::table('user_activity_logs')->get()->toJson();$b=Fixture::bundle('NO-EXAMS');$b['observations']=[];$b['identities'][0]['status']=null;
        $b=$this->compile($b,[$this->decision('status_default','active')]);$r=$this->preview($b);$row=collect($r['students'])->firstWhere('number','NO-EXAMS');
        self::assertFalse($row['creation']['ready']);self::assertSame(1,$r['population_groups']['missing_without_observations']);
        self::assertSame($logs,DB::table('user_activity_logs')->get()->toJson());
        $verification=$service->verify($actor,$prior,$uuid);self::assertTrue($verification['existing_academic_evidence_matches']);self::assertTrue($verification['students'][0]['current_state_matches']);
        self::assertSame(1,Student::where('student_number','PREVIOUS')->count());
    }
}
