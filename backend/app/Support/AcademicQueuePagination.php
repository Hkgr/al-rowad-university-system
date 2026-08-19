<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Bounded list pagination for academic queues and generic API indexes.
 * Do not load an unbounded university-wide result set into memory.
 */
final class AcademicQueuePagination
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public static function perPage(?int $requested, int $default = self::DEFAULT_PER_PAGE): int
    {
        if ($requested === null || $requested < 1) {
            return $default;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /**
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
