<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamMaterializationSqlContractTest extends TestCase
{
    private const FILES = ['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql'];

    private function path(string $file): string
    {
        return database_path('sql/supplementary-exam-materialization/'.$file);
    }

    private function sql(string $file): string
    {
        return file_get_contents($this->path($file));
    }

    #[Test]
    public function exact_manual_package_exists_without_migrations_or_seeders(): void
    {
        foreach ([...self::FILES, 'README.md'] as $file) {
            $this->assertFileExists($this->path($file));
        }

        $migrationNames = glob(database_path('migrations/*supplementary*materializ*')) ?: [];
        $seederNames = glob(database_path('seeders/*Supplementary*Materializ*')) ?: [];
        $this->assertSame([], $migrationNames);
        $this->assertSame([], $seederNames);
    }

    #[Test]
    public function scripts_are_fully_qualified_phpmyadmin_safe_and_semantic_about_integer_types(): void
    {
        foreach (self::FILES as $file) {
            $sql = $this->sql($file);
            $this->assertStringContainsString('alrowad_uni_rust', $sql);
            foreach (['DATABASE()', 'SIGNAL', 'DELIMITER', 'CREATE PROCEDURE', 'CREATE FUNCTION'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtoupper($sql));
            }
            $this->assertDoesNotMatchRegularExpression("/column_type\s*=\s*'int\(\d+\)'/i", $sql);
            $this->assertStringNotContainsString('int(11)', strtolower($sql));
        }

        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $sql = $this->sql($file);
            $this->assertStringContainsString("data_type = 'int'", $sql);
            $this->assertStringContainsString("column_type NOT LIKE '%unsigned%'", $sql);
        }
    }

    #[Test]
    public function targets_are_classified_and_required_indexes_foreign_keys_and_ownership_are_verified(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $sql = $this->sql($file);
            foreach (['ABSENT', 'COMPATIBLE', 'CONFLICT'] as $classification) {
                $this->assertStringContainsString($classification, $sql);
            }
            foreach ([
                'supplementary_exam_materializations',
                'supplementary_exam_materialization_events',
                'GROUP_CONCAT(column_name ORDER BY seq_in_index',
                "index_name = 'PRIMARY'",
                'information_schema.key_column_usage',
                'information_schema.check_constraints',
            ] as $proof) {
                $this->assertStringContainsString($proof, $sql);
            }
        }

        $verify = $this->sql('02_verify.sql');
        $this->assertStringContainsString('@verify_fk_types', $verify);
        $this->assertStringContainsString('Extra harmless FK-created indexes do not fail it', $verify);
        $this->assertStringContainsString('owned:supplementary-exam-materialization-phase6', $verify);
    }

    #[Test]
    public function dependency_and_unique_index_guards_use_the_exact_phase_six_contract(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql'] as $file) {
            $sql = $this->sql($file);
            $this->assertMatchesRegularExpression(
                '/SET @canonical_result_ready := \(\s*SELECT COUNT\(\*\) = 11/s',
                $sql,
            );
            $this->assertMatchesRegularExpression(
                '/SET @signed_parent_ids_ready := \(\s*SELECT COUNT\(\*\) = 13/s',
                $sql,
            );
        }

        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $sql = $this->sql($file);
            $this->assertStringContainsString('supplementary_exam_grade_event_id', $sql);
            $this->assertStringContainsString('before_theoretical_components_snapshot', $sql);
            $this->assertStringContainsString('after_theoretical_components_snapshot', $sql);
            $this->assertStringContainsString('OPTIONAL_RESULT_ANNOUNCED_AT', $sql);
            $this->assertStringContainsString('ABSENT_OPTIONAL', $sql);
            $this->assertStringContainsString('PRESENT_COMPATIBLE', $sql);
            $this->assertStringContainsString('datetime_precision = 0', $sql);
            $this->assertStringContainsString("LOWER(COALESCE(extra, '')) NOT LIKE '%on update%'", $sql);
            $this->assertStringContainsString('non_unique = 0 AND index_columns NOT IN', $sql);
            $this->assertMatchesRegularExpression(
                '/SET @(?:verify_)?mat_primary(?:_ready)? := \(\s*SELECT COUNT\(\*\) = 1/s',
                $sql,
            );
            $this->assertMatchesRegularExpression(
                '/SET @(?:verify_)?mat_json(?:_ready)? := \(\s*SELECT COUNT\(\*\) = 3/s',
                $sql,
            );
        }
    }

    #[Test]
    public function nullable_null_defaults_are_normalized_for_mariadb_metadata(): void
    {
        $normalization = <<<'REGEX'
/\(\s*column_default IS NULL\s*OR UPPER\(\s*TRIM\(\s*BOTH ''''\s*FROM CAST\(column_default AS CHAR\)\s*\)\s*\) = 'NULL'\s*\)/
REGEX;

        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $sql = $this->sql($file);

            $this->assertSame(2, preg_match_all($normalization, $sql));
            $this->assertDoesNotMatchRegularExpression(
                "/is_nullable = 'YES'\s+AND\s+column_default IS NULL/",
                $sql,
            );
            $this->assertMatchesRegularExpression(
                "/is_nullable = 'NO' AND column_default IS NULL/",
                $sql,
            );
            foreach ([
                'before_registration_result_status_id',
                'before_calculated_by_user_id',
                'before_calculated_at',
                'before_result_announced_at',
                'after_result_announced_at',
            ] as $nullableColumn) {
                $this->assertStringContainsString($nullableColumn, $sql);
            }
        }
    }

    #[Test]
    public function operator_outputs_have_the_required_exact_terminal_values(): void
    {
        $this->assertStringContainsString("'OVERALL', IF(@preflight_ready, 'READY', 'BLOCKED')", $this->sql('00_preflight.sql'));
        foreach (['APPLIED', 'ALREADY_APPLIED', 'BLOCKED'] as $result) {
            $this->assertStringContainsString("'{$result}'", $this->sql('01_apply.sql'));
        }
        $this->assertStringContainsString("'OVERALL', IF(@verify_pass, 'PASS', 'FAIL')", $this->sql('02_verify.sql'));
        foreach (['BLOCKED_IN_USE', 'BLOCKED_ADOPTED', 'ROLLED_BACK', 'NOTHING_TO_DO'] as $result) {
            $this->assertStringContainsString("'{$result}'", $this->sql('03_rollback.sql'));
        }
    }

    #[Test]
    public function permission_uniqueness_and_rollback_safety_are_explicit(): void
    {
        $apply = $this->sql('01_apply.sql');
        foreach ([
            'supplementary_exams.results.materialize',
            "role_code = ''exam_officer''",
            'sem6_registration_uq',
            'sem6_grade_result_uq',
            'sem6_grade_event_uq',
            'sem6_target_registration_uq',
            'sem6_target_result_uq',
            'sem6_source_version_uq',
        ] as $proof) {
            $this->assertStringContainsString($proof, $apply);
        }

        $rollback = $this->sql('03_rollback.sql');
        $this->assertStringContainsString('SELECT COUNT(*) INTO @mat_rows', $rollback);
        $this->assertStringContainsString('SELECT COUNT(*) INTO @event_rows', $rollback);
        $this->assertStringContainsString('SELECT COUNT(*) INTO @terminal_events', $rollback);
        $this->assertStringContainsString("status = ''results_materialized''", $rollback);
        $this->assertStringContainsString('BLOCKED_IN_USE', $rollback);
        foreach (['student_course_results', 'student_grade_components', 'student_course_registrations'] as $canonicalTable) {
            $this->assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`'.$canonicalTable.'`', $rollback);
            $this->assertStringNotContainsString('UPDATE `alrowad_uni_rust`.`'.$canonicalTable.'`', $rollback);
        }
    }
}
