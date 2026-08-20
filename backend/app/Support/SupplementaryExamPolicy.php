<?php

namespace App\Support;

use App\Exceptions\SupplementaryExamOfferingException;
use App\Models\SupplementaryExamPeriod;

final class SupplementaryExamPolicy
{
    public const SEMESTER_ORDER_FIRST = 1;

    public const SEMESTER_ORDER_SECOND = 2;

    public const SEMESTER_ORDER_SUMMER = 3;

    public const SUMMER_MAX_COURSES_PER_STUDENT = 3;

    /**
     * @return list<int>
     */
    public static function allowedSourceSemesterOrders(SupplementaryExamPeriod $period): array
    {
        return self::allowedSourceSemesterOrdersForOrder(self::periodSemesterOrder($period));
    }

    /**
     * @return list<int>
     */
    public static function allowedSourceSemesterOrdersForOrder(int $semesterOrder): array
    {
        return match ($semesterOrder) {
            self::SEMESTER_ORDER_FIRST => [self::SEMESTER_ORDER_FIRST],
            self::SEMESTER_ORDER_SECOND => [self::SEMESTER_ORDER_SECOND],
            self::SEMESTER_ORDER_SUMMER => [
                self::SEMESTER_ORDER_FIRST,
                self::SEMESTER_ORDER_SECOND,
                self::SEMESTER_ORDER_SUMMER,
            ],
            default => throw SupplementaryExamOfferingException::unsupportedSemesterPolicy(),
        };
    }

    public static function maxCoursesPerStudent(SupplementaryExamPeriod $period): ?int
    {
        return self::maxCoursesPerStudentForOrder(self::periodSemesterOrder($period));
    }

    public static function maxCoursesPerStudentForOrder(int $semesterOrder): ?int
    {
        if ($semesterOrder === self::SEMESTER_ORDER_SUMMER) {
            return self::SUMMER_MAX_COURSES_PER_STUDENT;
        }

        if (in_array($semesterOrder, [self::SEMESTER_ORDER_FIRST, self::SEMESTER_ORDER_SECOND], true)) {
            return null;
        }

        throw SupplementaryExamOfferingException::unsupportedSemesterPolicy();
    }

    public static function periodSemesterOrder(SupplementaryExamPeriod $period): int
    {
        $period->loadMissing('semester');
        $order = $period->semester?->semester_order;
        if ($order === null) {
            throw SupplementaryExamOfferingException::unsupportedSemesterPolicy();
        }

        return (int) $order;
    }
}
