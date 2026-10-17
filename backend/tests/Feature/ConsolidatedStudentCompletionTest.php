<?php

namespace Tests\Feature;

use App\Services\StudentCompletion\{CompletionData,CompletionFiles,CompletionSources,ConsolidatedCompletionDecisions,ConsolidatedHandoff};
use PhpOffice\PhpSpreadsheet\{Spreadsheet,Writer\Xlsx};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Only synthetic workbook/SQL inputs; no original workbooks or private configuration. */
final class ConsolidatedStudentCompletionTest extends TestCase
{
    private string $directory;
    protected function setUp(): void {parent::setUp();$this->directory=sys_get_temp_dir().'/consolidated-completion-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);}
    protected function tearDown(): void {foreach(glob($this->directory.'/*')?:[] as $f)unlink($f);rmdir($this->directory);parent::tearDown();}
    private function inputs(?string $damage=null): array
    {
        $rows=[];
        $add=function($source,$sheet,$row,$values,$formulas=[])use(&$rows){$id=$source.':'.$sheet.':'.$row;$v=json_encode((object)$values,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);$f=json_encode((object)$formulas);$rows[]=[$id,$source,$sheet,$row,'retained',$v,$f,ConsolidatedHandoff::sourceHash($id,$v,$f)];};
        $add('roster','بيانات الطلاب',4,['A'=>'EXAMPLE','B'=>'Never','C'=>'Admit']);
        $add('roster','بيانات الطلاب',5,['A'=>'SYN-1','B'=>'Synthetic','C'=>'Person','D'=>'Parent','N'=>'Synthetic college','O'=>'Synthetic program','S'=>'2023-12-10T00:00:00']);
        $add('exam1','Data',2,['P'=>'SYN-1','C'=>'Synthetic Person','D'=>'Parent','S'=>false,'L'=>'2023-12-10T00:00:00']);
        $add('exam1','OutcomeofMater',2,['H'=>'SYN-1','I'=>'Synthetic Person','E'=>'C1','F'=>'Course','K'=>0,'Q'=>false,'R'=>'2024/2023','Z'=>null],['K'=>'DO_NOT_EXECUTE()']);
        $add('exam1','OutcomeofMater',3,['H'=>'SYN-1','I'=>'Synthetic Person','E'=>'C2','K'=>null],['K'=>'NO_CACHED_VALUE()']);
        $add('exam1','OutcomeofMater',4,['I'=>'Unidentified synthetic person','K'=>'-']);
        $add('exam2','علامات',2,['H'=>'SYN-1','I'=>'Synthetic Person','E'=>'C1','K'=>false]);
        $add('conflicts','مراجعة بيانات الطلاب',6,['A'=>'SYN-1','B'=>'Synthetic Person','C'=>'Synthetic Person','D'=>'Review','E'=>null]);
        $add('environment','RU- CDI- ESI    ',43,['B'=>'Synthetic elective']);
        $tables=array_fill_keys(array_keys(ConsolidatedHandoff::HEADERS),[]);$tables['SourceRows']=$rows;
        $tables['ConflictReview']=[[$rows[7][0],'SYN-1','Synthetic Person','Synthetic Person','Review',null,'E_THEN_C']];
        $tables['Decisions']=[['status','missing',null,'status_default','active','USER_APPROVED','2026-10-17','Explicit approval','Missing only']];
        $tables['ExamOwners']=[[$rows[3][0],'exam1','OutcomeofMater',2,'SYN-1','Synthetic Person','C1','Course','SYN-1','owner','APPROVED','Exact source only']];
        $tables['Decisions'][]=['owner','source','SYN-1','source_owner','Synthetic Person','USER_APPROVED','2026-10-17','Exact ownership','No number-wide reassignment'];
        $groups=[];foreach($rows as $r)$groups[$r[1]][$r[2]][]=$r;
        foreach(['snapshot','roster','conflicts','exam1','exam2','environment'] as $source) {
            $sheets=$groups[$source]??[];$count=array_sum(array_map('count',$sheets));
            $tables['Sources'][]=[$source,'/absent-originals/'.$source.'.xlsx',str_repeat('a',64),$source==='snapshot'?'snapshot':'original_workbook',json_encode(array_keys($sheets)),$count,'Retained source rows'];
            foreach($sheets as $sheet=>$items)$tables['ExtractionControl'][]=[$source,$sheet,count($items),$items[0][3],$items[array_key_last($items)][3],hash('sha256',implode("\n",array_column($items,7)))];
        }
        $departments=[];
        foreach(['engineering_civil','engineering_survey','engineering_drawing','environment'] as $i=>$key) {
            $label='Synthetic department '.$i;$unit=$i===3?'INST-CD':'INST-ENG';$departments[]="(".($i+1).",".($i===3?2:1).",'D$i','$label')";
            $tables['Programs'][]=[$key,$unit,$label,$label,'approved',null,0,0,'Validate actual curriculum'];
            $tables['Curriculum'][]=['synthetic:'.$i,$key,1,'SYN-C'.$i,'Synthetic course '.$i,3,null,null,null,'COURSE','SOURCE','No invented budgets'];
        }
        if($damage==='hash')$tables['SourceRows'][3][5]='{"K":90}';
        if($damage==='duplicate')$tables['SourceRows'][]=$tables['SourceRows'][3];
        if($damage==='count')$tables['ExtractionControl'][0][2]++;
        if($damage==='mirror')$tables['ConflictReview'][0][4]='Changed';
        if($damage==='owner')$tables['ExamOwners'][0][4]='DIFFERENT';
        $book=new Spreadsheet;$book->getActiveSheet()->setTitle('Start')->setCellValue('A1','alrowad.student-completion.handoff.v1');
        foreach($tables as $name=>$data) {
            if($damage==='missing'&&$name==='CourseMappings')continue;
            $sheet=$book->createSheet()->setTitle($name);$header=ConsolidatedHandoff::HEADERS[$name];if($damage==='header'&&$name==='SourceRows')$header[0]='changed';
            $sheet->fromArray($header,null,'A1');foreach($data as $i=>$values)$sheet->fromArray($values,null,'A'.($i+2),true);
        }
        // This deliberately misleading index must never supply ownership, status or name authority.
        $book->createSheet()->setTitle('Students')->setCellValue('A1','FORGED INDEX STUDENT');
        $handoff=$this->directory.'/handoff.xlsx';(new Xlsx($book))->save($handoff);$book->disconnectWorksheets();
        $sql="INSERT INTO `students` (`student_id`,`student_number`,`first_name`) VALUES (1,'EXISTING','Synthetic');\n";
        $sql.="INSERT INTO `student_statuses` (`student_status_id`,`status_code`) VALUES (1,'active');\nINSERT INTO `academic_years` (`academic_year_id`,`year_name`) VALUES (1,'2023-2024');\n";
        $sql.="INSERT INTO `academic_programs` (`academic_program_id`,`department_id`,`program_code`) VALUES (90,90,'EXISTING');\n";
        $sql.="INSERT INTO `colleges` (`college_id`,`college_code`) VALUES (1,'INST-ENG'),(2,'INST-CD');\nINSERT INTO `departments` (`department_id`,`college_id`,`department_code`,`department_name`) VALUES ".implode(',',$departments).";\n";
        $sql.="INSERT INTO `academic_levels` (`academic_level_id`,`level_order`) VALUES (1,1),(2,2);\nINSERT INTO `semesters` (`semester_id`,`semester_order`) VALUES (1,1),(2,2);\n";
        $snapshot=$this->directory.'/snapshot.sql';file_put_contents($snapshot,$sql);
        return ['contract'=>'student-completion.consolidated-inputs.v1','approval_profile'=>'approved-followup-2026-10-17',
            'handoff'=>['path'=>$handoff,'sha256'=>hash_file('sha256',$handoff)],'snapshot'=>['path'=>$snapshot,'sha256'=>hash_file('sha256',$snapshot)]];
    }
    public function test_two_files_only_prepare_and_reconstruct_with_database_unavailable_and_exact_cached_types(): void
    {
        $input=$this->inputs();config(['database.default'=>'intentionally_unavailable']);
        $sources=new CompletionSources;$b=$sources->prepare($input);
        self::assertSame('student-completion.sources.v4',$b['contract']);self::assertCount(2,$b['identities']);self::assertCount(3,$b['observations']);
        self::assertSame(0,$b['observations'][0]['exam_mark']);self::assertFalse($b['observations'][0]['supplementary']);self::assertNull($b['observations'][1]['exam_mark']);self::assertFalse($b['observations'][2]['exam_mark']);
        self::assertFalse($b['identities'][1]['original']['cells']['S']);self::assertNull($b['identities'][1]['status']);
        self::assertSame('2023-12-10',$b['identities'][0]['enrollment_date']);self::assertSame('2023-12-10T00:00:00',$b['identities'][0]['original']['cells']['S']);
        self::assertCount(1,$b['unidentified_source_rows']);self::assertSame(9,$b['consolidated_extraction']['retained_rows']);
        self::assertSame(9,array_sum($b['consolidated_extraction']['uses']));self::assertSame(1,$b['consolidated_extraction']['uses']['documented_example_excluded']);
        self::assertFalse($b['consolidated_extraction']['students_index_used_for_authority']);
        self::assertSame('recorded_provenance_not_independently_rehashed',$b['sources']['roster']['verification']);
        self::assertSame('SYN-1',$b['observations'][0]['approved_owner_mapping']['canonical_number']);
        $file=$this->directory.'/bundle.json';CompletionFiles::write($file,$b);self::assertSame(CompletionData::hash($b),CompletionData::hash($sources->load($file,hash_file('sha256',$file))));
    }
    public static function invalidInputs(): array {return array_map(fn($v)=>[$v],['hash','duplicate','count','mirror','owner','missing','header']);}
    public function test_new_identity_contract_is_explicit_without_reinterpreting_issued_v4(): void
    {
        $input=$this->inputs();config(['database.default'=>'intentionally_unavailable']);$s=new CompletionSources;$old=$s->prepare($input);$oldHash=CompletionData::hash($old);
        $input['contract']='student-completion.consolidated-inputs.v2';$new=$s->prepare($input);
        self::assertSame('student-completion.sources.v5',$new['contract']);self::assertSame(\App\Services\CatalogCompletion\FollowupCourseIdentity::REVISION,$new['catalog_followup']['identity_mapping_revision']);
        self::assertArrayNotHasKey('identity_mapping_revision',$old['catalog_followup']);self::assertSame($oldHash,CompletionData::hash($s->prepare($old['config'])));
        $file=$this->directory.'/new.json';CompletionFiles::write($file,$new);self::assertSame(CompletionData::hash($new),CompletionData::hash($s->load($file,hash_file('sha256',$file))));
    }
    public function test_approved_identity_revision_uses_v6_without_reinterpreting_an_issued_v5_bundle(): void
    {
        $input=$this->inputs();config(['database.default'=>'intentionally_unavailable']);$input['contract']='student-completion.consolidated-inputs.v2';$s=new CompletionSources;
        $old=$s->prepare($input);$hash=CompletionData::hash($old);$input['contract']='student-completion.consolidated-inputs.v3';$new=$s->prepare($input);
        self::assertSame('student-completion.sources.v6',$new['contract']);self::assertSame(\App\Services\CatalogCompletion\FollowupCourseIdentity::APPROVED_REVISION,$new['catalog_followup']['identity_mapping_revision']);
        self::assertSame(\App\Services\CatalogCompletion\FollowupCourseIdentity::REVISION,$old['catalog_followup']['identity_mapping_revision']);self::assertNotSame($old['catalog_followup']['hash'],$new['catalog_followup']['hash']);
        self::assertSame($hash,CompletionData::hash($s->prepare($old['config'])));
        foreach(['v5'=>$old,'v6'=>$new] as $v=>$b){$file=$this->directory.'/'.$v.'.json';CompletionFiles::write($file,$b);self::assertSame(CompletionData::hash($b),CompletionData::hash($s->load($file,hash_file('sha256',$file))));}
    }
    #[DataProvider('invalidInputs')]
    public function test_incompatible_or_tampered_consolidated_evidence_is_rejected(string $damage): void
    {
        $input=$this->inputs($damage);config(['database.default'=>'intentionally_unavailable']);
        $this->expectException(\InvalidArgumentException::class);(new CompletionSources)->prepare($input);
    }
    public function test_source_hash_distinguishes_objects_floats_zero_false_and_missing_values(): void
    {
        $hash=ConsolidatedHandoff::sourceHash('synthetic:1','{"A":0,"B":false,"C":null,"D":1.0}','{}');
        // Independently encoded with Python json.dumps(sort_keys=True,ensure_ascii=False,separators=(',',':')).
        self::assertSame('694e33d6207628d9fe36763cef1a6b27d33a51d7acf6e21019840f372dea5f54',$hash);
        self::assertNotSame($hash,ConsolidatedHandoff::sourceHash('synthetic:1','{"A":false,"B":0,"C":null,"D":1.0}','{}'));
        self::assertNotSame($hash,ConsolidatedHandoff::sourceHash('synthetic:1','{"A":0,"B":false,"D":1.0}','{}'));
    }
    public function test_consolidated_decisions_keep_explicit_name_boundaries_and_college_qualified_ownership(): void
    {
        $ref=fn($file,$row)=>['file'=>$file,'sheet'=>'Synthetic','row'=>$row];
        $one=['number'=>'R99990001','first_name'=>'اختبار','last_name'=>'الاسم','father_name'=>'أب أول','full_name'=>'اختبار الاسم','college'=>'College','program'=>'إدارة الأعمال','enrollment_date'=>'2023-12-10','reference'=>$ref('roster',5)];
        $two=array_replace($one,['number'=>'R99990002','college'=>'Institute','father_name'=>'أب ثان','reference'=>$ref('roster',6)]);
        $observation=['number'=>'R99990001','name'=>'اختبار الاسم','program'=>'','college'=>'College','reference'=>$ref('exam1',2)];
        $review=['number'=>'R99990001','solution'=>'شخص (R99990001) وشخص (R99990002)','cells'=>['A'=>'R99990001','B'=>'اختبار الاسم','C'=>'اختبار الاسم','D'=>null,'E'=>'شخص (R99990001) وشخص (R99990002)'],'reference'=>$ref('conflicts',6)];
        $b=['config'=>['program_aliases'=>['إدارة الأعمال'=>'P1'],'college_aliases'=>['College'=>'C','Institute'=>'I']],'identities'=>[$one,$two],'observations'=>[$observation],'conflicts'=>[$review]];
        $result=(new ConsolidatedCompletionDecisions)->configure($b,['Decisions'=>[]]);
        self::assertSame('R99990001',$result['resolutions'][0]['records'][2]['target']);
        self::assertSame('R99990002',$result['resolutions'][0]['records'][1]['target']);
        $review['solution']='الاسم والكنية : اختبار الاسم - اسم الأب : أب جديد';$review['cells']['E']=$review['solution'];$b['conflicts']=[$review];$b['identities']=[$one];
        $result=(new ConsolidatedCompletionDecisions)->configure($b,['Decisions'=>[]]);$fields=$result['resolutions'][0]['targets'][0]['fields'];
        self::assertSame('الاسم',$fields['last_name']['value']);self::assertSame('أب جديد',$fields['father_name']['value']);self::assertSame('اختبار',$fields['first_name']['value']);
        // An explicit E program can use its owned observation's college even when
        // the source program cell was blank; it cannot invent an institute context.
        $review['solution']='إضافة الطالب في إدارة الأعمال';$review['cells']['E']=$review['solution'];$review['cells']['C']='غير وارد';$b['conflicts']=[$review];$b['identities']=[];
        $result=(new ConsolidatedCompletionDecisions)->configure($b,['Decisions'=>[]]);self::assertSame($observation['reference'],$result['resolutions'][0]['targets'][0]['fields']['program']['context_reference']);
        $b['identities']=[$one];$b['observations']=[array_replace($observation,['number'=>'R99990003'])];
        $d=['field'=>'approved_identity_mapping','authority'=>'USER_APPROVED','approved_value'=>json_encode(['approved_by_user'=>true,'canonical_number'=>$one['number'],
            'other_source_number'=>'R99990003','name'=>$one['full_name'],'father'=>$one['father_name']])];
        $result=(new ConsolidatedCompletionDecisions)->configure($b,['Decisions'=>[$d]]);self::assertSame('أب أول',$result['identity_decisions'][0]['father_name']);
    }
}
