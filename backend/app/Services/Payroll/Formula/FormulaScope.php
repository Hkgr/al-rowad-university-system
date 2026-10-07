<?php

namespace App\Services\Payroll\Formula;

/**
 * What a formula may reference: columns of the same employee row and global settings, by display label or by stable key.
 * Each entry: ['key' => string, 'label' => string, 'type' => 'number'|'text', 'source' => 'column'|'setting'].
 */
final class FormulaScope
{
    /** @var array<string, array> */
    private array $byKey = [];

    /** @var array<string, array> */
    private array $byLabel = [];

    /** @param list<array{key:string,label:string,type:string,source:string}> $entries */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $this->byKey[$entry['key']] = $entry;
            $this->byLabel[self::normalize($entry['label'])] = $entry;
        }
    }

    public static function normalize(string $label): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($label)));
    }

    public function byKey(string $key): ?array
    {
        return $this->byKey[$key] ?? null;
    }

    public function byLabel(string $label): ?array
    {
        return $this->byLabel[self::normalize($label)] ?? null;
    }

    /** @return list<array> */
    public function all(): array
    {
        return array_values($this->byKey);
    }
}
