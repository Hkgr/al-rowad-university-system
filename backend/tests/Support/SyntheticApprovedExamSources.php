<?php

namespace Tests\Support;

use App\Services\HistoricalExams\{ExamApprovedAuthority, ExamSources};
use App\Support\HistoricalExamSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** All people, workbooks, authority documents and schema here are synthetic. */
final class SyntheticApprovedExamSources
{
    public static function write(string $directory,array $values=[]): array
    {
        $inputs=HistoricalExamSourceFixture::write($directory,$values['program']??'Program',$values);
        if(isset($values['catalog_snapshot'])) {
            foreach($values['catalog_snapshot'] as $course) {
                $quoted=fn($s)=>"'".str_replace("'","''",$s)."'";
                file_put_contents($inputs['snapshot']['path'],"INSERT INTO `courses` (`course_id`,`course_code`,`course_name`,`credit_hours`,`is_active`) VALUES (".(int)$course['course_id'].",".$quoted($course['course_code']).",".$quoted($course['course_name']).",".(int)$course['credit_hours'].",1);\n",FILE_APPEND);
            }
            $inputs['snapshot']['sha256']=hash_file('sha256',$inputs['snapshot']['path']);
        }
        $decisions=array_fill(0,17,['source_references'=>[]]);
        $decisions[5]['selected_records']=[];
        $decisions[16]['approved_sitting_academic_year']='2025-2026';
        if(($values['exam']??null)==='-'&&($values['condition']??null)==='F')$decisions[11]['source_references']=['exam1:OutcomeofMater:2','exam2:علامات:2'];
        $group=['college'=>$values['college']??'College','program'=>$values['program']??'Program','code'=>$values['code']??'C1','name'=>$values['course']??'Course','credits'=>$values['credits']??'3',
            'decision'=>'delegated_typography_spelling_alias','target'=>['course_id'=>1,'course_code'=>$values['code']??'C1','course_name'=>$values['target_course_name']??'Course','credit_hours'=>(int)($values['credits']??3)],
            'required_program_id'=>null,'required_college_code'=>null,'references'=>['exam1:OutcomeofMater:2','exam2:علامات:2']];
        $docs=['PROMPT.md'=>'Synthetic approved instructions.','README_AR.md'=>'Synthetic control evidence.',
            'approved-decisions.json'=>['schema'=>'alrowad-exam-approved-decisions-handoff.v1','decisions'=>$decisions],
            'course-identity-review.json'=>['groups'=>[$group],'college_aliases'=>[]],
            'source-manifest.json'=>['sources'=>[['key'=>'exam1','sha256'=>$inputs['exam1']['sha256']],['key'=>'exam2','sha256'=>$inputs['exam2']['sha256']]]],
            'identity-and-period-controls.json'=>['controls'=>[]],'verified-source-findings.json'=>['findings'=>[]]];
        $pins=[];foreach($docs as $name=>$value){$path=$directory.'/'.$name;file_put_contents($path,is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$value);$pins[$name]=hash_file('sha256',$path);}
        $inputs['handoff']=['path'=>$directory];
        return ['inputs'=>$inputs,'reader'=>new ExamSources(new ExamApprovedAuthority($pins)),'pins'=>$pins];
    }

    /** SQLite behavioral fixture only; production DDL/constraints are separately exercised on MariaDB. */
    public static function schema(): void
    {
        foreach(HistoricalExamSchema::columns() as $table=>$columns) {
            Schema::create($table,function(Blueprint $t)use($table,$columns) {
                foreach($columns as $name=>$spec) {
                    if($spec[2]??false){$t->bigIncrements($name);continue;}
                    $column=match(true){$spec[0]==='int'=>$t->integer($name),$spec[0]==='int unsigned'=>$t->unsignedInteger($name),$spec[0]==='bigint'=>$t->bigInteger($name),
                        $spec[0]==='datetime'=>$t->dateTime($name),$spec[0]==='text'=>$t->text($name),$spec[0]==='longtext'=>$t->longText($name),
                        default=>$t->string($name,(int)preg_replace('/\D/','',$spec[0]))};
                    if($spec[1]??false)$column->nullable();
                }
                foreach(HistoricalExamSchema::indexes()[$table] as $name=>$spec) {
                    $fields=explode(',',$spec[1]);if($name==='PRIMARY'){$first=$fields[0];if(!($columns[$first][2]??false))$t->primary($fields);}
                    elseif($spec[0])$t->index($fields,$name);else $t->unique($fields,$name);
                }
            });
            DB::unprepared("CREATE TRIGGER synthetic_hx_{$table}_i AFTER INSERT ON {$table} BEGIN UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1; END");
            foreach(['UPDATE','DELETE'] as $event)DB::unprepared("CREATE TRIGGER synthetic_hx_{$table}_{$event} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT,'historical_exam_evidence_append_only'); END");
        }
    }
}
