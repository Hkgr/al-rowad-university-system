<?php

namespace App\Support;

final readonly class RegistrationProjectionContext
{
    /**
     * @param list<int> $excludedRegistrationIds
     * @param list<int> $excludedOfferingIds
     * @param list<int> $proposedAddOfferingIds
     */
    public function __construct(
        public array $excludedRegistrationIds = [],
        public array $excludedOfferingIds = [],
        public array $proposedAddOfferingIds = [],
    ) {
    }

    /** @return list<int> */
    public function excludedRegistrationIds(): array
    {
        return $this->ids($this->excludedRegistrationIds);
    }

    /** @return list<int> */
    public function excludedOfferingIds(): array
    {
        return $this->ids($this->excludedOfferingIds);
    }

    /** @return list<int> */
    public function proposedAddOfferingIds(): array
    {
        return $this->ids($this->proposedAddOfferingIds);
    }

    /** @return list<int> */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }
}
