<?php

namespace App\Support;

/**
 * Easy-to-type temporary passwords: three different short capitalised words
 * and four digits, e.g. "Nahar-Sukar-Reem-4827" (~2.4e8 combinations). Built
 * with random_int (CSPRNG), always 17+ characters with upper case, lower case and digits, so they satisfy the
 * server rule Password::min(10)->letters()->mixedCase()->numbers(). Letters
 * that are easy to confuse (I, l, O, 0, 1) are avoided. Never logged.
 */
final class TemporaryPassword
{
    private const WORDS = [
        'Amal', 'Bahar', 'Badr', 'Dana', 'Fajr', 'Farah', 'Ghaym', 'Hadi', 'Hana', 'Jabar',
        'Janna', 'Karam', 'Kanz', 'Maha', 'Marah', 'Masa', 'Nada', 'Nahar', 'Najm', 'Nasr',
        'Qamar', 'Rawda', 'Reem', 'Raya', 'Saba', 'Sahar', 'Samar', 'Sukar', 'Tamr', 'Tara',
        'Wafa', 'Ward', 'Yasm', 'Zahr', 'Zain', 'Zaytun', 'Bayan', 'Hayat', 'Mazen', 'Sama',
    ];

    private const DIGITS = '23456789';

    public static function generate(): string
    {
        $words = [];
        while (count($words) < 3) {
            $word = self::WORDS[random_int(0, count(self::WORDS) - 1)];
            if (! in_array($word, $words, true)) {
                $words[] = $word;
            }
        }
        $digits = '';
        for ($i = 0; $i < 4; $i++) {
            $digits .= self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];
        }

        return implode('-', $words).'-'.$digits;
    }

    /** @param array<string, true> $taken passwords already issued in this batch */
    public static function unique(array &$taken): string
    {
        do {
            $password = self::generate();
        } while (isset($taken[$password]));
        $taken[$password] = true;

        return $password;
    }
}
