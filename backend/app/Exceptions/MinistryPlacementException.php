<?php

namespace App\Exceptions;

use Exception;

class MinistryPlacementException extends Exception
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly string $errorCode = 'ministry_placement_invalid',
    ) {
        parent::__construct($message);
    }

    public static function invalidWorkbook(array $errors): self
    {
        return new self(
            'يجب تصحيح أخطاء الملف قبل الاستيراد.',
            $errors,
            422,
            'ministry_placement_workbook_invalid',
        );
    }
}
