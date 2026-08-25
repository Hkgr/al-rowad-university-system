<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend';
    $paths = [
        'importer' => $backendRoot.'/app/Imports/MinistryPlacementImport.php',
        'normalizer' => $backendRoot.'/app/Support/MinistryPlacementNormalizer.php',
        'service' => $backendRoot.'/app/Services/MinistryPlacementService.php',
        'access' => $backendRoot.'/app/Support/MinistryPlacementAccess.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/MinistryPlacementController.php',
        'routes' => $backendRoot.'/routes/api.php',
        'preflight' => $backendRoot.'/database/sql/ministry-placement/00_preflight.sql',
        'composer' => $backendRoot.'/composer.json',
        'api_client' => $frontendRoot.'/src/services/apiClient.js',
        'page' => $frontendRoot.'/src/features/student-affairs/pages/MinistryPlacementsPage.jsx',
        'app' => $frontendRoot.'/src/app/App.jsx',
        'nav' => $frontendRoot.'/src/features/student-affairs/nav.js',
    ];
    foreach ($paths as $name => $path) {
        if (! is_file($path)) $errors[] = 'Missing Ministry Placement Phase 1 file: '.$name;
    }
    if ($errors !== []) return $errors;

    $sources = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) $errors[] = $message;
    };

    $expectedMap = [
        'phone_number', 'email', 'max_total_score', 'total_score', 'directorate',
        'certificate_source_country', 'certificate_grant_year', 'subscription_number',
        'certificate_type', 'registration_type', 'accepted_preference_text', 'track',
        'placement_round_name', 'is_faculty_member_child', 'has_academic_sequence',
        'nationality', 'date_of_birth', 'gender', 'mother_name', 'last_name',
        'father_name', 'first_name', 'row_number', 'national_civil_id',
    ];
    foreach ($expectedMap as $index => $field) {
        $expect(str_contains($sources['importer'], $index." => '".$field."'"), 'Missing 24-column mapping at '.$index.': '.$field);
    }
    $expect(str_contains($sources['importer'], "for (\$rowNumber = 3;"), 'Data must begin at row 3.');
    $expect(str_contains($sources['importer'], "\$warnings[] = 'blank_title_row'"), 'Blank row 1 must be a warning.');
    $expect(str_contains($sources['service'], 'HEADER_ANCHORS') && str_contains($sources['service'], 'invalid_header_anchor_'), 'Row 2 anchors must be enforced.');
    $expect(str_contains($sources['normalizer'], "'٠' => '0'") && str_contains($sources['normalizer'], "'۰' => '0'"), 'Both Arabic digit sets must normalize for duplicate comparison.');
    $expect(str_contains($sources['normalizer'], 'duplicateKey') && str_contains($sources['normalizer'], "preg_replace('/[\\s\\p{Z}]+/u', '', \$asciiDigits)"), 'Duplicate key must normalize Unicode whitespace.');
    $expect(! str_contains($sources['normalizer'], '(int) $identifier'), 'Identifiers must never be cast to integers.');
    $expect(str_contains($sources['service'], "'duplicate_national_civil_id'"), 'All duplicate IDs must be reported.');
    $expect(str_contains($sources['normalizer'], "'ambiguous_date'"), 'Ambiguous DD/MM dates must fail.');
    $expect(str_contains($sources['normalizer'], "'invalid_boolean'"), 'Unknown booleans must fail.');
    $expect(str_contains($sources['service'], 'array_chunk($records, 500)') && str_contains($sources['service'], 'DB::transaction'), 'Import must use one transaction and chunked inserts.');

    foreach (['preview', 'import', 'index', 'show', 'records'] as $action) {
        $expect(str_contains($sources['controller'], 'function '.$action.'('), 'Missing controller action: '.$action);
    }
    $expect(! str_contains($sources['controller'], 'first_parsed_row_debug'), 'Raw debug row leakage is forbidden.');
    $expect(str_contains($sources['controller'], "'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']"), 'Record pagination must cap per_page at 100.');
    foreach (['match-program', 'convert-to-applicant'] as $forbidden) {
        $expect(! str_contains($sources['routes'], $forbidden), 'Phase 1 exposes forbidden endpoint: '.$forbidden);
    }
    $expect(str_contains($sources['access'], 'effectivePermissions()->contains') && str_contains($sources['access'], 'hasActualUniversityScope'), 'Authorization must use assigned permission and actual university scope.');
    $expect(! str_contains($sources['access'], 'hasPermission(') && ! str_contains($sources['access'], 'super_admin'), 'Ministry authorization must not use role bypasses.');

    $auditStart = strpos($sources['service'], 'UserActivityLog::query()->create');
    $auditEnd = strpos($sources['service'], 'return $batch', $auditStart === false ? 0 : $auditStart);
    $audit = $auditStart === false || $auditEnd === false ? '' : substr($sources['service'], $auditStart, $auditEnd - $auditStart);
    $expect(str_contains($audit, "'module_code' => 'admissions'") && str_contains($audit, "'action_code' => 'ministry_placement.import'"), 'Successful import audit codes are missing.');
    foreach (['national_civil_id', 'subscription_number', 'phone_number', 'email', 'first_name', 'last_name', 'notes', 'source_file_name'] as $sensitive) {
        $expect(! str_contains($audit, $sensitive), 'Audit description contains forbidden metadata: '.$sensitive);
    }
    $expect(strpos($sources['service'], 'UserActivityLog::query()->create') > strpos($sources['service'], "DB::table('ministry_placement_records')->insert"), 'Audit must be the final transactional write.');

    $sqlUpper = strtoupper($sources['preflight']);
    foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE ', 'DROP ', 'SIGNAL', 'DELIMITER', 'DATABASE()'] as $forbidden) {
        $expect(! str_contains($sqlUpper, $forbidden), 'Preflight is not read-only: '.$forbidden);
    }
    $expect(str_contains($sources['preflight'], "SELECT 'OVERALL', IF(@mp_ready, 'READY', 'BLOCKED')"), 'Preflight must visibly end READY/BLOCKED.');
    $expect(! preg_match('/COUNT\(\*\)\s*=\s*40/', $sources['preflight']), 'Preflight must not require exactly 40 total Ministry columns.');
    $expect(str_contains($sources['preflight'], '@mp_batch_required_columns') && str_contains($sources['preflight'], '@mp_record_required_columns'), 'Preflight must validate required columns semantically.');

    $expect(str_contains($sources['api_client'], 'options.body instanceof FormData') && str_contains($sources['api_client'], "!isFormData ? { 'Content-Type': 'application/json' }"), 'apiRequest must distinguish FormData and JSON.');
    $expect(str_contains($sources['page'], 'فحص الملف') && str_contains($sources['page'], 'اعتماد واستيراد الدفعة'), 'Preview-first UI is incomplete.');
    foreach (['ربط برنامج', 'تحويل لمتقدم', 'إنشاء طالب'] as $forbidden) {
        $expect(! str_contains($sources['page'], $forbidden), 'Phase 1 UI contains a later-stage control: '.$forbidden);
    }
    $expect(str_contains($sources['app'], '/student-affairs/ministry-placements') && str_contains($sources['nav'], '/student-affairs/ministry-placements'), 'Student Affairs route/nav is missing.');
    $expect(str_contains($sources['composer'], '"maatwebsite/excel": "^4.0"'), 'Excel dependency is missing.');
    $expect((glob($backendRoot.'/database/migrations/*ministry*placement*') ?: []) === [], 'Ministry Placement migrations are forbidden.');

    foreach (['Applicant::', 'AdmissionApplication::', 'Student::', 'UserRole::'] as $forbidden) {
        $expect(! str_contains($sources['service'], $forbidden), 'Import creates an out-of-scope entity: '.$forbidden);
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
    $arabic = \App\Support\MinistryPlacementNormalizer::duplicateKey(' ٠٠١٢٣٤٥٦٧٨٩ ');
    $ascii = \App\Support\MinistryPlacementNormalizer::duplicateKey("00123\u{00A0}456789");
    if ($arabic !== $ascii || $ascii !== '00123456789') {
        fwrite(STDERR, "Duplicate comparison normalization failed.\n");
        exit(1);
    }
    if (\App\Support\MinistryPlacementNormalizer::text(' ٠٠١٢٣ ') !== '٠٠١٢٣') {
        fwrite(STDERR, "Stored identifier normalization changed its digits.\n");
        exit(1);
    }
    fwrite(STDOUT, "Ministry Placement Phase 1 contract passed.\n");
}

return $contract;
