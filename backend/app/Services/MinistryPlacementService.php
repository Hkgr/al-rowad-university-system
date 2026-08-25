<?php

namespace App\Services;

use App\Exceptions\MinistryPlacementException;
use App\Imports\MinistryPlacementImport;
use App\Models\MinistryPlacementBatch;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Support\MinistryPlacementNormalizer as Normalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class MinistryPlacementService
{
    private const HEADER_ANCHORS = [
        0 => ['phone_number', 'phone', 'رقم الهاتف', 'الهاتف', 'رقم الموبايل'],
        1 => ['email', 'البريد الإلكتروني', 'البريد الالكتروني'],
        2 => ['max_total_score', 'المجموع الأعظمي', 'المجموع الاعظمي', 'العلامة العظمى'],
        3 => ['total_score', 'المجموع', 'العلامة'],
        7 => ['subscription_number', 'رقم الاكتتاب'],
        10 => ['accepted_preference_text', 'الرغبة المقبولة', 'الرغبة'],
        16 => ['date_of_birth', 'تاريخ الميلاد'],
        19 => ['last_name', 'الكنية', 'اسم العائلة', 'الاسم الأخير', 'الاسم الاخير'],
        21 => ['first_name', 'الاسم', 'الاسم الأول', 'الاسم الاول'],
        22 => ['row_number', 'رقم السطر', 'التسلسل', 'م'],
        23 => ['national_civil_id', 'الرقم الوطني', 'رقم الهوية', 'الرقم المدني'],
    ];

    private const STRING_LIMITS = [
        'national_civil_id' => 50,
        'subscription_number' => 50,
        'first_name' => 100,
        'last_name' => 100,
        'father_name' => 100,
        'mother_name' => 100,
        'gender' => 20,
        'nationality' => 100,
        'phone_number' => 30,
        'email' => 150,
        'certificate_type' => 255,
        'certificate_source_country' => 100,
        'directorate' => 100,
        'accepted_preference_text' => 500,
        'track' => 100,
        'placement_round_name' => 255,
        'registration_type' => 100,
    ];

    public function __construct(private readonly MinistryPlacementImport $reader) {}

    /** @return array<string, mixed> */
    public function preview(UploadedFile $file): array
    {
        $analysis = $this->analyze($file);
        unset($analysis['records']);

        return $analysis;
    }

    public function import(UploadedFile $file, array $input, User $actor): MinistryPlacementBatch
    {
        $analysis = $this->analyze($file);
        if ($analysis['structural_errors'] !== [] || $analysis['invalid_rows'] > 0 || $analysis['duplicate_rows'] > 0) {
            throw MinistryPlacementException::invalidWorkbook([
                'file' => array_values(array_unique(array_merge(
                    $analysis['structural_errors'],
                    $analysis['invalid_rows'] > 0 ? ['invalid_rows_present'] : [],
                    $analysis['duplicate_rows'] > 0 ? ['duplicate_rows_present'] : [],
                ))),
            ]);
        }

        return DB::transaction(function () use ($file, $input, $actor, $analysis): MinistryPlacementBatch {
            $now = now();
            $sourceName = mb_substr(basename($file->getClientOriginalName()), 0, 255);
            $batch = MinistryPlacementBatch::query()->create([
                'batch_name' => Normalizer::text($input['batch_name']),
                'source_file_name' => $sourceName,
                'academic_year_id' => (int) $input['academic_year_id'],
                'import_date' => $now->toDateString(),
                'imported_by_user_id' => (int) $actor->user_id,
                'notes' => Normalizer::text($input['notes'] ?? null),
            ]);

            $records = array_map(function (array $record) use ($batch, $now): array {
                return $record + [
                    'batch_id' => (int) $batch->batch_id,
                    'matched_academic_program_id' => null,
                    'applicant_id' => null,
                    'processing_status' => 'imported',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $analysis['records']);

            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('ministry_placement_records')->insert($chunk);
            }

            UserActivityLog::query()->create([
                'user_id' => (int) $actor->user_id,
                'module_code' => 'admissions',
                'action_code' => 'ministry_placement.import',
                'description' => json_encode([
                    'batch_id' => (int) $batch->batch_id,
                    'academic_year_id' => (int) $batch->academic_year_id,
                    'record_count' => count($records),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);

            return $batch->load(['academicYear', 'importedBy'])->loadCount('records');
        });
    }

    /** @return array<string, mixed> */
    private function analyze(UploadedFile $file): array
    {
        try {
            $workbook = $this->reader->parse($file);
        } catch (MinistryPlacementException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new MinistryPlacementException(
                'تعذرت قراءة ملف Excel.',
                ['file' => ['unreadable_workbook']],
                422,
                'ministry_placement_workbook_unreadable',
            );
        }
        $structuralErrors = array_merge($workbook['errors'], $this->headerErrors($workbook['headers']));
        $previewRows = [];
        $records = [];
        $duplicates = [];
        $ignoredBlankRows = 0;

        foreach ($workbook['rows'] as $rawRow) {
            if ($this->isBlankRow($rawRow['values'])) {
                $ignoredBlankRows++;
                continue;
            }

            [$record, $errors] = $this->normalizeRow($rawRow);
            $key = Normalizer::duplicateKey($record['national_civil_id']);
            $index = count($previewRows);
            if ($key !== null) {
                $duplicates[$key][] = $index;
            }
            $records[$index] = $record;
            $previewRows[$index] = [
                'source_row' => (int) $rawRow['source_row'],
                'row_number' => $record['row_number'],
                'national_civil_id' => $record['national_civil_id'],
                'subscription_number' => $record['subscription_number'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'full_name' => trim(($record['first_name'] ?? '').' '.($record['last_name'] ?? '')),
                'father_name' => $record['father_name'],
                'date_of_birth' => $record['date_of_birth'],
                'accepted_preference_text' => $record['accepted_preference_text'],
                'total_score' => $record['total_score'],
                'errors' => $errors,
                'status' => $errors === [] ? 'valid' : 'invalid',
            ];
        }

        if ($previewRows === []) {
            $structuralErrors[] = 'no_data_rows';
        }

        foreach ($duplicates as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            foreach ($indexes as $index) {
                $previewRows[$index]['errors']['national_civil_id'][] = 'duplicate_national_civil_id';
                $previewRows[$index]['status'] = 'duplicate';
            }
        }

        $statusCounts = array_count_values(array_column($previewRows, 'status'));
        $invalidRows = $statusCounts['invalid'] ?? 0;
        $duplicateRows = $statusCounts['duplicate'] ?? 0;
        $validRows = $statusCounts['valid'] ?? 0;
        $rowErrors = [];
        foreach ($previewRows as &$row) {
            if ($row['errors'] !== []) {
                $rowErrors[] = ['source_row' => $row['source_row'], 'errors' => $row['errors']];
            }
        }
        unset($row);

        return [
            'file_name' => basename($file->getClientOriginalName()),
            'rows_count' => count($previewRows),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'duplicate_rows' => $duplicateRows,
            'ignored_blank_rows' => $ignoredBlankRows,
            'normalized_preview_rows' => $previewRows,
            'row_errors' => $rowErrors,
            'warnings' => $workbook['warnings'],
            'structural_errors' => array_values(array_unique($structuralErrors)),
            'records' => array_values($records),
        ];
    }

    /** @return array<int, string> */
    private function headerErrors(array $headers): array
    {
        $errors = [];
        foreach ($headers as $index => $header) {
            if (($header['formula'] ?? false) === true) {
                $errors[] = 'formula_header_not_allowed_'.($index + 1);
            }
            if (Normalizer::headerKey($header['formatted'] ?? null) === '') {
                $errors[] = 'missing_header_'.($index + 1);
            }
        }
        foreach (self::HEADER_ANCHORS as $index => $aliases) {
            $actual = Normalizer::headerKey($headers[$index]['formatted'] ?? null);
            $accepted = array_map([Normalizer::class, 'headerKey'], $aliases);
            if (! in_array($actual, $accepted, true)) {
                $errors[] = 'invalid_header_anchor_'.($index + 1);
            }
        }

        return $errors;
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (($cell['formula'] ?? false) || Normalizer::text($cell['formatted'] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, array<int, string>>} */
    private function normalizeRow(array $rawRow): array
    {
        $cells = $rawRow['values'];
        $errors = [];
        foreach ($cells as $field => $cell) {
            if (($cell['formula'] ?? false) === true) {
                $errors[$field][] = 'formula_not_allowed';
            }
        }

        $record = [];
        foreach (self::STRING_LIMITS as $field => $limit) {
            $record[$field] = Normalizer::text($cells[$field]['formatted'] ?? null);
            if ($record[$field] !== null && mb_strlen($record[$field]) > $limit) {
                $errors[$field][] = 'max_length_'.$limit;
            }
        }
        foreach (['national_civil_id', 'first_name', 'last_name'] as $required) {
            if ($record[$required] === null) {
                $errors[$required][] = 'required';
            }
        }
        if ($record['email'] !== null && filter_var($record['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'invalid_email';
        }

        $date = Normalizer::date($cells['date_of_birth']);
        $record['date_of_birth'] = $date['value'];
        if ($date['error'] !== null) {
            $errors['date_of_birth'][] = $date['error'];
        }

        foreach (['max_total_score', 'total_score'] as $scoreField) {
            $score = Normalizer::score($cells[$scoreField]);
            $record[$scoreField] = $score['value'];
            if ($score['error'] !== null) {
                $errors[$scoreField][] = $score['error'];
            }
        }
        if ($record['total_score'] !== null && $record['max_total_score'] !== null
            && (float) $record['total_score'] > (float) $record['max_total_score']) {
            $errors['total_score'][] = 'exceeds_max_total_score';
        }

        foreach (['is_faculty_member_child', 'has_academic_sequence'] as $booleanField) {
            $boolean = Normalizer::boolean($cells[$booleanField]);
            $record[$booleanField] = $boolean['value'];
            if ($boolean['error'] !== null) {
                $errors[$booleanField][] = $boolean['error'];
            }
        }

        $year = Normalizer::text($cells['certificate_grant_year']['formatted'] ?? null);
        $year = $year === null ? null : strtr($year, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $record['certificate_grant_year'] = $year === null ? null : (int) $year;
        if ($year !== null && (preg_match('/^\d{4}$/', $year) !== 1 || (int) $year < 1900 || (int) $year > Normalizer::currentYearUtc())) {
            $errors['certificate_grant_year'][] = 'invalid_certificate_grant_year';
        }

        $rowNumber = Normalizer::text($cells['row_number']['formatted'] ?? null);
        $rowNumberAscii = $rowNumber === null ? null : strtr($rowNumber, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        if ($rowNumberAscii !== null && preg_match('/^\d+$/', $rowNumberAscii) !== 1) {
            $errors['row_number'][] = 'invalid_row_number';
        }
        $record['row_number'] = $rowNumberAscii === null || isset($errors['row_number'])
            ? (int) $rawRow['source_row']
            : (int) $rowNumberAscii;

        return [$record, $errors];
    }
}
