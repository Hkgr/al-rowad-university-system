<?php

namespace App\Imports;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class MinistryPlacementImport
{
    /** @var array<int, string> */
    public const COLUMN_MAP = [
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

    /**
     * Read formatted identifier text while retaining raw values for strict
     * date/number validation. Row 1 is informational, row 2 is the header,
     * and data begins at row 3.
     *
     * @return array{title: string, headers: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, warnings: array<int, string>, errors: array<int, string>}
     */
    public function parse(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($file->getRealPath());

        try {
            $sheet = $spreadsheet->getSheet(0);
            $warnings = [];
            $errors = [];
            $titleCells = [];
            foreach (self::COLUMN_MAP as $index => $_field) {
                $titleCells[] = $this->cell($sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).'1'))['formatted'];
            }
            $title = implode(' ', array_values(array_filter(array_map('trim', $titleCells), fn (string $value): bool => $value !== '')));

            if ($title === '') {
                $warnings[] = 'blank_title_row';
            }

            $headers = [];
            foreach (self::COLUMN_MAP as $index => $field) {
                $coordinate = Coordinate::stringFromColumnIndex($index + 1).'2';
                $headers[$index] = $this->cell($sheet->getCell($coordinate)) + ['field' => $field];
            }

            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                [$column] = Coordinate::coordinateFromString($coordinate);
                if (Coordinate::columnIndexFromString($column) <= count(self::COLUMN_MAP)) {
                    continue;
                }

                $cell = $sheet->getCell($coordinate);
                if ($this->hasContent($cell)) {
                    $errors[] = 'unexpected_data_after_column_x';
                    break;
                }
            }

            foreach ($spreadsheet->getAllSheets() as $sheetIndex => $candidate) {
                if ($sheetIndex === 0) {
                    continue;
                }
                $hasData = false;
                foreach ($candidate->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        if (trim((string) $cell->getFormattedValue()) !== '') {
                            $hasData = true;
                            break 2;
                        }
                    }
                }
                if ($hasData) {
                    $errors[] = 'additional_data_sheet_not_supported';
                    break;
                }
            }

            $rows = [];
            $highestRow = $sheet->getHighestDataRow();
            for ($rowNumber = 3; $rowNumber <= $highestRow; $rowNumber++) {
                $values = [];
                foreach (self::COLUMN_MAP as $index => $field) {
                    $coordinate = Coordinate::stringFromColumnIndex($index + 1).$rowNumber;
                    $values[$field] = $this->cell($sheet->getCell($coordinate));
                }
                $rows[] = ['source_row' => $rowNumber, 'values' => $values];
            }

            return compact('title', 'headers', 'rows', 'warnings', 'errors');
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /** @return array{raw: mixed, formatted: string, formula: bool} */
    private function cell(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): array
    {
        return [
            'raw' => $cell->getValue(),
            'formatted' => (string) $cell->getFormattedValue(),
            'formula' => $cell->getDataType() === DataType::TYPE_FORMULA,
        ];
    }

    private function hasContent(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): bool
    {
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return true;
        }

        foreach ([$cell->getValue(), $cell->getFormattedValue()] as $value) {
            if ($value !== null && preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', (string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}
