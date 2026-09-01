<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };
    $read = static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';

    $sqlRoot = $backendRoot.'/database/sql/semester-registration-modifications-phase5';
    $actualSql = is_dir($sqlRoot) ? array_map('basename', glob($sqlRoot.'/*') ?: []) : [];
    sort($actualSql);
    $expect($actualSql === ['00_preflight.sql', '01_apply.sql', '02_verify.sql'], 'Phase 5 SQL package must contain exactly preflight, apply, and verify.');

    $preflight = $read($sqlRoot.'/00_preflight.sql');
    $apply = $read($sqlRoot.'/01_apply.sql');
    $verify = $read($sqlRoot.'/02_verify.sql');
    foreach ([$preflight, $apply, $verify] as $sql) {
        $expect(str_contains($sql, '`alrowad_uni_rust`'), 'Every Phase 5 SQL script must explicitly target alrowad_uni_rust.');
        $expect(! preg_match('/\bDATABASE\s*\(/i', $sql), 'Phase 5 SQL must not use DATABASE().');
        $expect(! preg_match('/\b(?:PROCEDURE|DELIMITER|SIGNAL)\b/i', $sql), 'Phase 5 SQL must not use procedures, DELIMITER, or SIGNAL.');
        $expect(! preg_match('/^\s*(?:INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM)\b/im', $sql), 'Phase 5 SQL must not backfill or seed production rows.');
    }
    $expect(str_contains($preflight, "SELECT 'OVERALL'") && str_contains($preflight, "'READY','BLOCKED'"), 'Preflight must visibly end READY/BLOCKED.');
    $expect(str_contains($preflight, 'INCOMPLETE_RESUMABLE') && str_contains($preflight, '@srm5_request_compatible') && str_contains($preflight, '@srm5_item_compatible') && str_contains($preflight, '@srm5_event_compatible'), 'Preflight must classify each existing Phase 5 table and expose compatible partial installations as resumable.');
    $expect(substr_count(strtoupper($apply), 'CREATE TABLE') === 3, 'Apply must create exactly three Phase 5 tables.');
    $expect(str_contains($apply, '@srm5_apply_request_exists=0') && str_contains($apply, '@srm5_apply_item_exists=0') && str_contains($apply, '@srm5_apply_event_exists=0'), 'Apply must independently create each missing Phase 5 table.');
    $expect(str_contains($apply, "'RESUMED'") && str_contains($apply, "'ALREADY_APPLIED'"), 'Apply must distinguish resumed partial DDL from a complete rerun.');
    $expect(! str_contains($apply, '@srm5_apply_ready AND @srm5_apply_targets=0'), 'Apply must not require all three Phase 5 tables to be absent before creating a missing table.');
    foreach (['student_registration_modification_requests', 'student_registration_modification_items', 'student_registration_modification_events'] as $table) {
        $expect(str_contains($apply, $table) && str_contains($verify, $table), 'Missing Phase 5 table contract '.$table.'.');
    }
    foreach (['uq_srmod_current_slot', 'chk_srmod_current', 'chk_srmod_submission', 'chk_srmod_return', 'chk_srmod_materialized', 'chk_srmod_snapshot', 'chk_srmodi_source', 'idx_srmode_request_history'] as $marker) {
        $expect(str_contains($apply, $marker) && str_contains($verify, $marker), 'Missing Phase 5 invariant '.$marker.'.');
    }
    $expect(! preg_match('/ON\s+DELETE\s+CASCADE/i', $apply), 'Phase 5 foreign keys must be restrictive.');
    $expect(str_contains($verify, "SELECT 'OVERALL'") && str_contains($verify, "'PASS','FAIL'"), 'Verify must visibly end PASS/FAIL.');
    $expect(str_contains($verify, 'INCOMPLETE_RESUMABLE') && str_contains($verify, '@srm5v_existing_compatible'), 'Verify must report a compatible partial installation as incomplete and resumable.');
    $expect(! preg_match('/FROM\s+`alrowad_uni_rust`\.`student_registration_modification_(?:requests|items|events)`/i', $verify), 'Verify must not query a potentially missing Phase 5 table directly while reporting partial DDL.');
    $expect(! preg_match('/^\s*(?:INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM)\s+[^;]*(?:permissions|role_permissions)/im', $apply), 'Phase 5 must not write RBAC data.');

    $workflow = $read($backendRoot.'/app/Support/RegistrationModificationWorkflow.php');
    $service = $read($backendRoot.'/app/Services/RegistrationModificationService.php');
    $registration = $read($backendRoot.'/app/Services/RegistrationService.php');
    $requirements = $read($backendRoot.'/app/Services/AcademicRequirementService.php');
    $schedules = $read($backendRoot.'/app/Services/CourseOfferingScheduleService.php');
    $exception = $read($backendRoot.'/app/Exceptions/RegistrationException.php');
    $routes = $read($backendRoot.'/routes/api.php');
    $studentController = $read($backendRoot.'/app/Http/Controllers/Api/StudentSelfRegistrationController.php');
    $withdrawal = $read($backendRoot.'/app/Services/RegistrationWithdrawalService.php');

    foreach (['draft', 'submitted', 'returned', 'approved', 'expired', 'superseded'] as $status) {
        $expect(str_contains($workflow, "'{$status}'"), 'Missing modification status '.$status.'.');
    }
    foreach (['keep', 'remove', 'add'] as $operation) {
        $expect(str_contains($workflow, "'{$operation}'"), 'Missing delta operation '.$operation.'.');
    }
    $expect(str_contains($service, 'initial_registration_request_id') && str_contains($service, 'student_course_registration_id === null'), 'Draft creation must require a materialized approved initial request.');
    $expect(str_contains($service, 'registration_modification_no_changes'), 'Submission must reject an empty delta.');
    $expect(str_contains($service, 'registration_modification_stale') && str_contains($service, 'STATUS_SUPERSEDED'), 'Baseline drift must persist supersession and return a stable stale conflict.');
    $expect(str_contains($service, 'transitionRegisteredToDropped(') && ! str_contains($service, '->selfDrop('), 'Trusted approval must use the canonical low-level drop transition, never student selfDrop().');
    $expect(str_contains($service, 'materializeAdvisorApprovedModificationItemWithinTransaction('), 'Trusted add materialization boundary is missing.');
    $expect(str_contains($registration, 'evaluateRegistrationCandidatesForProjection') && str_contains($registration, 'officialRegistrationAcademicStanding($student)') && str_contains($registration, 'getMissingPrerequisites($student, $courseId, $academicStanding)'), 'Phase 5 projected candidates must reuse canonical passed-course and prerequisite semantics through RegistrationService.');
    $expect(substr_count($service, 'evaluateRegistrationCandidatesForProjection(') >= 2, 'Canonical projected candidate validation must run at add and submit/approval prevalidation.');
    $expect(str_contains($service, 'COURSE_ALREADY_PASSED') || str_contains($registration, 'COURSE_ALREADY_PASSED'), 'Projected validation must retain the course_already_passed machine reason.');
    $expect(str_contains($registration, "'missing_prerequisites'") && str_contains($registration, "'course_code'"), 'Projected prerequisite failures must retain structured course data.');
    $expect(str_contains($service, 'registration_modification_withdrawal_conflict'), 'Current withdrawal requests must block approval.');
    $expect(str_contains($service, "'below_recommended_minimum' =>"), 'Below 12 hours must remain an informational projection warning.');
    $expect(str_contains($service, '$lockedOfferings = CourseOffering::query()') && strpos($service, '$lockedOfferings = CourseOffering::query()') < strpos($service, '$official = $this->officialTermRegistrationsQuery'), 'Advisor approval must lock Offerings before official registrations.');
    $expect(str_contains($service, '$materializedApproval') && str_contains($service, '$this->approvalSnapshot($request)'), 'Approved materialized presentation must use immutable approval-hour snapshots instead of live projection math.');

    $behavior = $read($backendRoot.'/tests/Feature/SemesterRegistrationModificationsPhase5BehaviorTest.php');
    foreach ([
        'test_real_workflow_snapshots_exact_baseline_without_mutating_approved_initial_request',
        'test_real_add_only_approval_materializes_and_links_the_official_registration',
        'test_real_eighteen_hour_replace_succeeds_and_approved_presentation_uses_immutable_snapshot',
        'test_real_timetable_conflict_against_keep_blocks_but_removing_that_peer_allows_replacement',
        'test_real_passed_course_is_rejected_again_at_submit_with_stable_reason',
        'test_real_missing_prerequisite_is_rejected_again_at_submit_with_structured_course_data',
        'test_real_atomic_approval_rolls_back_prior_remove_and_add_when_later_add_fails',
    ] as $behaviorMarker) {
        $expect(str_contains($behavior, $behaviorMarker), 'Missing real Phase 5 behavior regression '.$behaviorMarker.'.');
    }

    $expect(str_contains($registration, 'RegistrationProjectionContext') && str_contains($requirements, 'RegistrationProjectionContext') && str_contains($schedules, 'RegistrationProjectionContext'), 'Canonical hours, requirements, and timetable boundaries must accept the immutable projection context.');
    $expect(str_contains($registration, 'materializeAdvisorApprovedModificationItemWithinTransaction') && str_contains($registration, 'RegistrationModificationWorkflow::OPERATION_ADD'), 'The trusted add boundary must bind a persisted Phase 5 add item.');
    $expect(str_contains($registration, "StudentRegistrationRequest::STATUS_APPROVED") && str_contains($registration, 'registrationModificationRequired()'), 'selfDrop must guard every approved workflow-managed student/term.');
    $expect(str_contains($exception, "'registration_modification_required'") && str_contains($exception, '409, self::REGISTRATION_MODIFICATION_REQUIRED'), 'The direct-drop guard must expose the stable 409 machine code.');
    $expect(str_contains($studentController, '$registrations->selfDrop('), 'The student HTTP drop endpoint must pass through the authoritative selfDrop guard.');
    $expect(substr_count($routes, "student/registration/{registration}/drop") === 1, 'There must be exactly one student-accessible immediate-drop endpoint.');
    $expect(! str_contains($withdrawal, 'RegistrationModificationService'), 'The withdrawal workflow must remain independent from Phase 5 modifications.');

    foreach ([
        'student/registration/modification',
        'academic-advising/registration-modifications',
        'registration_requests.view',
        'registration_requests.review',
    ] as $routeMarker) {
        $expect(str_contains($routes, $routeMarker), 'Missing route/authority marker '.$routeMarker.'.');
    }
    $expect(! str_contains($routes, 'registration_modifications.manage'), 'Phase 5 must reuse existing permissions and add no new RBAC code.');
    $expect((glob($backendRoot.'/database/migrations/*modification*') ?: []) === [], 'Phase 5 must not add a migration.');
    $expect((glob($backendRoot.'/database/seeders/*Modification*') ?: []) === [], 'Phase 5 must not add a seeder.');

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
    fwrite(STDOUT, "Semester Registration Phase 5 modification contract passed.\n");
}

return $contract;
