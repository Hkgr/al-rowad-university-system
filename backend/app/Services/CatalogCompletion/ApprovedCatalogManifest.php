<?php

namespace App\Services\CatalogCompletion;

use App\Exceptions\AcademicPlanException;

/** An allowlisted academic artifact, not an arbitrary bulk-write payload. */
final class ApprovedCatalogManifest
{
    public const SHA256 = '2e97008596d48323c9280aad64b07f63e1af68ecda137149c4a1d65c2bd42238';
    public readonly array $data;
    public readonly string $hash;

    public function __construct(?string $path = null, ?string $expectedHash = null)
    {
        $path ??= resource_path('academic-catalog/approved-2026-10.json');
        $bytes = is_file($path) ? file_get_contents($path) : false;
        $this->hash = $bytes === false ? '' : hash('sha256', $bytes);
        if (!hash_equals(self::SHA256, $this->hash) || ($expectedHash !== null && !hash_equals($this->hash, strtolower($expectedHash)))) {
            throw AcademicPlanException::conflict('catalog_completion_manifest_invalid', 'The approved manifest is missing or its fingerprint differs.');
        }
        $this->data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function rows(string $sheet, ?int $programId = null): array
    {
        $rows = $this->data['sheets'][$sheet];
        return $programId === null ? $rows : array_values(array_filter($rows, fn ($r) => (int) ($r['program_id'] ?? 0) === $programId));
    }

    public function programs(): array
    {
        return array_values(array_filter($this->rows('Programs'), fn ($r) => $r['scope'] === 'INCLUDE'));
    }

    public function baselineCourse(int $id): ?array
    {
        foreach ($this->rows('Catalog') as $row) if ((int) $row['course_id'] === $id) return $row;
        return null;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
