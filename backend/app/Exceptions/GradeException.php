<?php

namespace App\Exceptions;

use Exception;

class GradeException extends Exception
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
