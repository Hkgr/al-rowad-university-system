<?php
/** Real Laravel + disposable MariaDB browser bridge. Test authentication, not a deployed route. */
if (PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true)) { http_response_code(403); exit; }
require __DIR__.'/CatalogEnvironment.php';
$env=new CatalogEnvironment;
$app=$env->laravel();
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/__fixture/state' && $_SERVER['REQUEST_METHOD']==='GET') {
    header('Content-Type: application/json');
    echo json_encode(['courses'=>Illuminate\Support\Facades\DB::table('courses')->get(), 'memberships'=>Illuminate\Support\Facades\DB::table('program_courses')->get(), 'groups'=>Illuminate\Support\Facades\DB::table('academic_requirement_groups')->get(), 'audit_count'=>Illuminate\Support\Facades\DB::table('user_activity_logs')->count()]); exit;
}
if (!str_starts_with($path,'/api/v1/vice-presidency/scientific/course-management/')) { http_response_code(404); exit; }
Laravel\Sanctum\Sanctum::actingAs(App\Models\User::findOrFail(1));
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$request=Illuminate\Http\Request::capture();
$response=$kernel->handle($request); $response->send(); $kernel->terminate($request,$response);
