<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\StudentDisciplineFixture;

// Isolated synthetic acceptance fixture. Never uses a configured university database.
$database = $argv[1] ?? '';
$output = $argv[2] ?? '';
if (! str_starts_with(basename($database), 'student-discipline-') || ! str_ends_with($database, '.sqlite') || $output === '') {
    throw new InvalidArgumentException('Provide a student-discipline-*.sqlite file and a private credentials output outside Git.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
if (! file_exists($database)) {
    touch($database);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
StudentDisciplineFixture::install();
$identities = [];
foreach (['operator' => ['registration_officer', ['students.view', 'students.manage'], 'program'],
    'vp' => ['vice_president_administrative', ['students.view', 'students.manage', 'vice_presidency.administrative.access'], 'university'],
    'reader' => ['registration_officer', ['students.view'], 'program']] as $key => [$role, $permissions, $scope]) {
    $actor = StudentDisciplineFixture::actor($role, $permissions, $scope);
    $identities[$key] = ['token' => $actor->createToken('synthetic-local-acceptance')->plainTextToken,
        'user' => ['user_id' => $actor->user_id, 'name' => 'حساب اختبار اصطناعي', 'roles' => [$role], 'permissions' => $permissions, 'access_scopes' => [['type' => $scope, 'id' => 1]]]];
}
file_put_contents($output, json_encode($identities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo "Synthetic SQLite fixture prepared. Credentials remain outside Git.\n";
