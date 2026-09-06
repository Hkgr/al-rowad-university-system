<?php

namespace App\Support;

/** Canonical grade bands shared by transactional grading and read-only analytics. */
final class OfficialGradeScale
{
    public const EXCLUDED_STATUSES = ['incomplete', 'deprived', 'withdrawn'];

    public static function letter(float $mark): string
    {
        return match (true) {
            $mark >= 98 => 'A+', $mark >= 95 => 'A', $mark >= 90 => 'A-',
            $mark >= 85 => 'B+', $mark >= 80 => 'B', $mark >= 75 => 'B-',
            $mark >= 70 => 'C+', $mark >= 65 => 'C', $mark >= 60 => 'C-',
            $mark >= 55 => 'D+', $mark >= 50 => 'D', default => 'F',
        };
    }

    public static function points(string $letter, string $statusCode): float
    {
        if (in_array($letter, ['Z', 'W', 'I'], true) || in_array($statusCode, self::EXCLUDED_STATUSES, true)) {
            return 0.0;
        }

        return match ($letter) {
            'A+' => 4.0, 'A' => 3.75, 'A-' => 3.5, 'B+' => 3.25,
            'B' => 3.0, 'B-' => 2.75, 'C+' => 2.5, 'C' => 2.25,
            'C-' => 2.0, 'D+' => 1.75, 'D' => 1.5, default => 0.0,
        };
    }

    /** SQL is assembled only from server-owned column identifiers. */
    public static function pointsSql(string $mark, string $status): string
    {
        return "CASE WHEN {$status} IN ('incomplete','deprived','withdrawn') THEN 0 "
            ."WHEN {$mark} >= 98 THEN 4 WHEN {$mark} >= 95 THEN 3.75 WHEN {$mark} >= 90 THEN 3.5 "
            ."WHEN {$mark} >= 85 THEN 3.25 WHEN {$mark} >= 80 THEN 3 WHEN {$mark} >= 75 THEN 2.75 "
            ."WHEN {$mark} >= 70 THEN 2.5 WHEN {$mark} >= 65 THEN 2.25 WHEN {$mark} >= 60 THEN 2 "
            ."WHEN {$mark} >= 55 THEN 1.75 WHEN {$mark} >= 50 THEN 1.5 ELSE 0 END";
    }
}
