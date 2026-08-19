<?php

namespace App\Services;

use App\Models\LoginAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reuses login_audit_logs. Never persist credentials, bearer tokens, or hashes.
 */
class LoginAuditService
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_INACTIVE = 'inactive';

    public function record(Request $request, ?User $user, string $loginStatus): void
    {
        if (! Schema::hasTable('login_audit_logs')) {
            return;
        }

        $email = strtolower(trim((string) $request->input('email', '')));

        try {
            LoginAuditLog::query()->create([
                'user_id' => $user?->user_id,
                'username_attempted' => $this->truncate($email !== '' ? $email : null, 100),
                'login_status' => $this->truncate($loginStatus, 50) ?? self::STATUS_FAILED,
                'ip_address' => $this->truncate($request->ip(), 45),
                'user_agent' => $this->truncate((string) $request->userAgent(), 255),
                'attempted_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('login_audit_write_failed', [
                'exception' => $exception::class,
            ]);
        }
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
