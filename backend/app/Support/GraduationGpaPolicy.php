<?php

namespace App\Support;

/**
 * Canonical graduation GPA policy.
 *
 * GradeService already publishes GPA on a 4.0 scale
 * (`getGpaOverview()['scale']['maximum'] = 4.0`) and maps letter C-
 * (final mark >= 60) to 2.00 grade points. The published study rules
 * require a 60% / 2.0-on-4.0 minimum. This class stores that threshold
 * on the repository's actual 4.0 scale. It does not convert percentages.
 */
final class GraduationGpaPolicy
{
    public const SCALE_NAME = '4.0';

    public const SCALE_MAXIMUM = 4.0;

    public const MINIMUM_CUMULATIVE_GPA = 2.0;

    public const PUBLISHED_PERCENTAGE_EQUIVALENT = 60;

    public function scaleMaximum(): float
    {
        return self::SCALE_MAXIMUM;
    }

    public function minimumCumulativeGpa(): float
    {
        return self::MINIMUM_CUMULATIVE_GPA;
    }

    public function satisfies(?float $cumulativeGpa): bool
    {
        return $cumulativeGpa !== null && $cumulativeGpa >= self::MINIMUM_CUMULATIVE_GPA;
    }

    /**
     * @return array{scale: string, maximum: float, minimum_cumulative_gpa: float, published_percentage_equivalent: int}
     */
    public function describe(): array
    {
        return [
            'scale' => self::SCALE_NAME,
            'maximum' => self::SCALE_MAXIMUM,
            'minimum_cumulative_gpa' => self::MINIMUM_CUMULATIVE_GPA,
            'published_percentage_equivalent' => self::PUBLISHED_PERCENTAGE_EQUIVALENT,
        ];
    }
}
