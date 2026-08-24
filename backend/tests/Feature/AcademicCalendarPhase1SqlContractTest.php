<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AcademicCalendarPhase1SqlContractTest extends TestCase
{
    private const SQL_FILES = [
        '00_preflight.sql',
        '01_apply.sql',
        '02_verify.sql',
        '03_rollback.sql',
    ];

    private const EVENT_TYPE_CODES = [
        'admission_registration',
        'course_registration',
        'withdrawal',
        'study_period',
        'exam_preparation',
        'practical_exams',
        'theoretical_exams',
        'grade_appeals',
        'supplementary_exams',
        'university_break',
        'preparation_period',
        'holiday',
        'general_event',
    ];

    public function test_exact_manual_sql_package_exists(): void
    {
        foreach ([...self::SQL_FILES, 'README.md'] as $file) {
            self::assertFileExists($this->packagePath($file));
        }

        self::assertSame(
            [],
            glob($this->backendPath('database/migrations/*academic*calendar*')) ?: [],
        );
        self::assertSame(
            [],
            glob($this->backendPath('database/seeders/*Academic*Calendar*')) ?: [],
        );
    }

    public function test_scripts_are_phpmyadmin_safe_and_fully_qualified(): void
    {
        foreach (self::SQL_FILES as $file) {
            $sql = $this->sql($file);

            self::assertStringContainsString('alrowad_uni_rust', $sql);
            self::assertStringContainsString('information_schema', $sql);
            self::assertStringNotContainsString('DATABASE()', strtoupper($sql));
            self::assertStringNotContainsString('SIGNAL', strtoupper($sql));
            self::assertStringNotContainsString('DELIMITER', strtoupper($sql));
            self::assertStringNotContainsString('CREATE PROCEDURE', strtoupper($sql));
            self::assertStringNotContainsString('CREATE FUNCTION', strtoupper($sql));
            self::assertStringNotContainsString('int(11)', strtolower($sql));
            self::assertDoesNotMatchRegularExpression(
                "/column_type\\s*=\\s*'int\\(\\d+\\)'/i",
                $sql,
            );
        }

        foreach (['00_preflight.sql', '02_verify.sql'] as $readOnlyFile) {
            $sql = $this->sql($readOnlyFile);
            foreach (['INSERT INTO', 'UPDATE `', 'DELETE FROM', 'ALTER TABLE', 'CREATE TABLE', 'DROP TABLE'] as $mutation) {
                self::assertStringNotContainsString($mutation, strtoupper($sql));
            }
        }
    }

    public function test_academic_year_extension_is_additive_and_preserves_current_semantics(): void
    {
        $apply = $this->sql('01_apply.sql');

        self::assertStringContainsString('calendar_lifecycle_status', $apply);
        self::assertStringContainsString("WHEN ay.is_current = 1 THEN ''active''", $apply);
        self::assertStringContainsString("WHEN ay.end_date < current_year.current_start_date THEN ''closed''", $apply);
        self::assertStringContainsString("ELSE ''draft''", $apply);
        self::assertStringContainsString('ay.updated_at = ay.updated_at', $apply);
        self::assertStringContainsString('calendar_active_slot', $apply);
        self::assertStringContainsString('GENERATED ALWAYS AS', $apply);
        self::assertStringContainsString("is_generated = 'ALWAYS'", $this->sql('00_preflight.sql'));
        self::assertStringContainsString("is_generated = 'ALWAYS'", $this->sql('02_verify.sql'));
        self::assertStringContainsString('uq_ay_calendar_active_slot', $apply);
        self::assertStringNotContainsString('SET ay.is_current', $apply);
        self::assertStringNotContainsString('SET ay.is_active', $apply);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`academic_years`', $apply);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`semesters`', $apply);

        foreach (['first', 'second', 'summer'] as $semesterCode) {
            self::assertStringContainsString($semesterCode, $this->sql('00_preflight.sql'));
            self::assertStringContainsString($semesterCode, $this->sql('02_verify.sql'));
        }
    }

    public function test_four_narrow_tables_have_history_and_actor_contracts(): void
    {
        $apply = $this->sql('01_apply.sql');

        foreach ([
            'academic_calendar_event_types',
            'academic_calendar_events',
            'academic_calendar_event_versions',
            'academic_calendar_year_lifecycle_events',
        ] as $table) {
            self::assertStringContainsString("CREATE TABLE `alrowad_uni_rust`.`{$table}`", $apply);
        }

        foreach ([
            'created_by_user_id',
            'published_by_user_id',
            'cancelled_by_user_id',
            'actor_user_id',
        ] as $actorColumn) {
            self::assertStringContainsString($actorColumn, $apply);
        }

        self::assertStringContainsString('ON DELETE RESTRICT ON UPDATE RESTRICT', $apply);
        self::assertStringContainsString('chk_ace_cancellation', $apply);
        self::assertStringContainsString('chk_acyle_reason', $apply);
        self::assertStringContainsString('[academic-calendar-phase1]', $apply);
    }

    public function test_event_types_are_seeded_idempotently_by_code_without_fixed_ids(): void
    {
        $apply = $this->sql('01_apply.sql');
        $verify = $this->sql('02_verify.sql');

        foreach (self::EVENT_TYPE_CODES as $code) {
            self::assertStringContainsString($code, $apply);
            self::assertStringContainsString($code, $verify);
        }

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $apply);
        self::assertStringContainsString('uq_acet_code', $apply);
        self::assertStringContainsString(
            '(`event_type_code`, `name_ar`, `name_en`, `event_type_kind`, `default_is_enforcement`, `is_active`)',
            $apply,
        );
        self::assertStringNotContainsString(
            '(`academic_calendar_event_type_id`, `event_type_code`',
            $apply,
        );
    }

    public function test_published_revision_and_replacement_draft_can_coexist(): void
    {
        $apply = $this->sql('01_apply.sql');

        self::assertStringContainsString('version_number', $apply);
        self::assertStringContainsString('replaces_version_id', $apply);
        self::assertStringContainsString('change_reason', $apply);
        self::assertStringContainsString("publication_status` VARCHAR(16) NOT NULL DEFAULT ''draft''", $apply);
        self::assertStringContainsString(
            "CASE WHEN `publication_status` = ''published'' THEN `academic_calendar_event_id` ELSE NULL END",
            $apply,
        );
        self::assertStringContainsString('uq_acev_published_event_slot', $apply);
        self::assertStringContainsString('superseded_at', $apply);
        self::assertStringContainsString('chk_acev_publication', $apply);
        self::assertStringContainsString('starts_at` DATETIME', $apply);
        self::assertStringContainsString('ends_at` DATETIME', $apply);
        self::assertStringContainsString('is_enforcement` TINYINT(1) NOT NULL', $apply);
    }

    public function test_multiple_same_type_windows_and_overlaps_are_not_blocked(): void
    {
        $apply = $this->sql('01_apply.sql');
        $eventsDefinition = $this->between(
            $apply,
            'CREATE TABLE `alrowad_uni_rust`.`academic_calendar_events`',
            'PREPARE ac1_create_events',
        );

        self::assertStringNotContainsString(
            'UNIQUE KEY',
            $eventsDefinition,
            'Logical event context must not be unique by year, semester, or type.',
        );

        foreach (self::SQL_FILES as $file) {
            self::assertStringNotContainsString('CREATE TRIGGER', strtoupper($this->sql($file)));
        }

        self::assertStringContainsString('@ac1_forbidden_context_unique = 0', $this->sql('02_verify.sql'));
        self::assertStringContainsString('@ac1_calendar_triggers = 0', $this->sql('02_verify.sql'));
    }

    public function test_operator_reports_and_conservative_rollback_are_explicit(): void
    {
        $preflight = $this->sql('00_preflight.sql');
        $apply = $this->sql('01_apply.sql');
        $verify = $this->sql('02_verify.sql');
        $rollback = $this->sql('03_rollback.sql');

        self::assertStringContainsString("IF(@ac1_preflight_ready, 'READY', 'BLOCKED')", $preflight);
        foreach (['APPLIED', 'ALREADY_APPLIED', 'BLOCKED'] as $result) {
            self::assertStringContainsString("'{$result}'", $apply);
        }
        self::assertStringContainsString("IF(@ac1_verify_pass, 'PASS', 'FAIL')", $verify);
        foreach (['BLOCKED_IN_USE', 'BLOCKED_ADOPTED', 'ROLLED_BACK', 'NOTHING_TO_DO'] as $result) {
            self::assertStringContainsString("'{$result}'", $rollback);
        }

        self::assertStringContainsString('@ac1_event_rows > 0', $rollback);
        self::assertStringContainsString('@ac1_version_rows > 0', $rollback);
        self::assertStringContainsString('@ac1_lifecycle_event_rows > 0', $rollback);
        self::assertStringContainsString('@ac1_custom_type_rows > 0', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`academic_years`', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`semesters`', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`course_offerings`', $rollback);
    }

    public function test_no_application_api_rbac_ui_or_workflow_integration_is_added(): void
    {
        self::assertFileDoesNotExist($this->backendPath('app/Models/AcademicCalendarEvent.php'));
        self::assertFileDoesNotExist($this->backendPath('app/Services/AcademicCalendarService.php'));
        self::assertFileDoesNotExist($this->backendPath('app/Http/Controllers/Api/AcademicCalendarController.php'));
        self::assertDirectoryDoesNotExist($this->repoPath('frontend/src/features/academic-calendar'));

        $routes = file_get_contents($this->backendPath('routes/api.php'));
        self::assertStringNotContainsString("Route::apiResource('academic-calendar", $routes);

        $apply = $this->sql('01_apply.sql');
        foreach ([
            '`permissions`',
            '`role_permissions`',
            'student_grade_components',
            'grade_approvals',
            'supplementary_exam_periods',
            'supplementary_exam_offerings',
            'supplementary_exam_registrations',
            'supplementary_exam_results',
        ] as $outOfScopeObject) {
            self::assertStringNotContainsString($outOfScopeObject, $apply);
        }
    }

    private function packagePath(string $file): string
    {
        return $this->backendPath('database/sql/academic-calendar-phase1/'.$file);
    }

    private function backendPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }

    private function sql(string $file): string
    {
        return file_get_contents($this->packagePath($file));
    }

    private function between(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        $end = strpos($source, $endNeedle, $start === false ? 0 : $start);

        self::assertNotFalse($start, "Missing start marker: {$startNeedle}");
        self::assertNotFalse($end, "Missing end marker: {$endNeedle}");
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }
}
