<?php

namespace App\Services\StudentCompletion;

/** Canonical input encoding, not a database revision or an authority to mutate. */
final class CompletionData
{
    public static function text(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    }

    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return array_map(self::canonical(...), $value);
    }

    public static function json(mixed $value): string
    {
        $json='';foreach(self::tokens($value) as $part)$json.=$part;return $json;
    }

    public static function hash(mixed $value): string
    {
        $hash=hash_init('sha256');foreach(self::tokens($value) as $part)hash_update($hash,$part);return hash_final($hash);
    }
    public static function tokens(mixed $value): \Generator
    {
        if(!is_array($value)){yield json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return;}
        $list=array_is_list($value);yield $list?'[':'{';$keys=array_keys($value);if(!$list)sort($keys,SORT_STRING);$first=true;
        foreach($keys as $key) {
            if(!$first)yield ',';$first=false;
            if(!$list){yield json_encode((string)$key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);yield ':';}
            yield from self::tokens($value[$key]);
        }
        yield $list?']':'}';
    }

    public static function year(mixed $value): ?int
    {
        if (!preg_match('/^\s*(\d{4})\s*[-\/–]\s*(\d{4})\s*$/u', (string) $value, $m)) return null;
        return abs((int) $m[1] - (int) $m[2]) === 1 ? min((int) $m[1], (int) $m[2]) : null;
    }

    public static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        $text = self::text($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]00:00:00)?$/', $text, $m)) return null;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }
}
