<?php
// Synthetic fixture for the owner-payroll browser verification (frontend/tests/browser/owner-payroll.mjs).
// Run from backend/ against a DEDICATED, DISPOSABLE database (it drops and recreates the auth and payroll tables):
//   OWNER_PAYROLL_FIXTURE_CONFIRM=disposable DB_CONNECTION=mysql DB_DATABASE=payroll_browser ... php tests/Support/owner_payroll_browser_fixture.php /path/tokens.json
// Creates synthetic accounts only (super admin, owner, president, HR) and writes their API tokens to the given file.
// No production data, no real credentials.
chdir(dirname(__DIR__, 2));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Schema,Artisan};
// Destructive by design: only for a throwaway database whose name says so.
$database = (string) DB::connection()->getDatabaseName();
if (getenv('OWNER_PAYROLL_FIXTURE_CONFIRM') !== 'disposable' || ! preg_match('/(browser|test|scratch|dev)/i', $database)) {
    fwrite(STDERR, "Refusing to run: set OWNER_PAYROLL_FIXTURE_CONFIRM=disposable and use a database whose name contains browser/test/scratch/dev (got '{$database}').\n");
    exit(1);
}
use Illuminate\Database\Schema\Blueprint;
$defs = [
 'account_statuses' => fn(Blueprint $t)=>[$t->integer('account_status_id')->primary(),$t->string('status_code'),$t->string('status_name')->nullable(),$t->boolean('is_active')->default(true),$t->timestamps()],
 'users' => fn(Blueprint $t)=>[$t->integer('user_id')->primary(),$t->string('username'),$t->string('email'),$t->string('password_hash'),$t->integer('account_status_id'),$t->integer('student_id')->nullable(),$t->integer('employee_id')->nullable(),$t->integer('board_member_id')->nullable(),$t->dateTime('last_login_at')->nullable(),$t->dateTime('email_verified_at')->nullable(),$t->integer('failed_login_attempts')->default(0),$t->integer('created_by_user_id')->nullable(),$t->timestamps()],
 'system_modules' => fn(Blueprint $t)=>[$t->increments('module_id'),$t->string('module_code')->unique(),$t->string('module_name'),$t->string('description')->nullable(),$t->boolean('is_active')->default(true),$t->timestamps()],
 'roles' => fn(Blueprint $t)=>[$t->increments('role_id'),$t->string('role_code')->unique(),$t->string('role_name'),$t->string('description')->nullable(),$t->boolean('is_system_role')->default(false),$t->boolean('is_active')->default(true),$t->timestamps()],
 'permissions' => fn(Blueprint $t)=>[$t->increments('permission_id'),$t->integer('module_id'),$t->string('permission_code')->unique(),$t->string('permission_name'),$t->string('description')->nullable(),$t->boolean('is_active')->default(true),$t->timestamps()],
 'role_permissions' => fn(Blueprint $t)=>[$t->increments('role_permission_id'),$t->integer('role_id'),$t->integer('permission_id'),$t->dateTime('granted_at')->nullable()],
 'user_roles' => fn(Blueprint $t)=>[$t->increments('user_role_id'),$t->integer('user_id'),$t->integer('role_id'),$t->integer('assigned_by_user_id')->nullable(),$t->dateTime('assigned_at')->nullable(),$t->boolean('is_active')->default(true)],
 'user_access_scopes' => fn(Blueprint $t)=>[$t->increments('user_access_scope_id'),$t->integer('user_id'),$t->string('scope_type'),$t->integer('scope_id'),$t->boolean('is_active')->default(true),$t->timestamps()],
 'colleges' => fn(Blueprint $t)=>[$t->integer('college_id')->primary(),$t->integer('organizational_unit_id')->nullable(),$t->string('college_code'),$t->string('college_name'),$t->text('description')->nullable(),$t->boolean('is_active')->default(true),$t->timestamps()],
 'departments' => fn(Blueprint $t)=>[$t->integer('department_id')->primary(),$t->integer('college_id'),$t->integer('organizational_unit_id')->nullable(),$t->string('department_code'),$t->string('department_name'),$t->boolean('is_active')->default(true),$t->timestamps()],
 'academic_programs' => fn(Blueprint $t)=>[$t->integer('academic_program_id')->primary(),$t->integer('department_id'),$t->string('program_code'),$t->string('program_name'),$t->boolean('is_active')->default(true),$t->timestamps()],
];
Schema::disableForeignKeyConstraints();
foreach (array_reverse(array_keys($defs)) as $t) Schema::dropIfExists($t);
foreach (['payroll_entries','payroll_employees','payroll_bodies','personal_access_tokens'] as $t) Schema::dropIfExists($t);
foreach ($defs as $name => $def) Schema::create($name, $def);
(require base_path('database/migrations/2026_06_13_131705_create_personal_access_tokens_table.php'))->up();
(require base_path('database/migrations/2026_10_08_000000_create_owner_payroll_tables.php'))->up();
DB::table('account_statuses')->insert([['account_status_id'=>1,'status_code'=>'active'],['account_status_id'=>2,'status_code'=>'disabled']]);
foreach (['super_admin','university_president','hr_officer','technical_team'] as $i=>$c) DB::table('roles')->insert(['role_id'=>$i+1,'role_code'=>$c,'role_name'=>$c,'is_system_role'=>1]);
Artisan::call('owner-portal:provision-access'); // creates role university_owner (id 5)
$users=[1=>['admin.synthetic',1],2=>['owner.synthetic',5],3=>['president.synthetic',2],4=>['hr.synthetic',3]];
foreach($users as $id=>[$u,$role]){ DB::table('users')->insert(['user_id'=>$id,'username'=>$u,'email'=>"$u@example.invalid",'password_hash'=>'x','account_status_id'=>1]); DB::table('user_roles')->insert(['user_id'=>$id,'role_id'=>$role,'is_active'=>1]); }
$out=[];
foreach([1=>'admin',2=>'owner',3=>'president',4=>'hr'] as $id=>$k){ $u=App\Models\User::find($id); $out[$k]=$u->createToken('dev')->plainTextToken; }
file_put_contents($argv[1] ?? 'owner-payroll-tokens.json', json_encode($out));
echo "seeded\n";
