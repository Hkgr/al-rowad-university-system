<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MinistryPlacementImport implements WithStartRow
{
    use Importable;

    /**
     * Column index → database field (Ministry Excel order after header row).
     *
     * @var array<int, string>
     */
    private const COLUMN_MAP = [
        0 => 'phone_number',
        1 => 'email',
        2 => 'max_total_score',
        3 => 'total_score',
        4 => 'directorate',
        5 => 'certificate_source_country',
        6 => 'certificate_grant_year',
        7 => 'subscription_number',
        8 => 'certificate_type',
        9 => 'registration_type',
        10 => 'accepted_preference_text',
        11 => 'track',
        12 => 'placement_round_name',
        13 => 'is_faculty_member_child',
        14 => 'has_academic_sequence',
        15 => 'nationality',
        16 => 'date_of_birth',
        17 => 'gender',
        18 => 'mother_name',
        19 => 'last_name',
        20 => 'father_name',
        21 => 'first_name',
        22 => 'row_number',
        23 => 'national_civil_id',
    ];

    public function startRow(): int
    {
        return 3;
    }

    /**
     * Parse the Ministry Excel file into associative row arrays for the service.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(UploadedFile $file): array
    {
        $sheets = $this->toArray($file);
        $rawRows = $sheets[0] ?? [];
        $parsed = [];

        foreach ($rawRows as $rawRow) {
            $row = [];

            foreach (self::COLUMN_MAP as $index => $field) {
                $row[$field] = $rawRow[$index] ?? null;
            }

            $parsed[] = $row;
        }

        return $parsed;
    }
}
