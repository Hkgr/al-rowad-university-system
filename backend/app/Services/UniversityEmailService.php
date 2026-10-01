<?php

namespace App\Services;

use App\Exceptions\UniversityEmailException;
use App\Models\{Student, StudentUniversityEmail, User, UserActivityLog};
use App\Support\UniversityEmailAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

final class UniversityEmailService
{
    public function __construct(private readonly DataScopeService $scope) {}

    private function students(User $user): Builder
    {
        // Existing explicit academic scopes, without virtual admin or teaching grants.
        return $this->scope->scopeManualGradeStudents(Student::query(), $user);
    }

    public function search(User $user, array $input): array
    {
        UniversityEmailAccess::authorize($user, UniversityEmailAccess::VIEW);
        $q = trim($input['q'] ?? '');
        $query = $this->students($user)->with('academicProgram.department.college');
        if ($q !== '') $query->where(fn (Builder $query) => $query->where('student_number', 'like', '%'.$q.'%')
            ->orWhereRaw("TRIM(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.$q.'%']));
        // CONCAT works on MariaDB and the supported Laravel SQLite connection.
        $page = $query->orderBy('student_number')->orderBy('student_id')->paginate($input['per_page'] ?? 15, ['*'], 'page', $input['page'] ?? 1);
        $schemaReady = Schema::hasTable('student_university_emails');
        // One bounded local lookup per page, never a Mailcow call or per-student query.
        $drafts = $schemaReady ? StudentUniversityEmail::query()
            ->whereIn('student_id', $page->getCollection()->modelKeys())
            ->get(['student_id', 'email_address', 'provisioning_status', 'handover_status'])->keyBy('student_id') : collect();
        return ['data' => $page->getCollection()->map(fn (Student $s) => $this->studentData($s) +
                ['email_preparation' => $this->emailSummary($drafts->get($s->student_id), $schemaReady)])->all(),
            'email_schema_ready' => $schemaReady,
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]];
    }

    public function show(User $user, int $id): array
    {
        UniversityEmailAccess::authorize($user, UniversityEmailAccess::VIEW);
        $student = $this->students($user)->with('academicProgram.department.college')->find($id);
        abort_unless($student, 403);
        $this->schemaReady();
        return $this->projection($student);
    }

    public function save(User $user, int $id, array $input): array
    {
        UniversityEmailAccess::authorize($user, UniversityEmailAccess::VIEW);
        UniversityEmailAccess::authorize($user, UniversityEmailAccess::MANAGE);
        $this->schemaReady();
        try {
            return DB::transaction(function () use ($user, $id, $input): array {
                // Serializes first creation and every edit for the same student.
                $student = Student::query()->lockForUpdate()->find($id);
                abort_unless($student && $this->students($user)->whereKey($id)->exists(), 403);
                $draft = StudentUniversityEmail::query()->where('student_id', $id)->lockForUpdate()->first();
                if (($draft?->revision ?? 0) !== $input['revision']) {
                    throw new UniversityEmailException('university_email_stale', 'تغيرت المسودة؛ راجع النسخة المحفوظة قبل تأكيد التعديل.');
                }
                if ($draft && ($draft->provisioning_status !== 'draft' || $draft->handover_status !== 'not_delivered')) {
                    throw new UniversityEmailException('university_email_not_draft', 'لا يمكن تعديل عنوان تم إنشاؤه أو تسليمه من تجهيز المسودات.');
                }
                $name = strtolower(preg_replace('/\A\s+|\s+\z/u', '', $input['english_first_name']));
                if ($draft && Schema::hasTable('university_email_operations')
                    && \App\Models\UniversityEmailOperation::where('university_email_id', $draft->university_email_id)->where('status', '!=', 'cancelled')->exists()) {
                    throw new UniversityEmailException('university_email_identity_frozen', 'بدأت عملية إنشاء مرتبطة بهذه المسودة؛ لا يمكن تغيير عنوانها.');
                }
                $number = strtolower(trim($student->student_number));
                $settings = $this->settings();
                if (! preg_match('/\A[a-z]+\z/D', $name) || ! preg_match('/\A[a-z0-9]+\z/D', $number)) {
                    throw ValidationException::withMessages(['english_first_name' => 'اكتب أحرفًا إنكليزية فقط؛ الاسم المركب متصل مثل abdulrahman. يجب أن يكون الرقم الجامعي صالحًا.']);
                }
                $local = $name.'.'.$number;
                $email = $local.'@'.$settings['domain'];
                if (strlen($local) > 64 || strlen($email) > 254) {
                    throw ValidationException::withMessages(['english_first_name' => 'العنوان يتجاوز الطول المسموح؛ لا يمكن اختصاره تلقائيًا.']);
                }
                if ($draft && $draft->english_first_name === $name && $draft->email_address === $email && $draft->quota_mb === $settings['quota_mb']) {
                    return $this->projection($student);
                }
                $created = $draft === null;
                $draft ??= new StudentUniversityEmail(['student_id' => $id, 'created_by_user_id' => $user->user_id]);
                $draft->fill(['english_first_name' => $name, 'email_address' => $email, 'quota_mb' => $settings['quota_mb'],
                    'provisioning_status' => 'draft', 'handover_status' => 'not_delivered',
                    'revision' => ($draft->revision ?? 0) + 1, 'updated_by_user_id' => $user->user_id]);
                $draft->save();
                UserActivityLog::create(['user_id' => $user->user_id, 'module_code' => 'users_permissions',
                    'action_code' => $created ? 'university_email.draft_created' : 'university_email.draft_updated',
                    'description' => json_encode(['student_id' => $id, 'record_id' => $draft->university_email_id, 'revision' => $draft->revision], JSON_THROW_ON_ERROR),
                    'created_at' => now()]);
                return $this->projection($student);
            });
        } catch (QueryException $e) {
            if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException
                || in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
                throw new UniversityEmailException('university_email_conflict', 'تعارض حفظ المسودة؛ أعد تحميل البيانات وراجعها دون إعادة إرسال تلقائية.');
            }
            throw $e;
        }
    }

