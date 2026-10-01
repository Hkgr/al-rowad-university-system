<?php

namespace App\Services;

use App\Exceptions\UniversityEmailException;
use App\Models\{Student, StudentUniversityEmail, UniversityEmailOperation, User, UserActivityLog};
use App\Support\UniversityEmailAccess as Access;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/** Durable identities, short local transactions, single remote writes, no stored credentials. */
final class UniversityEmailProvisioningService
{
    public function __construct(private readonly DataScopeService $scope, private readonly MailcowProvisioningClient $remote) {}

    public static function schemaReady(): bool
    {
        return Schema::hasTable('university_email_operations') && Schema::hasTable('university_email_receipts')
            && Schema::hasColumns('university_email_operations', ['cancelled_at', 'cancelled_by_user_id'])
            && Schema::hasColumns('student_university_emails', ['creation_operation_id', 'credential_operation_id']);
    }

    private function authorize(User $user, int $student, string $permission): void
    {
        Access::authorize($user, Access::VIEW);
        Access::authorize($user, $permission);
        abort_unless($this->scope->scopeManualGradeStudents(Student::query(), $user)->whereKey($student)->exists(), 403);
        if (! self::schemaReady()) $this->fail('university_email_phase2_schema_not_ready', 503);
    }

    private function locked(int $student, ?User $user = null): StudentUniversityEmail
    {
        Student::query()->whereKey($student)->lockForUpdate()->firstOrFail();
        if ($user) {
            Access::authorize($user, Access::VIEW);
            abort_unless($this->scope->scopeManualGradeStudents(Student::query(), $user)->whereKey($student)->exists(), 403);
        }
        $email = StudentUniversityEmail::where('student_id', $student)->lockForUpdate()->first();
        if (! $email) $this->fail('university_email_draft_required');
        return $email;
    }

    public function state(User $user, int $student): array
    {
        $this->authorize($user, $student, Access::VIEW);
        $email = StudentUniversityEmail::where('student_id', $student)->first();
        return $this->describe($email);
    }

    private function describe(?StudentUniversityEmail $email): array
    {
        $operations = $email ? UniversityEmailOperation::where('university_email_id', $email->university_email_id)
            ->orderByRaw('CASE WHEN active_slot = 1 THEN 0 WHEN operation_id = ? THEN 1 ELSE 2 END', [$email->credential_operation_id ?? ''])
            ->orderByDesc('created_at')->orderByDesc('operation_id')->limit(50)->get() : collect();
        return ['schema_ready' => true, 'enabled' => $this->remote->enabled(), 'draft_locked' => $email && UniversityEmailOperation::where('university_email_id', $email->university_email_id)->where('status', '!=', 'cancelled')->exists(),
            'email_address' => $email?->email_address, 'provisioning_status' => $email?->provisioning_status,
            'handover_status' => $email?->handover_status, 'creation_operation_id' => $email?->creation_operation_id,
            'credential_operation_id' => $email?->credential_operation_id,
            'operations' => $operations->map(fn ($op) => $op->only(['operation_id', 'kind', 'status', 'generation', 'draft_revision', 'failure_code', 'write_started_at', 'verified_at', 'cancelled_at']) +
                ['can_cancel' => ! $op->write_started_at && in_array($op->status, ['prepared', 'failed', 'preflight', 'conflict'], true)])->all()];
    }

