<?php
namespace Tests\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
class SupplementaryExamEligibilitySqlContractTest extends TestCase
{
 #[Test] public function manual_sql_obeys_phase_three_safety_contract():void{$dir=database_path('sql/supplementary-exam-eligibility');$files=['00_preflight.sql','01_apply.sql','02_verify.sql','03_rollback.sql'];foreach($files as $name){$sql=file_get_contents("$dir/$name");$this->assertDoesNotMatchRegularExpression('/\b(SIGNAL|DELIMITER|CREATE\s+PROCEDURE)\b|DATABASE\s*\(/i',$sql,"SUPP-ELIG-SQL-01..03 $name");$this->assertMatchesRegularExpression('/SELECT\s.+;\s*$/is',$sql,"SUPP-ELIG-SQL-13 $name");$this->assertStringContainsString("table_schema='alrowad_uni_rust'",str_replace(' = ','=',$sql));}$all=implode("\n",array_map(fn($f)=>file_get_contents("$dir/$f"),$files));$this->assertStringContainsString('PHASE2_NOT_DEPLOYED',$all);$this->assertStringNotContainsString('supplementary_exam_registrations`',$all);$this->assertStringNotContainsString('UPDATE `alrowad_uni_rust`.`student_course_results`',$all);}
}
