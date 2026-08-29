<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend';
    $paths = [
        'service' => $backendRoot.'/app/Services/MinistryPlacementApplicantConversionService.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/MinistryPlacementApplicantConversionController.php',
        'individual_request' => $backendRoot.'/app/Http/Requests/MinistryPlacement/ConvertMinistryPlacementApplicantRequest.php',
        'batch_request' => $backendRoot.'/app/Http/Requests/MinistryPlacement/ConvertMinistryPlacementBatchRequest.php',
        'model' => $backendRoot.'/app/Models/MinistryPlacementRecord.php',
        'access' => $backendRoot.'/app/Support/MinistryPlacementAccess.php',
        'normalizer' => $backendRoot.'/app/Support/MinistryPlacementNormalizer.php',
        'phase1_service' => $backendRoot.'/app/Services/MinistryPlacementService.php',
        'phase2_service' => $backendRoot.'/app/Services/MinistryPlacementProgramMatchingService.php',
        'routes' => $backendRoot.'/routes/api.php',
        'preflight' => $backendRoot.'/database/sql/ministry-placement/20_phase3_preflight.sql',
        'page' => $frontendRoot.'/src/features/student-affairs/pages/MinistryPlacementsPage.jsx',
        'panel' => $frontendRoot.'/src/features/student-affairs/components/MinistryApplicantConversionPanel.jsx',
        'helper' => $frontendRoot.'/src/features/student-affairs/lib/ministryPlacement.js',
    ];
    foreach ($paths as $name => $path) {
        if (! is_file($path)) $errors[] = 'Missing Ministry Placement Phase 3 file: '.$name;
    }
    if ($errors !== []) return $errors;

    $sources = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) $errors[] = $message;
    };

    foreach ([
        "Route::get('ministry-placements/{batch}/applicant-conversion'",
        "Route::post('ministry-placement-records/{record}/convert-to-applicant'",
        "Route::post('ministry-placements/{batch}/applicant-conversion/convert-all'",
    ] as $route) {
        $expect(str_contains($sources['routes'], $route), 'Missing Phase 3 route: '.$route);
    }
    $expect(str_contains($sources['routes'], 'MinistryPlacementApplicantConversionController'), 'Conversion routes need a dedicated controller.');

    $expect(str_contains($sources['access'], 'effectivePermissions()->contains') && str_contains($sources['access'], 'hasActualUniversityScope'), 'Phase 3 must reuse assigned admissions permission plus actual university scope.');
    $expect(! str_contains($sources['access'], 'hasPermission(') && ! str_contains($sources['access'], 'super_admin'), 'Phase 3 must not inherit a role/scope bypass.');
    $expect(str_contains($sources['individual_request'], 'MinistryPlacementAccess::class') && str_contains($sources['individual_request'], 'canManage'), 'Individual conversion must authorize server side.');
    $expect(str_contains($sources['individual_request'], '$this->all() !== []') && str_contains($sources['individual_request'], 'ministry_placement_conversion_payload_not_allowed') && str_contains($sources['individual_request'], '422'), 'The no-input endpoint must explicitly reject every non-empty payload.');
    $allowlistStart = strpos($sources['batch_request'], 'private const ALLOWED_KEYS');
    $allowlistEnd = strpos($sources['batch_request'], 'private array $unexpectedKeys', $allowlistStart === false ? 0 : $allowlistStart);
    $allowlist = $allowlistStart === false || $allowlistEnd === false ? '' : substr($sources['batch_request'], $allowlistStart, $allowlistEnd - $allowlistStart);
    preg_match_all("/'([^']+)'/", $allowlist, $allowedKeyMatches);
    $expect(($allowedKeyMatches[1] ?? []) === ['expected_eligible_count', 'expected_snapshot'], 'Bulk conversion allowlist must contain exactly its two concurrency fields.');
    foreach (['academic_program_id', 'applicant_id', 'academic_year_id', 'applicant_number', 'decision_status', 'decided_by_user_id', 'student_id', 'user_id'] as $forbiddenBulkField) {
        $expect(! str_contains($allowlist, $forbiddenBulkField), 'Bulk conversion allowlist contains a server-derived field: '.$forbiddenBulkField);
    }
    $expect(str_contains($sources['batch_request'], 'array_diff(array_keys($this->all()), self::ALLOWED_KEYS)') && str_contains($sources['batch_request'], 'ministry_placement_conversion_batch_payload_not_allowed') && str_contains($sources['batch_request'], '422'), 'Bulk conversion must reject unexpected top-level fields with its stable 422 code.');
    $expect(str_contains($sources['batch_request'], 'parent::failedValidation($validator)'), 'Normal validation for the two legal bulk fields must remain unchanged.');
    foreach (['applicant_id', 'academic_program_id', 'academic_year_id', 'applicant_number', 'decision_status', 'decided_by_user_id'] as $serverDerived) {
        $expect(! str_contains($sources['controller'], "validated['{$serverDerived}']"), 'Client input controls a server-derived conversion field: '.$serverDerived);
    }

    foreach (['DB::transaction', 'lockForUpdate', "'MP-R'.(int) \$record->placement_record_id", 'MinistryPlacementNormalizer::duplicateKey', 'applicantDataIsValid', "'processing_status' => 'applicant_created'", "'decision_status' => self::PENDING_DECISION", "'decision_date' => null", "'decided_by_user_id' => null", "'notes' => null"] as $required) {
        $expect(str_contains($sources['service'], $required), 'Conversion safety/mapping is missing: '.$required);
    }
    $expect(str_contains($sources['service'], "'address' => null") && ! str_contains($sources['service'], "'national_civil_id' =>"), 'Applicant mapping must stay schema-compatible and exclude the national identity.');
    $expect(str_contains($sources['service'], "'matchedAcademicProgram.department.college'") && str_contains($sources['service'], "'applicant'"), 'Conversion reads must eager-load the hierarchy and exact Applicant.');
    $expect(str_contains($sources['service'], "whereIn('applicant_id', \$linkedApplicantIds->all())") && str_contains($sources['service'], "where('academic_program_id', (int) \$linkedRecord->matched_academic_program_id)") && str_contains($sources['service'], "where('academic_year_id', (int) \$batch->academic_year_id)"), 'Individual replay must query the exact applicant/program/year triple.');
    $expect(str_contains($sources['service'], '$applications->count() !== 1') && str_contains($sources['service'], '$applications->sole()'), 'Replay must fail closed on zero or multiple exact applications.');
    $expect(! preg_match('/application(Query)?[^;]{0,500}->(first|latest|oldest)\s*\(/s', $sources['service']), 'Application ambiguity must never be resolved with first/latest/oldest.');
    $expect(str_contains($sources['service'], "private const LATER_DECISIONS = ['accepted', 'rejected']") && str_contains($sources['service'], "'decision_status_unsupported'") && str_contains($sources['service'], "'conversion_state' => 'later_stage'"), 'Decision states must use an explicit allowlist and fail unknown values closed.');

    // Snapshot validity assumes Phase 1 imported identity/profile fields remain immutable
    // through Ministry APIs. Any future staging-edit feature must revisit this contract.
    $snapshotStart = strpos($sources['service'], 'private function snapshot');
    $snapshotEnd = strpos($sources['service'], 'private function recordPayload', $snapshotStart === false ? 0 : $snapshotStart);
    $snapshot = $snapshotStart === false || $snapshotEnd === false ? '' : substr($sources['service'], $snapshotStart, $snapshotEnd - $snapshotStart);
    foreach (['placement_record_id', 'matched_academic_program_id', '$academicYearId', "hash('sha256'", 'sortBy'] as $required) {
        $expect(str_contains($snapshot, $required), 'Eligible snapshot is incomplete: '.$required);
    }
    foreach (['national_civil_id', 'first_name', 'last_name', 'phone_number', 'email', 'applicant_number'] as $forbidden) {
        $expect(! str_contains($snapshot, $forbidden), 'Eligible snapshot leaks or depends on identity/profile data: '.$forbidden);
    }
    $expect(str_contains($sources['service'], 'hash_equals($snapshot, $expectedSnapshot)') && str_contains($sources['service'], 'ministry_placement_conversion_batch_stale'), 'Bulk conversion must compare both server count and snapshot under lock.');
    $expect(strpos($sources['service'], 'foreach ($eligible as $item)') > strpos($sources['service'], 'hash_equals($snapshot, $expectedSnapshot)'), 'Bulk writes must begin only after locked snapshot validation.');

    foreach (['ministry_placement.applicant_convert', 'ministry_placement.applicant_convert_bulk'] as $action) {
        $expect(str_contains($sources['service'], $action), 'Missing conversion audit action: '.$action);
    }
    $auditStart = strpos($sources['service'], 'private function audit');
    $audit = $auditStart === false ? '' : substr($sources['service'], $auditStart);
    foreach (['national_civil_id', 'subscription_number', 'first_name', 'last_name', 'phone_number', 'email', 'accepted_preference_text', 'applicant_number'] as $forbidden) {
        $expect(! str_contains($audit, $forbidden), 'Audit helper contains forbidden personal metadata: '.$forbidden);
    }

    foreach (['Applicant::query()->create', 'AdmissionApplication::query()->create'] as $allowedWrite) {
        $expect(str_contains($sources['service'], $allowedWrite), 'Dedicated Phase 3 conversion write is missing: '.$allowedWrite);
        $expect(! str_contains($sources['phase1_service'].$sources['phase2_service'], $allowedWrite), 'Earlier Ministry phases must not create conversion entities: '.$allowedWrite);
    }
    foreach (['Student::create', 'Student::query()->create', 'User::create', 'User::query()->create', 'UserRole::create', 'UserRole::query()->create', 'password_hash', 'student_number', 'course_registration', 'academic_term'] as $forbidden) {
        $expect(! str_contains($sources['service'].$sources['controller'], $forbidden), 'Phase 3 contains a forbidden later-stage write: '.$forbidden);
    }
    $expect((glob($backendRoot.'/database/migrations/*ministry*placement*') ?: []) === [], 'Ministry Placement migrations remain forbidden.');

    $ministryRecordMutationRoutes = array_filter(preg_split('/\R/', $sources['routes']) ?: [], fn (string $line): bool => str_contains($line, 'ministry-placement-records/{record}') && preg_match('/Route::(put|patch|post|delete)/i', $line));
    foreach ($ministryRecordMutationRoutes as $line) {
        $expect(str_contains($line, 'program-match') || str_contains($line, 'convert-to-applicant'), 'A Ministry API now edits immutable imported identity/profile fields: '.trim($line));
    }
    $expect(! preg_match('/function\s+(update|editIdentity|updateProfile)\s*\(/', $sources['controller']), 'Phase 3 must preserve immutable Ministry staging identity/profile data.');

    $sqlUpper = strtoupper($sources['preflight']);
    foreach (['PREPARE', 'EXECUTE', 'DEALLOCATE', 'DATABASE()', 'DELIMITER', 'SIGNAL', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE ', 'DROP '] as $forbidden) {
        $expect(! str_contains($sqlUpper, $forbidden), 'Phase 3 preflight is not phpMyAdmin-safe/read-only: '.$forbidden);
    }
    foreach (['CONVERSION_DATA_READINESS', 'potential_convertible_records', 'multiple_application_records', 'program_inactive_records', 'exact_duplicate_national_id_records', "SELECT 'OVERALL'", "'READY', 'BLOCKED'", '`alrowad_uni_rust`'] as $required) {
        $expect(str_contains($sources['preflight'], $required), 'Phase 3 preflight is incomplete: '.$required);
    }
    foreach (['@mp3_audit_columns', "column_name = 'created_at'", "data_type = 'timestamp'", '@mp3_authorization_columns', '@mp3_authorization_primary_keys', '@mp3_authorization_foreign_keys', '@mp3_active_account_status', "status_code = 'active'", '@mp3_active_permissions', '@mp3_active_pres_root', "unit_code = 'PRES'", 'RBAC_ACCOUNT_STRUCTURE', 'ACTUAL_UNIVERSITY_SCOPE_STRUCTURE'] as $required) {
        $expect(str_contains($sources['preflight'], $required), 'Phase 3 runtime authorization/audit preflight is incomplete: '.$required);
    }
    foreach (['account_status_id', 'user_role_id', 'role_permission_id', 'permission_code', 'user_access_scope_id', 'scope_type', 'scope_id', 'organizational_unit_id', 'unit_code'] as $requiredColumn) {
        $expect(str_contains($sources['preflight'], $requiredColumn), 'Phase 3 preflight lacks an authorization/scope column: '.$requiredColumn);
    }
    foreach (['users', 'account_statuses', 'roles', 'user_roles', 'role_permissions', 'permissions', 'user_access_scopes', 'organizational_units'] as $requiredAuthorizationTable) {
        $expect(str_contains($sources['preflight'], "table_name = '{$requiredAuthorizationTable}'"), 'Phase 3 preflight lacks a structural authorization table check: '.$requiredAuthorizationTable);
    }
    foreach ([
        "table_name = 'users' AND column_name = 'account_status_id' AND referenced_table_name = 'account_statuses'",
        "table_name = 'user_roles' AND column_name = 'user_id' AND referenced_table_name = 'users'",
        "table_name = 'user_roles' AND column_name = 'role_id' AND referenced_table_name = 'roles'",
        "table_name = 'role_permissions' AND column_name = 'role_id' AND referenced_table_name = 'roles'",
        "table_name = 'role_permissions' AND column_name = 'permission_id' AND referenced_table_name = 'permissions'",
        "table_name = 'user_access_scopes' AND column_name = 'user_id' AND referenced_table_name = 'users'",
    ] as $requiredAuthorizationForeignKey) {
        $expect(str_contains($sources['preflight'], $requiredAuthorizationForeignKey), 'Phase 3 preflight lacks an authorization/account foreign key: '.$requiredAuthorizationForeignKey);
    }
    $readyStart = strpos($sources['preflight'], 'SET @mp3_ready');
    $readyEnd = strpos($sources['preflight'], 'SELECT', $readyStart === false ? 0 : $readyStart);
    $ready = $readyStart === false || $readyEnd === false ? '' : substr($sources['preflight'], $readyStart, $readyEnd - $readyStart);
    foreach (['@mp3_audit_columns', '@mp3_authorization_columns', '@mp3_authorization_primary_keys', '@mp3_authorization_foreign_keys', '@mp3_active_account_status', '@mp3_active_permissions', '@mp3_active_pres_root'] as $requiredReadyCheck) {
        $expect(str_contains($ready, $requiredReadyCheck), 'OVERALL readiness omits: '.$requiredReadyCheck);
    }
    $expect(! str_contains($sources['preflight'], 'admission_application_id` FROM `alrowad_uni_rust`.`ministry_placement_records'), 'Preflight must not invent a Ministry application FK column.');

    foreach (['applicant-conversion', 'expected_eligible_count', 'expected_snapshot', 'ministry_placement_conversion_batch_stale', 'canConvertMinistryRecord', 'canBulkConvertMinistryApplicants'] as $required) {
        $expect(str_contains($sources['panel'].$sources['helper'], $required), 'Phase 3 UI contract is incomplete: '.$required);
    }
    $expect(str_contains($sources['page'], "setBatchView('applicant_conversion')") && str_contains($sources['page'], '<MinistryApplicantConversionPanel'), 'The existing Ministry portal needs the third conversion tab.');
    $expect(str_contains($sources['panel'], 'role="dialog"') && str_contains($sources['panel'], 'onConfirm={convertRecord}') && str_contains($sources['panel'], 'onConfirm={convertAll}'), 'Individual and bulk conversion require explicit confirmation.');
    $expect(str_contains($sources['panel'], 'onChanged?.()') && str_contains($sources['panel'], 'createLatestRequestGuard'), 'Conversion UI must preserve authoritative refresh and stale-response guards.');
    foreach (['accept', 'reject', 'Student::', 'User::', 'password'] as $forbidden) {
        $expect(! str_contains($sources['panel'], $forbidden), 'Conversion UI exposes a forbidden later-phase action: '.$forbidden);
    }

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) fwrite(STDERR, $error.PHP_EOL);
        exit(1);
    }

    require_once dirname(__DIR__, 2).'/app/Support/MinistryPlacementNormalizer.php';
    $arabic = "\u{0660}\u{0660}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}\u{0668}\u{0669}";
    if (\App\Support\MinistryPlacementNormalizer::duplicateKey($arabic) !== \App\Support\MinistryPlacementNormalizer::duplicateKey('00123456789')) {
        fwrite(STDERR, "Phase 3 identity safety no longer shares the Phase 1 canonical duplicate key.\n");
        exit(1);
    }

    fwrite(STDOUT, "Ministry Placement Phase 3 contract passed.\n");
}

return $contract;
