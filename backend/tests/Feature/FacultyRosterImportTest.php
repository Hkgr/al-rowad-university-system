<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdministrativeGovernanceSchema;
use Tests\TestCase;

/**
 * faculty:import-roster over SQLite (schema copied from production, synthetic data).
 * Fixture colleges: A (code A, has dean user 6), B (code B, no dean), C (no unit).
 */
final class FacultyRosterImportTest extends TestCase
{
    use AdministrativeGovernanceSchema;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAdministrativeGovernanceSchema();
        $this->seedAdministrativeGovernanceFixture();
        DB::table('positions')->insert(['position_id' => 2, 'position_code' => 'INSTRUCTOR', 'position_title' => 'Instructor']);
        $this->dir = sys_get_temp_dir().'/roster-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_dry_run_of_the_incomplete_template_writes_nothing_and_flags_every_row(): void
    {
        $file = $this->csv([
            $this->row(1, ['employee_number' => '', 'email' => '']),
            $this->row(2, ['college_code' => '', 'employee_number' => 'N-2', 'email' => 'n2@example.invalid']),
            $this->row(3, ['name_split_confirmed' => '', 'employee_number' => 'N-3', 'email' => 'n3@example.invalid']),
        ]);
        $before = $this->counts();

        $this->artisan('faculty:import-roster', ['file' => $file, '--report' => $this->dir.'/r'])->assertSuccessful();

        self::assertSame($before, $this->counts());
        $report = json_decode(file_get_contents($this->dir.'/r.json'), true);
        self::assertSame(['NEEDS_REVIEW', 'NEEDS_REVIEW', 'NEEDS_REVIEW'], array_column($report['rows'], 'status'));
        self::assertStringContainsString('الرقم الوظيفي', implode(' ', $report['rows'][0]['reasons']));
        self::assertStringContainsString('رمز الكلية', implode(' ', $report['rows'][1]['reasons']));
        self::assertStringContainsString('name_split_confirmed', implode(' ', $report['rows'][2]['reasons']));
    }

