<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

final class UniversityEmailException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $this->errorCode, 'message' => $this->getMessage()], $this->status)
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    // Controlled failures contain no upstream exceptions or requests and need no log.
    public function report(): bool { return true; }
}
