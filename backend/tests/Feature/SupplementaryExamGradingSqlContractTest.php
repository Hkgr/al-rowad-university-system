<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamGradingSqlContractTest extends TestCase
{
    private const FILES = ['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql'];

    private function sql(string $name): string
    {
        return file_get_contents(database_path('sql/supplementary-exam-grading/'.$name));
    }

    #[Test]
    public function package_has_exact_final_visible_operator_contracts(): void
    {
        foreach ([...self::FILES, 'README.md'] as $file) {
            $this->assertFileExists(database_path('sql/supplementary-exam-grading/'.$file));
        }

        $this->assertMatchesRegularExpression("/SELECT 'OVERALL' AS report_section, IF\(@preflight_ready,'READY','BLOCKED'\) AS result;\s*$/", $this->sql('00_preflight.sql'));
        $this->assertMatchesRegularExpression("/SELECT 'OVERALL' AS report_section, IF\(NOT @apply_success,'BLOCKED',IF\(@initial_absent=0 AND @after_owned_permissions=@before_owned_permissions,'ALREADY_APPLIED','APPLIED'\)\) AS result;\s*$/", $this->sql('01_apply.sql'));
        $this->assertMatchesRegularExpression("/SELECT 'OVERALL' AS report_section, IF\(@verify_pass,'PASS','FAIL'\) AS result;\s*$/", $this->sql('02_verify.sql'));
        $this->assertMatchesRegularExpression("/SELECT 'ROLLBACK_RESULT' AS report_section, IF\(@in_use>0,'BLOCKED_IN_USE',IF\(@blocked_adopted,'BLOCKED_ADOPTED',IF\(@can_rollback,'ROLLED_BACK','NOTHING_TO_DO'\)\)\) AS result;\s*$/", $this->sql('03_rollback.sql'));
        $this->assertStringNotContainsString("'ROLLED_BACK','APPLIED'", $this->sql('01_apply.sql'));
    }

    #[Test]
    public function dynamic_no_op_branches_emit_no_intermediate_operator_results(): void
    {
        foreach (['01_apply.sql', '03_rollback.sql'] as $file) {
            $sql = $this->sql($file);
            $this->assertStringContainsString('SET @phase5_noop := @phase5_noop', $sql);
            $this->assertStringNotContainsString("SELECT ''BLOCKED''", $sql);
            $this->assertStringNotContainsString("SELECT ''PRESERVED''", $sql);
            $this->assertStringNotContainsString("SELECT 'BLOCKED'", $sql);
            $this->assertStringNotContainsString("SELECT 'PRESERVED'", $sql);
        }
    }

    #[Test]
    public function rollback_counts_every_optional_workflow_table_and_preserves_adopted_objects(): void
    {
        $sql = $this->sql('03_rollback.sql');
        foreach ([
            'supplementary_exam_grader_assignments' => '@a_rows',
            'supplementary_exam_grade_results' => '@r_rows',
            'supplementary_exam_grade_submissions' => '@s_rows',
            'supplementary_exam_grade_events' => '@e_rows',
        ] as $table => $rowVariable) {
            $this->assertStringContainsString("SELECT COUNT(*) INTO {$rowVariable} FROM `alrowad_uni_rust`.`{$table}`", $sql);
        }
        $this->assertStringContainsString('SET @in_use := @a_rows + @r_rows + @s_rows + @e_rows', $sql);
        $this->assertStringContainsString('BLOCKED_IN_USE', $sql);
        $this->assertStringContainsString('BLOCKED_ADOPTED', $sql);
        $this->assertStringContainsString("table_comment='owned:supplementary-exam-grading-phase5'", $sql);
        $this->assertStringContainsString("description='owned:supplementary-exam-grading-phase5'", $sql);
        $this->assertStringContainsString('WHERE @can_rollback', $sql);
    }

    #[Test]
    public function every_target_is_semantically_classified_before_adoption(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $sql = $this->sql($file);
            foreach (['a', 'r', 's', 'e'] as $target) {
                $prefix = $file === '02_verify.sql' ? 'verify_' : '';
                $this->assertStringContainsString("@{$prefix}{$target}_compatible", $sql);
                $this->assertStringContainsString("@{$prefix}{$target}_classification", $sql);
            }
            foreach (['table_type=\'BASE TABLE\'', "engine='InnoDB'", 'column_type=', 'is_nullable=', 'column_default', 'extra=', 'GROUP_CONCAT(column_name ORDER BY seq_in_index', 'referenced_table_name', 'lc.column_type=rc.column_type', "'ABSENT'", "'COMPATIBLE'", "'CONFLICT'"] as $proof) {
                $this->assertStringContainsString($proof, $sql);
            }
        }
    }

    #[Test]
    public function apply_recomputes_dependencies_conflicts_and_reinspects_each_created_target(): void
    {
        $sql = $this->sql('01_apply.sql');
        foreach (['@phase14_tables_ready', '@phase14_columns_ready', '@phase14_keys_ready', '@regular_tables_ready', '@regular_columns_ready', '@dependency_types_ready', '@dependency_fk_signedness_ready', '@rbac_dependencies_ready', '@target_conflicts', '@permission_conflicts', '@forbidden_mappings'] as $guard) {
            $this->assertStringContainsString($guard, $sql);
        }
        foreach (['@post_a_compatible', '@post_r_compatible', '@post_s_compatible', '@post_e_compatible'] as $postcondition) {
            $this->assertStringContainsString($postcondition, $sql);
        }
        $this->assertStringContainsString("@apply_ready AND @a_classification='ABSENT'", $sql);
        $this->assertStringContainsString("@apply_ready AND @r_classification='ABSENT'", $sql);
        $this->assertStringContainsString("@apply_ready AND @s_classification='ABSENT'", $sql);
        $this->assertStringContainsString("@apply_ready AND @e_classification='ABSENT'", $sql);
    }

    #[Test]
    public function verify_repeats_complete_dependencies_targets_and_exact_rbac_matrix(): void
    {
        $sql = $this->sql('02_verify.sql');
        foreach (['@phase14_tables_ready', '@phase14_columns_ready', '@phase14_keys_ready', '@regular_tables_ready', '@regular_columns_ready', '@dependency_types_ready', '@dependency_fk_signedness_ready', '@verify_a_compatible', '@verify_r_compatible', '@verify_s_compatible', '@verify_e_compatible', '@permissions_ready', '@expected_mappings', '@forbidden_mappings'] as $condition) {
            $this->assertStringContainsString($condition, $sql);
        }
        $this->assertStringContainsString("COUNT(DISTINCT CONCAT(r.role_code,':',p.permission_code))=7", $sql);
    }

    #[Test]
    public function scripts_are_manual_fully_qualified_and_forbidden_construct_free(): void
    {
        foreach (self::FILES as $file) {
            $sql = $this->sql($file);
            $this->assertStringContainsString('alrowad_uni_rust', $sql);
            foreach (['DATABASE()', 'SIGNAL', 'DELIMITER', 'CREATE PROCEDURE'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $sql);
            }
        }
    }

    #[Test]
    public function phase_four_regular_grade_legacy_and_rbac_dependencies_are_audited(): void
    {
        $preflight = $this->sql('00_preflight.sql');
        foreach (['supplementary_exam_registration_events', 'student_course_results', 'grading_policies', 'faculty_members', 'doctor_instructor', 'exam_officer'] as $dependency) {
            $this->assertStringContainsString($dependency, $preflight);
        }
        $readme = $this->sql('README.md');
        $this->assertStringContainsString('legacy `supplementary_exam_results` table is preserved', $readme);
    }

    #[Test]
    public function rollback_never_drops_regular_or_phase_four_objects(): void
    {
        $rollback = $this->sql('03_rollback.sql');
        foreach (['student_course_results', 'student_grade_components', 'supplementary_exam_registrations', 'supplementary_exam_periods'] as $regular) {
            $this->assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`'.$regular, $rollback);
        }
    }
}
