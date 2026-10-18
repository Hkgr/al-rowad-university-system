<?php

namespace App\Exceptions;

use Exception;

class AcademicRequirementConfigurationException extends Exception
{
    public const ERROR_CODE = 'academic_requirement_configuration_invalid';

    public function __construct(
        string $message,
        public readonly array $context = [],
        public readonly int $status = 409,
        public readonly string $errorCode = self::ERROR_CODE,
    ) {
        parent::__construct($message);
    }
}