    /** Explicit cancellation releases only unused slots; no remote action or history deletion. */
    public function cancel(User $user, int $student, string $id, int $generation): array
    {
        $this->authorize($user, $student, Access::VIEW);
        return DB::transaction(function () use ($user, $student, $id, $generation) {
            $email = $this->locked($student, $user);
            $op = UniversityEmailOperation::whereKey($id)->where('university_email_id', $email->university_email_id)->lockForUpdate()->first();
            abort_unless($op, 403);
            Access::authorize($user, $op->kind === 'create' ? Access::CREATE : Access::RECOVER);
            if ($op->generation !== $generation || $op->write_started_at
                || ! in_array($op->status, ['prepared', 'failed', 'preflight', 'conflict'], true)) $this->fail('university_email_operation_not_cancellable');
            // Invalidates all issued proofs and the old preflight worker's generation.
            $op->generation++;
            $op->fill(['status' => 'cancelled', 'creation_slot' => null, 'active_slot' => null,
                'cancelled_at' => now(), 'cancelled_by_user_id' => $user->user_id, 'failure_code' => null]);
            $op->save();
            $this->audit($user, $email, $op, 'operation_cancelled');
            return $this->describe($email);
        });
    }

    /** Password and proof exist only in this response and the browser's ephemeral memory. */
    public function password(User $user, int $student, int $revision, string $kind): array
    {
        $this->authorize($user, $student, $kind === 'create' ? Access::CREATE : Access::RECOVER);
        Access::authorize($user, Access::RECEIPT);
        $this->remote->requireEnabled();
        if (strlen((string) config('app.key')) < 32) $this->fail('university_email_configuration_invalid', 503);
        $op = DB::transaction(function () use ($user, $student, $revision, $kind) {
            $email = $this->locked($student, $user);
            Access::authorize($user, $kind === 'create' ? Access::CREATE : Access::RECOVER);
            Access::authorize($user, Access::RECEIPT);
            if ($email->revision !== $revision) $this->fail('university_email_stale');
            if ($email->handover_status !== 'not_delivered') $this->fail('university_email_already_delivered');
            $number = strtolower(trim(Student::findOrFail($student)->student_number));
            if ($email->email_address !== $email->english_first_name.'.'.$number.'@alrowaduni.edu.sy' || $email->quota_mb !== 50) $this->fail('university_email_identity_invalid');
            if ($kind === 'create' && $email->provisioning_status !== 'draft') $this->fail('university_email_already_created');
            if ($kind === 'reset' && (! $email->creation_operation_id || $email->provisioning_status !== 'created')) $this->fail('university_email_not_owned');
            $active = UniversityEmailOperation::where('university_email_id', $email->university_email_id)->where('active_slot', 1)->lockForUpdate()->first();
            $op = $active ?? ($kind === 'create' ? UniversityEmailOperation::where('university_email_id', $email->university_email_id)->where('creation_slot', 1)->lockForUpdate()->first() : null);
            if ($op && ($op->kind !== $kind || ! in_array($op->status, ['prepared', 'failed'], true) || $op->write_started_at)) $this->fail('university_email_operation_not_retryable');
            if ($op) {
                $op->generation++;
                $op->fill(['issued_by_user_id' => $user->user_id, 'status' => 'prepared', 'active_slot' => 1, 'failure_code' => null]);
            } else {
                $op = new UniversityEmailOperation(['operation_id' => (string) Str::uuid(), 'university_email_id' => $email->university_email_id,
                    'kind' => $kind, 'creation_slot' => $kind === 'create' ? 1 : null, 'active_slot' => 1, 'status' => 'prepared',
                    'draft_revision' => $revision, 'email_address' => $email->email_address, 'quota_mb' => 50, 'generation' => 1,
                    'initiated_by_user_id' => $user->user_id, 'issued_by_user_id' => $user->user_id]);
            }
            $op->save();
            $this->audit($user, $email, $op, 'credentials_prepared');
            return $op;
        });
        $password = $this->generatePassword();
        return ['operation_id' => $op->operation_id, 'generation' => $op->generation, 'kind' => $op->kind,
            'password' => $password, 'credential_proof' => $this->proof($op, $user, $password)];
    }

