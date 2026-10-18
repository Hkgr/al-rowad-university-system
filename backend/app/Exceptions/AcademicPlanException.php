<?php

namespace App\Exceptions;

/** Controlled failures retain the caller's draft; mutations are never replayed. */
final class AcademicPlanException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->status);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($message, $code);
    }
}
