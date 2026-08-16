<?php

namespace App\Exceptions;

use Exception;

class GraduationEligibilityException extends Exception
{
    public const ERROR_CODE = 'graduation_requirements_not_met';

    public const PROGRAM_CHANGE_ERROR_CODE = 'graduation_program_change_not_allowed';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 409,
        public readonly string $errorCode = self::ERROR_CODE,
    ) {
        parent::__construct($message);
    }
}
