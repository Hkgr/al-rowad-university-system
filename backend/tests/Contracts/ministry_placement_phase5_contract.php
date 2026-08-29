<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend';
    $paths = [
        'service' => $backendRoot.'/app/Services/MinistryPlacementReconciliationService.php',
        'request' => $backendRoot.'/app/Http/Requests/MinistryPlacement/ReconcileMinistryPlacementRequest.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/MinistryPlacementReconciliationController.php',
        'ministry_controller' => $backendRoot.'/app/Http/Controllers/Api/MinistryPlacementController.php',
        'routes' => $backendRoot.'/routes/api.php',
        'phase1_sql' => $backendRoot.'/database/sql/ministry-placement/00_preflight.sql',
        'phase2_sql' => $backendRoot.'/database/sql/ministry-placement/10_phase2_preflight.sql',
        'phase3_sql' => $backendRoot.'/database/sql/ministry-placement/20_phase3_preflight.sql',
        'phase4_sql' => $backendRoot.'/database/sql/ministry-placement/30_phase4_preflight.sql',
        'phase5_sql' => $backendRoot.'/database/sql/ministry-placement/40_phase5_reconciliation.sql',
        'docs' => dirname($backendRoot).'/docs/ministry-placement-production-readiness.md',
        'page' => $frontendRoot.'/src/features/student-affairs/pages/MinistryPlacementsPage.jsx',
        'panel' => $frontendRoot.'/src/features/student-affairs/components/MinistryReconciliationPanel.jsx',
        'add_student' => $frontendRoot.'/src/features/student-affairs/pages/AddStudentPage.jsx',
        'auth' => $frontendRoot.'/src/features/auth/auth.js',
        'nav' => $frontendRoot.'/src/features/student-affairs/nav.js',
        'app' => $frontendRoot.'/src/app/App.jsx',
    ];
    foreach ($paths as $name => $path) {
        if (! is_file($path)) $errors[] = 'Missing Ministry Placement Phase 5 file: '.$name;
    }
    if ($errors !== []) return $errors;
    $sources = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) $errors[] = $message;
    };

    foreach ([
        "Route::get('ministry-placement-reconciliation'",
        "Route::get('ministry-placements/{batch}/reconciliation'",
    ] as $route) $expect(str_contains($sources['routes'], $route), 'Missing GET reconciliation route: '.$route);
    $expect(! preg_match("/Route::(post|put|patch|delete)\([^\n]*reconciliation/", $sources['routes']), 'Phase 5 must not expose a reconciliation mutation route.');
    $expect(str_contains($sources['request'], 'MinistryPlacementAccess') && str_contains($sources['request'], 'canView'), 'Reconciliation must use existing Ministry view authority.');
    $expect(str_contains($sources['request'], 'array_diff(array_keys($this->query->all()), $allowed)'), 'Reconciliation query input must reject unknown keys.');
    foreach (['batch_id', 'severity', 'pipeline_state', 'issue_code', 'page', 'per_page'] as $field) {
        $expect(str_contains($sources['request'], "'{$field}'"), 'Missing reconciliation query field: '.$field);
    }
    $expect(str_contains($sources['request'], "'max:100'") && str_contains($sources['request'], "'min:1'"), 'Pagination must be positive and capped at 100.');

    foreach (['imported', 'matched', 'applicant_pending', 'documents_pending', 'enrolled', 'rejected', 'inconsistent'] as $state) {
        $expect(str_contains($sources['service'], "'{$state}'"), 'Missing derived state: '.$state);
    }
    foreach (['identity_conflict_terminal_record', 'identity_conflict_multiple_terminal_records', 'identity_conflict', 'identity_missing_terminal_record', 'historical_program_hierarchy_inactive', 'ministry_state_chain_mismatch', 'orphan_expected_applicant', 'orphan_expected_application', 'orphan_expected_student'] as $issue) {
        $expect(str_contains($sources['service'], "'{$issue}'"), 'Missing Phase 5 issue: '.$issue);
    }
    $expect(str_contains($sources['service'], 'MinistryPlacementNormalizer::duplicateKey'), 'Identity reconciliation must reuse duplicateKey().');
    $expect(str_contains($sources['service'], '$terminalCount >= 2') && str_contains($sources['service'], "'identity_conflict_multiple_terminal_records'"), 'Multiple coherent terminal identities must block.');
    $expect(strpos($sources['service'], "'identity_conflict_multiple_terminal_records'") < strpos($sources['service'], "'identity_conflict_terminal_record'"), 'Multiple-terminal identity issue must precede terminal warning.');
    $expect(str_contains($sources['service'], "['accepted', 'rejected']") && str_contains($sources['service'], "\$processing === 'enrolled'"), 'Decision and canonical enrolled semantics are incomplete.');
    $expect(str_contains($sources['service'], "'accepted', 'enrolled', 'rejected'") && str_contains($sources['service'], "return 'inconsistent'"), 'Noncanonical accepted processing state must fail closed.');
    $expect(str_contains($sources['service'], 'expectedApplications->count() > 1') && str_contains($sources['service'], 'expectedApplications->sole()'), 'Exact application ambiguity must fail closed.');
    $expect(! preg_match('/expectedApplications[^;]{0,400}->(first|latest|oldest)\s*\(/s', $sources['service']), 'Exact application ambiguity must not be guessed.');
    $expect(str_contains($sources['service'], 'Student::withTrashed()'), 'Soft-deleted Students must participate in reconciliation.');
    $expect(str_contains($sources['service'], '$candidateApplicantIds') && str_contains($sources['service'], "whereIn('applicant_id', \$candidateApplicantIds->all())"), 'Expected orphan Applicants and their downstream rows must be bulk-loaded.');
    $expect(str_contains($sources['service'], '$record->applicant_id === null && $expectedApplicant !== null'), 'Orphan reporting must not substitute the deterministic Applicant for the durable link.');
    $expect(str_contains($sources['service'], "orderBy('batch_id')") && str_contains($sources['service'], "orderBy('placement_record_id')"), 'Record ordering must be deterministic.');
    $expect(str_contains($sources['service'], "hash('sha256'") && str_contains($sources['service'], 'issueChecksumMaterial'), 'Checksums must use deterministic safe issue material.');
    foreach (['related_batch_id', 'related_record_id', 'related_applicant_id', 'related_application_id', 'related_student_id'] as $safeRelationship) {
        $expect(str_contains($sources['service'], "'{$safeRelationship}'"), 'Checksum omits safe relationship key: '.$safeRelationship);
    }
    $checksumStart = strpos($sources['service'], 'private function checksum');
    $checksumEnd = strpos($sources['service'], 'private function auditCoverage', $checksumStart ?: 0);
    $checksum = $checksumStart === false || $checksumEnd === false ? '' : substr($sources['service'], $checksumStart, $checksumEnd - $checksumStart);
    foreach (['national_civil_id', 'applicant_number', 'student_number', 'first_name', 'last_name', 'phone_number', 'email', 'date_of_birth'] as $pii) {
        $expect(! str_contains($checksum, $pii), 'Checksum contains personal data: '.$pii);
    }
    foreach (['DB::transaction', 'lockForUpdate', '::create(', '->create(', '->update(', '->delete(', '->insert(', 'save(', 'UserRole'] as $write) {
        $expect(! str_contains($sources['service'].$sources['controller'], $write), 'Phase 5 read service contains a write/lock primitive: '.$write);
    }
    $expect(str_contains($sources['service'], "'ministry_placement.program_match_bulk'") && str_contains($sources['service'], "selectRaw('action_code, COUNT(*) AS action_count')"), 'Audit coverage must include Phase 2 bulk action counts.');
    $expect(! str_contains(substr($sources['service'], strpos($sources['service'], 'private function auditCoverage')), 'description'), 'Audit coverage must not expose descriptions.');

    $sqlPaths = ['phase1_sql', 'phase2_sql', 'phase3_sql', 'phase4_sql', 'phase5_sql'];
    foreach ($sqlPaths as $name) {
        $sql = $sources[$name];
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (['PREPARE', 'EXECUTE', 'DEALLOCATE PREPARE', 'DELIMITER', 'SIGNAL', 'DATABASE()'] as $forbidden) {
            $expect(! str_contains(strtoupper($withoutComments), $forbidden), $name.' is not phpMyAdmin-safe: '.$forbidden);
        }
        $expect(! preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b/im', $withoutComments), $name.' contains DDL/DML.');
    }
    $expect(str_contains($sources['phase1_sql'], "TRIM(BOTH '''' FROM COALESCE(column_default, '')) = 'imported'"), 'Phase 1 imported default must be MariaDB-safe.');
    $expect(str_contains($sources['phase3_sql'], "TRIM(BOTH '''' FROM COALESCE(column_default, '')) = 'pending'"), 'Phase 3 pending default must be MariaDB-safe.');
    $expect(! preg_match("/column_default\s*=\s*'(?:imported|pending)'/i", $sources['phase1_sql'].$sources['phase3_sql']), 'Naive quoted-string default comparison remains.');
    foreach (['alrowad_uni_rust', 'RELATIONAL_DATA_GATE', 'INFORMATIONAL_NON_AUTHORITATIVE', 'MinistryPlacementNormalizer::duplicateKey()', 'APPLICATION_GATE_REQUIRED', "SELECT 'OVERALL'", "'READY', 'BLOCKED'", 'NONCANONICAL_ACCEPTED_MINISTRY_STATUS'] as $required) {
        $expect(str_contains($sources['phase5_sql'], $required), 'Phase 5 SQL report is incomplete: '.$required);
    }
    foreach (['SELECT COUNT(*) = 40', 'SELECT COUNT(*) = 20', '= 22', '@mp5_required_indexes', '@mp5_ministry_identity_uniqueness', '@mp5_applicant_number_uniqueness', '@mp5_student_uniqueness'] as $requiredContract) {
        $expect(str_contains($sources['phase5_sql'], $requiredContract), 'Phase 5 combined structural contract is incomplete: '.$requiredContract);
    }

    foreach (['five deliberately separate stages', 'production_gate=READY', 'identity_conflict_multiple_terminal_records', 'no repair', 'canonical identity authority'] as $required) {
        $expect(str_contains($sources['docs'], $required), 'Production readiness documentation is incomplete: '.$required);
    }
    $expect(str_contains($sources['page'], "setBatchView('reconciliation')") && str_contains($sources['page'], '<MinistryReconciliationPanel'), 'The fifth final-audit tab is missing.');
    $expect(str_contains($sources['page'], '<MinistryGlobalReconciliationCard'), 'The global production gate is missing.');
    $expect(str_contains($sources['panel'], 'identity_conflict_multiple_terminal_records') && str_contains($sources['panel'], 'SHA-256'), 'UI must distinguish terminal blockers and show checksum.');
    foreach (['إصلاح', 'دمج', 'تجاوز', 'إنشاء حسابات', 'تسجيل مقررات'] as $boundary) {
        $expect(str_contains($sources['panel'], $boundary), 'Read-only UI boundary missing: '.$boundary);
    }
    $expect(! preg_match('/<button[^>]*>[^<]*(إصلاح|دمج|تجاوز|فرض)/u', $sources['panel']), 'Reconciliation panel exposes a repair control.');

    $expect(str_contains($sources['add_student'], 'رفع طلاب المفاضلة') && str_contains($sources['add_student'], "navigate('/student-affairs/ministry-placements')"), 'Add Student must provide the Ministry entry action.');
    $expect(str_contains($sources['add_student'], 'hasAssignedPermission(PERMISSIONS.admissionsManage)') && str_contains($sources['add_student'], 'hasActualUniversityScope()'), 'Ministry import entry must require assigned manage plus actual university scope.');
    $expect(! str_contains($sources['nav'], '/student-affairs/ministry-placements'), 'Ministry Placement must not be in Student Affairs navigation.');
    $expect(! str_contains($sources['nav'].$sources['app'], 'ministryPlacementNav'), 'Dedicated Ministry-only navigation must be removed.');
    $expect(str_contains($sources['app'], '<DashboardLayout nav={studentAffairsNav}') && str_contains($sources['page'], 'العودة إلى إضافة طالب'), 'Ministry page must use the normal shell and explicit return action.');
    $expect(str_contains($sources['app'], 'ACCESS.studentAffairsAddStudent') && str_contains($sources['auth'], 'PERMISSIONS.admissionsManage') && str_contains($sources['add_student'], 'canCreateManualStudent'), 'Add Student entry must preserve manual authority and require Ministry manage authority.');
    $expect(! preg_match('/studentAffairsAddStudent[\s\S]{0,250}admissionsView/', $sources['auth']), 'Admissions view-only authority must not open Add Student.');
    foreach (['studentAffairs', 'studentAffairsAddStudent', 'studentAffairsArchivedStudents', 'studentAffairsApprovedRegistrationRequests'] as $accessContract) {
        $expect(str_contains($sources['nav'], 'ACCESS.'.$accessContract), 'Student Affairs nav item lacks explicit route-parity access: '.$accessContract);
    }
    $expect(str_contains($sources['nav'], "allPermissions: ['students.view'], allRoles: ['registration_officer'], assignedPermissions: ['supplementary_exams.registrations.view']"), 'Supplementary registration nav must match its nested route authority.');
    $registrationLanding = strpos($sources['auth'], "hasRole(ROLES.registrationOfficer, user)");
    $courseRegistrationLanding = strpos($sources['auth'], 'canAccess(ACCESS.courseRegistration, user)');
    $expect($registrationLanding !== false && $courseRegistrationLanding !== false && $registrationLanding < $courseRegistrationLanding, 'registration_officer must land in Student Affairs before generic course-registration fallback.');
    $expect(str_contains($sources['routes'], "Route::get('ministry-placement-academic-years'") && str_contains($sources['ministry_controller'], 'public function academicYears') && str_contains($sources['ministry_controller'], 'canManage'), 'Ministry-specific academic-year catalog is missing or too broad.');
    $expect(str_contains($sources['page'], '/v1/ministry-placement-academic-years') && str_contains($sources['page'], 'Promise.allSettled'), 'Initial loading must use the Ministry catalog with granular failures.');

    $expect((glob($backendRoot.'/database/migrations/*ministry*') ?: []) === [], 'Phase 5 must add no Ministry migration.');

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) fwrite(STDERR, $error.PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, "Ministry Placement Phase 5 contract passed.\n");
}

return $contract;
