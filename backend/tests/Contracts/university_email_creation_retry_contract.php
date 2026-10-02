<?php
declare(strict_types=1);
// Static safety boundaries supplement the real HTTP regression suite.
$root = dirname(__DIR__, 2);
$service = file_get_contents($root.'/app/Services/UniversityEmailProvisioningService.php');
$controller = file_get_contents($root.'/app/Http/Controllers/Api/UniversityEmailProvisioningController.php');
$routes = file_get_contents($root.'/routes/api.php');
$check = function (bool $ok, string $why): void { if (! $ok) throw new RuntimeException($why); };
foreach (['retryCreate', 'checkCreation'] as $method) $check(str_contains($controller, 'public function '.$method.'('), 'Explicit authorized entry point: '.$method);
$check(str_contains($routes, "Route::post('retry-create', 'retryCreate')") && str_contains($routes, "Route::post('creation-check', 'checkCreation')"), 'POST only orchestration');
$check(!preg_match("/Route::get\('(retry-create|creation-check)'/", $routes), 'No GET side effects');
$check(str_contains($service, "\$op->kind !== 'create'") && str_contains($service, "\$email->provisioning_status === 'created'"), 'Retry cannot cancel management or confirmed mailbox history');
$check(str_contains($service, "\$this->cancel(\$user, \$student, \$op->operation_id, \$previous['generation'])"), 'Reuse canonical cancellation with exact previous generation');
$check(str_contains($service, "\$op->generation !== \$generation || \$op->write_started_at"), 'Write authority and generation remain authoritative');
$check(str_contains($service, '$op->generation++;') && str_contains($service, "'operation_cancelled'"), 'Invalidation and audit preserved');
$start = strpos($service, 'public function checkCreation(');
$end = strpos($service, 'public function resetNow(', $start);
$read = substr($service, $start, $end - $start);
$check(str_contains($read, '$this->reconcile(') && ! preg_match('/->(execute|create|cancel|password|delete)\(/', $read), 'Automatic check reuses only remote-read reconciliation');
$start = strpos($service, 'private function createWithPreparation(');
$end = strpos($service, 'private function describe(', $start);
$create = substr($service, $start, $end - $start);
$check(strpos($create, '$state = $this->execute(') > strpos($create, '});'), 'Remote writes only after preparation commit');
$check(!preg_match('/Http::|->retry\(|->delete\(|Log::|session\(/', $create), 'No remote transport, automatic retry, compensation or secret persistence');
echo "University email safe retry source contract passed (static only)\n";
