<?php

namespace App\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SupplementaryExamOfferingGovernance
{
    public const PERMISSION_VIEW = 'supplementary_exams.offerings.view';

    public const PERMISSION_MANAGE = 'supplementary_exams.offerings.manage';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const EVENT_OPENED = 'opened';

    public const EVENT_CLOSED = 'closed';

    public const EVENT_REOPENED = 'reopened';

    /**
     * Fail-closed. Table presence alone is not enough.
     * Inspection uses the Schema Builder instance, not Schema::class.
     */
    public static function schemaReady(): bool
    {
        try {
            $builder = Schema::connection((string) config('database.default'));
            if (! $builder instanceof Builder
                || ! method_exists($builder, 'getIndexes')
                || ! method_exists($builder, 'getForeignKeys')
                || ! method_exists($builder, 'getColumns')) {
                return false;
            }

            return self::offeringsContractReady($builder)
                && self::sourcesContractReady($builder)
                && self::eventsContractReady($builder);
        } catch (Throwable) {
            return false;
        }
    }

    private static function offeringsContractReady(Builder $builder): bool
    {
        if (! $builder->hasTable('supplementary_exam_offerings')) {
            return false;
        }

        $columns = collect($builder->getColumns('supplementary_exam_offerings'))->keyBy('name');
        foreach ([
            'supplementary_exam_offering_id',
            'supplementary_exam_period_id',
            'academic_program_id',
            'course_id',
            'status',
            'opened_by_user_id',
            'opened_at',
            'closed_by_user_id',
            'closed_at',
            'created_at',
            'updated_at',
        ] as $name) {
            if (! $columns->has($name)) {
                return false;
            }
        }

        $pk = $columns->get('supplementary_exam_offering_id');
        if (! self::columnIsInteger($pk) || empty($pk['auto_increment']) || ! empty($pk['nullable'])) {
            return false;
        }
        if (! self::columnIsInteger($columns->get('supplementary_exam_period_id'))
            || ! self::columnIsInteger($columns->get('academic_program_id'))
            || ! self::columnIsInteger($columns->get('course_id'))
            || ! self::columnIsInteger($columns->get('opened_by_user_id'))
            || ! self::columnIsInteger($columns->get('closed_by_user_id'), true)
            || ! self::columnIsString($columns->get('status'))
            || ! self::columnIsDateTime($columns->get('opened_at'))
            || ! self::columnIsDateTime($columns->get('closed_at'), true)
            || ! self::columnIsDateTime($columns->get('created_at'))
            || ! self::columnIsDateTime($columns->get('updated_at'))) {
            return false;
        }
        if (! empty($columns->get('supplementary_exam_period_id')['nullable'])
            || ! empty($columns->get('academic_program_id')['nullable'])
            || ! empty($columns->get('course_id')['nullable'])
            || ! empty($columns->get('status')['nullable'])
            || ! empty($columns->get('opened_by_user_id')['nullable'])
            || ! empty($columns->get('opened_at')['nullable'])) {
            return false;
        }

        $hasIdentityUnique = false;
        $hasPrimary = false;
        foreach ($builder->getIndexes('supplementary_exam_offerings') as $index) {
            $indexColumns = array_values($index['columns'] ?? []);
            if (! empty($index['primary']) && $indexColumns === ['supplementary_exam_offering_id']) {
                $hasPrimary = true;
            }
            if (! empty($index['unique']) && $indexColumns === ['supplementary_exam_period_id', 'academic_program_id', 'course_id']) {
                $hasIdentityUnique = true;
            }
        }

        $requiredFks = [
            'supplementary_exam_period_id' => ['supplementary_exam_periods', 'supplementary_exam_period_id'],
            'academic_program_id' => ['academic_programs', 'academic_program_id'],
            'course_id' => ['courses', 'course_id'],
            'opened_by_user_id' => ['users', 'user_id'],
            'closed_by_user_id' => ['users', 'user_id'],
        ];

        return $hasPrimary && $hasIdentityUnique && self::foreignKeysReady($builder, 'supplementary_exam_offerings', $requiredFks);
    }

    private static function sourcesContractReady(Builder $builder): bool
    {
        if (! $builder->hasTable('supplementary_exam_offering_sources')) {
            return false;
        }

        $columns = collect($builder->getColumns('supplementary_exam_offering_sources'))->keyBy('name');
        foreach ([
            'supplementary_exam_offering_source_id',
            'supplementary_exam_offering_id',
            'course_offering_id',
            'created_at',
        ] as $name) {
            if (! $columns->has($name)) {
                return false;
            }
        }

        $pk = $columns->get('supplementary_exam_offering_source_id');
        if (! self::columnIsInteger($pk) || empty($pk['auto_increment']) || ! empty($pk['nullable'])) {
            return false;
        }
        if (! self::columnIsInteger($columns->get('supplementary_exam_offering_id'))
            || ! self::columnIsInteger($columns->get('course_offering_id'))
            || ! self::columnIsDateTime($columns->get('created_at'))
            || ! empty($columns->get('supplementary_exam_offering_id')['nullable'])
            || ! empty($columns->get('course_offering_id')['nullable'])) {
            return false;
        }

        $hasUnique = false;
        $hasPrimary = false;
        foreach ($builder->getIndexes('supplementary_exam_offering_sources') as $index) {
            $indexColumns = array_values($index['columns'] ?? []);
            if (! empty($index['primary']) && $indexColumns === ['supplementary_exam_offering_source_id']) {
                $hasPrimary = true;
            }
            if (! empty($index['unique']) && $indexColumns === ['supplementary_exam_offering_id', 'course_offering_id']) {
                $hasUnique = true;
            }
        }

        return $hasPrimary && $hasUnique && self::foreignKeysReady($builder, 'supplementary_exam_offering_sources', [
            'supplementary_exam_offering_id' => ['supplementary_exam_offerings', 'supplementary_exam_offering_id'],
            'course_offering_id' => ['course_offerings', 'course_offering_id'],
        ]);
    }

