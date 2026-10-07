<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Controlled owner-payroll failure; renders its own JSON (see render()). */
class PayrollException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $errors = [],
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        $payload = ['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode];
        // `errors` is omitted when empty so the shared API client exposes `data` (conflict details) as error.details.
        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }
        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        return response()->json($payload, $this->status);
    }
}
