<?php

namespace App\Support;

use Exception;

class AdministrativeGovernanceException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $errorCode = 'administrative_governance_invalid',
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(): self
    {
        return new self('يتطلب هذا الإجراء دور نائب رئيس الجامعة للشؤون الإدارية مع الصلاحية المسندة والنطاق الجامعي.', 403, 'administrative_governance_forbidden');
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($message, 409, $code);
    }

    public static function denied(string $code, string $message): self
    {
        return new self($message, 403, $code);
    }

    public static function invalid(string $code, string $message, array $errors = []): self
    {
        return new self($message, 422, $code, $errors);
    }
}
