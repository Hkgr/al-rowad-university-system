<?php

namespace Tests\Feature;

use App\Services\StudentCompletion\{CompletionData,CompletionFiles,CompletionSnapshot,CompletionSources};
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\{Spreadsheet,Writer\Xlsx};
use Tests\TestCase;

final class StudentCompletionSourcesTest extends TestCase
{
    private string $directory;
    protected function setUp(): void {parent::setUp();$this->directory=sys_get_temp_dir().'/synthetic-student-completion-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);}
    protected function tearDown(): void {foreach(glob($this->directory.'/*')?:[] as $f)unlink($f);rmdir($this->directory);parent::tearDown();}
    private function source(string $name,array $sheets): array
    {
        $b=new Spreadsheet;$b->removeSheetByIndex(0);
        foreach($sheets as $nameSheet=>$cells) {$s=$b->createSheet();$s->setTitle($nameSheet);foreach($cells as $address=>$value)$s->setCellValue($address,$value);}
        $path=$this->directory.'/'.$name;(new Xlsx($b))->save($path);$b->disconnectWorksheets();return ['path'=>$path,'sha256'=>hash_file('sha256',$path)];
    }
    private function config(): array
    {
        $path=$this->directory.'/snapshot.sql';
        file_put_contents($path,"DROP TABLE students;\nINSERT INTO `students` (`student_id`,`student_number`,`first_name`) VALUES\n(1,'S1','Name; intact');\nINSERT INTO `student_statuses` (`student_status_id`,`status_code`) VALUES\n(1,'active');\nINSERT INTO `academic_years` (`academic_year_id`,`year_name`) VALUES\n(1,'2023-2024');\nINSERT INTO `academic_programs` (`academic_program_id`,`program_code`) VALUES\n(1,'P1');\nINSERT INTO `users` (`password`) VALUES\n('NEVER_COPY');\n");
        return ['snapshot'=>['path'=>$path,'sha256'=>hash_file('sha256',$path)],
            'roster'=>$this->source('roster.xlsx',['بيانات الطلاب'=>['A3'=>'الرقم الجامعي','B3'=>'الاسم الأول','C3'=>'الكنية','O3'=>'القسم','S3'=>'تاريخ التسجيل',
                'A4'=>'EXAMPLE','B4'=>'Sample','A5'=>'S2','B5'=>'Synthetic','C5'=>'Person','O5'=>'Program','S5'=>'2023-12-10']])+['example_rows'=>[4]],
            'conflicts'=>$this->source('conflicts.xlsx',['مراجعة بيانات الطلاب'=>['A5'=>'الرقم الجامعي','D5'=>'المشكلة','E5'=>'حل المشكلة','A6'=>'S3','D6'=>'Unresolved']]),
            'examinations'=>[$this->source('exam.xlsm',['Marks'=>['H1'=>'الرقم الجامعي','I1'=>'الاسم','E1'=>'رمز المقرر','K1'=>'الامتحان النهائي','R1'=>'العام الدراسي',
                'H2'=>'S2','I2'=>'Synthetic Person','E2'=>'C1','K2'=>'-','R2'=>'2024/2023','D2'=>7,
                'H3'=>'S3','I3'=>'Another Person','E3'=>'C1','K3'=>0,'R3'=>'2024/2023']])+['regular_sheets'=>['Marks']]]];
    }
    public function test_prepare_reads_sources_without_a_database_query_preserves_mark_types_and_skips_only_explicit_example(): void
    {
        $c=$this->config();$queries=[];DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        $bundle=(new CompletionSources)->prepare($c);
        self::assertSame([],$queries);self::assertCount(1,$bundle['identities']);self::assertSame('S2',$bundle['identities'][0]['number']);
        self::assertSame('-',$bundle['observations'][0]['exam_mark']);self::assertSame(0,$bundle['observations'][1]['exam_mark']);
        self::assertSame(7,$bundle['observations'][0]['plan_term']);self::assertNull($bundle['conflicts'][0]['solution']);
        self::assertSame('Name; intact',$bundle['snapshot']['students'][0]['first_name']);self::assertArrayNotHasKey('users',$bundle['snapshot']);
    }
    public function test_bundle_reconstruction_rejects_changed_source_and_fabricated_normalized_values(): void
    {
        $sources=new CompletionSources;$c=$this->config();$b=$sources->prepare($c);$path=$this->directory.'/bundle.json';
        CompletionFiles::write($path,$b);self::assertSame(CompletionData::hash($b),CompletionData::hash($sources->load($path,hash_file('sha256',$path))));
        $b['observations'][0]['exam_mark']=90;file_put_contents($path,CompletionData::json($b));
        $this->expectException(\InvalidArgumentException::class);$sources->load($path,hash_file('sha256',$path));
    }
    public function test_changed_column_contract_is_not_silently_accepted(): void
    {
        $c=$this->config();$c['examinations'][0]=$this->source('bad.xlsx',['Marks'=>['H1'=>'Wrong column']])+['regular_sheets'=>['Marks']];
        $this->expectException(\InvalidArgumentException::class);(new CompletionSources)->prepare($c);
    }
    public function test_snapshot_rejects_expressions_instead_of_executing_them(): void
    {
        $p=$this->directory.'/bad.sql';file_put_contents($p,"INSERT INTO `students` (`student_id`) VALUES\n(SLEEP(5));");
        $this->expectException(\InvalidArgumentException::class);(new CompletionSnapshot)->read($p);
    }
    public function test_identifiable_artifacts_cannot_be_written_inside_checkout(): void
    {
        $this->expectException(\InvalidArgumentException::class);CompletionFiles::write(base_path('private-report-must-not-exist.json'),['private'=>true]);
    }

    public function test_identity_display_copies_retain_original_values_without_inventing_an_active_status(): void
    {
        $c=$this->config();
        $headers=['P1'=>'الرقم الجامعي','C1'=>'الاسم والكنية','L1'=>'تاريخ التسجيل'];
        $c['examinations']=[$this->source('identities.xlsm',[
            'Data'=>$headers+['P2'=>'S2','C2'=>'  Synthetic   Person  ','L2'=>'2023-12-10','S2'=>true],
            'FilterVac'=>$headers+['P2'=>'S3','C2'=>'Display Person','S2'=>true,'C3'=>'Unnamed number'],
        ])+['regular_sheets'=>[],'identity_sheets'=>['Data','FilterVac']]];
        $b=(new CompletionSources)->prepare($c);
        $master=$b['identities'][1];$display=$b['identities'][2];
        self::assertSame('Synthetic Person',$master['full_name']);
        self::assertSame('  Synthetic   Person  ',$master['original']['full_name']);
        self::assertSame('active',$master['status']);self::assertNull($display['status']);
        self::assertSame('display_copy',$display['source_role']);
        self::assertSame(3,$b['unidentified_source_rows'][0]['reference']['row']);
    }
}
