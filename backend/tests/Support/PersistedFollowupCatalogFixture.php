<?php

namespace Tests\Support;

use App\Services\StudentCompletion\{CompletionFiles,CompletionSnapshot,CompletionSources,ConsolidatedHandoff};
use Illuminate\Support\Facades\{DB,Schema};
use PhpOffice\PhpSpreadsheet\{Spreadsheet,Writer\Xlsx};

/** Tiny synthetic v6 inputs, real preparation/writer/loader. Never imports source SQL. */
final class PersistedFollowupCatalogFixture
{
    private const SHARED=[
        403=>['RU111','اللغة العربية (1)','لغة عربية (1)'],404=>['RU112','اللغة الإنكليزية (1)','لغة انكليزية (1)'],
        405=>['RU113','مدخل الى المعلوماتيه','مدخل الى المعلوماتية'],406=>['RU121','اللغة العربية (2)','لغة عربية (2)'],
        407=>['RU122','اللغة الإنكليزية (2)','لغة الإنكليزية (2)'],408=>['RU123','حضارة وثقافة','الحضارة والثقافة'],
        409=>['RUS211','مدخل إلى الحياة الجامعية','مدخل إلى الحياة الجامعية'],
    ];
    public static function seed(): void
    {
        if(!app()->environment('testing')||!(DB::getDriverName()==='sqlite'||Schema::hasTable('catalog_test_marker')))throw new \RuntimeException('Synthetic isolated fixture required');
        foreach(self::SHARED as $id=>[$code,,$name])if(!DB::table('courses')->where('course_id',$id)->exists())DB::table('courses')->insert(['course_id'=>$id,'course_code'=>$code,'course_name'=>$name,'credit_hours'=>2,'is_active'=>true]);
        foreach([1900,1901] as $index=>$program) {
            DB::table('academic_programs')->insert(['academic_program_id'=>$program,'department_id'=>$index+1,'program_code'=>'PERSIST-LEGACY-'.$index,'program_name'=>'Synthetic context owner '.$index,'total_credit_hours'=>$index?2:14]);
            foreach(['mandatory','elective'] as $type) {
                $group=DB::table('academic_requirement_groups')->insertGetId(['academic_program_id'=>$program,'group_code'=>'PERSIST-G-'.$index.'-'.$type,'group_name'=>'Synthetic context '.$type,'requirement_scope'=>'university','requirement_type'=>$type,'required_credit_hours'=>0,'is_active'=>true],'requirement_group_id');
                foreach($index?[403]:array_keys(self::SHARED) as $id)if(($id===409?'elective':'mandatory')===$type) {
                    $pc=DB::table('program_courses')->insertGetId(['academic_program_id'=>$program,'course_id'=>$id,'course_type'=>$type,'academic_level_id'=>1,'recommended_semester_id'=>1,'is_active'=>true],'program_course_id');
                    DB::table('program_course_requirement_groups')->insert(['program_course_id'=>$pc,'requirement_group_id'=>$group]);
                }
            }
        }
        foreach(['INST-ENG','INST-CD'] as $code)$units[$code]=DB::table('colleges')->where('college_code',$code)->value('college_id')
            ?? DB::table('colleges')->insertGetId(['college_code'=>$code,'college_name'=>'Synthetic persisted institute '.$code,'is_active'=>true],'college_id');
        foreach(range(0,3) as $i)DB::table('departments')->insert(['college_id'=>$units[$i===3?'INST-CD':'INST-ENG'],'department_code'=>'PERSIST-D'.$i,'department_name'=>'Synthetic persisted department '.$i,'is_active'=>true]);
    }

