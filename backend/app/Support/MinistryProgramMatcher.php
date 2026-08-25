<?php

namespace App\Support;

final class MinistryProgramMatcher
{
    public const EXACT = 'EXACT';

    public const CONTAINS_PROGRAM_NAME = 'CONTAINS_PROGRAM_NAME';

    /** @var array<int, string> */
    private const GENERIC_LEADING_WRAPPERS = [
        'برنامج الإجازة في',
    ];

    public function normalize(?string $value): string
    {
        $value = mb_strtolower((string) $value, 'UTF-8');
        $value = str_replace('ـ', '', $value);
        $value = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي',
        ]);
        $value = preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public function preferenceKey(?string $value): string
    {
        return hash('sha256', $this->normalize($value));
    }

    /**
     * @param array<int, array<string, mixed>> $programs Active hierarchy catalog, loaded once by the caller.
     * @return array{suggestion_status: string, match_tier: ?string, candidate_count: int, suggestions: array<int, array<string, mixed>>}
     */
    public function suggestions(?string $preference, array $programs, int $limit = 5): array
    {
        $normalizedPreference = $this->normalize($preference);
        if ($normalizedPreference === '') {
            return $this->result('missing_preference', null, []);
        }

        $exact = [];
        $contains = [];
        foreach ($programs as $program) {
            $names = $this->comparisonNames((string) ($program['program_name'] ?? ''));
            $code = $this->normalize((string) ($program['program_code'] ?? ''));
            if (in_array($normalizedPreference, $names, true) || ($code !== '' && $normalizedPreference === $code)) {
                $exact[] = $program + ['match_tier' => self::EXACT];
                continue;
            }
            if ($this->containsAny($normalizedPreference, $names)) {
                $contains[] = $program + ['match_tier' => self::CONTAINS_PROGRAM_NAME];
            }
        }

        $tier = $exact !== [] ? self::EXACT : ($contains !== [] ? self::CONTAINS_PROGRAM_NAME : null);
        $candidates = $exact !== [] ? $exact : $contains;
        usort($candidates, static fn (array $left, array $right): int => [
            (string) ($left['program_name'] ?? ''),
            (string) ($left['program_code'] ?? ''),
            (int) ($left['academic_program_id'] ?? 0),
        ] <=> [
            (string) ($right['program_name'] ?? ''),
            (string) ($right['program_code'] ?? ''),
            (int) ($right['academic_program_id'] ?? 0),
        ]);

        $status = $candidates === [] ? 'no_match' : (count($candidates) === 1 ? 'unique' : 'ambiguous');

        return $this->result($status, $tier, $candidates, $limit);
    }

    /** @return array<int, string> */
    private function comparisonNames(string $programName): array
    {
        $fullName = $this->normalize($programName);
        if ($fullName === '') {
            return [];
        }

        $names = [$fullName];
        foreach (self::GENERIC_LEADING_WRAPPERS as $wrapper) {
            $normalizedWrapper = $this->normalize($wrapper);
            $prefix = $normalizedWrapper.' ';
            if ($normalizedWrapper !== '' && str_starts_with($fullName, $prefix)) {
                $remainder = trim(mb_substr($fullName, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
                if ($remainder !== '') {
                    $names[] = $remainder;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<int, string> $names */
    private function containsAny(string $preference, array $names): bool
    {
        foreach ($names as $name) {
            if (str_contains($preference, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function result(string $status, ?string $tier, array $candidates, int $limit = 5): array
    {
        return [
            'suggestion_status' => $status,
            'match_tier' => $tier,
            'candidate_count' => count($candidates),
            'suggestions' => array_slice($candidates, 0, max(1, $limit)),
        ];
    }
}