    private function schemaReady(): void
    {
        if (! Schema::hasTable('student_university_emails')) throw new UniversityEmailException('university_email_schema_not_ready', 'تجهيز البريد غير جاهز؛ راجع مسؤول النظام.', 503);
    }
    private function settings(): array
    {
        $domain = (string) config('mailcow.student_domain');
        if ($domain !== 'alrowaduni.edu.sy' || (int) config('mailcow.student_quota_mb') !== 50) {
            throw new UniversityEmailException('university_email_configuration_invalid', 'يلزم نطاق الطلاب المعتمد وحصة 50 MiB؛ راجع الإعدادات.', 503);
        }
        return ['domain' => $domain, 'quota_mb' => 50];
    }
    private function projection(Student $student): array
    {
        $student->loadMissing('academicProgram.department.college');
        $draft = StudentUniversityEmail::query()->where('student_id', $student->student_id)->first();
        return ['student' => $this->studentData($student) + ['email_preparation' => $this->emailSummary($draft, true)], 'draft' => $draft ? $draft->only(['university_email_id', 'english_first_name', 'email_address', 'quota_mb', 'provisioning_status', 'handover_status', 'revision', 'created_at', 'updated_at']) : null,
            'settings' => $this->settings(), 'draft_locked' => $draft && Schema::hasTable('university_email_operations')
                && \App\Models\UniversityEmailOperation::where('university_email_id', $draft->university_email_id)->where('status', '!=', 'cancelled')->exists()];
    }
    private function emailSummary(?StudentUniversityEmail $draft, bool $available): array
    {
        return ['available' => $available, 'email_address' => $draft?->email_address,
            'provisioning_status' => $draft?->provisioning_status, 'handover_status' => $draft?->handover_status];
    }
    private function studentData(Student $student): array
    {
        return ['student_id' => $student->student_id, 'student_number' => $student->student_number,
            'full_name' => trim($student->first_name.' '.$student->last_name),
            'program' => $student->academicProgram?->program_name, 'college' => $student->academicProgram?->department?->college?->college_name];
    }
}
