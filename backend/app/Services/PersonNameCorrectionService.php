<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Person-record domain service for name corrections only. The account holder's
 * name lives on the linked `employees` or `students` row (users has no name);
 * this service changes the four name columns of that same row and nothing else:
 * no employment, academic, contact or identity-link field, and never a new person.
 */
class PersonNameCorrectionService
{
    public const FIELDS = ['first_name', 'last_name', 'father_name', 'mother_name'];

    private const TABLES = [
        'employee' => ['table' => 'employees', 'key' => 'employee_id'],
        'student' => ['table' => 'students', 'key' => 'student_id'],
    ];

    /** @return array{type:string, id:int, first_name:?string, last_name:?string, father_name:?string, mother_name:?string}|null */
    public function current(string $type, ?int $id): ?array
    {
        if ($id === null || ! isset(self::TABLES[$type])) {
            return null;
        }
        $meta = self::TABLES[$type];
        $row = DB::table($meta['table'])->where($meta['key'], $id)->first(self::FIELDS);

        return $row === null ? null : ['type' => $type, 'id' => $id] + (array) $row;
    }

    /**
     * Must run inside the caller's transaction (row lock).
     *
     * @return array{before: array<string, ?string>, after: array<string, ?string>, changed: list<string>}
     */
    public function correct(string $type, int $id, array $names): array
    {
        $meta = self::TABLES[$type] ?? throw ValidationException::withMessages(['person_type' => ['نوع السجل غير معروف.']]);
        $row = DB::table($meta['table'])->where($meta['key'], $id)->lockForUpdate()->first(self::FIELDS);
        if ($row === null) {
            throw ValidationException::withMessages(['person_type' => ['السجل المرتبط بالحساب غير موجود.']]);
        }
        $before = (array) $row;
        $after = $before;
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $names)) {
                $value = $names[$field] === null ? null : trim(preg_replace('/\s+/u', ' ', (string) $names[$field]));
                $after[$field] = $value === '' ? null : $value;
            }
        }
        foreach (['first_name', 'last_name'] as $required) {
            if ($after[$required] === null) {
                throw ValidationException::withMessages([$required => ['هذا الحقل مطلوب.']]);
            }
        }
        $changed = array_values(array_filter(self::FIELDS, fn ($field) => $before[$field] !== $after[$field]));
        if ($changed !== []) {
            DB::table($meta['table'])->where($meta['key'], $id)->update(array_intersect_key($after, array_flip($changed)) + ['updated_at' => now()]);
        }

        return ['before' => $before, 'after' => $after, 'changed' => $changed];
    }
}
