<?php

namespace App\Services\Payroll\Formula;

use Brick\Math\BigDecimal;

/**
 * Result of evaluating a cell: a number, a text, a boolean (comparison), "missing" (a required input is blank, so the
 * result is unavailable) or an error (division by zero, overflow). Missing and errors are never silently turned into zero.
 */
final class FormulaValue
{
    private function __construct(
        public readonly string $state, // ok | missing | error
        public readonly BigDecimal|string|bool|null $value = null,
        public readonly string $message = '',
        public readonly string $code = '',
    ) {}

    public static function number(BigDecimal|string|int $v): self
    {
        return new self('ok', $v instanceof BigDecimal ? $v : BigDecimal::of($v));
    }

    public static function text(string $v): self
    {
        return new self('ok', $v);
    }

    public static function bool(bool $v): self
    {
        return new self('ok', $v);
    }

    public static function missing(string $message): self
    {
        return new self('missing', null, $message, 'missing_input');
    }

    public static function error(string $code, string $message): self
    {
        return new self('error', null, $message, $code);
    }

    public function ok(): bool
    {
        return $this->state === 'ok';
    }

    public function isNumber(): bool
    {
        return $this->state === 'ok' && $this->value instanceof BigDecimal;
    }
}
