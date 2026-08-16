<?php

namespace App\Exceptions;

use Exception;

class RegistrationException extends Exception
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
