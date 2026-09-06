<?php

namespace App\Support;

/** Canonical grade bands shared by transactional grading and read-only analytics. */
final class OfficialGradeScale
{
    public const EXCLUDED_STATUSES = ['incomplete', 'deprived', 'withdrawn'];

    /** Descending thresholds are the single source used by PHP and SQL. */
    public const BANDS = [
        ['minimum' => 98, 'letter' => 'A+', 'points' => 4.0],
        ['minimum' => 95, 'letter' => 'A', 'points' => 3.75],
        ['minimum' => 90, 'letter' => 'A-', 'points' => 3.5],
        ['minimum' => 85, 'letter' => 'B+', 'points' => 3.25],
        ['minimum' => 80, 'letter' => 'B', 'points' => 3.0],
        ['minimum' => 75, 'letter' => 'B-', 'points' => 2.75],
        ['minimum' => 70, 'letter' => 'C+', 'points' => 2.5],
        ['minimum' => 65, 'letter' => 'C', 'points' => 2.25],
        ['minimum' => 60, 'letter' => 'C-', 'points' => 2.0],
        ['minimum' => 55, 'letter' => 'D+', 'points' => 1.75],
        ['minimum' => 50, 'letter' => 'D', 'points' => 1.5],
    ];

    public static function letter(float $mark): string
    {
        foreach (self::BANDS as $band) if ($mark >= $band['minimum']) return $band['letter'];
        return 'F';
    }

    public static function points(string $letter, string $statusCode): float
    {
        if (in_array($letter, ['F', 'Z', 'W', 'I'], true) || $statusCode === 'failed' || in_array($statusCode, self::EXCLUDED_STATUSES, true)) {
            return 0.0;
        }

        foreach (self::BANDS as $band) if ($letter === $band['letter']) return $band['points'];
        return 0.0;
    }

    /** SQL is assembled only from server-owned column identifiers. */
    public static function pointsSql(string $mark, string $status): string
    {
        $clauses = "CASE WHEN {$status} IN ('failed','incomplete','deprived','withdrawn') THEN 0";
        foreach (self::BANDS as $band) $clauses .= " WHEN {$mark} >= {$band['minimum']} THEN {$band['points']}";
        return $clauses.' ELSE 0 END';
    }
}
