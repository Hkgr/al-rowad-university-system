<?php

namespace App\Services;

use App\Exceptions\MinistryPlacementException;
use App\Imports\MinistryPlacementImport;
use App\Models\AdmissionApplication;
use App\Models\Applicant;
use App\Models\MinistryPlacementBatch;
use App\Models\MinistryPlacementRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class MinistryPlacementService
{
    public function importBatch(UploadedFile $file, array $meta, ?int $userId): MinistryPlacementBatch
    {
        return DB::transaction(function () use ($file, $meta, $userId): MinistryPlacementBatch {
            $batch = MinistryPlacementBatch::query()->create([
                'batch_name' => $meta['batch_name'],
                'source_file_name' => $file->getClientOriginalName(),
                'academic_year_id' => $meta['academic_year_id'],
                'import_date' => now()->toDateString(),
                'imported_by_user_id' => $userId,
                'notes' => $meta['notes'] ?? null,
            ]);

            $rows = (new MinistryPlacementImport)->parse($file);

            foreach ($rows as $row) {
                $nationalCivilId = trim((string) ($row['national_civil_id'] ?? ''));

                if ($nationalCivilId === '') {
                    continue;
                }

                MinistryPlacementRecord::query()->create([
                    'batch_id' => $batch->batch_id,
                    'row_number' => $this->nullableInt($row['row_number'] ?? null),
                    'national_civil_id' => $nationalCivilId,
                    'subscription_number' => $this->nullableString($row['subscription_number'] ?? null),
                    'first_name' => $this->nullableString($row['first_name'] ?? null),
                    'last_name' => $this->nullableString($row['last_name'] ?? null),
                    'father_name' => $this->nullableString($row['father_name'] ?? null),
                    'mother_name' => $this->nullableString($row['mother_name'] ?? null),
                    'date_of_birth' => $this->parseDate($row['date_of_birth'] ?? null),
                    'gender' => $this->nullableString($row['gender'] ?? null),
                    'nationality' => $this->nullableString($row['nationality'] ?? null),
                    'phone_number' => $this->nullableString($row['phone_number'] ?? null),
                    'email' => $this->nullableString($row['email'] ?? null),
                    'certificate_type' => $this->nullableString($row['certificate_type'] ?? null),
                    'certificate_source_country' => $this->nullableString($row['certificate_source_country'] ?? null),
                    'certificate_grant_year' => $this->nullableInt($row['certificate_grant_year'] ?? null),
                    'directorate' => $this->nullableString($row['directorate'] ?? null),
                    'total_score' => $this->nullableDecimal($row['total_score'] ?? null),
                    'max_total_score' => $this->nullableDecimal($row['max_total_score'] ?? null),
                    'accepted_preference_text' => $this->nullableString($row['accepted_preference_text'] ?? null),
                    'track' => $this->nullableString($row['track'] ?? null),
                    'placement_round_name' => $this->nullableString($row['placement_round_name'] ?? null),
                    'registration_type' => $this->nullableString($row['registration_type'] ?? null),
                    'is_faculty_member_child' => $this->parseBoolean($row['is_faculty_member_child'] ?? null),
                    'has_academic_sequence' => $this->parseBoolean($row['has_academic_sequence'] ?? null),
                    'processing_status' => 'imported',
                ]);
            }

            return $batch->fresh(['records', 'academicYear', 'importedBy']);
        });
    }

    public function matchProgram(int $placementRecordId, int $academicProgramId): MinistryPlacementRecord
    {
        $record = MinistryPlacementRecord::query()->findOrFail($placementRecordId);
        $record->update([
            'matched_academic_program_id' => $academicProgramId,
        ]);

        return $record->fresh(['matchedAcademicProgram', 'batch', 'applicant']);
    }

    public function convertToApplicant(int $placementRecordId, ?int $userId): MinistryPlacementRecord
    {
        return DB::transaction(function () use ($placementRecordId, $userId): MinistryPlacementRecord {
            $record = MinistryPlacementRecord::query()
                ->with('batch')
                ->findOrFail($placementRecordId);

            if ($record->matched_academic_program_id === null) {
                throw new MinistryPlacementException(
                    'A matched academic program is required before converting a placement record to an applicant.'
                );
            }

            // Applicant has no national_civil_id (or equivalent unique civil-ID field);
            // always create a new applicant row for this conversion.
            $applicant = Applicant::query()->create([
                'applicant_number' => $this->generateApplicantNumber($record),
                'first_name' => $record->first_name,
                'last_name' => $record->last_name,
                'father_name' => $record->father_name,
                'mother_name' => $record->mother_name,
                'date_of_birth' => $record->date_of_birth,
                'gender' => $record->gender,
                'nationality' => $record->nationality,
                'phone_number' => $record->phone_number,
                'email' => $record->email,
            ]);

            AdmissionApplication::query()->create([
                'applicant_id' => $applicant->applicant_id,
                'academic_program_id' => $record->matched_academic_program_id,
                'academic_year_id' => $record->batch->academic_year_id,
                'application_date' => now()->toDateString(),
                'decision_status' => 'pending',
                'decided_by_user_id' => $userId,
            ]);

            $record->update([
                'applicant_id' => $applicant->applicant_id,
                'processing_status' => 'applicant_created',
            ]);

            return $record->fresh(['matchedAcademicProgram', 'batch', 'applicant']);
        });
    }

    private function generateApplicantNumber(MinistryPlacementRecord $record): string
    {
        $base = 'MP-'.($record->national_civil_id ?: $record->placement_record_id);
        $candidate = $base;
        $suffix = 1;

        while (Applicant::query()->where('applicant_number', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtoupper(trim((string) $value));

        return in_array($normalized, ['TRUE', '1', 'YES', 'نعم'], true);
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
