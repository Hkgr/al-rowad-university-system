<?php

namespace App\Services;

use App\Models\UserActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Audit of the generic CRUD endpoints (HandlesApiCrud): which record of which table
 * was created, updated or deleted, by whom and when. Only field NAMES are kept —
 * never values — so personal or academic data is not copied into the log.
 * Like LoginAuditService, a failed audit write never breaks the operation.
 */
class ResourceAuditService
{
    public const MODULE_CODE = 'resources';

    private const IGNORED_FIELDS = ['created_at', 'updated_at', 'deleted_at'];

    /** @param list<string> $fields */
    public function record(?int $actorUserId, string $event, Model $model, array $fields = []): void
    {
        if ($actorUserId === null || ! $this->ready()) {
            return;
        }
        $fields = array_values(array_diff($fields, self::IGNORED_FIELDS));
        if ($event === 'updated' && $fields === []) {
            return;
        }
        try {
            UserActivityLog::query()->create([
                'user_id' => $actorUserId,
                'module_code' => self::MODULE_CODE,
                'action_code' => 'resource.'.$event,
                'description' => json_encode([
                    'resource' => $model->getTable(),
                    'record_id' => $model->getKey(),
                    'fields' => $fields,
                    'outcome' => 'success',
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('resource_audit_write_failed', ['exception' => $exception::class]);
        }
    }

    private function ready(): bool
    {
        return Schema::hasTable('user_activity_logs');
    }
}