    private static function eventsContractReady(Builder $builder): bool
    {
        if (! $builder->hasTable('supplementary_exam_offering_events')) {
            return false;
        }

        $columns = collect($builder->getColumns('supplementary_exam_offering_events'))->keyBy('name');
        foreach ([
            'supplementary_exam_offering_event_id',
            'supplementary_exam_offering_id',
            'event_type',
            'from_status',
            'to_status',
            'actor_user_id',
            'notes',
            'created_at',
        ] as $name) {
            if (! $columns->has($name)) {
                return false;
            }
        }

        $pk = $columns->get('supplementary_exam_offering_event_id');
        if (! self::columnIsInteger($pk) || empty($pk['auto_increment']) || ! empty($pk['nullable'])) {
            return false;
        }
        if (! self::columnIsInteger($columns->get('supplementary_exam_offering_id'))
            || ! self::columnIsInteger($columns->get('actor_user_id'))
            || ! self::columnIsString($columns->get('event_type'))
            || ! self::columnIsString($columns->get('to_status'))
            || ! self::columnIsString($columns->get('from_status'), true)
            || ! self::columnIsText($columns->get('notes'), true)
            || ! self::columnIsDateTime($columns->get('created_at'))
            || ! empty($columns->get('supplementary_exam_offering_id')['nullable'])
            || ! empty($columns->get('actor_user_id')['nullable'])
            || ! empty($columns->get('event_type')['nullable'])
            || ! empty($columns->get('to_status')['nullable'])) {
            return false;
        }

        $hasPrimary = false;
        $hasOfferingIndex = false;
        $hasActorIndex = false;
        $hasLookupIndex = false;
        foreach ($builder->getIndexes('supplementary_exam_offering_events') as $index) {
            $indexColumns = array_values($index['columns'] ?? []);
            if (! empty($index['primary']) && $indexColumns === ['supplementary_exam_offering_event_id']) {
                $hasPrimary = true;
            }
            if (($indexColumns[0] ?? null) === 'supplementary_exam_offering_id') {
                $hasOfferingIndex = true;
            }
            if (($indexColumns[0] ?? null) === 'actor_user_id') {
                $hasActorIndex = true;
            }
            if (($indexColumns[0] ?? null) === 'event_type' && ($indexColumns[1] ?? null) === 'to_status') {
                $hasLookupIndex = true;
            }
        }

        return $hasPrimary && $hasOfferingIndex && $hasActorIndex && $hasLookupIndex
            && self::foreignKeysReady($builder, 'supplementary_exam_offering_events', [
                'supplementary_exam_offering_id' => ['supplementary_exam_offerings', 'supplementary_exam_offering_id'],
                'actor_user_id' => ['users', 'user_id'],
            ]);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $required
     */
    private static function foreignKeysReady(Builder $builder, string $table, array $required): bool
    {
        $found = [];
        foreach ($builder->getForeignKeys($table) as $foreign) {
            $local = array_values($foreign['columns'] ?? []);
            $remoteTable = (string) ($foreign['foreign_table'] ?? '');
            $remoteColumns = array_values($foreign['foreign_columns'] ?? []);
            if (count($local) !== 1) {
                continue;
            }
            $column = $local[0];
            $found[$column] = [$remoteTable, $remoteColumns[0] ?? ''];
        }

        foreach ($required as $column => [$tableName, $remote]) {
            if (($found[$column] ?? null) !== [$tableName, $remote]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $column
     */
    private static function columnIsInteger(?array $column, bool $nullable = false): bool
    {
        if ($column === null) {
            return false;
        }
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
        if (! in_array($type, ['int', 'integer', 'bigint', 'mediumint'], true)) {
            return false;
        }

        return self::columnMatchesNullability($column, $nullable);
    }

    /**
     * @param  array<string, mixed>|null  $column
     */
    private static function columnIsString(?array $column, bool $nullable = false): bool
    {
        if ($column === null) {
            return false;
        }
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
        if (! in_array($type, ['varchar', 'char', 'string'], true)) {
            return false;
        }

        return self::columnMatchesNullability($column, $nullable);
    }

    /**
     * @param  array<string, mixed>|null  $column
     */
    private static function columnIsText(?array $column, bool $nullable = false): bool
    {
        if ($column === null) {
            return false;
        }
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
        if (! in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char', 'string'], true)) {
            return false;
        }

        return self::columnMatchesNullability($column, $nullable);
    }

    /**
     * @param  array<string, mixed>|null  $column
     */
    private static function columnIsDateTime(?array $column, bool $nullable = false): bool
    {
        if ($column === null) {
            return false;
        }
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
        if (! in_array($type, ['timestamp', 'datetime'], true)) {
            return false;
        }

        return self::columnMatchesNullability($column, $nullable);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function columnMatchesNullability(array $column, bool $nullable): bool
    {
        return $nullable === ! empty($column['nullable']);
    }
}
