<?php

namespace App\Exceptions;

use RuntimeException;

final class AcademicCatalogException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->status);
    }
}