    private function generatePassword(): string
    {
        $length = (int) config('mailcow.password_length');
        if ($length < 24 || $length > 64) $this->fail('university_email_configuration_invalid', 503);
        $pools = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '!@#$%?='];
        $characters = array_map(fn ($p) => $p[random_int(0, strlen($p) - 1)], $pools);
        $all = implode('', $pools);
        while (count($characters) < $length) $characters[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($characters) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]]; }
        return implode('', $characters);
    }

    private function proof(UniversityEmailOperation $op, User $user, #[\SensitiveParameter] string $password): string
    {
        return hash_hmac('sha256', $op->operation_id.'|'.$op->generation.'|'.$user->user_id.'|'.$password, (string) config('app.key'));
    }

    public function execute(User $user, int $student, #[\SensitiveParameter] array $input): array
    {
        // Lock/re-authorize exact persisted operation before any remote action.
        $this->authorize($user, $student, Access::VIEW);
        Access::authorize($user, Access::RECEIPT);
        $this->remote->requireEnabled();
        $op = DB::transaction(function () use ($user, $student, $input) {
            $email = $this->locked($student, $user);
            $op = UniversityEmailOperation::whereKey($input['operation_id'])->where('university_email_id', $email->university_email_id)->lockForUpdate()->first();
            abort_unless($op, 403);
            Access::authorize($user, $op->kind === 'create' ? Access::CREATE : Access::RECOVER);
            if ($op->status !== 'prepared' || $op->active_slot !== 1 || $op->generation !== (int) $input['generation']
                || $op->issued_by_user_id !== $user->user_id || $email->revision !== $op->draft_revision
                || $email->handover_status !== 'not_delivered' || ! hash_equals($this->proof($op, $user, $input['password']), $input['credential_proof'])) $this->fail('university_email_operation_stale');
            $op->status = 'preflight'; $op->save();
            return $op;
        });
        try {
            $mailbox = $this->remote->mailbox($op->email_address);
            if ($op->kind === 'create') {
                if ($mailbox || $this->remote->aliasExists($op->email_address)) $this->fail('university_email_address_conflict');
            } else {
                $email = StudentUniversityEmail::findOrFail($op->university_email_id);
                if (! $this->verified($mailbox, $email->creation_operation_id)) $this->fail('university_email_not_owned');
            }
            $studentName = DB::transaction(function () use ($op, $user) {
                $email = $this->locked(StudentUniversityEmail::findOrFail($op->university_email_id)->student_id, $user);
                Access::authorize($user, $op->kind === 'create' ? Access::CREATE : Access::RECOVER);
                Access::authorize($user, Access::RECEIPT);
                $current = UniversityEmailOperation::whereKey($op->operation_id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'preflight' || $current->generation !== $op->generation
                    || $current->issued_by_user_id !== $op->issued_by_user_id || $email->revision !== $op->draft_revision
                    || $email->email_address !== $op->email_address || $email->handover_status !== 'not_delivered') $this->fail('university_email_operation_stale');
                $current->status = 'in_progress'; $current->write_started_at = now(); $current->save();
                $student = Student::findOrFail($email->student_id);
                return trim($student->first_name.' '.$student->last_name);
            });
            $op->refresh();
            if ($op->kind === 'create') $this->remote->create($op->email_address, $this->marker($op->operation_id), $input['password'], $studentName);
            else $this->remote->reset($op->email_address, [...$mailbox['tags'], $this->marker($op->operation_id)], $input['password']);
            if (! $this->verified($this->remote->mailbox($op->email_address), $op->operation_id)) $this->fail('university_email_remote_verification_failed', 502);
            return $this->confirm($user, $student, $op, true);
        } catch (\Throwable $failure) {
            // Local-commit failure after remote creation never invokes DELETE or a second POST.
            $denied = $failure instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                && in_array($failure->getStatusCode(), [401, 403], true);
            $code = $denied ? 'university_email_access_denied' : ($failure instanceof UniversityEmailException ? $failure->errorCode : 'university_email_local_confirmation_failed');
            $this->recordFailure($op, $code);
            throw new UniversityEmailException($code, 'لم يتم تأكيد العملية. لا تُعد إرسالها؛ راجع الحالة أو نفّذ المصالحة المخصصة للقراءة فقط.', $denied ? 403 : 409);
        }
    }

    private function recordFailure(UniversityEmailOperation $op, string $code): void
    {
        try {
            DB::transaction(function () use ($op, $code) {
                $this->locked(StudentUniversityEmail::findOrFail($op->university_email_id)->student_id);
                $current = UniversityEmailOperation::whereKey($op->operation_id)->lockForUpdate()->firstOrFail();
                if (in_array($current->status, ['confirmed', 'cancelled'], true) || $current->generation !== $op->generation) return;
                $current->status = $current->write_started_at ? 'uncertain' : ($code === 'university_email_address_conflict' ? 'conflict' : 'failed');
                $current->active_slot = $current->write_started_at ? 1 : null;
                $current->failure_code = $code; $current->save();
            });
        } catch (\Throwable) { /* Durable preflight/in_progress survives for later read-only recovery. */ }
    }

    public function reconcile(User $user, int $student, string $id): array
    {
        $this->authorize($user, $student, Access::CREATE);
        $email = StudentUniversityEmail::where('student_id', $student)->firstOrFail();
        $op = UniversityEmailOperation::whereKey($id)->where('university_email_id', $email->university_email_id)->first();
        abort_unless($op, 403);
        if ($op->status === 'confirmed') return $this->describe($email);
        if (! in_array($op->status, ['preflight', 'in_progress', 'uncertain'], true) || $op->updated_at->greaterThan(now()->subSeconds(60))) $this->fail('university_email_operation_in_progress');
        if ($op->status === 'preflight' && ! $op->write_started_at) {
            // Cancel an abandoned read-only preflight under the same lock. The old
            // worker must recheck this status before obtaining its write authority.
            return DB::transaction(function () use ($user, $student, $op) {
                $email = $this->locked($student, $user);
                $current = UniversityEmailOperation::whereKey($op->operation_id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'preflight' || $current->write_started_at || $current->generation !== $op->generation) $this->fail('university_email_operation_stale');
                $current->status = 'failed'; $current->active_slot = null; $current->failure_code = 'university_email_preflight_abandoned'; $current->save();
                $this->audit($user, $email, $current, 'preflight_abandoned');
                return $this->describe($email);
            });
        }
        $mailbox = $this->remote->mailbox($op->email_address);
        // Tags prove module ownership, not what password won an uncertain reset.
        if ($op->kind !== 'create' || ! $op->write_started_at || ! $this->verified($mailbox, $op->operation_id)) $this->fail('university_email_manual_review_required');
        return $this->confirm($user, $student, $op, false);
    }

    private function confirm(User $user, int $student, UniversityEmailOperation $op, bool $credentialConfirmed): array
    {
        return DB::transaction(function () use ($user, $student, $op, $credentialConfirmed) {
            $email = $this->locked($student, $user);
            $current = UniversityEmailOperation::whereKey($op->operation_id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'confirmed') return $this->describe($email);
            if (! in_array($current->status, ['in_progress', 'uncertain'], true) || $current->generation !== $op->generation
                || $email->revision !== $op->draft_revision || $email->handover_status !== 'not_delivered') $this->fail('university_email_operation_stale');
            $current->status = 'confirmed'; $current->active_slot = null; $current->verified_at = now(); $current->failure_code = null; $current->save();
            if ($op->kind === 'create') { $email->creation_operation_id = $op->operation_id; $email->provisioning_status = 'created'; }
            $email->credential_operation_id = $credentialConfirmed ? $op->operation_id : null;
            $email->save();
            $this->audit($user, $email, $current, $credentialConfirmed ? $op->kind.'_confirmed' : 'creation_reconciled');
            return $this->describe($email);
        });
    }

    private function marker(?string $id): string { return 'alrowad-university-email:'.$id; }
    private function verified(?array $box, ?string $id): bool
    {
        return $id && $box && $box['domain'] === 'alrowaduni.edu.sy' && $box['quota_bytes'] === 50 * 1048576
            && $box['active'] && $box['force_password_change'] && in_array($this->marker($id), $box['tags'], true);
    }

    public function receipt(User $user, int $student, string $id, int $generation): array
    {
        $this->authorize($user, $student, Access::RECEIPT);
        return DB::transaction(function () use ($user, $student, $id, $generation) {
            $email = $this->locked($student, $user);
            Access::authorize($user, Access::RECEIPT);
            $op = UniversityEmailOperation::whereKey($id)->where('university_email_id', $email->university_email_id)->lockForUpdate()->first();
            if (! $op || $op->status !== 'confirmed' || $op->generation !== $generation || $op->issued_by_user_id !== $user->user_id
                || $email->credential_operation_id !== $id || ! $email->creation_operation_id || $email->handover_status !== 'not_delivered'
                || UniversityEmailOperation::where('university_email_id', $email->university_email_id)->where('active_slot', 1)->exists()) $this->fail('university_email_credentials_unavailable');
            $receipt = DB::table('university_email_receipts')->where('university_email_id', $email->university_email_id)
                ->where('credential_operation_id', $id)->where('issued_by_user_id', $user->user_id)->first();
            if (! $receipt) {
                $receipt = (object) ['receipt_id' => (string) Str::uuid(), 'university_email_id' => $email->university_email_id,
                    'credential_operation_id' => $id, 'issued_by_user_id' => $user->user_id, 'issued_at' => now()->toDateTimeString()];
                DB::table('university_email_receipts')->insert((array) $receipt);
                $this->audit($user, $email, $op, 'receipt_issued', ['receipt_id' => $receipt->receipt_id]);
            }
            $safe = app(UniversityEmailService::class)->show($user, $student)['student'];
            return ['receipt_id' => $receipt->receipt_id, 'operation_id' => $id, 'generation' => $generation,
                'student' => $safe, 'email_address' => $email->email_address,
                'issued_at' => \Illuminate\Support\Carbon::parse($receipt->issued_at, 'UTC')->toIso8601String(),
                'employee' => app(UserIdentityService::class)->documentGenerator($user)['display_name'],
                'account_url' => config('mailcow.account_url'), 'webmail_url' => config('mailcow.webmail_url')];
        });
    }

    public function selfEmail(User $user): array
    {
        abort_unless($user->accountStatus?->status_code === 'active' && $user->student_id && $user->effectiveRoles()->contains('student'), 403);
        if (! self::schemaReady()) return ['available' => false, 'reason' => 'university_email_phase2_schema_not_ready'];
        $email = StudentUniversityEmail::where('student_id', $user->student_id)->where('provisioning_status', 'created')->first();
        $confirmed = $email?->creation_operation_id && UniversityEmailOperation::whereKey($email->creation_operation_id)->where('kind', 'create')->where('status', 'confirmed')->exists();
        return ['available' => true, 'created' => (bool) $confirmed, 'email_address' => $confirmed ? $email->email_address : null,
            'handover_status' => $confirmed ? $email->handover_status : null, 'account_url' => config('mailcow.account_url'), 'webmail_url' => config('mailcow.webmail_url')];
    }

    private function audit(User $user, StudentUniversityEmail $email, UniversityEmailOperation $op, string $action, array $extra = []): void
    {
        UserActivityLog::create(['user_id' => $user->user_id, 'module_code' => 'users_permissions', 'action_code' => 'university_email.'.$action,
            'description' => json_encode(['student_id' => $email->student_id, 'record_id' => $email->university_email_id,
                'operation_id' => $op->operation_id, 'revision' => $op->draft_revision, 'generation' => $op->generation] + $extra, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function fail(string $code, int $status = 409): never
    {
        throw new UniversityEmailException($code, 'العملية غير متاحة بحالتها الحالية. راجع الحالة الرسمية والصلاحيات قبل المتابعة.', $status);
    }
}
