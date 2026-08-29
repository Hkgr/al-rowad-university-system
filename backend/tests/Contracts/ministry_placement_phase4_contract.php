<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend';
    $paths = [
        'service' => $backendRoot.'/app/Services/MinistryPlacementStudentEnrollmentService.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/MinistryPlacementStudentEnrollmentController.php',
        'individual_request' => $backendRoot.'/app/Http/Requests/MinistryPlacement/EnrollMinistryPlacementStudentRequest.php',
        'batch_request' => $backendRoot.'/app/Http/Requests/MinistryPlacement/EnrollMinistryPlacementBatchRequest.php',
        'access' => $backendRoot.'/app/Support/MinistryPlacementAccess.php',
        'phase1' => $backendRoot.'/app/Services/MinistryPlacementService.php',
        'phase2' => $backendRoot.'/app/Services/MinistryPlacementProgramMatchingService.php',
        'phase3' => $backendRoot.'/app/Services/MinistryPlacementApplicantConversionService.php',
        'routes' => $backendRoot.'/routes/api.php',
        'preflight' => $backendRoot.'/database/sql/ministry-placement/30_phase4_preflight.sql',
        'page' => $frontendRoot.'/src/features/student-affairs/pages/MinistryPlacementsPage.jsx',
        'panel' => $frontendRoot.'/src/features/student-affairs/components/MinistryStudentEnrollmentPanel.jsx',
        'helper' => $frontendRoot.'/src/features/student-affairs/lib/ministryPlacement.js',
    ];
    foreach ($paths as $name => $path) {
        if (! is_file($path)) $errors[] = 'Missing Ministry Placement Phase 4 file: '.$name;
    }
    if ($errors !== []) return $errors;

    $sources = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) $errors[] = $message;
    };

    foreach ([
        "Route::get('ministry-placement-academic-levels'",
        "Route::get('ministry-placements/{batch}/student-enrollment'",
        "Route::post('ministry-placement-records/{record}/enroll-student'",
        "Route::post('ministry-placements/{batch}/student-enrollment/enroll-all'",
    ] as $route) $expect(str_contains($sources['routes'], $route), 'Missing Phase 4 route: '.$route);
    $expect(str_contains($sources['controller'], 'MinistryPlacementAccess') && str_contains($sources['controller'], 'canView'), 'Phase 4 reads must use Ministry authorization.');
    $expect(str_contains($sources['individual_request'], 'canManage') && str_contains($sources['batch_request'], 'canManage'), 'Phase 4 mutations must use Ministry admissions.manage authority.');
    $expect(! str_contains($sources['access'], 'hasPermission(') && ! str_contains($sources['access'], 'super_admin'), 'Phase 4 must preserve assigned permission plus actual scope semantics.');

    $individualAllowlist = substr($sources['individual_request'], strpos($sources['individual_request'], 'private const ALLOWED_KEYS'), 240);
    preg_match_all("/'([^']+)'/", $individualAllowlist, $individualKeys);
    $expect(array_slice($individualKeys[1] ?? [], 0, 3) === ['student_number', 'current_academic_level_id', 'enrollment_date'], 'Individual request allowlist is not the exact operator input.');
    foreach (['applicant_id', 'admission_application_id', 'academic_program_id', 'academic_year_id', 'student_status_id', 'decision_status', 'decision_date', 'decided_by_user_id', 'processing_status', 'first_name', 'last_name', 'email', 'phone_number', 'user_id', 'password'] as $field) {
        $expect(! str_contains($individualAllowlist, $field), 'Individual request allowlist contains a server-derived field: '.$field);
    }
    $expect(str_contains($sources['individual_request'], 'array_diff(array_keys($this->all()), self::ALLOWED_KEYS)') && str_contains($sources['individual_request'], 'ministry_placement_enrollment_payload_not_allowed'), 'Individual request must reject unknown top-level fields.');
    $expect(str_contains($sources['batch_request'], 'ALLOWED_ITEM_KEYS') && str_contains($sources['batch_request'], 'unexpectedItemKeys') && str_contains($sources['batch_request'], 'ministry_placement_enrollment_batch_payload_not_allowed'), 'Bulk request must reject unknown top-level and nested fields.');

    foreach (['DB::transaction', 'lockForUpdate', 'MinistryPlacementNormalizer::duplicateKey', "where('status_code', 'active')", "where('is_active', true)", "'decision_status' => self::ACCEPTED", "'decided_by_user_id' => (int) \$actor->user_id", "'processing_status' => 'enrolled'", 'Student::query()->create'] as $required) {
        $expect(str_contains($sources['service'], $required), 'Phase 4 transaction/mapping is incomplete: '.$required);
    }
    $expect(str_contains($sources['service'], "->where('applicant_id'") || str_contains($sources['service'], "whereIn('applicant_id'"), 'Phase 4 must resolve applications through the linked Applicant.');
    $expect(str_contains($sources['service'], 'expectedApplications->count() !== 1') && str_contains($sources['service'], 'expectedApplications->sole()'), 'Phase 4 must fail closed on an ambiguous exact application triple.');
    $expect(! preg_match('/application(Query)?[^;]{0,500}->(first|latest|oldest)\s*\(/s', $sources['service']), 'Application ambiguity must not be resolved with first/latest/oldest.');
    $expect(str_contains($sources['service'], 'matched_academic_program_id') && str_contains($sources['service'], 'academic_year_id'), 'Application replay must retain explicit program/year context.');
    $expect(str_contains($sources['service'], 'applicant->first_name') && str_contains($sources['service'], 'applicant->address') && str_contains($sources['service'], "'academic_program_id' => (int) \$application->academic_program_id"), 'Student profile/program mapping must come from Applicant/Application.');
    $expect(! str_contains($sources['service'], 'accept_applicant_as_student'), 'Laravel must not call the legacy acceptance procedure.');
    foreach (['MP-S', 'STU-', 'national_civil_id.', 'subscription_number.'] as $forbiddenNumberPolicy) {
        $expect(! str_contains($sources['service'], $forbiddenNumberPolicy), 'Phase 4 invents a student-number policy: '.$forbiddenNumberPolicy);
    }

    $snapshotStart = strpos($sources['service'], 'private function snapshot');
    $snapshotEnd = strpos($sources['service'], 'private function recordPayload', $snapshotStart === false ? 0 : $snapshotStart);
    $snapshot = $snapshotStart === false || $snapshotEnd === false ? '' : substr($sources['service'], $snapshotStart, $snapshotEnd - $snapshotStart);
    foreach (['placement_record_id', 'applicant_id', 'admission_application_id', 'matched_academic_program_id', '$academicYearId', "hash('sha256'"] as $field) {
        $expect(str_contains($snapshot, $field), 'Bulk snapshot is missing safe membership material: '.$field);
    }
    foreach (['national_civil_id', 'student_number', 'first_name', 'last_name', 'phone_number', 'email'] as $forbidden) {
        $expect(! str_contains($snapshot, $forbidden), 'Bulk snapshot contains personal data: '.$forbidden);
    }
    $validationPosition = strpos($sources['service'], '$numberKeys =');
    $writePosition = strpos($sources['service'], 'foreach ($prepared as $preparedItem)');
    $expect($validationPosition !== false && $writePosition !== false && $validationPosition < $writePosition, 'Bulk validation must precede every Student write.');
    $expect(str_contains($sources['service'], "'ministry_placement_enrollment_batch_stale'") && str_contains($sources['service'], '$eligibleIds->all() !== $inputIds->all()'), 'Bulk membership/count/snapshot must fail stale.');

    foreach (['ministry_placement.student_enroll', 'ministry_placement.student_enroll_bulk'] as $action) $expect(str_contains($sources['service'], $action), 'Missing Phase 4 audit action: '.$action);
    $auditStart = strpos($sources['service'], 'private function audit');
    $audit = $auditStart === false ? '' : substr($sources['service'], $auditStart);
    foreach (['national_civil_id', 'student_number', 'first_name', 'last_name', 'email', 'phone_number', 'password', 'accepted_preference_text'] as $forbidden) {
        $expect(! str_contains($audit, $forbidden), 'Audit helper contains personal data: '.$forbidden);
    }

    $expect(! str_contains($sources['phase1'].$sources['phase2'].$sources['phase3'], 'Student::query()->create'), 'Student creation leaked into an earlier Ministry phase.');
    foreach (['User::query()->create', 'UserRole::query()->create', 'StudentAcademicTerm::query()->create', 'StudentCourseRegistration::query()->create', 'password_hash', 'student_number' . "' => 'MP"] as $forbiddenWrite) {
        $expect(! str_contains($sources['service'].$sources['controller'], $forbiddenWrite), 'Phase 4 contains a forbidden later-stage write: '.$forbiddenWrite);
    }
    $expect((glob($backendRoot.'/database/migrations/*ministry*placement*') ?: []) === [], 'Ministry Placement migrations remain forbidden.');

    $sqlUpper = strtoupper($sources['preflight']);
    foreach (['PREPARE', 'EXECUTE', 'DEALLOCATE', 'DATABASE()', 'DELIMITER', 'SIGNAL', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE ', 'DROP '] as $forbidden) {
        $expect(! str_contains($sqlUpper, $forbidden), 'Phase 4 preflight is not read-only/phpMyAdmin-safe: '.$forbidden);
    }
    foreach (['STUDENT_ENROLLMENT_DATA_READINESS', 'pending_ready_candidates', 'already_enrolled_records', 'accepted_without_student', 'student_with_nonaccepted_application', 'ministry_enrolled_without_student', 'student_program_mismatch', 'identity_conflict_candidates', 'DATABASE_AND_TABLES', 'REQUIRED_COLUMNS', 'STUDENT_UNIQUENESS', 'KEYS_AND_RELATIONSHIPS', 'STUDENT_REFERENCE_DATA', 'AUTHORIZATION_AND_AUDIT', "SELECT 'OVERALL'", "'READY', 'BLOCKED'", '`alrowad_uni_rust`'] as $required) {
        $expect(str_contains($sources['preflight'], $required), 'Phase 4 preflight is incomplete: '.$required);
    }
    $expect(str_contains($sources['preflight'], "TRIM(BOTH '''' FROM COALESCE(column_default, '')) = 'pending'") && str_contains($sources['preflight'], "TRIM(BOTH '''' FROM COALESCE(column_default, '')) = 'imported'"), 'MariaDB quoted string defaults must be normalized.');
    foreach (['@mp4_student_columns', '@mp4_student_uniqueness', '@mp4_required_foreign_keys', '@mp4_active_student_status', '@mp4_active_academic_levels', '@mp4_authorization_columns', '@mp4_audit_columns', '@mp4_active_permissions', '@mp4_active_pres_root'] as $readyCheck) {
        $expect(str_contains(substr($sources['preflight'], strpos($sources['preflight'], 'SET @mp4_ready'), 900), $readyCheck), 'OVERALL readiness omits: '.$readyCheck);
    }

    $expect(str_contains($sources['page'], "setBatchView('student_enrollment')") && str_contains($sources['page'], '<MinistryStudentEnrollmentPanel'), 'The existing Ministry portal needs its fourth Phase 4 tab.');
    foreach (['ministry-placement-academic-levels', 'student-enrollment', 'enroll-student', 'expected_eligible_count', 'expected_snapshot', 'items', 'createLatestRequestGuard'] as $required) {
        $expect(str_contains($sources['panel'], $required), 'Phase 4 UI contract is incomplete: '.$required);
    }
    $expect(str_contains($sources['panel'], 'role="dialog"') && str_contains($sources['panel'], 'لن يتم إنشاء حساب مستخدم أو كلمة مرور') && str_contains($sources['panel'], 'لن يتم إنشاء حسابات مستخدمين أو كلمات مرور أو تسجيل مقررات'), 'Phase 4 mutations require explicit scope-safe confirmation.');
    $expect(str_contains($sources['panel'], "err.errorCode === 'ministry_placement_enrollment_batch_stale'") && str_contains($sources['panel'], 'setConfirmation(null)'), 'Stale bulk UI must refresh without retrying.');
    foreach (['إنشاء حساب', 'توليد كلمة مرور', 'تسجيل مقررات'] as $forbiddenControl) {
        $expect(! preg_match('/<button[^>]*>[^<]*'.preg_quote($forbiddenControl, '/').'/u', $sources['panel']), 'Phase 4 UI exposes a forbidden control: '.$forbiddenControl);
    }

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) fwrite(STDERR, $error.PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, "Ministry Placement Phase 4 contract passed.\n");
}
