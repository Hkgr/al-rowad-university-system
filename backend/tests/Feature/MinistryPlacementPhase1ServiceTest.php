<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MinistryPlacementService;
use App\Support\MinistryPlacementAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MinistryPlacementPhase1ServiceTest extends TestCase
{
    private MinistryPlacementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026-2027']);
        DB::table('users')->insert(['user_id' => 7, 'username' => 'operator']);
        $this->service = app(MinistryPlacementService::class);
    }

    public function test_preview_is_read_only_and_blank_title_is_only_a_warning(): void
    {
        $file = $this->workbook([$this->validRow('00123456789')], '');
        $preview = $this->service->preview($file);

        self::assertSame(1, $preview['valid_rows']);
        self::assertContains('blank_title_row', $preview['warnings']);
        self::assertSame([], $preview['structural_errors']);
        self::assertSame(0, DB::table('ministry_placement_batches')->count());
        self::assertSame(0, DB::table('ministry_placement_records')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_empty_data_area_cannot_be_imported_as_an_empty_batch(): void
    {
        $preview = $this->service->preview($this->workbook([]));

        self::assertContains('no_data_rows', $preview['structural_errors']);
        self::assertSame(0, $preview['rows_count']);
    }

    public function test_access_requires_direct_permission_and_actual_university_scope_without_role_bypass(): void
    {
        DB::table('roles')->insert([
            ['role_id' => 1, 'role_code' => 'registration_officer', 'is_active' => 1],
            ['role_id' => 2, 'role_code' => 'super_admin', 'is_active' => 1],
        ]);
        DB::table('permissions')->insert([
            ['permission_id' => 1, 'permission_code' => 'admissions.view', 'is_active' => 1],
            ['permission_id' => 2, 'permission_code' => 'admissions.manage', 'is_active' => 1],
        ]);
        DB::table('role_permissions')->insert([
            ['role_permission_id' => 1, 'role_id' => 1, 'permission_id' => 1],
            ['role_permission_id' => 2, 'role_id' => 1, 'permission_id' => 2],
        ]);
        DB::table('user_roles')->insert(['user_role_id' => 1, 'user_id' => 7, 'role_id' => 1, 'is_active' => 1]);
        $access = app(MinistryPlacementAccess::class);
        $actor = User::query()->findOrFail(7);
        self::assertFalse($access->canView($actor));
        self::assertFalse($access->canManage($actor));

        DB::table('organizational_units')->insert(['organizational_unit_id' => 10, 'unit_code' => 'PRES']);
        DB::table('user_access_scopes')->insert(['user_access_scope_id' => 1, 'user_id' => 7, 'scope_type' => 'university', 'scope_id' => 10, 'is_active' => 1]);
        self::assertTrue($access->canView($actor));
        self::assertTrue($access->canManage($actor));

        DB::table('users')->insert(['user_id' => 8, 'username' => 'root-role-only']);
        DB::table('user_roles')->insert(['user_role_id' => 2, 'user_id' => 8, 'role_id' => 2, 'is_active' => 1]);
        self::assertFalse($access->canView(User::query()->findOrFail(8)));
    }

    public function test_duplicate_comparison_normalizes_digits_and_whitespace_without_changing_stored_text(): void
    {
        $file = $this->workbook([
            $this->validRow('٠٠١٢٣٤٥٦٧٨٩'),
            $this->validRow("00123\u{00A0}456789"),
        ]);
        $preview = $this->service->preview($file);

        self::assertSame(2, $preview['duplicate_rows']);
        self::assertSame('٠٠١٢٣٤٥٦٧٨٩', $preview['normalized_preview_rows'][0]['national_civil_id']);
        self::assertSame("00123\u{00A0}456789", $preview['normalized_preview_rows'][1]['national_civil_id']);
        self::assertSame(['duplicate', 'duplicate'], array_column($preview['normalized_preview_rows'], 'status'));
    }

    public function test_clean_import_preserves_precision_and_creates_one_safe_activity_record(): void
    {
        $file = $this->workbook([$this->validRow('00123456789')]);
        $actor = User::query()->findOrFail(7);
        $batch = $this->service->import($file, [
            'batch_name' => 'دفعة اختبار',
            'academic_year_id' => 1,
            'notes' => 'مراجعة تشغيلية',
        ], $actor);

        self::assertSame(1, DB::table('ministry_placement_batches')->count());
        self::assertSame(1, DB::table('ministry_placement_records')->count());
        $record = DB::table('ministry_placement_records')->first();
        self::assertSame('00123456789', $record->national_civil_id);
        self::assertSame('00004215', $record->subscription_number);
        self::assertSame('123.456', number_format((float) $record->total_score, 3, '.', ''));
        self::assertSame('imported', $record->processing_status);

        $logs = DB::table('user_activity_logs')->get();
        self::assertCount(1, $logs);
        self::assertSame('admissions', $logs[0]->module_code);
        self::assertSame('ministry_placement.import', $logs[0]->action_code);
        self::assertSame([
            'batch_id' => (int) $batch->batch_id,
            'academic_year_id' => 1,
            'record_count' => 1,
        ], json_decode($logs[0]->description, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_audit_failure_rolls_back_batch_and_every_record(): void
    {
        Schema::drop('user_activity_logs');
        $file = $this->workbook([$this->validRow('00123456789')]);

        try {
            $this->service->import($file, ['batch_name' => 'دفعة', 'academic_year_id' => 1], User::query()->findOrFail(7));
            self::fail('Expected the missing audit table to fail the transaction.');
        } catch (\Throwable) {
            self::assertSame(0, DB::table('ministry_placement_batches')->count());
            self::assertSame(0, DB::table('ministry_placement_records')->count());
        }
    }

    public function test_invalid_and_ambiguous_values_are_reported_instead_of_becoming_null_or_false(): void
    {
        $row = $this->validRow('00123456789');
        $row[13] = 'ربما';
        $row[16] = '03/04/2005';
        $preview = $this->service->preview($this->workbook([$row]));

        self::assertSame(1, $preview['invalid_rows']);
        self::assertContains('invalid_boolean', $preview['normalized_preview_rows'][0]['errors']['is_faculty_member_child']);
        self::assertContains('ambiguous_date', $preview['normalized_preview_rows'][0]['errors']['date_of_birth']);
    }

    public function test_excel_serial_blank_rows_required_names_and_score_range_are_explicit(): void
    {
        $serial = $this->validRow('00123456789');
        $serial[16] = '45000';
        $validPreview = $this->service->preview($this->workbook([$serial, array_fill(0, 24, '')]));
        self::assertSame(1, $validPreview['valid_rows']);
        self::assertSame(1, $validPreview['ignored_blank_rows']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $validPreview['normalized_preview_rows'][0]['date_of_birth']);

        $invalid = $this->validRow('00123456780');
        $invalid[3] = '1000.000';
        $invalid[21] = '';
        $invalid[19] = '';
        $preview = $this->service->preview($this->workbook([$invalid]));
        self::assertContains('required', $preview['normalized_preview_rows'][0]['errors']['first_name']);
        self::assertContains('required', $preview['normalized_preview_rows'][0]['errors']['last_name']);
        self::assertContains('invalid_score', $preview['normalized_preview_rows'][0]['errors']['total_score']);
    }

    /** @return array<int, mixed> */
    private function validRow(string $nationalId): array
    {
        return [
            '0990000000', 'student@example.test', '240.000', '123.456', 'دمشق', 'سورية', '2026',
            '00004215', 'ثانوية عامة', 'عام', 'هندسة معلوماتية', 'علمي', 'المفاضلة العامة',
            'لا', 'نعم', 'سوري', '13/04/2005', 'ذكر', 'الأم', 'الكنية', 'الأب', 'الاسم', '1', $nationalId,
        ];
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function workbook(array $rows, string $title = 'نتائج مفاضلة الوزارة'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', $title);
        foreach (\App\Imports\MinistryPlacementImport::COLUMN_MAP as $index => $field) {
            $sheet->setCellValue([$index + 1, 2], $field);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 3], (string) $value, DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'ministry-placement-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'ministry-placement.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function createSchema(): void
    {
        Schema::create('academic_years', fn (Blueprint $table) => $table->increments('academic_year_id')->string('year_name'));
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('username');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('role_id');
            $table->string('role_code');
            $table->boolean('is_active');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->increments('permission_id');
            $table->string('permission_code');
            $table->boolean('is_active');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->increments('role_permission_id');
            $table->integer('role_id');
            $table->integer('permission_id');
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->increments('user_role_id');
            $table->integer('user_id');
            $table->integer('role_id');
            $table->boolean('is_active');
        });
        Schema::create('organizational_units', function (Blueprint $table): void {
            $table->increments('organizational_unit_id');
            $table->string('unit_code')->nullable();
        });
        Schema::create('user_access_scopes', function (Blueprint $table): void {
            $table->increments('user_access_scope_id');
            $table->integer('user_id');
            $table->string('scope_type');
            $table->integer('scope_id');
            $table->boolean('is_active');
        });
        Schema::create('ministry_placement_batches', function (Blueprint $table): void {
            $table->increments('batch_id');
            $table->string('batch_name');
            $table->string('source_file_name')->nullable();
            $table->integer('academic_year_id');
            $table->date('import_date');
            $table->integer('imported_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('ministry_placement_records', function (Blueprint $table): void {
            $table->increments('placement_record_id');
            $table->integer('batch_id');
            $table->integer('row_number')->nullable();
            foreach (['national_civil_id', 'subscription_number', 'first_name', 'last_name', 'father_name', 'mother_name', 'gender', 'nationality', 'phone_number', 'email', 'certificate_type', 'certificate_source_country', 'directorate', 'accepted_preference_text', 'track', 'placement_round_name', 'registration_type', 'processing_status'] as $column) $table->string($column)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->integer('certificate_grant_year')->nullable();
            $table->decimal('total_score', 6, 3)->nullable();
            $table->decimal('max_total_score', 6, 3)->nullable();
            $table->integer('matched_academic_program_id')->nullable();
            $table->boolean('is_faculty_member_child')->default(false);
            $table->boolean('has_academic_sequence')->default(false);
            $table->integer('applicant_id')->nullable();
            $table->timestamps();
        });
        Schema::create('user_activity_logs', function (Blueprint $table): void {
            $table->bigIncrements('activity_log_id');
            $table->integer('user_id');
            $table->string('module_code', 80)->nullable();
            $table->string('action_code', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
