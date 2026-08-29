<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend';
    $paths = [
        'matcher' => $backendRoot.'/app/Support/MinistryProgramMatcher.php',
        'matching_service' => $backendRoot.'/app/Services/MinistryPlacementProgramMatchingService.php',
        'exception' => $backendRoot.'/app/Exceptions/MinistryPlacementException.php',
        'model' => $backendRoot.'/app/Models/MinistryPlacementRecord.php',
        'resource' => $backendRoot.'/app/Http/Resources/MinistryPlacementRecordResource.php',
        'access' => $backendRoot.'/app/Support/MinistryPlacementAccess.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/MinistryPlacementController.php',
        'routes' => $backendRoot.'/routes/api.php',
        'phase1_preflight' => $backendRoot.'/database/sql/ministry-placement/00_preflight.sql',
        'phase2_preflight' => $backendRoot.'/database/sql/ministry-placement/10_phase2_preflight.sql',
        'page' => $frontendRoot.'/src/features/student-affairs/pages/MinistryPlacementsPage.jsx',
        'panel' => $frontendRoot.'/src/features/student-affairs/components/MinistryProgramMatchingPanel.jsx',
        'picker' => $frontendRoot.'/src/features/student-affairs/components/MinistryProgramPickerDialog.jsx',
        'frontend_helper' => $frontendRoot.'/src/features/student-affairs/lib/ministryPlacement.js',
        'request_guard' => $frontendRoot.'/src/features/student-affairs/lib/latestRequestGuard.js',
    ];
    foreach ($paths as $name => $path) {
        if (! is_file($path)) $errors[] = 'Missing Ministry Placement Phase 2 file: '.$name;
    }
    if ($errors !== []) return $errors;

    $sources = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) $errors[] = $message;
    };

    foreach ([
        "Route::get('ministry-placement-programs'",
        "Route::get('ministry-placements/{batch}/program-matching'",
        "Route::post('ministry-placements/{batch}/program-matching/apply-group'",
        "Route::put('ministry-placement-records/{record}/program-match'",
        "Route::delete('ministry-placement-records/{record}/program-match'",
    ] as $route) $expect(str_contains($sources['routes'], $route), 'Missing Phase 2 route: '.$route);
    $expect(! preg_match("/convert-to-applicant[^\n]*MinistryPlacementController::class/", $sources['routes']), 'The Phase 2 matching controller must never own applicant conversion.');
    $expect(str_contains($sources['routes'], "[MinistryPlacementApplicantConversionController::class, 'convert']"), 'Later conversion must remain isolated in its dedicated Phase 3 controller.');

    $expect(str_contains($sources['access'], 'effectivePermissions()->contains') && str_contains($sources['access'], 'hasActualUniversityScope'), 'Phase 2 must retain exact Ministry authority.');
    $expect(! str_contains($sources['access'], 'hasPermission(') && ! str_contains($sources['access'], 'super_admin'), 'Phase 2 access must not use a role bypass.');

    foreach (['locked', 'stale_match', 'matched', 'unmatched'] as $state) {
        $expect(str_contains($sources['model'], "return '".$state."'"), 'Missing fail-closed state: '.$state);
    }
    $locked = strpos($sources['model'], "return 'locked'");
    $stale = strpos($sources['model'], "return 'stale_match'");
    $matched = strpos($sources['model'], "return 'matched'");
    $unmatched = strpos($sources['model'], "return 'unmatched'");
    $expect($locked < $stale && $stale < $matched && $matched < $unmatched, 'Program state precedence is not fail closed.');
    $expect(str_contains($sources['resource'], 'matched_academic_program_id') && str_contains($sources['resource'], 'program_match_state'), 'Record resource lacks program matching context.');
    $expect(str_contains($sources['controller'], "with('matchedAcademicProgram.department.college')"), 'Record listing must eager-load the hierarchy.');

    foreach (['EXACT', 'CONTAINS_PROGRAM_NAME', 'ambiguous', 'missing_preference'] as $token) {
        $expect(str_contains($sources['matcher'], $token), 'Matcher contract is missing '.$token);
    }
    $expect(str_contains($sources['matcher'], 'GENERIC_LEADING_WRAPPERS') && str_contains($sources['matcher'], "'برنامج الإجازة في'"), 'Matcher must derive only the approved official leading-wrapper alias.');
    $expect(str_contains($sources['matcher'], '$this->normalize($wrapper)') && str_contains($sources['matcher'], 'str_starts_with($fullName, $prefix)'), 'Wrapper aliases must use normalized prefix-only matching.');
    foreach (['Levenshtein', 'levenshtein', 'Http::', 'OpenAI', 'curl_'] as $forbidden) {
        $expect(! str_contains($sources['matcher'], $forbidden), 'Matcher contains forbidden fuzzy/external behavior: '.$forbidden);
    }
    $expect(substr_count($sources['matching_service'], '$this->activeProgramsQuery()') <= 4, 'Active program catalog must not be queried inside each group.');
    $groupStart = strpos($sources['matching_service'], 'private function groupPayload');
    $groupEnd = strpos($sources['matching_service'], 'private function activeProgramsQuery', $groupStart ?: 0);
    $groupSource = $groupStart === false || $groupEnd === false ? '' : substr($sources['matching_service'], $groupStart, $groupEnd - $groupStart);
    $expect(! str_contains($groupSource, 'AcademicProgram::') && ! str_contains($groupSource, 'activeProgramsQuery'), 'Suggestion grouping introduces an N+1 program query.');

    foreach (['DB::transaction', 'lockForUpdate', 'ministry_placement.program_match', 'ministry_placement.program_unmatch', 'ministry_placement.program_match_bulk'] as $required) {
        $expect(str_contains($sources['matching_service'], $required), 'Mutation safety is missing: '.$required);
    }
    $expect(str_contains($sources['matching_service'], "\$states->get('unmatched', collect())"), 'Bulk eligibility must be canonical unmatched only.');
    $expect(str_contains($sources['matching_service'], 'eligible->count() !== $expectedEligibleCount') && str_contains($sources['matching_service'], 'groupStale()'), 'Bulk stale-count guard is missing.');
    $expect(str_contains($sources['matching_service'], '$normalizedPreferences->count() !== 1') && str_contains($sources['matching_service'], "if (\$normalizedPreference === '')") && str_contains($sources['matching_service'], 'groupNotBulkMatchable()'), 'Bulk matching must recompute one non-empty normalized preference instead of trusting its hash.');
    $expect(str_contains($sources['matching_service'], "'bulk_matchable' => \$bulkMatchable") && str_contains($sources['matching_service'], "'individual_review_count' => \$individualReviewCount"), 'Missing-preference groups need explicit bulk and individual-review counts.');
    $expect(str_contains($sources['exception'], 'ministry_placement_group_not_bulk_matchable') && str_contains($sources['exception'], 'لا يمكن تطبيق مطابقة جماعية على سجلات لا تحتوي رغبة وزارة محددة.'), 'Missing-preference bulk rejection must use the stable typed error.');
    $expect(! str_contains($sources['matching_service'], "where('processing_status', 'program_matched')->update"), 'Bulk matching must not overwrite human matches.');
    foreach (['previous_program_id', 'new_program_id'] as $required) {
        $expect(str_contains($sources['matching_service'], $required), 'Individual rematch audit is incomplete: '.$required);
    }

    foreach (['Applicant::create', 'AdmissionApplication::create', 'Student::create', 'User::create', 'UserRole::create', 'password'] as $forbidden) {
        $expect(! str_contains($sources['matching_service'].$sources['controller'], $forbidden), 'Cross-phase production write found: '.$forbidden);
    }
    $expect((glob($backendRoot.'/database/migrations/*ministry*placement*') ?: []) === [], 'Ministry migrations are forbidden.');

    foreach (['phase1_preflight', 'phase2_preflight'] as $sqlName) {
        $sqlUpper = strtoupper($sources[$sqlName]);
        foreach (['PREPARE', 'EXECUTE', 'DEALLOCATE', 'DELIMITER', 'SIGNAL', 'DATABASE()', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE ', 'DROP '] as $forbidden) {
            $expect(! str_contains($sqlUpper, $forbidden), $sqlName.' is not phpMyAdmin-safe/read-only: '.$forbidden);
        }
        $expect(str_contains($sources[$sqlName], "SELECT 'OVERALL'"), $sqlName.' lacks visible OVERALL output.');
        $expect(str_contains($sources[$sqlName], "'READY', 'BLOCKED'"), $sqlName.' lacks READY/BLOCKED states.');
        $expect(str_contains($sources[$sqlName], '`alrowad_uni_rust`'), $sqlName.' must use the explicit database.');
    }
    foreach (['MATCHING_DATA_READINESS', 'matched_academic_program_id', 'accepted_preference_text', 'processing_status', 'applicant_id', '@mp2_required_foreign_keys'] as $required) {
        $expect(str_contains($sources['phase2_preflight'], $required), 'Phase 2 preflight is incomplete: '.$required);
    }

    foreach (['مطابقة البرامج', 'program_match_state', 'تعديل المطابقة', 'إزالة المطابقة'] as $required) {
        $expect(str_contains($sources['page'].$sources['panel'], $required), 'Phase 2 UI is missing: '.$required);
    }
    $expect(str_contains($sources['picker'], '/v1/ministry-placement-programs') && str_contains($sources['picker'], 'تأكيد المطابقة'), 'Program selection must use the Ministry endpoint and explicit confirmation.');
    $expect(str_contains($sources['panel'], 'ministry_placement_group_stale') && str_contains($sources['panel'], 'await Promise.all([load(), onChanged?.()])'), 'Stale group conflicts must refresh without blind retry.');
    $expect(str_contains($sources['page'], 'createLatestRequestGuard') && str_contains($sources['page'], 'recordsRequestGuard.current.isCurrent'), 'Record requests must reject stale batch/page/search responses.');
    $expect(str_contains($sources['request_guard'], 'generation') && str_contains($sources['request_guard'], 'sameContext'), 'Latest-request guard must validate both generation and captured context.');
    $expect(str_contains($sources['page'], 'setRecordMatch(null)') && str_contains($sources['page'], 'setRecordUnmatch(null)'), 'Changing batch must clear individual record dialogs.');
    $expect(str_contains($sources['panel'], 'bindSelectionToBatch') && str_contains($sources['panel'], 'selectionForBatch'), 'Group selections must be bound to and checked against their batch.');
    $expect(str_contains($sources['panel'], 'canBulkMatchProgramGroup(canManage, group)') && str_contains($sources['frontend_helper'], 'group?.bulk_matchable === true') && str_contains($sources['panel'], 'لا توجد رغبة — يلزم مراجعة فردية'), 'Missing-preference groups must not expose a bulk action.');
    foreach (['إنشاء طالب', 'تحويل لمتقدم', 'إنشاء حساب'] as $forbidden) {
        $expect(! str_contains($sources['panel'].$sources['picker'], $forbidden), 'Later-phase UI control found in Phase 2 components: '.$forbidden);
    }

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) fwrite(STDERR, $error.PHP_EOL);
        exit(1);
    }
    require_once dirname(__DIR__, 2).'/app/Support/MinistryProgramMatcher.php';
    $matcher = new \App\Support\MinistryProgramMatcher();
    $normalized = $matcher->normalize("  بَرنامجــ   إِدارة، الأعمال  ");
    if ($normalized !== 'برنامج ادارة الاعمال') {
        fwrite(STDERR, "Arabic preference normalization failed: {$normalized}\n");
        exit(1);
    }
    $catalog = [
        ['academic_program_id' => 1, 'program_code' => 'BUS', 'program_name' => 'إدارة الأعمال'],
        ['academic_program_id' => 2, 'program_code' => 'LAW', 'program_name' => 'الحقوق'],
    ];
    $suggestion = $matcher->suggestions('برنامج الإجازة في إدارة الأعمال', $catalog);
    if ($suggestion['suggestion_status'] !== 'unique' || $suggestion['match_tier'] !== 'CONTAINS_PROGRAM_NAME' || $suggestion['candidate_count'] !== 1) {
        fwrite(STDERR, "Deterministic suggestion matching failed.\n");
        exit(1);
    }
    $wrappedCatalog = [
        ['academic_program_id' => 10, 'program_code' => 'BUS', 'program_name' => 'برنامج الإجازة في إدارة الأعمال'],
        ['academic_program_id' => 11, 'program_code' => 'SWE', 'program_name' => 'برنامج الإجازة في هندسة البرمجيات'],
    ];
    $originalCatalog = serialize($wrappedCatalog);
    foreach ([
        ['إدارة الأعمال', 'EXACT', 10],
        ['هندسة البرمجيات', 'EXACT', 11],
        ['قبول عام - إدارة الأعمال', 'CONTAINS_PROGRAM_NAME', 10],
    ] as [$preference, $tier, $programId]) {
        $result = $matcher->suggestions($preference, $wrappedCatalog);
        if ($result['suggestion_status'] !== 'unique' || $result['match_tier'] !== $tier || $result['suggestions'][0]['academic_program_id'] !== $programId) {
            fwrite(STDERR, "Official wrapper alias matching failed for {$preference}.\n");
            exit(1);
        }
    }
    $collision = $matcher->suggestions('إدارة الأعمال', [$wrappedCatalog[0], ['academic_program_id' => 12, 'program_code' => 'BUS2', 'program_name' => 'برنامج الإجازة في إدارة الأعمال']]);
    if ($collision['suggestion_status'] !== 'ambiguous' || $collision['candidate_count'] !== 2 || serialize($wrappedCatalog) !== $originalCatalog) {
        fwrite(STDERR, "Official wrapper collision/original-string safety failed.\n");
        exit(1);
    }
    $nonLeading = $matcher->suggestions('الأعمال', [['academic_program_id' => 13, 'program_code' => 'ODD', 'program_name' => 'إدارة برنامج الإجازة في الأعمال']]);
    if ($nonLeading['suggestion_status'] !== 'no_match') {
        fwrite(STDERR, "Official wrapper must only be stripped at the beginning.\n");
        exit(1);
    }
    foreach ([null, '', " \u{00A0}\t ", '...،؛ !!!'] as $missingPreference) {
        if ($matcher->normalize($missingPreference) !== '' || $matcher->suggestions($missingPreference, $wrappedCatalog)['suggestion_status'] !== 'missing_preference') {
            fwrite(STDERR, "Null/blank/punctuation-only preferences must normalize to missing.\n");
            exit(1);
        }
    }
    fwrite(STDOUT, "Ministry Placement Phase 2 contract passed.\n");
}

return $contract;
