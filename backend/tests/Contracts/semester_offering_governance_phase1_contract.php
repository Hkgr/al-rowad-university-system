<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend/src';
    $paths = [
        'preflight' => $backendRoot.'/database/sql/semester-offering-governance-phase1/00_preflight.sql',
        'apply' => $backendRoot.'/database/sql/semester-offering-governance-phase1/01_apply.sql',
        'verify' => $backendRoot.'/database/sql/semester-offering-governance-phase1/02_verify.sql',
        'opening' => $backendRoot.'/app/Services/CourseOfferingOpeningService.php',
        'gate' => $backendRoot.'/app/Services/SemesterOfferingNormalOpenGate.php',
        'workflow' => $backendRoot.'/app/Services/SemesterOfferingGovernanceService.php',
        'coverage' => $backendRoot.'/app/Services/CourseOfferingInstructorCoverageService.php',
        'dean' => $backendRoot.'/app/Services/DeanRegistrationOfferingService.php',
        'generic_offerings' => $backendRoot.'/app/Http/Controllers/Api/CourseOfferingController.php',
        'routes' => $backendRoot.'/routes/api.php',
        'frontend_dean' => $frontendRoot.'/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx',
        'frontend_scientific' => $frontendRoot.'/features/vice-presidency/pages/SemesterOfferingDetail.jsx',
        'frontend_routes' => $frontendRoot.'/app/App.jsx',
    ];
    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing Phase 1 contract source: '.$name;
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $source = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };

    $packageFiles = array_map('basename', glob($backendRoot.'/database/sql/semester-offering-governance-phase1/*') ?: []);
    sort($packageFiles);
    $expect($packageFiles === ['00_preflight.sql', '01_apply.sql', '02_verify.sql'], 'SQL package layout must contain exactly the three approved scripts.');
    $expect(str_contains($source['preflight'], 'USE `alrowad_uni_rust`') && str_contains($source['verify'], 'USE `alrowad_uni_rust`'), 'SQL scripts must explicitly target alrowad_uni_rust.');
    foreach (['preflight', 'verify'] as $readOnly) {
        $sql = $source[$readOnly];
        foreach (['PREPARE', 'EXECUTE', 'DELIMITER', 'SIGNAL', 'DATABASE()', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'INSERT INTO', 'UPDATE `', 'DELETE FROM'] as $forbidden) {
            $expect(! str_contains(strtoupper($sql), strtoupper($forbidden)), $readOnly.' must remain read-only/phpMyAdmin-safe: '.$forbidden);
        }
    }
    $expect(str_contains($source['preflight'], "SELECT 'OVERALL' AS report_section, IF(@sog_ready,'READY','BLOCKED')"), 'Preflight must end with visible READY/BLOCKED.');
    $expect(str_contains($source['verify'], "SELECT 'OVERALL' report_section, IF(@sog_ok,'PASS','FAIL')"), 'Verify must end with visible PASS/FAIL.');
    foreach (['semester_offering_requests', 'semester_offering_reviews', 'semester_offering_events'] as $table) {
        $expect(str_contains($source['apply'], 'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`'.$table.'`'), 'Apply is missing '.$table.'.');
    }
    foreach (['materialized_at', 'uq_sor_offering', 'uq_sorv_request_version', 'chk_sor_materialization', 'chk_sorv_provenance', 'scientific_returned', 'scientific_approved', 'materialized'] as $invariant) {
        $expect(str_contains($source['apply'], $invariant), 'SQL persistence contract is missing '.$invariant.'.');
    }
    $expect(str_contains($source['preflight'], '@sog_target_shape=31') && str_contains($source['preflight'], '@sog_target_fks=8') && str_contains($source['preflight'], '@sog_target_fk_rules=8') && str_contains($source['preflight'], '@sog_target_checks=13'), 'Preflight must semantically classify all required columns, restrictive FKs, and checks.');
    $expect(str_contains($source['preflight'], '@sog_core_tables=23') && str_contains($source['preflight'], '@sog_core_columns=89') && str_contains($source['preflight'], "event_type_code='course_registration' AND is_active=1"), 'Preflight must protect the complete curriculum, teaching-assignment, RBAC-write, and Academic Calendar prerequisites.');
    $expect(str_contains($source['verify'], '@sog_shape=31') && str_contains($source['verify'], '@sog_unique=2') && str_contains($source['verify'], '@sog_indexes=6') && str_contains($source['verify'], '@sog_fks=8') && str_contains($source['verify'], '@sog_fk_rules=8') && str_contains($source['verify'], '@sog_checks=13'), 'Verify must protect exact compatible shapes and fixed metadata counts.');
    $expect(substr_count($source['apply'], 'ON UPDATE RESTRICT ON DELETE RESTRICT') === 8, 'All eight parent/audit foreign keys must be restrictive.');
    foreach (['course_offerings.semester_governance.view', 'course_offerings.semester_governance.manage', 'course_offerings.semester_governance.review_scientific'] as $permission) {
        $expect(str_contains($source['apply'], $permission), 'Missing governance permission '.$permission.'.');
    }
    $expect(str_contains($source['apply'], "r.role_code='dean'") && str_contains($source['apply'], "r.role_code='vice_president_scientific'"), 'RBAC mappings must be limited to Dean and Scientific VP responsibilities.');
    $expect(! preg_match("/vice_president_administrative[^\n]{0,250}semester_governance/", $source['apply']), 'Administrative VP must receive no governance permission.');

    // Server-derived applicability: request absence never determines applicability.
    $expect(str_contains($source['gate'], "->where('academic_program_id', \$lockedOffering->academic_program_id)") && str_contains($source['gate'], "->where('course_id', \$lockedOffering->course_id)") && str_contains($source['gate'], "->where('is_active', true)"), 'Normal-opening applicability must derive from current ProgramCourse identity.');
    $expect(str_contains($source['gate'], 'if ($programCourses->count() !== 1)') && str_contains($source['gate'], 'curriculumUnavailable()'), 'Missing or ambiguous current curriculum membership must fail closed.');
    $expect(strpos($source['gate'], 'schemaReady()') < strpos($source['gate'], 'if ($proof === null)'), 'Schema readiness must fail before a missing proof can be treated as normal workflow state.');
    $expect(str_contains($source['gate'], 'semester_offering_schema_not_ready') || str_contains($source['gate'], 'schemaNotReady()'), 'Missing expected governance schema must surface the controlled readiness failure.');
    $expect(str_contains($source['gate'], 'approvalRequired()') && str_contains($source['gate'], '$request->materialized_at !== null'), 'Missing and consumed approvals must never open an applicable offering.');
    $expect(str_contains($source['opening'], '$unchangedOpen = $originalStatus === self::STATUS_OPEN') && str_contains($source['opening'], 'if ($unchangedOpen)'), 'Already-open legacy offerings must remain grandfathered/idempotent.');
    $expect(substr_count($source['opening'], '$this->semesterGovernance->authorize($locked, $semesterProof)') >= 2, 'Every CLOSED to OPEN shape must pass the central governance gate.');
    preg_match_all('/\$this->semesterGovernance->authorize\(\$locked, \$semesterProof\)/', $source['opening'], $openingGateMatches, PREG_OFFSET_CAPTURE);
    preg_match_all('/\$this->coverage->assertCompleteForNormalOpening\(\$locked\)/', $source['opening'], $openingCoverageMatches, PREG_OFFSET_CAPTURE);
    $expect(count($openingGateMatches[0] ?? []) >= 2 && count($openingCoverageMatches[0] ?? []) >= 2
        && $openingGateMatches[0][0][1] < $openingCoverageMatches[0][0][1]
        && $openingGateMatches[0][1][1] < $openingCoverageMatches[0][1][1], 'Governance readiness/proof must fail closed before coverage evaluation in both normal CLOSED to OPEN shapes.');
    $expect(str_contains($source['generic_offerings'], '$this->opening->applyThenGuardOpenCoverage(') && str_contains($source['generic_offerings'], "'status' => CourseOfferingOpeningService::STATUS_CLOSED"), 'Generic create/update paths must create CLOSED and delegate all possible normal opening to the central gate.');
    $expect(str_contains($source['dean'], '$this->opening->normalOpen($offering, $user)') && ! str_contains($source['dean'], 'new SemesterOfferingOpeningProof'), 'Legacy Dean normal-open endpoint must not fabricate governance proof or bypass the central gate.');
    $exceptionStart = strpos($source['opening'], 'public function openFromApprovedException');
    $exceptionEnd = strpos($source['opening'], 'private function assertCurrentApprovedReview', $exceptionStart ?: 0);
    $exceptionMethod = ($exceptionStart !== false && $exceptionEnd !== false) ? substr($source['opening'], $exceptionStart, $exceptionEnd - $exceptionStart) : '';
    $expect($exceptionMethod !== '' && ! str_contains($exceptionMethod, 'semesterGovernance'), 'Exceptional opening must remain separate and unaffected.');

    $approveStart = strpos($source['workflow'], 'public function approve');
    $returnStart = strpos($source['workflow'], 'public function returnForEditing', $approveStart ?: 0);
    $approve = ($approveStart !== false && $returnStart !== false) ? substr($source['workflow'], $approveStart, $returnStart - $approveStart) : '';
    $expect($approve !== '', 'Scientific approval method is missing.');
    $positions = array_map(static fn (string $needle) => strpos($approve, $needle), [
        '$this->lockOffering($offeringId)',
        '->lockForUpdate()',
        '$this->lockCurrentReview($request)',
        '$this->lockCoverageGraph($lockedOffering)',
        '$this->opening->normalOpen($lockedOffering, $actor, $proof)',
    ]);
    $expect(! in_array(false, $positions, true), 'Approval must include the canonical offering/request/review/coverage/open sequence.');
    $expect($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3] && $positions[3] < $positions[4], 'Approval lock/use ordering must be offering then request/review then coverage then opening.');
    $expect(str_contains($source['workflow'], '$this->coverage->assertCompleteForNormalOpening($lockedOffering)') && ! str_contains($source['workflow'], "status', 'approved'"), 'Governance must delegate coverage to the canonical coverage service without reinterpreting teaching status.');
    $expect(str_contains($source['gate'], '$request->materialized_at = now()') && str_contains($source['workflow'], 'DB::transaction(function () use ($actor, $routeRequest)'), 'Approval consumption and opening must share the approval transaction.');
    $expect(str_contains($source['workflow'], "['first', 'second']") && str_contains($source['workflow'], "\$semesterCode === 'summer'") && str_contains($source['workflow'], "\$courseType === 'elective'"), 'Proposal validation must protect regular mandatory and summer/elective minimum rules.');
    $expect(substr_count($source['workflow'], 'effectivePermissions()->contains(') >= 2 && ! str_contains($source['workflow'], 'hasPermission('), 'Governance mutations must require directly effective assigned permissions without the super-admin permission shortcut.');
    $expect(str_contains($source['workflow'], 'hasActualUniversityScope($actor)') && str_contains($source['workflow'], 'canAccessProgram($actor, $programId)'), 'Scientific and Dean governance must retain actual university/program DataScope checks.');
    $expect(str_contains($source['dean'], "->where('course_type', 'mandatory')") && ! preg_match('/course_type[^\n]{0,180}recommended_semester_id/', $source['dean']), 'Regular mandatory preparation must not be gated by advisory semester metadata.');
    $expect(substr_count($source['dean'], 'if ($regularSemester)') >= 2, 'Non-selected regular modes must not auto-select electives; regular electives require explicit selected mode.');
    $expect(str_contains($source['dean'], "semester_code === 'summer' && \$mode !== 'selected'"), 'Summer preparation must require explicit selected-mode choices.');

    foreach (['/proposal', '/submit', 'vice-presidency/scientific/semester-offerings', '/approve', '/return'] as $route) {
        $expect(str_contains($source['routes'], $route), 'Missing governance API route fragment '.$route.'.');
    }
    $expect(str_contains($source['frontend_dean'], 'إرسال للاعتماد العلمي') && ! str_contains($source['frontend_dean'], 'تأكيد فتح المادة'), 'Dean UI must submit governance rather than directly open normal offerings.');
    $expect(str_contains($source['frontend_dean'], 'الحد الأدنى للتسجيل') && str_contains($source['frontend_dean'], 'ملاحظة الإعادة'), 'Dean UI must expose minimum and return-state correction context.');
    $expect(str_contains($source['frontend_scientific'], 'إعادة للتعديل') && str_contains($source['frontend_scientific'], 'اعتماد'), 'Scientific UI must expose per-offering decisions.');
    $expect(str_contains($source['frontend_routes'], 'actualUniversityScope: true') && str_contains($source['frontend_scientific'], 'semesterOfferingGovernanceReviewScientific'), 'Scientific route/actions must require actual role, assigned permission, and actual university scope.');

    $expect((glob($backendRoot.'/database/migrations/*semester*offering*governance*') ?: []) === [], 'Phase 1 must not add a Laravel migration.');
    $expect(! str_contains(strtolower($source['workflow'].$source['dean']), 'academic_plan_approval'), 'Phase 1 must not invent academic-plan approval/versioning.');

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
    fwrite(STDOUT, "Semester offering governance Phase 1 contract passed.\n");
}

return $contract;
