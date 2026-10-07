<?php

namespace App\Services\Payroll\Formula;

use RuntimeException;

/** A formula that cannot be accepted (syntax, unknown reference, type, complexity, cycle). Messages are Arabic and user-facing. */
final class FormulaException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'syntax', public readonly ?int $position = null)
    {
        parent::__construct($message);
    }
}
