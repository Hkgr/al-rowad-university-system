<?php

namespace App\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SupplementaryExamPeriodGovernance
{
    public const PERMISSION_VIEW = 'supplementary_exams.periods.view';

    public const PERMISSION_DECIDE = 'supplementary_exams.periods.decide';

    public const STATUS_LEGACY = 'legacy';

    public const STATUS_ANNOUNCED = 'announced';

    public const EVENT_ANNOUNCED = 'announced';

    /**
     * Documented for later phases. Phase 1 must not transition to these.
     *
     * @var list<string>
     */
    public const FUTURE_STATUSES = [
        'registration_open',
        'registration_closed',
        'in_progress',
        'results_processing',
        'locked',
    ];

    /**
     * Fail-closed. Column presence alone is not enough: identity UNIQUE and
     * the event-table audit contract must be inspectably usable. Incomplete
     * SQL deployment must not allow announcement.
     *
     * Inspection uses the Schema Builder instance. getIndexes/getForeignKeys/
     * getColumns are Builder methods forwarded by the Schema facade; they are
     * not methods on Schema::class.
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

            if (! $builder->hasTable('supplementary_exam_periods')
                || ! $builder->hasTable('supplementary_exam_period_events')
                || ! $builder->hasColumn('supplementary_exam_periods', 'status')
                || ! $builder->hasColumn('supplementary_exam_periods', 'opened_by_user_id')
                || ! $builder->hasColumn('supplementary_exam_periods', 'opened_at')
                || ! $builder->hasColumn('supplementary_exam_periods', 'decision_note')) {
                return false;
            }

            return self::identityUniqueReady($builder)
                && self::eventsTableContractReady($builder);
        } catch (Throwable) {
            return false;
        }
    }

    private static function identityUniqueReady(Builder $builder): bool
    {
        foreach ($builder->getIndexes('supplementary_exam_periods') as $index) {
            $columns = array_values($index['columns'] ?? []);
            if (! empty($index['unique']) && $columns === ['academic_year_id', 'semester_id']) {
                return true;
            }
        }

        return false;
    }

    private static function eventsTableContractReady(Builder $builder): bool
    {
        $columns = collect($builder->getColumns('supplementary_exam_period_events'))->keyBy('name');
        foreach ([
            'supplementary_exam_period_event_id',
            'supplementary_exam_period_id',
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

        $pk = $columns->get('supplementary_exam_period_event_id');
        if (! self::columnIsInteger($pk) || empty($pk['auto_increment']) || ! empty($pk['nullable'])) {
            return false;
        }
        if (! self::columnIsInteger($columns->get('supplementary_exam_period_id'))
            || ! self::columnIsInteger($columns->get('actor_user_id'))
            || ! self::columnIsString($columns->get('event_type'))
            || ! self::columnIsString($columns->get('to_status'))
            || ! self::columnIsString($columns->get('from_status'), true)
            || ! self::columnIsText($columns->get('notes'), true)
            || ! self::columnIsDateTime($columns->get('created_at'))) {
            return false;
        }
        if (! empty($columns->get('supplementary_exam_period_id')['nullable'])
            || ! empty($columns->get('actor_user_id')['nullable'])
            || ! empty($columns->get('event_type')['nullable'])
            || ! empty($columns->get('to_status')['nullable'])
            || ! empty($columns->get('created_at')['nullable'])) {
            return false;
        }

        $hasPeriodIndex = false;
        $hasActorIndex = false;
        $hasEventTypeIndex = false;
        $hasPrimary = false;
        foreach ($builder->getIndexes('supplementary_exam_period_events') as $index) {
            $indexColumns = array_values($index['columns'] ?? []);
            if (! empty($index['primary']) && $indexColumns === ['supplementary_exam_period_event_id']) {
                $hasPrimary = true;
            }
            if (($indexColumns[0] ?? null) === 'supplementary_exam_period_id') {
                $hasPeriodIndex = true;
            }
            if (($indexColumns[0] ?? null) === 'actor_user_id') {
                $hasActorIndex = true;
            }
            if (($indexColumns[0] ?? null) === 'event_type' && ($indexColumns[1] ?? null) === 'to_status') {
                $hasEventTypeIndex = true;
            }
        }

        $hasPeriodFk = false;
        $hasActorFk = false;
        foreach ($builder->getForeignKeys('supplementary_exam_period_events') as $foreign) {
            $local = array_values($foreign['columns'] ?? []);
            $remoteTable = (string) ($foreign['foreign_table'] ?? '');
            $remoteColumns = array_values($foreign['foreign_columns'] ?? []);
            if ($local === ['supplementary_exam_period_id']
                && $remoteTable === 'supplementary_exam_periods'
                && $remoteColumns === ['supplementary_exam_period_id']) {
                $hasPeriodFk = true;
            }
            if ($local === ['actor_user_id']
                && $remoteTable === 'users'
                && $remoteColumns === ['user_id']) {
                $hasActorFk = true;
            }
        }

        return $hasPrimary && $hasPeriodIndex && $hasActorIndex && $hasEventTypeIndex
            && $hasPeriodFk && $hasActorFk;
    }

    /**
     * @param  array<string, mixed>|null  $column
     */
    private static function columnIsInteger(?array $column): bool
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

        return in_array($type, ['int', 'integer', 'bigint', 'mediumint'], true);
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
    private static function columnIsDateTime(?array $column): bool
    {
        if ($column === null) {
            return false;
        }
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

        return in_array($type, ['timestamp', 'datetime'], true)
            && self::columnMatchesNullability($column, false);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function columnMatchesNullability(array $column, bool $nullable): bool
    {
        return $nullable === ! empty($column['nullable']);
    }
}