    public function test_apply_creates_links_and_refuses_unsafe_rows_then_is_idempotent(): void
    {
        $rows = [
            $this->row(1, ['is_dean' => 'yes', 'college_code' => 'B', 'first_name' => 'عيسى', 'last_name' => 'درويش', 'employee_number' => 'N-1', 'email' => 'N1@Example.invalid']),
            $this->row(2, ['first_name' => 'سيف', 'last_name' => 'قدة', 'employee_number' => 'N-2', 'email' => 'n2@example.invalid', 'username' => 'saif.q']),
            // Existing employee without profile/account: linked by number + matching name.
            $this->row(3, ['first_name' => 'T', 'last_name' => 'Free', 'employee_number' => 'E-20', 'email' => 'free@example.invalid']),
            // Existing employee with an instructor account (same email): no second account.
            $this->row(4, ['first_name' => 'T', 'last_name' => 'Candidate', 'employee_number' => 'E-8', 'email' => 'candidate@alrowad.test']),
            // Number of another person.
            $this->row(5, ['first_name' => 'اسم', 'last_name' => 'آخر', 'employee_number' => 'E-31', 'email' => 'x5@example.invalid']),
            // Same name as an existing employee, no number/email match: review, never merge.
            $this->row(6, ['first_name' => 'T', 'last_name' => 'Loose', 'employee_number' => 'N-6', 'email' => 'n6@example.invalid']),
            // College A already has a dean.
            $this->row(7, ['is_dean' => 'yes', 'first_name' => 'طه', 'last_name' => 'المرشد', 'employee_number' => 'N-7', 'email' => 'n7@example.invalid']),
            // Email of another employee's account.
            $this->row(8, ['first_name' => 'خالد', 'last_name' => 'الخطيب', 'employee_number' => 'N-8', 'email' => 'dean.a@alrowad.test']),
            // Duplicate employee number inside the file.
            $this->row(9, ['first_name' => 'وسيم', 'last_name' => 'الرشيد', 'employee_number' => 'DUP', 'email' => 'n9@example.invalid']),
            $this->row(10, ['first_name' => 'ياسر', 'last_name' => 'معراوي', 'employee_number' => 'DUP', 'email' => 'n10@example.invalid']),
            // super_admin's employee record.
            $this->row(11, ['first_name' => 'T', 'last_name' => 'Admin', 'employee_number' => 'E-1', 'email' => 'admin@alrowad.test']),
        ];
        $file = $this->csv($rows);

        $this->artisan('faculty:import-roster', $this->applyArgs($file, 'a1'))->assertSuccessful();

        $report = json_decode(file_get_contents($this->dir.'/a1.json'), true);
        self::assertArrayNotHasKey('credentials', $report);
        $status = array_column($report['rows'], 'status', 'row_no');
        self::assertSame(['1' => 'CREATE', '2' => 'CREATE', '3' => 'UPDATE_AFFILIATION', '4' => 'UPDATE_AFFILIATION', '5' => 'CONFLICT', '6' => 'NEEDS_REVIEW', '7' => 'CONFLICT', '8' => 'CONFLICT', '9' => 'CONFLICT', '10' => 'CONFLICT', '11' => 'CONFLICT'], $status);
        self::assertSame(4, $report['summary']['ready']);
        self::assertSame(1, $report['summary']['ready_deans']);

        // One employee, one profile, one account per person; accounts linked to the employee.
        foreach (['N-1', 'N-2', 'E-20', 'E-8'] as $number) {
            $employeeId = DB::table('employees')->where('employee_number', $number)->value('employee_id');
            self::assertSame(1, DB::table('faculty_members')->where('employee_id', $employeeId)->count(), $number);
            self::assertSame(1, DB::table('users')->where('employee_id', $employeeId)->count(), $number);
            self::assertSame(1, DB::table('employee_unit_assignments')->where('employee_id', $employeeId)->whereDate('start_date', '2025-01-01')->whereNull('end_date')->count(), $number);
            self::assertNull(DB::table('employees')->where('employee_id', $employeeId)->value('hire_date'));
        }
        self::assertSame(0, DB::table('employees')->whereIn('employee_number', ['N-6', 'N-7', 'N-8', 'DUP'])->count());
        self::assertSame(1, DB::table('users')->where('employee_id', 8)->count(), 'no second account for an existing one');
        self::assertSame(0, DB::table('course_offering_instructors')->count(), 'no teaching assignment is created');
        self::assertSame(4, DB::table('teaching_assignment_requests')->count(), 'no teaching-assignment request is created');

        // Dean = teacher + dean: both roles, one college scope, DEAN position from 2025-01-01.
        $dean = User::query()->where('employee_id', DB::table('employees')->where('employee_number', 'N-1')->value('employee_id'))->firstOrFail();
        self::assertEqualsCanonicalizing(['doctor_instructor', 'dean'], $dean->effectiveRoles()->all());
        self::assertSame([2], DB::table('user_access_scopes')->where('user_id', $dean->user_id)->where('is_active', 1)->pluck('scope_id')->map(fn ($i) => (int) $i)->all());
        self::assertSame(0, DB::table('user_access_scopes')->where('user_id', $dean->user_id)->where('scope_type', 'university')->count());
        self::assertSame('2025-01-01', substr((string) DB::table('employee_positions')->where('employee_id', $dean->employee_id)->where('position_id', 1)->value('start_date'), 0, 10));
        self::assertSame('n1@example.invalid', $dean->email);
        // Execution time, not the effective date, for account/role/audit timestamps.
        self::assertSame(now()->toDateString(), substr((string) $dean->created_at, 0, 10));
        self::assertSame(0, DB::table('user_roles')->where('user_id', $dean->user_id)->whereDate('assigned_at', '<>', now()->toDateString())->count());
        self::assertSame(0, DB::table('user_activity_logs')->whereYear('created_at', 2025)->count());
        // The existing dean of A was not touched.
        self::assertSame(1, (int) DB::table('user_access_scopes')->where('user_id', 6)->where('scope_id', 1)->value('is_active'));

        // Credentials: only for new accounts, unique, strong, hashed on the server.
        $credentials = array_map(fn ($line) => str_getcsv($line, ',', '"', '\\'), file($this->dir.'/a1-cred.csv', FILE_IGNORE_NEW_LINES));
        array_shift($credentials);
        self::assertCount(3, $credentials);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->dir.'/a1-cred.csv')), -4));
        $passwords = array_column($credentials, 5);
        self::assertCount(3, array_unique($passwords));
        foreach ($credentials as [, , , $username, , $password]) {
            self::assertMatchesRegularExpression('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{10,}$/', $password);
            self::assertTrue(Hash::check($password, DB::table('users')->where('username', $username)->value('password_hash')));
            self::assertStringNotContainsString($password, file_get_contents($this->dir.'/a1.json'));
            self::assertStringNotContainsString($password, file_get_contents($this->dir.'/a1.csv'));
            self::assertSame(0, DB::table('user_activity_logs')->where('description', 'like', '%'.$password.'%')->count());
        }
        self::assertSame(0, DB::table('user_activity_logs')->where('description', 'like', '%$2y$%')->count());

        // Re-apply: nothing duplicated, no new accounts or credentials.
        $counts = $this->counts();
        $this->artisan('faculty:import-roster', $this->applyArgs($file, 'a2'))->assertSuccessful();
        self::assertSame($counts, $this->counts());
        self::assertFileDoesNotExist($this->dir.'/a2-cred.csv');
        $again = array_column(json_decode(file_get_contents($this->dir.'/a2.json'), true)['rows'], 'status', 'row_no');
        self::assertSame(['EXISTING_LINK', 'EXISTING_LINK', 'EXISTING_LINK', 'EXISTING_LINK'], [$again['1'], $again['2'], $again['3'], $again['4']]);
    }

    public function test_dean_sees_the_imported_teacher_and_only_his_college(): void
    {
        $file = $this->csv([
            $this->row(1, ['is_dean' => 'yes', 'college_code' => 'B', 'first_name' => 'زيد', 'last_name' => 'حسينو', 'employee_number' => 'N-1', 'email' => 'n1@example.invalid']),
            $this->row(2, ['college_code' => 'B', 'first_name' => 'طاهر', 'last_name' => 'حميدي', 'employee_number' => 'N-2', 'email' => 'n2@example.invalid']),
        ]);
        $this->artisan('faculty:import-roster', $this->applyArgs($file, 'd'))->assertSuccessful();
        $teacher = DB::table('faculty_members as fm')->join('employees as e', 'e.employee_id', '=', 'fm.employee_id')->where('e.employee_number', 'N-2')->value('fm.faculty_member_id');

        Sanctum::actingAs(User::query()->where('username', 'emp.n-1')->firstOrFail());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.college.college_id', 2);
        $visible = collect($this->getJson('/api/v1/teaching-staff?per_page=100')->assertOk()->json('data.data'))->pluck('faculty_member_id')->all();
        self::assertContains((int) $teacher, $visible);
        self::assertNotContains(1, $visible, 'college A teacher is not visible to the college B dean');

        Sanctum::actingAs(User::query()->where('username', 'emp.n-2')->firstOrFail());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.roles', ['doctor_instructor'])->assertJsonPath('data.college', null);
        $this->getJson('/api/v1/teaching-staff')->assertForbidden();
    }

    public function test_explicit_replacement_keeps_the_previous_dean_account(): void
    {
        $file = $this->csv([$this->row(1, ['is_dean' => 'yes', 'replace_current_dean' => 'yes', 'first_name' => 'طه', 'last_name' => 'المرشد', 'employee_number' => 'N-1', 'email' => 'n1@example.invalid'])]);
        $this->artisan('faculty:import-roster', $this->applyArgs($file, 'x'))->assertSuccessful();
        self::assertSame(0, (int) DB::table('user_access_scopes')->where('user_id', 6)->where('scope_id', 1)->value('is_active'));
        self::assertSame(1, DB::table('users')->where('user_id', 6)->count());
        $position = DB::table('employee_positions')->where('employee_id', 6)->first();
        self::assertGreaterThanOrEqual($position->start_date, $position->end_date, 'a position is never closed before it started');
    }

    public function test_actors_must_hold_the_existing_authorizations(): void
    {
        $file = $this->csv([$this->row(1, ['employee_number' => 'N-1', 'email' => 'n1@example.invalid'])]);
        $this->artisan('faculty:import-roster', ['file' => $file, '--apply' => true, '--vp-actor' => 'vp.noscope', '--accounts-actor' => 'admin', '--report' => $this->dir.'/f'])->assertFailed();
        $this->artisan('faculty:import-roster', ['file' => $file, '--apply' => true, '--vp-actor' => 'vp.admin', '--accounts-actor' => 'nobody', '--report' => $this->dir.'/f'])->assertFailed();
        $this->artisan('faculty:import-roster', ['file' => $file, '--apply' => true, '--report' => $this->dir.'/f'])->assertFailed();
        self::assertSame(0, DB::table('employees')->where('employee_number', 'N-1')->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function applyArgs(string $file, string $name): array
    {
        return ['file' => $file, '--apply' => true, '--vp-actor' => 'vp.admin', '--accounts-actor' => 'admin', '--report' => $this->dir.'/'.$name, '--credentials-out' => $this->dir.'/'.$name.'-cred.csv'];
    }

    private function row(int $no, array $overrides = []): array
    {
        return array_merge([
            'row_no' => (string) $no, 'source_college' => 'كلية أ', 'source_name' => 'د اسم '.$no, 'source_designation' => 'معادل', 'source_title' => 'د',
            'equivalence_status_source' => 'معادل', 'is_dean' => 'no', 'college_code' => 'A', 'first_name' => 'اسم', 'last_name' => 'رقم'.$no,
            'name_split_confirmed' => 'yes', 'employee_number' => '', 'email' => '', 'username' => '', 'replace_current_dean' => 'no', 'operator_notes' => '',
        ], $overrides);
    }

    private function csv(array $rows): string
    {
        $path = $this->dir.'/roster-'.bin2hex(random_bytes(3)).'.csv';
        $handle = fopen($path, 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, \App\Services\FacultyRosterImportService::COLUMNS, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column], \App\Services\FacultyRosterImportService::COLUMNS), ',', '"', '\\');
        }
        fclose($handle);

        return $path;
    }

    private function counts(): array
    {
        return array_map(fn ($table) => DB::table($table)->count(), ['employees' => 'employees', 'faculty_members' => 'faculty_members', 'users' => 'users', 'user_roles' => 'user_roles', 'user_access_scopes' => 'user_access_scopes', 'employee_unit_assignments' => 'employee_unit_assignments', 'employee_positions' => 'employee_positions']);
    }
}
