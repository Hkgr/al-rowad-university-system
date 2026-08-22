<?php
namespace Tests\Feature;
use Tests\TestCase;
class SupplementaryExamRegistrationSqlContractTest extends TestCase
{
 private function sql($n){return file_get_contents(database_path("sql/supplementary-exam-registration/$n"));}
 public function test_phpmyadmin_policy():void{foreach(['00_preflight.sql','01_apply.sql','02_verify.sql','03_rollback.sql'] as $n){$s=$this->sql($n);$this->assertDoesNotMatchRegularExpression('/\b(SIGNAL|DELIMITER|CREATE\s+PROCEDURE)\b|DATABASE\s*\(/i',$s);$this->assertMatchesRegularExpression('/SELECT\s.+;\s*$/is',$s);$this->assertStringContainsString("table_schema='alrowad_uni_rust'",$s);}}
 public function test_identity_fks_and_summer_invariant():void{$a=$this->sql('01_apply.sql');foreach(['uq_ser_source','uq_ser_current','fk_ser_offering','fk_ser_student','fk_ser_source','fk_ser_registered_by','fk_ser_cancelled_by'] as $x)$this->assertStringContainsString($x,$a);$v=$this->sql('02_verify.sql');$this->assertStringContainsString('semester_order=3',$v);$this->assertStringNotContainsString('semester_id=3',$v);$this->assertStringContainsString('current_slot IS NULL OR r.current_slot<>1',$v);}
 public function test_no_forbidden_academic_mutations():void{$all=implode("\n",array_map(fn($n)=>$this->sql($n),['00_preflight.sql','01_apply.sql','02_verify.sql','03_rollback.sql']));foreach(['INSERT INTO `alrowad_uni_rust`.`student_course_registrations`','INSERT INTO `alrowad_uni_rust`.`course_offerings`','UPDATE `alrowad_uni_rust`.`supplementary_exam_results`'] as $x)$this->assertStringNotContainsString($x,$all);}
}
