<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $package = $backendRoot.'/database/sql/academic-calendar-schema-compatibility-repair';
    $layout = ['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'];

    foreach ($layout as $file) {
        if (! is_file($package.'/'.$file)) {
            $errors[] = 'Missing package file: '.$file;
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $preflight = file_get_contents($package.'/00_preflight.sql');
    $apply = file_get_contents($package.'/01_apply.sql');
    $verify = file_get_contents($package.'/02_verify.sql');
    $rollback = file_get_contents($package.'/03_rollback.sql');
    $readme = file_get_contents($package.'/README.md');
    $allSql = $preflight."\n".$apply."\n".$verify."\n".$rollback;

    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };

    $expect(str_contains($preflight, "'OVERALL'") && str_contains($preflight, "'READY'") && str_contains($preflight, "'BLOCKED'"), 'Preflight must visibly terminate READY or BLOCKED.');
    $expect(str_contains($verify, "'OVERALL'") && str_contains($verify, "'PASS'") && str_contains($verify, "'FAIL'"), 'Verify must visibly terminate PASS or FAIL.');
    $expect(str_contains($apply, "SELECT 'OVERALL' AS report_section") && str_contains($apply, "'APPLIED', 'BLOCKED'"), 'Apply must end with a plain visible OVERALL APPLIED or BLOCKED result.');
    $expect(str_contains($preflight, 'REPAIRABLE_SOURCE') && str_contains($preflight, 'ALREADY_COMPATIBLE') && str_contains($preflight, 'SAFE_PARTIAL') && str_contains($preflight, 'CONFLICTING'), 'Preflight must classify source, target, partial, and conflicting layouts.');
    $expect(str_contains($preflight, 'event_context_columns=0 AND version_context_columns=2') && str_contains($preflight, 'state_enum_columns=5') && str_contains($preflight, 'source_indexes=4') && str_contains($preflight, 'context_fk_source=2'), 'Preflight source fingerprint does not describe the known deployed layout.');
    $expect(str_contains($preflight, 'event_context_columns=2 AND version_context_columns=0') && str_contains($preflight, 'state_varchar_columns=5') && str_contains($preflight, 'known_check_count=12') && str_contains($preflight, 'target_indexes=10') && str_contains($preflight, 'context_fk_target=2'), 'Preflight target fingerprint does not describe the merged layout.');
    $expect(str_contains($preflight, 'event_rows=0 AND version_rows=0'), 'Preflight must require empty event and revision history.');
    $expect(str_contains($apply, '@acr_event_rows = 0 AND @acr_version_rows = 0'), 'Apply must independently require empty event and revision history.');
    $expect(str_contains($rollback, '@acr_event_rows=0 AND @acr_version_rows=0'), 'Rollback must fail closed after event or revision history exists.');

    $freshEventGuard = '(SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0';
    $freshVersionGuard = '(SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0';
    $destructiveRevisionContextOperations = [
        'DROP FOREIGN KEY `fk_acev_semester`',
        'DROP FOREIGN KEY `fk_acev_event_type`',
        'DROP INDEX `idx_acev_semester`',
        'DROP INDEX `idx_acev_event_type`',
        'DROP COLUMN `semester_id`',
        'DROP COLUMN `academic_calendar_event_type_id`',
    ];
    foreach ($destructiveRevisionContextOperations as $operation) {
        $operationPosition = strpos($apply, $operation);
        $guardPosition = $operationPosition === false
            ? false
            : strrpos(substr($apply, 0, $operationPosition), 'SET @acr_sql := IF(');
        $guardedBlock = $guardPosition === false || $operationPosition === false
            ? ''
            : substr($apply, $guardPosition, $operationPosition - $guardPosition);
        $expect(str_contains($guardedBlock, $freshEventGuard) && str_contains($guardedBlock, $freshVersionGuard), 'Destructive revision-context operation lacks fresh zero-row guards: '.$operation);
    }
    $expect(substr_count($apply, $freshEventGuard) >= 7 && substr_count($apply, $freshVersionGuard) >= 7, 'Apply must repeat live empty-table checks for every destructive step and the final result.');

    $expect(str_contains($apply, 'DROP COLUMN `semester_id`') && str_contains($apply, 'DROP COLUMN `academic_calendar_event_type_id`'), 'Apply must remove context columns from revisions.');
    $expect(str_contains($apply, 'ADD COLUMN `semester_id` INT NULL AFTER `academic_year_id`'), 'Apply must add nullable semester context to logical events.');
    $expect(str_contains($apply, 'ADD COLUMN `academic_calendar_event_type_id` INT NOT NULL AFTER `semester_id`'), 'Apply must add event-type context to logical events.');
    $expect(str_contains($apply, 'fk_ace_semester') && str_contains($apply, 'fk_ace_event_type'), 'Apply must restore logical-event context foreign keys.');
    $expect(str_contains($apply, 'idx_ace_year_semester') && str_contains($apply, 'idx_ace_event_type'), 'Apply must restore logical-event context indexes.');
    $targetIndexes = [
        'ADD KEY `idx_acet_kind_active` (`event_type_kind`, `is_active`)',
        'ADD KEY `idx_ace_year_semester` (`academic_year_id`, `semester_id`)',
        'ADD KEY `idx_ace_event_type` (`academic_calendar_event_type_id`)',
        'ADD KEY `idx_ace_cancelled_at` (`cancelled_at`)',
        'ADD KEY `idx_acev_event_status` (`academic_calendar_event_id`, `publication_status`)',
        'ADD KEY `idx_acev_publication_window` (`publication_status`, `starts_at`, `ends_at`)',
        'ADD KEY `idx_acev_replaces` (`replaces_version_id`)',
        'ADD KEY `idx_acyle_year_occurred` (`academic_year_id`, `occurred_at`)',
        'ADD KEY `idx_acyle_status_occurred` (`to_status`, `occurred_at`)',
        'ADD KEY `idx_acyle_actor` (`actor_user_id`)',
    ];
    foreach ($targetIndexes as $targetIndex) {
        $expect(str_contains($apply, $targetIndex), 'Apply cannot restore required target index: '.$targetIndex);
    }
    $expect(str_contains($apply, '@acr_post_target_indexes = 10'), 'Apply final result must validate all ten required target indexes.');
    $expect(str_contains($preflight, 'known_common_index_names=compatible_common_indexes') && str_contains($preflight, 'known_migration_index_names=compatible_migration_indexes'), 'SAFE_PARTIAL must reject known index names with incompatible definitions.');
    $expect(str_contains($apply, '@acr_known_common_index_names = @acr_compatible_common_indexes') && str_contains($apply, '@acr_known_migration_index_names = @acr_compatible_migration_indexes'), 'Apply must independently reject known index names with incompatible definitions.');
    $expect(str_contains($verify, 'AS context_ownership'), 'Verify must assert merged logical-event context ownership.');
    $expect(str_contains($verify, 'SELECT COUNT(*) = 42 FROM information_schema.columns') && str_contains($verify, 'SELECT COUNT(*) = 11 FROM information_schema.key_column_usage k') && str_contains($verify, 'SELECT COUNT(*) = 18 FROM information_schema.columns'), 'Verify must protect the exact merged column, FK, and signed-key counts.');
    $expect(str_contains($preflight, 'generated_slots=2 AND generated_unique_indexes=2') && str_contains($verify, 'generated_slots AND generated_unique_indexes'), 'Generated single-active and single-published uniqueness must remain intact.');

    foreach (['calendar_lifecycle_status', 'event_type_kind', 'publication_status', 'from_status', 'to_status'] as $stateColumn) {
        $expect(str_contains($allSql, $stateColumn), 'Missing state-column contract: '.$stateColumn);
    }
    foreach (['chk_ay_calendar_lifecycle_status', 'chk_acet_kind', 'chk_acet_flags', 'chk_ace_cancellation', 'chk_acev_version_number', 'chk_acev_window', 'chk_acev_enforcement', 'chk_acev_change_reason', 'chk_acev_publication', 'chk_acyle_from_status', 'chk_acyle_to_status', 'chk_acyle_reason'] as $check) {
        $expect(str_contains($apply, $check) && str_contains($verify, $check), 'Missing repaired check contract: '.$check);
    }

    $codes = ['admission_registration', 'course_registration', 'withdrawal', 'study_period', 'exam_preparation', 'practical_exams', 'theoretical_exams', 'grade_appeals', 'supplementary_exams', 'university_break', 'preparation_period', 'holiday', 'general_event'];
    foreach ($codes as $code) {
        $expect(str_contains($preflight, $code), 'Preflight does not preserve seeded code: '.$code);
    }
    $expect(str_contains($preflight, 'academic_calendar.manage') && str_contains($verify, 'academic_calendar.manage'), 'Permission preservation is not represented.');
    $expect(str_contains($preflight, 'vice_president_scientific') && str_contains($verify, 'vice_president_scientific'), 'Scientific VP mapping preservation is not represented.');

    foreach ([$preflight, $verify] as $readOnlySql) {
        $expect(! preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE|TRUNCATE)\b/im', $readOnlySql), 'A read-only script contains a mutating statement.');
        $expect(! preg_match('/\b(PREPARE|EXECUTE)\b/i', $readOnlySql), 'A read-only operator report contains dynamic execution.');
        $expect(! preg_match('/\bINTO\s+@/i', $readOnlySql), 'A read-only operator report contains SELECT INTO user-variable reporting.');
        $expect(! preg_match('/^\s*SET\s+@/im', $readOnlySql), 'A read-only operator report must be a direct CTE-backed SELECT.');
    }
    $expect(! preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/im', $apply), 'Apply must contain no data mutation.');
    $expect(! str_contains(strtoupper($apply), 'CREATE TABLE'), 'Apply must not create a replacement calendar table.');
    $expect(! str_contains(strtoupper($rollback), 'DROP TABLE'), 'Rollback must never drop a calendar table.');
    foreach (['DATABASE()', 'DELIMITER', 'SIGNAL', 'CREATE PROCEDURE', 'CREATE FUNCTION'] as $forbidden) {
        $expect(! str_contains(strtoupper($allSql), $forbidden), 'Forbidden SQL construct found: '.$forbidden);
    }
    foreach (['01_apply.sql' => $apply, '03_rollback.sql' => $rollback] as $file => $sql) {
        preg_match_all('/(?<!DEALLOCATE )\bPREPARE\s+[a-z0-9_]+\s+FROM\b/i', $sql, $prepares);
        preg_match_all('/\bEXECUTE\s+[a-z0-9_]+\b/i', $sql, $executes);
        preg_match_all('/\bDEALLOCATE\s+PREPARE\s+[a-z0-9_]+\b/i', $sql, $deallocates);
        $expect(count($prepares[0]) === count($executes[0]) && count($prepares[0]) === count($deallocates[0]), $file.' has an unbalanced prepared statement lifecycle.');
    }
    $expect(substr_count($allSql, '`alrowad_uni_rust`') > 20, 'SQL must use the explicit production database.');
    $expect(! str_contains(strtoupper($allSql), 'INSERT INTO `ALROWAD_UNI_RUST`'), 'Repair package must not seed production data.');
    $expect(str_contains($readme, 'academic-calendar-phase1/02_verify.sql') && str_contains($readme, 'Phase 3'), 'README must require both verification gates before Phase 3.');
    $maintenancePosition = strpos($readme, 'Put the Laravel application in maintenance mode');
    $backupPosition = strpos($readme, 'Take and retain a database backup');
    $preflightPosition = strpos($readme, 'Run `00_preflight.sql`');
    $applyPosition = strpos($readme, 'Run `01_apply.sql`');
    $repairVerifyPosition = strpos($readme, 'Run `02_verify.sql`');
    $phaseOneVerifyPosition = strpos($readme, 'Re-run `../academic-calendar-phase1/02_verify.sql`');
    $exitMaintenancePosition = strpos($readme, 'Exit maintenance mode only after both verification scripts return `PASS`');
    $operatorOrder = [$maintenancePosition, $backupPosition, $preflightPosition, $applyPosition, $repairVerifyPosition, $phaseOneVerifyPosition, $exitMaintenancePosition];
    $sortedOperatorOrder = $operatorOrder;
    sort($sortedOperatorOrder);
    $expect(! in_array(false, $operatorOrder, true) && $operatorOrder === $sortedOperatorOrder, 'README must enforce maintenance, backup, preflight, apply, both verifies, then maintenance exit in order.');
    $expect(str_contains($readme, 'Do not run this repair while the application') && str_contains($readme, 'MariaDB DDL commits') && str_contains($readme, 'hard operational gate'), 'README must explain the maintenance-mode concurrency gate.');

    $immutablePackages = [
        'database/sql/academic-calendar-phase1/00_preflight.sql' => 'b3b40213bf336ba3221d9109838d20b53739cc2ef5ec80cbe3e118412cc0d342',
        'database/sql/academic-calendar-phase1/01_apply.sql' => 'b12739e01aa489d3c4277cb7d5662109aa0c7bbe7b1ff2f23473302425bbb339',
        'database/sql/academic-calendar-phase1/02_verify.sql' => '35706b06b5c818d00f0bded1477d5ce2a9ebb74752ebcb929d148056810035cb',
        'database/sql/academic-calendar-phase1/03_rollback.sql' => '83371ec3b6a2a9ca8baba385f991036c3478c4aaeed9cbf576ed6d841a7bf7b8',
        'database/sql/academic-calendar-phase1/README.md' => '719bbacb596fab3113aa0f9f3fe522370efa1e68bc2123744337a32239c1499c',
    ];
    foreach ($immutablePackages as $file => $sha256) {
        $expect(hash_file('sha256', $backendRoot.'/'.$file) === $sha256, 'Previously merged SQL package changed: '.$file);
    }

    $phaseOneApply = file_get_contents($backendRoot.'/database/sql/academic-calendar-phase1/01_apply.sql');
    $mergedEvents = explode("CREATE TABLE `alrowad_uni_rust`.`academic_calendar_event_versions`", explode("CREATE TABLE `alrowad_uni_rust`.`academic_calendar_events`", $phaseOneApply, 2)[1] ?? '', 2)[0];
    $mergedVersions = explode("CREATE TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events`", explode("CREATE TABLE `alrowad_uni_rust`.`academic_calendar_event_versions`", $phaseOneApply, 2)[1] ?? '', 2)[0];
    $expect(str_contains($mergedEvents, '`semester_id` INT DEFAULT NULL') && str_contains($mergedEvents, '`academic_calendar_event_type_id` INT NOT NULL'), 'Merged Phase 1 must keep semester and type on logical events.');
    $expect(! str_contains($mergedVersions, '`semester_id`') && ! str_contains($mergedVersions, '`academic_calendar_event_type_id`'), 'Merged Phase 1 revisions must not own semester or type context.');
    foreach (['idx_ace_year_semester', 'idx_ace_event_type', 'idx_acev_event_status', 'idx_acev_publication_window', 'idx_acyle_status_occurred', 'fk_ace_semester', 'fk_ace_event_type', 'fk_acev_event', 'fk_acyle_year', '[academic-calendar-phase1] logical university calendar events', '[academic-calendar-phase1] immutable event content revisions'] as $mergedToken) {
        $expect(str_contains($phaseOneApply, $mergedToken) && str_contains($apply.$verify, $mergedToken), 'Repair target drifted from merged Phase 1 token: '.$mergedToken);
    }

    $rbacPackage = $backendRoot.'/database/sql/academic-calendar-phase2-rbac';
    $rbacPreflight = file_get_contents($rbacPackage.'/00_preflight.sql');
    $rbacApply = file_get_contents($rbacPackage.'/01_apply.sql');
    $rbacVerify = file_get_contents($rbacPackage.'/02_verify.sql');
    $rbacRollback = file_get_contents($rbacPackage.'/03_rollback.sql');
    foreach ([$rbacPreflight, $rbacVerify] as $readOnlySql) {
        $expect(! preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE|TRUNCATE)\b/im', $readOnlySql), 'A Phase 2 RBAC read-only report contains a mutation.');
        $expect(! preg_match('/\b(PREPARE|EXECUTE)\b/i', $readOnlySql) && ! preg_match('/\bINTO\s+@/i', $readOnlySql) && ! preg_match('/^\s*SET\s+@/im', $readOnlySql), 'A Phase 2 RBAC read-only report is not phpMyAdmin-visible plain SELECT.');
    }
    $marker = '[academic-calendar-phase2-rbac]';
    $expect(str_contains($rbacApply, $marker) && str_contains($rbacRollback, $marker), 'Phase 2 RBAC ownership marker must control creation and rollback.');
    $mappingInsert = explode('COMMIT;', explode('INSERT INTO `alrowad_uni_rust`.`role_permissions`', $rbacApply, 2)[1] ?? '', 2)[0];
    $expect(str_contains($mappingInsert, "p.description LIKE '%[academic-calendar-phase2-rbac]%'"), 'Apply may create a mapping only for a package-owned permission.');
    $expect(str_contains($rbacPreflight, 'permission_rows=0 OR owned_permission=1 OR scientific_mapping=1'), 'Preflight must block an externally owned permission without the Scientific VP mapping.');
    $expect(str_contains($rbacPreflight, 'EXTERNAL_PRESERVED') && str_contains($rbacPreflight, 'EXTERNAL_MAPPING_MISSING_BLOCKED'), 'Preflight must distinguish preserved and blocked external ownership states.');
    $expect(substr_count($rbacRollback, "description LIKE '%[academic-calendar-phase2-rbac]%'") >= 3, 'Rollback must remove only marker-owned artifacts.');
    $expect(str_contains($rbacApply, "SELECT 'OVERALL' AS report_section") && str_contains($rbacApply, "'APPLIED', 'BLOCKED'"), 'Phase 2 apply must visibly terminate OVERALL APPLIED or BLOCKED.');

    $calendarMigrations = glob($backendRoot.'/database/migrations/*academic*calendar*') ?: [];
    $expect($calendarMigrations === [], 'A calendar migration exists outside the manual repair package.');
    $expect(! is_file($backendRoot.'/app/Services/AcademicCalendarPolicyService.php'), 'Phase 3 policy service must remain absent from the repair PR.');

    foreach (['RegistrationService', 'RegistrationWithdrawalService', 'GradeService', 'GradePartWorkflowService', 'SupplementaryExam'] as $workflow) {
        $expect(! str_contains($allSql.$readme, $workflow), 'Repair package must not integrate operational workflow: '.$workflow);
    }

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, $error.PHP_EOL);
        }
        exit(1);
    }

    fwrite(STDOUT, "Academic Calendar schema compatibility repair contract passed.\n");
}

return $contract;
