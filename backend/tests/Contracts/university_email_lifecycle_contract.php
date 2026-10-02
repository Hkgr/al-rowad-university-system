<?php
declare(strict_types=1);
// Source boundaries supplement, never replace, HTTP and independent MariaDB connection tests.
$root = dirname(__DIR__, 2);
$read = fn (string $path): string => file_get_contents($root.'/'.$path);
$check = function (bool $ok, string $why): void { if (! $ok) throw new RuntimeException($why); };
$service = $read('app/Services/UniversityEmailProvisioningService.php');
$client = $read('app/Services/MailcowProvisioningClient.php');
$scope = $read('app/Services/DataScopeService.php');
$access = $read('app/Support/UniversityEmailAccess.php');
$migration = $read('database/migrations/2026_10_02_000000_add_university_email_deletion_lifecycle.php');
$check(str_contains($access, "DELETE = 'university_email.delete'") && str_contains($access, "'delete' => self::DELETE"), 'Independent deletion authority');
$start = strpos($scope, 'public function scopeUniversityEmailStudents(');
$end = strpos($scope, "\n    public function ", $start + 1) ?: strlen($scope);
$check(str_contains(substr($scope, $start, $end - $start), 'UniversityEmailAccess::allows') && str_contains($scope, 'scopeActualAcademicStudents'), 'Email-only central access; academic scope remains independent');
$check(str_contains($client, "\$this->success(\$this->request('POST', 'delete/mailbox', [\$address]), 'mailbox_removed', \$address)") && !str_contains($client, '->retry('), 'One official Mailcow removal request, never transport retry');
$check(str_contains($service, '\$box === null') || str_contains($service, '$box === null'), 'Read-verified absence required');
$check(str_contains($service, "'creation_operation_id' => null, 'credential_operation_id' => null") && str_contains($service, 'lifecycle_revision = $email->revision'), 'Current cycle references and monotonic authority boundary');
$check(str_contains($service, "'provisioning_status' => 'deleted'") && str_contains($service, "'deleted_by_user_id' => \$op->issued_by_user_id"), 'Local lifecycle, historical deletion actor');
$check(!preg_match('/->(?:delete|forceDelete)\(/', $service) && !str_contains($migration, 'Schema::drop'), 'No local history deletion');
$check(str_contains($service, 'documentGenerator(User::findOrFail($op->issued_by_user_id))') && str_contains($service, "'issued_by_user_id' => \$user->user_id"), 'Credential issuer in document, downloader in audit');
$check(str_contains($migration, "'deleted'") && str_contains($migration, "'delete'") && str_contains($migration, 'rollback refused'), 'Additive ENUM migration and explicit unsafe rollback refusal');
$check(!preg_match('/\$t->\w+\([\'\"](?:password|credential_proof)[\'\"]/', $migration), 'No secret schema');
echo "University email lifecycle source contract passed (static only)\n";
