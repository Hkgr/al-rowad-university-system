<?php

/** Loopback-only test harness, not a public route. Uses a fresh temporary SQLite DB. */
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

$database = getenv('SCIENTIFIC_CATALOG_TEST_DB');
$directory = $database ? realpath(dirname($database)) : false;
$temporaryRoot = realpath(sys_get_temp_dir());
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)
    || !$directory || strcasecmp($directory, $temporaryRoot) !== 0
    || !preg_match('/^scientific-catalog-[a-f0-9-]+\.sqlite$/', basename($database))
    || (PHP_SAPI === 'cli-server' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) {
    http_response_code(403);
    exit('Isolated temporary SQLite / loopback required.');
}
$initializing = PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--init';
if ($initializing) {
    $newFile = @fopen($database, 'x'); // Refuse to overwrite any existing fixture.
    if (!$newFile) exit('Fixture already exists; choose a new temporary path.');
    fclose($newFile);
} elseif (!is_file($database)) {
    exit('Initialize the isolated fixture first.');
}
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'SESSION_DRIVER' => 'array',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr',
    'APP_CONFIG_CACHE' => $database.'.no-config-cache'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== $database
    || config('database.connections.sqlite.url')) {
    throw new RuntimeException('Refusing non-fixture connection.');
}
if ($initializing) {
    \Tests\Support\ScientificCatalogFixture::initialize();
    foreach (['university', 'college', 'department'] as $i => $scope) {
        foreach (['mandatory', 'elective'] as $j => $type) {
            DB::table('academic_requirement_groups')->insert([
                'requirement_group_id' => 2 * $i + $j + 1, 'academic_program_id' => 1,
                'group_code' => 'TEST-'.$i.$j, 'group_name' => 'متطلبات اختبارية',
                'requirement_scope' => $scope, 'requirement_type' => $type,
                'required_credit_hours' => $i === 0 && $j === 0 ? 3 : 0,
            ]);
        }
    }
    DB::table('program_courses')->insert(['program_course_id' => 1, 'academic_program_id' => 1,
        'course_id' => 1, 'course_type' => 'mandatory', 'academic_level_id' => 1, 'recommended_semester_id' => 1]);
    DB::table('program_course_requirement_groups')->insert(['program_course_id' => 1, 'requirement_group_id' => 1]);
    DB::table('program_courses')->insert(['program_course_id' => 2, 'academic_program_id' => 2,
        'course_id' => 2, 'course_type' => 'mandatory']);
    DB::table('students')->insert(['student_id' => 1, 'academic_program_id' => 2]);
    echo "Initialized isolated catalog fixture.\n";
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__fixture/state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['courses' => DB::table('courses')->get(), 'memberships' => DB::table('program_courses')->get(),
        'groups' => DB::table('academic_requirement_groups')->get(), 'audit_count' => DB::table('user_activity_logs')->count()]);
    exit;
}
if (!str_starts_with($path, '/api/v1/vice-presidency/scientific/course-management/')) {
    http_response_code(404); exit;
}
// Test authentication only. Real Laravel middleware, access policy, SQL services and writes run.
Sanctum::actingAs(\App\Models\User::findOrFail(1));
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