    public static function write(string $directory): array
    {
        $tables=array_fill_keys(array_keys(ConsolidatedHandoff::HEADERS),[]);
        foreach(range(43,46) as $n) {
            $id='environment:RU- CDI- ESI    :'.$n;$v=json_encode(['B'=>'Synthetic elective '.($n-42)]);$f='{}';
            $tables['SourceRows'][]=[$id,'environment','RU- CDI- ESI    ',$n,'retained',$v,$f,ConsolidatedHandoff::sourceHash($id,$v,$f)];
        }
        foreach(['snapshot','roster','conflicts','exam1','exam2','environment'] as $source) {
            $rows=$source==='environment'?$tables['SourceRows']:[];
            $tables['Sources'][]=[$source,'/synthetic/'.$source.'.xlsx',str_repeat('a',64),$source==='snapshot'?'snapshot':'original_workbook',json_encode($rows?['RU- CDI- ESI    ']:[]),count($rows),'Synthetic only'];
            if($rows)$tables['ExtractionControl'][]=[$source,'RU- CDI- ESI    ',4,43,46,hash('sha256',implode("\n",array_column($rows,7)))];
        }
        $refs=[[827,828,829,835,836,837],[856,857,858,864,865,866],[799,800,801,807,808,809],['11:C','12:C','13:C','11:G','12:G','13:G']];
        foreach(['engineering_civil','engineering_survey','engineering_drawing','environment'] as $i=>$key) {
            $d=DB::table('departments')->where('department_code','PERSIST-D'.$i)->sole();
            $tables['Programs'][]=[$key,$i===3?'INST-CD':'INST-ENG',$d->department_name,$d->department_name,'approved',null,0,0,'Synthetic context comparison'];
            foreach(array_slice(self::SHARED,0,6,true) as $offset=>$course) {
                $n=array_search($offset,array_keys(self::SHARED),true);[$code,$source]=$course;
                $ref=$i===3?'environment:الخطة الإرشادية:'.$refs[$i][$n]:'exam1:DBMater:'.$refs[$i][$n];
                $tables['Curriculum'][]=[$ref,$key,1,$code,$source,2,null,null,null,'COURSE','SOURCE','Synthetic university course'];
            }
            if($i<3)$tables['Curriculum'][]=['exam1:DBMater:'.[830,859,802][$i],$key,1,'RUS111','مدخل إلى الحياة الجامعية',2,null,null,null,'COURSE','SOURCE','Exact approved equivalence'];
            // Aggregate synthetic subjects keep this serialization fixture small; canonical services validate them normally.
            if($i===0)$tables['Curriculum'][]=['synthetic:persisted-elective',$key,1,'RUS-PERSIST-E','Synthetic persisted university elective',2,null,null,null,'COURSE','SOURCE','Synthetic only'];
            $tables['Curriculum'][]=['synthetic:persisted-institute:'.$i,$key,1,'PERSIST-C'.$i,'Synthetic persisted institute subject '.$i,[69,66,64,58][$i],null,null,null,'COURSE','SOURCE','Synthetic only'];
        }
        $book=new Spreadsheet;$book->getActiveSheet()->setTitle('Start')->setCellValue('A1','alrowad.student-completion.handoff.v1');
        foreach($tables as $name=>$rows){$sheet=$book->createSheet()->setTitle($name);$sheet->fromArray(ConsolidatedHandoff::HEADERS[$name],null,'A1');foreach($rows as $i=>$r)$sheet->fromArray($r,null,'A'.($i+2),true);}
        $handoff=$directory.'/handoff.xlsx';(new Xlsx($book))->save($handoff);$book->disconnectWorksheets();
        $sql='';$quote=fn($v)=>$v===null?'NULL':(is_int($v)||is_float($v)?(string)$v:"'".str_replace(['\\',"'","\r","\n"],['\\\\',"\\'",'\\r','\\n'],(string)$v)."'");
        foreach([...CompletionSnapshot::TABLES,...CompletionSnapshot::FOLLOWUP_TABLES,'course_departments'] as $table)if(Schema::hasTable($table)) {
            $rows=DB::table($table)->orderByRaw('1')->get();if($rows->isEmpty())continue;$columns=array_keys((array)$rows[0]);
            $sql.='INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES '.implode(',',array_map(fn($r)=>'('.implode(',',array_map($quote,array_values((array)$r))).')',$rows->all())).";\n";
        }
        $snapshot=$directory.'/snapshot.sql';file_put_contents($snapshot,$sql);
        $input=['contract'=>'student-completion.consolidated-inputs.v3','approval_profile'=>'approved-followup-2026-10-17',
            'handoff'=>['path'=>$handoff,'sha256'=>hash_file('sha256',$handoff)],'snapshot'=>['path'=>$snapshot,'sha256'=>hash_file('sha256',$snapshot)]];
        $bundle=(new CompletionSources)->prepare($input);$file=$directory.'/bundle.json';CompletionFiles::write($file,$bundle);
        return ['path'=>$file,'hash'=>hash_file('sha256',$file),'bundle'=>(new CompletionSources)->load($file,hash_file('sha256',$file)),'original'=>$bundle];
    }
}
