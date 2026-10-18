<?php

namespace App\Exceptions;

use Exception;

class AccountAdministrationException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 403,
        public readonly string $errorCode = 'account_administration_forbidden',
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $errorCode, string $message): self
    {
        return new self($message, 403, $errorCode);
    }

    public static function conflict(string $errorCode, string $message): self
    {
        return new self($message, 409, $errorCode);
    }
}
