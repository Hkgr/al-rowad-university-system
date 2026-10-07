<?php

namespace App\Services\Payroll;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Strict parsing of one manual input value sent by the client (the browser already normalises Arabic digits and pasted
 * spreadsheet formatting; the server accepts plain ASCII decimals only, as strings or integers — never floats).
 *
 * Wire formats: amount "1234.50" (Syrian pounds, up to 2 decimals), number up to 6 decimals, percent as a FRACTION ("0.07" = 7%),
 * text as a string. Blank is null or "". Negatives only when the column allows signed values.
 */
final class PayrollInput
{
    public const TEXT_LIMIT = 255;

    /** @return BigDecimal|string|null null = blank */
    public static function parse(array $column, mixed $raw): BigDecimal|string|null
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if ($column['value_type'] === 'text') {
            $text = PayrollText::clean(is_scalar($raw) ? (string) $raw : null);
            if ($text === null) {
                return null;
            }
            if (mb_strlen($text) > self::TEXT_LIMIT || PayrollText::hasControlCharacters($text)) {
                throw new InvalidArgumentException('نص غير صالح (حتى '.self::TEXT_LIMIT.' حرفًا دون محارف تحكم).');
            }

            return $text;
        }
        if (! is_string($raw) && ! is_int($raw)) {
            throw new InvalidArgumentException('أرسل القيمة كنص عشري.');
        }
        $text = trim((string) $raw);
        $decimals = match ($column['value_type']) {
            'amount' => 2,
            default => 6,
        };
        $integerDigits = $column['value_type'] === 'percent' ? 3 : 9;
        if (! preg_match('/^(-?)(\d{1,'.$integerDigits.'})(?:\.(\d{1,'.$decimals.'}))?$/', $text, $m)) {
            if (preg_match('/^-/', $text) && ! $column['allow_negative']) {
                throw new InvalidArgumentException('لا يُقبل مبلغ سالب في هذا العمود.');
            }
            throw new InvalidArgumentException($column['value_type'] === 'amount'
                ? 'قيمة غير صالحة؛ أدخل رقمًا بحد أقصى منزلتين عشريتين وبحد أقصى 999,999,999.99.'
                : 'قيمة غير صالحة؛ أدخل رقمًا بحد أقصى '.$decimals.' منازل عشرية.');
        }
        if ($m[1] === '-' && ! $column['allow_negative'] && ! BigDecimal::of($text)->isZero()) {
            throw new InvalidArgumentException('لا يُقبل مبلغ سالب في هذا العمود.');
        }

        return BigDecimal::of($text);
    }
}
