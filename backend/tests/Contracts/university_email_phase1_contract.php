<?php

$contract = static function (string $root): array {
    $errors = [];
    $expect = static function (bool $pass, string $label) use (&$errors): void { if (! $pass) $errors[] = $label; };
    $read = static fn (string $path): string => file_get_contents($root.'/'.$path);
    $mailcow = $read('app/Services/MailcowReadService.php');
    $service = $read('app/Services/UniversityEmailService.php');
    $migration = $read('database/migrations/2026_10_01_000000_create_student_university_emails.php');
    $access = $read('app/Support/UniversityEmailAccess.php');
    $provision = $read('app/Console/Commands/EnableUniversityEmailPermissions.php');
    $expect(str_contains($provision, "DB::table('system_modules')") && ! str_contains($provision, "DB::table('modules')"), 'Actual RBAC system_modules table');
    $expect(str_contains($mailcow, "'allow_redirects' => false") && str_contains($mailcow, "'verify' => true"), 'TLS and redirect safety');
    $expect(str_contains($mailcow, 'connectTimeout(5)->timeout(15)'), 'Bounded transport');
    $expect(str_contains($mailcow, '/api/v1/get/domain/') && ! preg_match('/->(?:post|put|patch|delete)\(/', $mailcow), 'Mailcow read-only fixed path');
    $expect(! str_contains($mailcow, '->throw(') && ! str_contains($mailcow, 'Log::'), 'No upstream exception/body logging');
    $expect(str_contains($mailcow, 'JSON_THROW_ON_ERROR') && str_contains($mailcow, 'mailcow_invalid_response'), 'JSON semantic validation');
    $expect(str_contains($access, 'effectivePermissions()') && ! str_contains($access, 'hasPermission(') && str_contains($access, 'ROLE_TECHNICAL_TEAM'), 'Assigned technical RBAC only');
    $expect(str_contains($service, 'scopeManualGradeStudents') && str_contains($service, 'lockForUpdate()') && str_contains($service, 'university_email_stale'), 'Explicit scopes and monotonic revision with student lock');
    $expect(! preg_match('/(?:MailcowReadService|Http::|password|student->save|student->update)/', $service), 'Local drafts independent of Mailcow and student/login writes');
    $expect(str_contains($migration, "integer('student_id')->unique()") && str_contains($migration, "string('email_address', 254)->unique()"), 'Student/address uniqueness and signed key');
    $expect(str_contains($migration, "integer('created_by_user_id')") && str_contains($migration, 'restrictOnDelete()'), 'Compatible signed restrictive audit keys');
    $expect(! preg_match('/password|cascadeOnDelete|DB::table\([^)]*students[^)]*\)->update/', $migration), 'Non-destructive independent storage');
    $expect(str_contains($service, "'draft'") && str_contains($service, "'not_delivered'"), 'Draft never means created or delivered');
    return $errors;
};
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    echo $errors ? implode("\n", $errors)."\n" : "University email Phase 1 source contract passed (static checks only).\n";
    exit($errors ? 1 : 0);
}
return $contract;
