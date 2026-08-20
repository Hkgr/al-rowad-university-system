<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SupplementaryExamOfferingSqlContractTest extends TestCase
{
    public function test_supp_offer_sql_01_no_signal(): void
    {
        foreach ($this->sqlFiles() as $source) {
            self::assertDoesNotMatchRegularExpression('/\bSIGNAL\b/', $this->withoutComments($source));
        }
    }

    public function test_supp_offer_sql_02_no_delimiter_or_procedures(): void
    {
        foreach ($this->sqlFiles() as $source) {
            $body = $this->withoutComments($source);
            self::assertDoesNotMatchRegularExpression('/\bDELIMITER\b/', $body);
            self::assertDoesNotMatchRegularExpression('/\bCREATE\s+PROCEDURE\b/i', $body);
            self::assertDoesNotMatchRegularExpression('/\bCREATE\s+FUNCTION\b/i', $body);
        }
    }

    public function test_supp_offer_sql_03_no_database_function(): void
    {
        foreach ($this->sqlFiles() as $source) {
            self::assertDoesNotMatchRegularExpression('/\bDATABASE\s*\(/i', $this->withoutComments($source));
        }
    }

    public function test_supp_offer_sql_04_application_objects_fully_qualified(): void
    {
        foreach ($this->sqlFiles() as $source) {
            self::assertStringContainsString('`alrowad_uni_rust`.`', $source);
            self::assertStringContainsString("table_schema = 'alrowad_uni_rust'", $source);
        }
        $apply = $this->sql('01_apply.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`supplementary_exam_offerings`', $apply);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`supplementary_exam_offering_sources`', $apply);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`supplementary_exam_offering_events`', $apply);
    }

    public function test_supp_offer_sql_05_phase1_missing_blocks(): void
    {
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        self::assertStringContainsString("PHASE1_NOT_DEPLOYED", $preflight);
        self::assertStringContainsString("WHEN @phase1_ready = 0 THEN 'PHASE1_NOT_DEPLOYED'", $preflight);
        self::assertStringContainsString('@phase1_ready = 1', $apply);
        self::assertStringContainsString("AND @offerings_state = 'ABSENT'", $apply);
        self::assertStringContainsString('@apply_ready = 1 AND @offerings_state', $apply);
    }

    public function test_supp_offer_sql_06_equivalent_unique_index_names_accepted(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $source = $this->sql($file);
            self::assertStringContainsString("HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'supplementary_exam_period_id,academic_program_id,course_id'", $source);
            self::assertStringContainsString("HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'supplementary_exam_offering_id,course_offering_id'", $source);
            self::assertStringContainsString("HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'academic_year_id,semester_id'", $source);
        }
    }

    public function test_supp_offer_sql_07_incompatible_preexisting_is_blocked(): void
    {
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        self::assertStringContainsString("ELSE 'CONFLICT'", $preflight);
        self::assertStringContainsString("TARGET_SCHEMA_CONFLICT", $preflight);
        self::assertStringContainsString('@target_schema_safe = 1', $preflight);
        self::assertStringContainsString("AND @offerings_state = 'ABSENT'", $apply);
        self::assertStringNotContainsString('ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_offerings` ADD', $apply);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`supplementary_exam_offerings`', $apply);
    }

    public function test_supp_offer_sql_08_fk_signedness_matches_current_schema(): void
    {
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        self::assertStringContainsString("NOT LIKE '%unsigned%'", $preflight);
        self::assertStringContainsString('users', $preflight);
        self::assertStringContainsString('course_id', $preflight);
        self::assertStringContainsString('academic_program_id', $preflight);
        self::assertStringContainsString('course_offering_id', $preflight);
        self::assertStringContainsString('supplementary_exam_period_id', $preflight);
        self::assertStringContainsString('`opened_by_user_id` INT NOT NULL', $apply);
        self::assertStringContainsString('`course_offering_id` INT NOT NULL', $apply);
        self::assertStringNotContainsString('INT UNSIGNED', strtoupper($apply));
        self::assertStringNotContainsString('BIGINT', strtoupper($this->withoutComments($apply)));
    }

    public function test_supp_offer_sql_09_rerun_after_apply_is_idempotent(): void
    {
        $apply = $this->sql('01_apply.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $apply);
        self::assertStringContainsString("AND @offerings_state = 'ABSENT'", $apply);
        self::assertStringContainsString("AND @sources_state = 'ABSENT'", $apply);
        self::assertStringContainsString("AND @events_state = 'ABSENT'", $apply);
        self::assertStringContainsString("AND @view_perm_state = 'ABSENT'", $apply);
        self::assertStringContainsString('AND NOT EXISTS', $apply);
        self::assertStringContainsString("'APPLIED'", $apply);
    }

    public function test_supp_offer_sql_10_partial_compatible_is_resumable(): void
    {
        $apply = $this->sql('01_apply.sql');
        self::assertStringContainsString("AND @sources_state = 'ABSENT' AND @sources_any = 0 AND @offerings_exist = 1", $apply);
        self::assertStringContainsString("AND @events_state = 'ABSENT' AND @events_any = 0 AND @offerings_exist = 1", $apply);
        self::assertStringContainsString("'COMPATIBLE'", $apply);
    }

    public function test_supp_offer_sql_11_rollback_keeps_adopted_objects(): void
    {
        $rollback = $this->sql('03_rollback.sql');
        self::assertStringContainsString('ADOPTED_DO_NOT_DROP', $rollback);
        self::assertStringContainsString("LIKE '%[phase2-supplementary-exam-offerings]%'", $rollback);
        self::assertStringContainsString('AND @offerings_owned = 1', $rollback);
        self::assertStringContainsString('AND @sources_owned = 1', $rollback);
        self::assertStringContainsString('AND @events_owned = 1', $rollback);
        self::assertStringContainsString('AND p.description LIKE \'%[phase2-supplementary-exam-offerings]%\'', $rollback);
    }

    public function test_supp_offer_sql_12_rollback_blocks_when_workflow_data_exists(): void
    {
        $rollback = $this->sql('03_rollback.sql');
        self::assertStringContainsString('BLOCKED_IN_USE', $rollback);
        self::assertStringContainsString('@offering_rows > 0 OR @source_rows > 0 OR @event_rows > 0', $rollback);
        self::assertStringContainsString('@rollback_status = \'READY\' AND @offerings_exist = 1 AND @offerings_owned = 1 AND @offering_rows = 0', $rollback);
    }

    public function test_supp_offer_sql_13_final_visible_select_exists(): void
    {
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        $verify = $this->sql('02_verify.sql');
        $rollback = $this->sql('03_rollback.sql');

        self::assertStringEndsWith(
            "SELECT\n    'OVERALL' AS report_section,\n    @overall AS result,\n    @blocker_code AS blocker_code,\n    @phase1_ready AS phase1_ready,\n    @semester_policy_ready AS semester_policy_ready,\n    @target_schema_safe AS target_schema_safe,\n    @rbac_safe AS rbac_safe;\n",
            $preflight
        );
        self::assertStringContainsString("'APPLY_RESULT' AS report_section", $apply);
        self::assertStringContainsString('@apply_status AS result', $apply);
        self::assertStringContainsString("'OVERALL' AS report_section", $verify);
        self::assertStringContainsString('@phase1_ready AS phase1_ready', $verify);
        self::assertStringContainsString('@theory_hours_ok AS theory_hours_ok', $verify);
        self::assertStringContainsString('@offerings_contract_ok AS offerings_contract_ok', $verify);
        self::assertStringContainsString("'ROLLBACK_RESULT' AS report_section", $rollback);
        self::assertStringContainsString('@rollback_status AS result', $rollback);
    }

    public function test_supp_offer_sql_14_no_destructive_sql_on_academic_tables(): void
    {
        $apply = $this->withoutComments($this->sql('01_apply.sql'));
        $rollback = $this->withoutComments($this->sql('03_rollback.sql'));
        foreach ([$apply, $rollback] as $source) {
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP)\b[^;]*`course_offerings`/i', $source);
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP)\b[^;]*`student_course_registrations`/i', $source);
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP)\b[^;]*`supplementary_exam_results`/i', $source);
            self::assertDoesNotMatchRegularExpression('/DROP TABLE[^;]*`supplementary_exam_periods`/i', $source);
            self::assertDoesNotMatchRegularExpression('/DROP TABLE[^;]*`supplementary_exam_period_events`/i', $source);
        }
    }

    public function test_supp_offer_sql_15_verify_phase1_is_not_weaker_than_preflight(): void
    {
        $markers = [
            '@phase1_cols_ok',
            '@p1_fk_opened_by_ok',
            '@p1_events_engine_ok',
            '@p1_events_pk_ok',
            '@p1_events_pk_ai_ok',
            '@p1_events_types_ok',
            '@p1_events_fk_period_ok',
            '@p1_events_fk_actor_ok',
            '@p1_events_idx_period_ok',
            '@p1_events_idx_actor_ok',
            '@p1_events_idx_lookup_ok',
            "AND @p1_fk_opened_by_ok = 1",
            "AND @p1_events_types_ok = 1",
            "AND @p1_events_fk_period_ok = 1",
            "AND @p1_events_idx_lookup_ok = 1",
            "p.permission_code = 'supplementary_exams.periods.view' AND p.is_active = 1 AND sm.module_code = 'exams'",
            "p.permission_code = 'supplementary_exams.periods.decide' AND p.is_active = 1 AND sm.module_code = 'exams'",
            "HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') LIKE 'event_type,to_status%'",
        ];
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        $verify = $this->sql('02_verify.sql');
        foreach ([$preflight, $apply, $verify] as $source) {
            foreach ($markers as $marker) {
                self::assertStringContainsString($marker, $source);
            }
        }

        $start = 'SET @periods_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'alrowad_uni_rust\' AND table_name = \'supplementary_exam_periods\' AND table_type = \'BASE TABLE\'), 0);';
        $end = 'SET @phase1_ready := IF(';
        $extract = static function (string $source) use ($start, $end): string {
            $from = strpos($source, $start);
            $to = strpos($source, $end, $from);
            self::assertNotFalse($from);
            self::assertNotFalse($to);

            return substr($source, $from, $to - $from);
        };
        $preflightBlock = $extract($preflight);
        self::assertSame($preflightBlock, $extract($apply));
        self::assertSame($preflightBlock, $extract($verify));
    }

    public function test_supp_offer_sql_16_phase1_event_defect_blocks(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $source = $this->sql($file);
            self::assertStringContainsString('@p1_events_types_ok = 1', $source);
            self::assertStringContainsString('@p1_events_fk_period_ok = 1', $source);
            self::assertStringContainsString('@p1_events_fk_actor_ok = 1', $source);
            self::assertStringContainsString('@p1_events_idx_period_ok = 1', $source);
            self::assertStringContainsString('@p1_events_idx_actor_ok = 1', $source);
            self::assertStringContainsString('@p1_events_idx_lookup_ok = 1', $source);
            self::assertStringContainsString('@p1_events_engine_ok = 1', $source);
            self::assertStringContainsString("LOWER(c.column_type) LIKE '%unsigned%'", $source);
        }
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        $verify = $this->sql('02_verify.sql');
        self::assertStringContainsString("WHEN @phase1_ready = 0 THEN 'PHASE1_NOT_DEPLOYED'", $preflight);
        self::assertStringContainsString('@apply_ready = 1 AND @offerings_state', $apply);
        self::assertStringContainsString('@phase1_ready = 1', $verify);
        self::assertStringContainsString("'FAIL'", $verify);
    }

    public function test_supp_offer_sql_17_theoretical_hours_required(): void
    {
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        foreach ([$preflight, $apply] as $source) {
            self::assertStringContainsString("UNION ALL SELECT 'courses', 'theoretical_hours'", $source);
            self::assertStringContainsString('@theory_hours_type_ok = 1', $source);
        }
        self::assertStringContainsString('theoretical_hours', $this->sql('02_verify.sql'));
        self::assertStringContainsString('@theory_hours_ok = 1', $this->sql('02_verify.sql'));
        self::assertStringContainsString("WHEN @db_ready = 0 OR @structure_ok = 0 OR @pk_signed = 0 THEN 'REQUIRED_STRUCTURE_MISSING'", $preflight);
    }

    public function test_pack_layout_and_preflight_read_only(): void
    {
        $dir = dirname(__DIR__, 2).'/database/sql/supplementary-exam-offerings';
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists($dir.'/'.$file);
        }
        $preflight = $this->withoutComments($this->sql('00_preflight.sql'));
        self::assertDoesNotMatchRegularExpression('/\bINSERT\b/i', $preflight);
        self::assertDoesNotMatchRegularExpression('/\bUPDATE\b/i', $preflight);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\b/i', $preflight);
        self::assertDoesNotMatchRegularExpression('/\bALTER\b/i', $preflight);
        self::assertDoesNotMatchRegularExpression('/\bDROP\b/i', $preflight);
        self::assertDoesNotMatchRegularExpression('/\bCREATE\b/i', $preflight);
        self::assertStringContainsString('semester_order = 1', $this->sql('00_preflight.sql'));
        self::assertStringContainsString("status_code = 'registered'", $this->sql('00_preflight.sql'));
        self::assertStringContainsString("status_code = 'completed'", $this->sql('00_preflight.sql'));
        self::assertStringContainsString('[phase2-supplementary-exam-offerings]', $this->sql('01_apply.sql'));
        self::assertStringContainsString("r.role_code = 'dean'", $this->sql('01_apply.sql'));
        self::assertStringNotContainsString("r.role_code = 'vice_president_scientific'\n  AND r.is_active = 1\n  AND p.permission_code = 'supplementary_exams.offerings.manage'", $this->sql('01_apply.sql'));
    }

    /**
     * @return list<string>
     */
    private function sqlFiles(): array
    {
        return [
            $this->sql('00_preflight.sql'),
            $this->sql('01_apply.sql'),
            $this->sql('02_verify.sql'),
            $this->sql('03_rollback.sql'),
        ];
    }

    private function sql(string $file): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/database/sql/supplementary-exam-offerings/'.$file);
    }

    private function withoutComments(string $source): string
    {
        $source = preg_replace('/--.*$/m', '', $source);

        return preg_replace('/\/\*.*?\*\//s', '', $source);
    }
}
