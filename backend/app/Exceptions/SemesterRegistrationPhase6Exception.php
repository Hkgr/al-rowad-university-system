<?php

namespace App\Exceptions;

use Exception;

class SemesterRegistrationPhase6Exception extends Exception
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409, public readonly array $errors = []) { parent::__construct($message); }
    public static function fail(string $code, string $message, int $status = 409, array $errors = []): self { return new self($message, $code, $status, $errors); }
    public static function minimumSchema(): self { return self::fail('minimum_enrollment_schema_not_ready', 'Minimum-enrollment review schema is not ready.', 503); }
    public static function replacementSchema(): self { return self::fail('registration_replacement_schema_not_ready', 'Registration replacement schema is not ready.', 503); }
    public static function replacementSource(): self { return self::fail('replacement_source_not_eligible', 'The cancelled registration is not eligible for replacement.'); }
    public static function consumed(): self { return self::fail('replacement_source_already_consumed', 'The cancelled registration has already been replaced.'); }
    public static function stale(): self { return self::fail('registration_replacement_stale', 'The replacement request provenance changed and the request was superseded.'); }
    public static function duplicateSource(): self { return self::fail('replacement_source_already_selected', 'The cancelled registration is already selected in this request.', 422); }
    public static function duplicateTarget(): self { return self::fail('replacement_target_already_selected', 'The replacement Offering is already selected in this request.', 422); }
}
