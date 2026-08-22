<?php
namespace Tests\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
class SupplementaryExamGradingSqlContractTest extends TestCase
{
 private function sql(string $name):string{return file_get_contents(database_path('sql/supplementary-exam-grading/'.$name));}
 #[Test] public function package_has_visible_fail_closed_contract():void{foreach(['00_preflight.sql','01_apply.sql','02_verify.sql','03_rollback.sql','README.md'] as $f)$this->assertFileExists(database_path('sql/supplementary-exam-grading/'.$f));$this->assertStringContainsString("'READY','BLOCKED'",$this->sql('00_preflight.sql'));$this->assertStringContainsString("'PASS','FAIL'",$this->sql('02_verify.sql'));$this->assertStringContainsString('BLOCKED_IN_USE',$this->sql('03_rollback.sql'));}
 #[Test] public function scripts_are_manual_fully_qualified_and_forbidden_construct_free():void{foreach(['00_preflight.sql','01_apply.sql','02_verify.sql','03_rollback.sql'] as $f){$s=$this->sql($f);$this->assertStringContainsString('alrowad_uni_rust',$s);foreach(['DATABASE()','SIGNAL','DELIMITER','CREATE PROCEDURE'] as $bad)$this->assertStringNotContainsString($bad,$s);}}
 #[Test] public function phase_four_dependencies_conflicts_rbac_and_legacy_are_audited():void{$p=$this->sql('00_preflight.sql');foreach(['supplementary_exam_registration_events','student_course_results','grading_policies','target_shape_conflicts','COMPATIBLE','CONFLICT','LEGACY_PRESERVED','doctor_instructor','exam_officer'] as $x)$this->assertStringContainsString($x,$p);}
 #[Test] public function rollback_never_names_regular_mutation_or_phase_four_drop():void{$r=$this->sql('03_rollback.sql');foreach(['student_course_results','student_grade_components','supplementary_exam_registrations','supplementary_exam_periods'] as $regular)$this->assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`'.$regular,$r);}
}
