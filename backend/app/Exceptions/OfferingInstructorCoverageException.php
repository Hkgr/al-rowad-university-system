<?php

namespace App\Exceptions;

use Exception;

class OfferingInstructorCoverageException extends Exception
{
    public const INCOMPLETE = 'offering_instructor_coverage_incomplete';

    public const COMPONENTS_UNDEFINED = 'offering_teaching_components_undefined';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 409,
        public readonly ?string $errorCode = null,
        public readonly array $coverage = [],
    ) {
        parent::__construct($message);
    }

    public static function incomplete(array $coverage): self
    {
        $message = 'لا يمكن فتح المادة قبل استكمال تكليف المدرسين المعتمدين.';

        return new self($message, ['offering' => [$message]], 409, self::INCOMPLETE, $coverage);
    }

    public static function componentsUndefined(array $coverage = []): self
    {
        $message = 'لا يمكن فتح المادة لأن مكونات التدريس للمقرر غير محددة.';

        return new self($message, ['course' => [$message]], 422, self::COMPONENTS_UNDEFINED, $coverage);
    }
}
