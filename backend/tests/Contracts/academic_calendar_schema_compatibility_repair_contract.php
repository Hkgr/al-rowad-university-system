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

    $expect(substr_count($preflight, "'OVERALL'") >= 1 && str_contains($preflight, "'READY', 'BLOCKED'"), 'Preflight must visibly terminate READY or BLOCKED.');
    $expect(str_contains($verify, "'OVERALL'") && str_contains($verify, "'PASS','FAIL'"), 'Verify must visibly terminate PASS or FAIL.');
    $expect(str_contains($preflight, 'REPAIRABLE_SOURCE') && str_contains($preflight, 'ALREADY_COMPATIBLE') && str_contains($preflight, 'SAFE_PARTIAL') && str_contains($preflight, 'CONFLICTING'), 'Preflight must classify source, target, partial, and conflicting layouts.');
    $expect(str_contains($preflight, '@acr_event_context_columns = 0 AND @acr_version_context_columns = 2') && str_contains($preflight, '@acr_state_enum_columns = 5') && str_contains($preflight, '@acr_source_indexes = 4') && str_contains($preflight, '@acr_context_fk_source = 2'), 'Preflight source fingerprint does not describe the known deployed layout.');
    $expect(str_contains($preflight, '@acr_event_context_columns = 2 AND @acr_version_context_columns = 0') && str_contains($preflight, '@acr_state_varchar_columns = 5') && str_contains($preflight, '@acr_known_check_count = 12') && str_contains($preflight, '@acr_target_indexes = 10') && str_contains($preflight, '@acr_context_fk_target = 2'), 'Preflight target fingerprint does not describe the merged layout.');
    $expect(str_contains($preflight, '@acr_event_rows = 0 AND @acr_version_rows = 0'), 'Preflight must require empty event and revision history.');
    $expect(str_contains($apply, '@acr_event_rows = 0 AND @acr_version_rows = 0'), 'Apply must independently require empty event and revision history.');
    $expect(str_contains($rollback, '@acr_event_rows=0 AND @acr_version_rows=0'), 'Rollback must fail closed after event or revision history exists.');

    $expect(str_contains($apply, 'DROP COLUMN `semester_id`') && str_contains($apply, 'DROP COLUMN `academic_calendar_event_type_id`'), 'Apply must remove context columns from revisions.');
    $expect(str_contains($apply, 'ADD COLUMN `semester_id` INT NULL AFTER `academic_year_id`'), 'Apply must add nullable semester context to logical events.');
    $expect(str_contains($apply, 'ADD COLUMN `academic_calendar_event_type_id` INT NOT NULL AFTER `semester_id`'), 'Apply must add event-type context to logical events.');
    $expect(str_contains($apply, 'fk_ace_semester') && str_contains($apply, 'fk_ace_event_type'), 'Apply must restore logical-event context foreign keys.');
    $expect(str_contains($apply, 'idx_ace_year_semester') && str_contains($apply, 'idx_ace_event_type'), 'Apply must restore logical-event context indexes.');
    $expect(str_contains($verify, '@acr_context_ownership'), 'Verify must assert merged logical-event context ownership.');
    $expect(str_contains($verify, 'SELECT COUNT(*) = 42 FROM information_schema.columns') && str_contains($verify, 'SELECT COUNT(*) = 11 FROM information_schema.key_column_usage k') && str_contains($verify, 'SELECT COUNT(*) = 18 FROM information_schema.columns'), 'Verify must protect the exact merged column, FK, and signed-key counts.');
    $expect(str_contains($preflight, '@acr_generated_slots = 2 AND @acr_generated_unique_indexes = 2') && str_contains($verify, '@acr_generated_slots AND @acr_generated_unique_indexes'), 'Generated single-active and single-published uniqueness must remain intact.');

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
    }
    $expect(! preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/im', $apply), 'Apply must contain no data mutation.');
    $expect(! str_contains(strtoupper($apply), 'CREATE TABLE'), 'Apply must not create a replacement calendar table.');
    $expect(! str_contains(strtoupper($rollback), 'DROP TABLE'), 'Rollback must never drop a calendar table.');
    foreach (['DATABASE()', 'DELIMITER', 'SIGNAL', 'CREATE PROCEDURE', 'CREATE FUNCTION'] as $forbidden) {
        $expect(! str_contains(strtoupper($allSql), $forbidden), 'Forbidden SQL construct found: '.$forbidden);
    }
    foreach (['00_preflight.sql' => $preflight, '01_apply.sql' => $apply, '02_verify.sql' => $verify, '03_rollback.sql' => $rollback] as $file => $sql) {
        preg_match_all('/(?<!DEALLOCATE )\bPREPARE\s+[a-z0-9_]+\s+FROM\b/i', $sql, $prepares);
        preg_match_all('/\bEXECUTE\s+[a-z0-9_]+\b/i', $sql, $executes);
        preg_match_all('/\bDEALLOCATE\s+PREPARE\s+[a-z0-9_]+\b/i', $sql, $deallocates);
        $expect(count($prepares[0]) === count($executes[0]) && count($prepares[0]) === count($deallocates[0]), $file.' has an unbalanced prepared statement lifecycle.');
    }
    $expect(substr_count($allSql, '`alrowad_uni_rust`') > 20, 'SQL must use the explicit production database.');
    $expect(! str_contains(strtoupper($allSql), 'INSERT INTO `ALROWAD_UNI_RUST`'), 'Repair package must not seed production data.');
    $expect(str_contains($readme, 'academic-calendar-phase1/02_verify.sql') && str_contains($readme, 'Phase 3'), 'README must require both verification gates before Phase 3.');

    $immutablePackages = [
        'database/sql/academic-calendar-phase1/00_preflight.sql' => 'b3b40213bf336ba3221d9109838d20b53739cc2ef5ec80cbe3e118412cc0d342',
        'database/sql/academic-calendar-phase1/01_apply.sql' => 'b12739e01aa489d3c4277cb7d5662109aa0c7bbe7b1ff2f23473302425bbb339',
        'database/sql/academic-calendar-phase1/02_verify.sql' => '35706b06b5c818d00f0bded1477d5ce2a9ebb74752ebcb929d148056810035cb',
        'database/sql/academic-calendar-phase1/03_rollback.sql' => '83371ec3b6a2a9ca8baba385f991036c3478c4aaeed9cbf576ed6d841a7bf7b8',
        'database/sql/academic-calendar-phase1/README.md' => '719bbacb596fab3113aa0f9f3fe522370efa1e68bc2123744337a32239c1499c',
        'database/sql/academic-calendar-phase2-rbac/00_preflight.sql' => 'e61e331d44d38210547d32e3469673ae76bf6f7d27bf8ed2e0b80923b63ceb49',
        'database/sql/academic-calendar-phase2-rbac/01_apply.sql' => '1f8ab5bff3671316f3e6101c7ba5ad9c311d3e52b0f5314fc3094cfaed64c16f',
        'database/sql/academic-calendar-phase2-rbac/02_verify.sql' => '66f5d75dbdd27b520670ab69bfe4d396d010fd78768a1bfee2746e0852165a9f',
        'database/sql/academic-calendar-phase2-rbac/03_rollback.sql' => 'a60fb09f935cb0dc6c70d2418d93469ba9f4d34d21d417f711b107f46ec3ddea',
        'database/sql/academic-calendar-phase2-rbac/README.md' => 'b64c73fa66433588e0ec1af0ae6699608fe5d42ef287ac72b2820b11050d9293',
    ];
    foreach ($immutablePackages as $file => $sha256) {
        $expect(hash_file('sha256', $backendRoot.'/'.$file) === $sha256, 'Previously merged SQL package changed: '.$file);
    }

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
