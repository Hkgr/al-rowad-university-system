<?php

namespace App\Services;

use App\Exceptions\AcademicCalendarException;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicCalendarEventType;
use App\Models\AcademicCalendarEventVersion;
use App\Models\AcademicCalendarYearLifecycleEvent;
use App\Models\AcademicYear;
use App\Models\CourseOfferingMinimumEnrollmentReview;
use App\Models\Semester;
use App\Models\StudentRegistrationReplacementRequest;
use App\Models\User;
use App\Support\AcademicCalendar;
use App\Support\SemesterRegistrationPhase6;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AcademicCalendarService
{
    private const COURSE_REGISTRATION_EVENT_TYPE = 'course_registration';
    private const COURSE_REGISTRATION_REPLACEMENT_EVENT_TYPE = 'course_registration_replacement';

    public function catalog(?User $manager = null): array
    {
        $this->assertSchemaReady();
        if ($manager !== null) {
            $this->assertCanManage($manager);
        }

        return [
            'academic_years' => AcademicYear::query()->orderByDesc('is_current')->orderByDesc('start_date')->get([
                'academic_year_id', 'year_name', 'start_date', 'end_date', 'is_current', 'is_active', 'calendar_lifecycle_status',
            ]),
            'semesters' => Semester::query()->orderBy('semester_order')->get([
                'semester_id', 'semester_code', 'semester_name', 'semester_order', 'is_active',
            ]),
            'event_types' => AcademicCalendarEventType::query()->orderBy('event_type_kind')->orderBy('academic_calendar_event_type_id')->get(),
        ];
    }

    public function publicEvents(array $filters): Collection
    {
        $this->assertSchemaReady();
        $yearId = $filters['academic_year_id'] ?? null;
        if ($yearId === null) {
            $currentIds = AcademicYear::query()->where('is_current', true)->pluck('academic_year_id');
            $yearId = $currentIds->count() === 1 ? $currentIds->first() : null;
        }
        if ($yearId === null) {
            throw AcademicCalendarException::conflict('لا توجد سنة أكاديمية حالية واحدة لعرض التقويم.');
        }

        $query = AcademicCalendarEvent::query()
            ->with(['academicYear', 'semester', 'eventType', 'versions' => fn ($q) => $q->where('publication_status', 'published')])
            ->where('academic_year_id', $yearId)
            ->whereHas('versions', fn ($q) => $q->where('publication_status', 'published'));

        if (isset($filters['academic_calendar_event_type_id'])) {
            $query->where('academic_calendar_event_type_id', (int) $filters['academic_calendar_event_type_id']);
        }
        if (isset($filters['semester_id'])) {
            $query->where(fn ($q) => $q->where('semester_id', (int) $filters['semester_id'])->orWhereNull('semester_id'));
        }
        if (isset($filters['from'])) {
            $from = CarbonImmutable::parse($filters['from'])->utc();
            $query->whereHas('versions', fn ($q) => $q->where('publication_status', 'published')->where('ends_at', '>=', $from));
        }
        if (isset($filters['to'])) {
            $to = CarbonImmutable::parse($filters['to'])->utc();
            $query->whereHas('versions', fn ($q) => $q->where('publication_status', 'published')->where('starts_at', '<=', $to));
        }

        return $query->get()->map(fn (AcademicCalendarEvent $event) => $this->publicPayload($event))
            ->sortBy('starts_at')->values();
    }

    public function managementEvents(User $user, array $filters): Collection
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();
        $query = AcademicCalendarEvent::query()->with(['academicYear', 'semester', 'eventType', 'versions' => fn ($q) => $q->orderByDesc('version_number')]);
        if (isset($filters['academic_year_id'])) {
            $query->where('academic_year_id', (int) $filters['academic_year_id']);
        }
        if (isset($filters['academic_calendar_event_type_id'])) {
            $query->where('academic_calendar_event_type_id', (int) $filters['academic_calendar_event_type_id']);
        }
        if (isset($filters['semester_id'])) {
            $query->where(fn ($q) => $q->where('semester_id', (int) $filters['semester_id'])->orWhereNull('semester_id'));
        }

        return $query->get()->map(fn (AcademicCalendarEvent $event) => $this->managementPayload($event));
    }

    public function createDraft(User $user, array $data): array
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();

        return DB::transaction(function () use ($user, $data): array {
            AcademicCalendarEvent::query()
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('academic_calendar_event_type_id', $data['academic_calendar_event_type_id'])
                ->when(
                    ($data['semester_id'] ?? null) === null,
                    fn ($query) => $query->whereNull('semester_id'),
                    fn ($query) => $query->where('semester_id', $data['semester_id']),
                )
                ->orderBy('academic_calendar_event_id')
                ->lockForUpdate()
                ->get(['academic_calendar_event_id']);
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
            $this->assertYearMutable($year);
            $eventType = AcademicCalendarEventType::query()->where('is_active', true)->lockForUpdate()->findOrFail($data['academic_calendar_event_type_id']);
            if (($data['semester_id'] ?? null) !== null) {
                Semester::query()->lockForUpdate()->findOrFail($data['semester_id']);
            }
            $this->assertUniqueRegistrationRoot(
                $eventType,
                (int) $year->academic_year_id,
                isset($data['semester_id']) ? (int) $data['semester_id'] : null,
            );
            $data = $this->enforceRegistrationDeadlineSemantics(
                $eventType,
                isset($data['semester_id']) ? (int) $data['semester_id'] : null,
                $data,
                requireExplicitSpecialized: true,
            );

            $event = AcademicCalendarEvent::query()->create([
                'academic_year_id' => $year->academic_year_id,
                'semester_id' => $data['semester_id'] ?? null,
                'academic_calendar_event_type_id' => $data['academic_calendar_event_type_id'],
                'created_by_user_id' => $user->user_id,
                'created_at' => now(),
            ]);
            $version = $event->versions()->create($this->versionData($data) + [
                'version_number' => 1,
                'replaces_version_id' => null,
                'change_reason' => AcademicCalendar::INITIAL_CHANGE_REASON,
                'created_by_user_id' => $user->user_id,
                'created_at' => now(),
                'publication_status' => 'draft',
            ]);

            return $this->mutationPayload($event, $version);
        });
    }

    public function editDraft(User $user, AcademicCalendarEvent $event, AcademicCalendarEventVersion $version, array $data): array
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();

        return DB::transaction(function () use ($event, $version, $data): array {
            $lockedEvent = AcademicCalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($lockedEvent->academic_year_id);
            $this->assertYearMutable($year);
            $draft = $lockedEvent->versions()->lockForUpdate()->findOrFail($version->getKey());
            if ($draft->publication_status !== 'draft') {
                throw AcademicCalendarException::conflict('لا يمكن تعديل نسخة منشورة أو مستبدلة.');
            }
            $hasPublishedHistory = $lockedEvent->versions()->whereIn('publication_status', ['published', 'superseded'])->exists();
            if ($hasPublishedHistory && collect(['academic_year_id', 'semester_id', 'academic_calendar_event_type_id'])->contains(fn ($key) => array_key_exists($key, $data))) {
                throw AcademicCalendarException::conflict('لا يمكن تغيير السياق الأكاديمي بعد النشر؛ ألغِ الحدث وأنشئ حدثاً جديداً.');
            }
            if (! $hasPublishedHistory) {
                if (isset($data['academic_year_id']) && (int) $data['academic_year_id'] !== (int) $lockedEvent->academic_year_id) {
                    $this->assertYearMutable(AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']));
                }
                if (array_key_exists('semester_id', $data) && $data['semester_id'] !== null) {
                    Semester::query()->lockForUpdate()->findOrFail($data['semester_id']);
                }
                if (isset($data['academic_calendar_event_type_id'])) {
                    AcademicCalendarEventType::query()->where('is_active', true)->lockForUpdate()->findOrFail($data['academic_calendar_event_type_id']);
                }
                foreach (['academic_year_id', 'semester_id', 'academic_calendar_event_type_id'] as $key) {
                    if (array_key_exists($key, $data)) {
                        $lockedEvent->{$key} = $data[$key];
                    }
                }
                $lockedEvent->save();
            }
            $eventType = AcademicCalendarEventType::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($lockedEvent->academic_calendar_event_type_id);
            $this->assertPhase6CalendarMutationAllowed($eventType, $lockedEvent);
            $this->assertUniqueRegistrationRoot(
                $eventType,
                (int) $lockedEvent->academic_year_id,
                $lockedEvent->semester_id === null ? null : (int) $lockedEvent->semester_id,
                (int) $lockedEvent->getKey(),
            );
            $data = $this->enforceRegistrationDeadlineSemantics(
                $eventType,
                $lockedEvent->semester_id === null ? null : (int) $lockedEvent->semester_id,
                $data,
                $draft,
            );
            $draft->fill($this->versionData($data));
            if (array_key_exists('public_notes', $data)) {
                $draft->public_notes = $data['public_notes'];
            }
            if (isset($data['change_reason'])) {
                $draft->change_reason = trim($data['change_reason']);
            }
            if (($draft->replaces_version_id !== null || $draft->starts_at->lte(now())) && blank($data['change_reason'] ?? null)) {
                throw AcademicCalendarException::conflict('سبب التغيير مطلوب للمسودة البديلة أو للحدث الذي بدأ بالفعل.');
            }
            if ($draft->ends_at->lt($draft->starts_at)) {
                throw AcademicCalendarException::conflict('يجب ألا يسبق وقت النهاية وقت البداية.');
            }
            $draft->save();

            return $this->mutationPayload($lockedEvent, $draft);
        });
    }

    public function createReplacementDraft(User $user, AcademicCalendarEvent $event, array $data): array
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();

        return DB::transaction(function () use ($user, $event, $data): array {
            $locked = AcademicCalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $this->assertYearMutable(AcademicYear::query()->lockForUpdate()->findOrFail($locked->academic_year_id));
            if ($locked->cancelled_at !== null) {
                throw AcademicCalendarException::conflict('لا يمكن إنشاء بديل لحدث ملغى.');
            }
            $versions = $locked->versions()->lockForUpdate()->get();
            $eventType = AcademicCalendarEventType::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($locked->academic_calendar_event_type_id);
            $this->assertPhase6CalendarMutationAllowed($eventType, $locked);
            $published = $versions->firstWhere('publication_status', 'published');
            if ($published === null || $versions->contains('publication_status', 'draft')) {
                throw AcademicCalendarException::conflict('يجب وجود نسخة منشورة واحدة وألا توجد مسودة معلقة.');
            }
            if (blank($data['change_reason'] ?? null)) {
                throw AcademicCalendarException::conflict('سبب التغيير مطلوب.');
            }
            if ($this->isRegistrationDeadlineType($eventType)
                && collect(['starts_at', 'student_registration_ends_at', 'advisor_approval_ends_at'])
                    ->contains(fn (string $key): bool => ! array_key_exists($key, $data) || $data[$key] === null)) {
                throw AcademicCalendarException::conflict('يجب تحديد بداية التسجيل وموعد الطالب وموعد اعتماد المرشد صراحةً في النسخة البديلة.');
            }
            $payload = array_merge([
                'title' => $published->title,
                'public_notes' => $published->public_notes,
                'starts_at' => $published->starts_at,
                'ends_at' => $published->ends_at,
                'is_enforcement' => $published->is_enforcement,
            ], $data);
            $payload = $this->enforceRegistrationDeadlineSemantics(
                $eventType,
                $locked->semester_id === null ? null : (int) $locked->semester_id,
                $payload,
                $published,
                requireExplicitSpecialized: $this->isRegistrationDeadlineType($eventType),
            );
            if (CarbonImmutable::parse($payload['ends_at'])->lt(CarbonImmutable::parse($payload['starts_at']))) {
                throw AcademicCalendarException::conflict('يجب ألا يسبق وقت النهاية وقت البداية.');
            }
            $draft = $locked->versions()->create($this->versionData($payload) + [
                'version_number' => ((int) $versions->max('version_number')) + 1,
                'replaces_version_id' => $published->getKey(),
                'change_reason' => trim($data['change_reason']),
                'created_by_user_id' => $user->user_id,
                'created_at' => now(),
                'publication_status' => 'draft',
            ]);

            return $this->mutationPayload($locked, $draft);
        });
    }

    public function publish(User $user, AcademicCalendarEvent $event, AcademicCalendarEventVersion $version): array
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();

        try {
            return DB::transaction(function () use ($user, $event, $version): array {
                $locked = AcademicCalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey());
                $this->assertYearMutable(AcademicYear::query()->lockForUpdate()->findOrFail($locked->academic_year_id));
                if ($locked->cancelled_at !== null) {
                    throw AcademicCalendarException::conflict('لا يمكن نشر حدث ملغى.');
                }
                $versions = $locked->versions()->lockForUpdate()->get();
                $eventType = AcademicCalendarEventType::query()
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->findOrFail($locked->academic_calendar_event_type_id);
                $draft = $versions->firstWhere('academic_calendar_event_version_id', $version->getKey());
                if ($draft === null || $draft->publication_status !== 'draft') {
                    throw AcademicCalendarException::conflict('المسودة تغيرت أو نُشرت مسبقاً.');
                }
                $published = $versions->firstWhere('publication_status', 'published');
                if ($published !== null && $draft->replaces_version_id === null) {
                    throw AcademicCalendarException::conflict('توجد نسخة منشورة؛ يجب استخدام مسودة بديلة مرتبطة بها.');
                }
                if ($draft->replaces_version_id !== null && ($published === null || (int) $draft->replaces_version_id !== (int) $published->getKey())) {
                    throw AcademicCalendarException::conflict('لم تعد المسودة تستبدل النسخة المنشورة الحالية.');
                }
                $this->assertUniqueRegistrationRoot(
                    $eventType,
                    (int) $locked->academic_year_id,
                    $locked->semester_id === null ? null : (int) $locked->semester_id,
                    (int) $locked->getKey(),
                );
                $this->enforceRegistrationDeadlineSemantics(
                    $eventType,
                    $locked->semester_id === null ? null : (int) $locked->semester_id,
                    [],
                    $draft,
                );
                $this->assertPhase6PublicationAllowed($eventType, $locked);
                $now = now();
                if ($published !== null) {
                    $published->publication_status = 'superseded';
                    $published->superseded_at = $now;
                    $published->save();
                }
                $draft->publication_status = 'published';
                $draft->published_by_user_id = $user->user_id;
                $draft->published_at = $now;
                $draft->save();

                return $this->mutationPayload($locked, $draft);
            });
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') === '23000') {
                throw AcademicCalendarException::conflict('تعارض نشر متزامن؛ أعد تحميل الحدث وحاول مجدداً.');
            }
            throw $exception;
        }
    }

    public function deleteDraft(User $user, AcademicCalendarEvent $event, AcademicCalendarEventVersion $version): void
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();
        DB::transaction(function () use ($event, $version): void {
            $locked = AcademicCalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $this->assertYearMutable(AcademicYear::query()->lockForUpdate()->findOrFail($locked->academic_year_id));
            $versions = $locked->versions()->lockForUpdate()->get();
            $draft = $versions->firstWhere('academic_calendar_event_version_id', $version->getKey());
            if ($draft === null || $draft->publication_status !== 'draft') {
                throw AcademicCalendarException::conflict('لا يمكن حذف نسخة منشورة أو مستبدلة.');
            }
            $draft->delete();
            if ($versions->count() === 1) {
                $locked->delete();
            }
        });
    }

    public function cancel(User $user, AcademicCalendarEvent $event, string $reason): array
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();
        if (blank($reason)) {
            throw AcademicCalendarException::conflict('سبب الإلغاء مطلوب.');
        }

        return DB::transaction(function () use ($user, $event, $reason): array {
            $locked = AcademicCalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $this->assertYearMutable(AcademicYear::query()->lockForUpdate()->findOrFail($locked->academic_year_id));
            $versions = $locked->versions()->lockForUpdate()->get();
            if ($locked->cancelled_at !== null || ! $versions->contains('publication_status', 'published')) {
                throw AcademicCalendarException::conflict('الحدث غير منشور أو ملغى مسبقاً.');
            }
            if ($versions->contains('publication_status', 'draft')) {
                throw AcademicCalendarException::conflict('احذف المسودة البديلة قبل إلغاء الحدث.');
            }
            $eventType = AcademicCalendarEventType::query()->findOrFail($locked->academic_calendar_event_type_id);
            $this->assertPhase6CalendarMutationAllowed($eventType, $locked);
            $locked->cancelled_by_user_id = $user->user_id;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = trim($reason);
            $locked->save();

            return $this->managementPayload($locked->fresh(['academicYear', 'semester', 'eventType', 'versions']));
        });
    }

    public function history(User $user, AcademicCalendarEvent $event): array
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();
        return $this->managementPayload($event->load(['academicYear', 'semester', 'eventType', 'versions' => fn ($q) => $q->orderByDesc('version_number')]));
    }

    public function transitionYear(User $user, AcademicYear $year, string $action, string $reason): array
    {
        $this->assertCanManage($user);
        $this->assertSchemaReady();
        if (blank($reason)) {
            throw AcademicCalendarException::conflict('سبب تغيير دورة حياة السنة مطلوب.');
        }

        return DB::transaction(function () use ($user, $year, $action, $reason): array {
            $years = AcademicYear::query()->orderBy('academic_year_id')->lockForUpdate()->get();
            $target = $years->firstWhere('academic_year_id', $year->getKey());
            $active = $years->where('calendar_lifecycle_status', 'active');
            $current = $years->where('is_current', true);
            if ($target === null || $active->count() !== 1 || $current->count() !== 1 || (int) $active->first()->getKey() !== (int) $current->first()->getKey()) {
                throw AcademicCalendarException::conflict('حالة السنوات الحالية غير متسقة؛ لم يُنفذ أي تغيير.');
            }
            $now = now();
            if ($action === 'activate') {
                if ($target->calendar_lifecycle_status !== 'draft' || ! $target->is_active || $target->is_current) {
                    throw AcademicCalendarException::conflict('يمكن تفعيل سنة مسودة ومتاحة وغير حالية فقط.');
                }
                $previous = $active->first();
                $previous->calendar_lifecycle_status = 'closed';
                $previous->is_current = false;
                $previous->save();
                $this->auditYear($previous, 'active', 'closed', $user, $reason, $now);
                $target->calendar_lifecycle_status = 'active';
                $target->is_current = true;
                $target->save();
                $this->auditYear($target, 'draft', 'active', $user, $reason, $now);
            } elseif ($action === 'reopen') {
                if ($target->calendar_lifecycle_status !== 'closed' || $target->is_current) {
                    throw AcademicCalendarException::conflict('يمكن إعادة فتح سنة مغلقة وغير حالية فقط.');
                }
                $target->calendar_lifecycle_status = 'draft';
                $target->save();
                $this->auditYear($target, 'closed', 'draft', $user, $reason, $now);
            } elseif ($action === 'close') {
                if ($target->calendar_lifecycle_status !== 'draft' || $target->is_current) {
                    throw AcademicCalendarException::conflict('يمكن إغلاق سنة مسودة وغير حالية فقط.');
                }
                $target->calendar_lifecycle_status = 'closed';
                $target->save();
                $this->auditYear($target, 'draft', 'closed', $user, $reason, $now);
            } else {
                throw AcademicCalendarException::conflict('إجراء دورة حياة غير معروف.');
            }

            return $target->fresh()->toArray();
        });
    }

    public function assertCanManage(User $user): void
    {
        if (! $user->isScientificVicePresident() || ! $user->effectivePermissions()->contains(AcademicCalendar::PERMISSION_MANAGE)) {
            throw AcademicCalendarException::forbidden();
        }
    }

    private function assertSchemaReady(): void
    {
        if (! AcademicCalendar::schemaReady()) {
            throw AcademicCalendarException::schemaNotReady();
        }
    }

    private function assertYearMutable(AcademicYear $year): void
    {
        if ($year->calendar_lifecycle_status === 'closed') {
            throw AcademicCalendarException::conflict('السنة الأكاديمية مغلقة؛ أعد فتحها للتصحيح أولاً.');
        }
    }

    private function assertUniqueRegistrationRoot(
        AcademicCalendarEventType $eventType,
        int $academicYearId,
        ?int $semesterId,
        ?int $exceptEventId = null,
    ): void {
        if (! $this->isRegistrationDeadlineType($eventType)) {
            return;
        }
        if ($semesterId === null) {
            throw AcademicCalendarException::conflict('يجب ربط نافذة تسجيل المقررات بفصل دراسي محدد.');
        }

        $duplicate = AcademicCalendarEvent::query()
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->where('academic_calendar_event_type_id', $eventType->getKey())
            ->whereNull('cancelled_at')
            ->when($exceptEventId !== null, fn ($query) => $query->whereKeyNot($exceptEventId))
            ->lockForUpdate()
            ->exists();

        if ($duplicate) {
            throw AcademicCalendarException::conflict('توجد نافذة تسجيل مقررات غير ملغاة لهذه السنة والفصل بالفعل.');
        }
    }

    private function enforceRegistrationDeadlineSemantics(
        AcademicCalendarEventType $eventType,
        ?int $semesterId,
        array $data,
        ?AcademicCalendarEventVersion $base = null,
        bool $requireExplicitSpecialized = false,
    ): array {
        $specialized = ['student_registration_ends_at', 'advisor_approval_ends_at'];
        if (! $this->isRegistrationDeadlineType($eventType)) {
            foreach ($specialized as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== null) {
                    throw AcademicCalendarException::conflict('المواعيد النهائية المتخصصة متاحة لنافذة تسجيل المقررات فقط.');
                }
                $data[$key] = null;
            }

            return $data;
        }

        if ($semesterId === null) {
            throw AcademicCalendarException::conflict('يجب ربط نافذة تسجيل المقررات بفصل دراسي محدد.');
        }
        if ($requireExplicitSpecialized) {
            foreach ($specialized as $key) {
                if (! array_key_exists($key, $data) || $data[$key] === null || trim((string) $data[$key]) === '') {
                    throw AcademicCalendarException::conflict('يجب تحديد الموعد النهائي للطالب والموعد النهائي لاعتماد المرشد صراحةً.');
                }
            }
        }

        $startsAt = $data['starts_at'] ?? $base?->starts_at;
        $studentEndsAt = $data['student_registration_ends_at'] ?? $base?->student_registration_ends_at;
        $advisorEndsAt = $data['advisor_approval_ends_at'] ?? $base?->advisor_approval_ends_at;
        if ($startsAt === null || $studentEndsAt === null || $advisorEndsAt === null) {
            throw AcademicCalendarException::conflict('إعداد نافذة تسجيل المقررات غير مكتمل.');
        }

        $starts = CarbonImmutable::parse($startsAt)->utc();
        $studentEnds = CarbonImmutable::parse($studentEndsAt)->utc();
        $advisorEnds = CarbonImmutable::parse($advisorEndsAt)->utc();
        if ($studentEnds->lt($starts) || $advisorEnds->lt($studentEnds)) {
            throw AcademicCalendarException::conflict('يجب أن يبدأ التسجيل قبل موعد الطالب، وأن يسبق موعد الطالب موعد اعتماد المرشد أو يساويه.');
        }

        $data['student_registration_ends_at'] = $studentEnds;
        $data['advisor_approval_ends_at'] = $advisorEnds;
        $data['ends_at'] = $advisorEnds;
        $data['is_enforcement'] = true;

        return $data;
    }

    private function versionData(array $data): array
    {
        $result = [];
        foreach (['title', 'public_notes', 'is_enforcement'] as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }
        foreach (['starts_at', 'ends_at', 'student_registration_ends_at', 'advisor_approval_ends_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key] === null
                    ? null
                    : CarbonImmutable::parse($data[$key])->utc()->format('Y-m-d H:i:s');
            }
        }
        return $result;
    }

    private function isRegistrationDeadlineType(AcademicCalendarEventType $eventType): bool
    {
        return in_array($eventType->event_type_code, [self::COURSE_REGISTRATION_EVENT_TYPE, self::COURSE_REGISTRATION_REPLACEMENT_EVENT_TYPE], true);
    }

    private function assertPhase6PublicationAllowed(AcademicCalendarEventType $eventType, AcademicCalendarEvent $event): void
    {
        if ($eventType->event_type_code !== self::COURSE_REGISTRATION_REPLACEMENT_EVENT_TYPE) {
            $this->assertPhase6CalendarMutationAllowed($eventType, $event);
            return;
        }

        if (! SemesterRegistrationPhase6::schemaReady()) {
            throw \App\Exceptions\SemesterRegistrationPhase6Exception::replacementSchema();
        }
        $this->assertPhase6CalendarMutationAllowed($eventType, $event);
        app(MinimumEnrollmentReviewService::class)->assertReplacementWindowReady((int) $event->academic_year_id, (int) $event->semester_id);
    }

    private function assertPhase6CalendarMutationAllowed(AcademicCalendarEventType $eventType, AcademicCalendarEvent $event): void
    {
        if (! SemesterRegistrationPhase6::schemaReady()) return;

        if ($eventType->event_type_code === self::COURSE_REGISTRATION_EVENT_TYPE
            && CourseOfferingMinimumEnrollmentReview::query()->where('academic_year_id', $event->academic_year_id)->where('semester_id', $event->semester_id)->exists()) {
            throw AcademicCalendarException::conflict('لا يمكن تغيير مواعيد التسجيل بعد تثبيت مراجعات الحد الأدنى.');
        }
        if ($eventType->event_type_code === self::COURSE_REGISTRATION_REPLACEMENT_EVENT_TYPE
            && StudentRegistrationReplacementRequest::query()->where('academic_year_id', $event->academic_year_id)->where('semester_id', $event->semester_id)->whereNotNull('first_submitted_at')->exists()) {
            throw AcademicCalendarException::conflict('لا يمكن تغيير أو إلغاء نافذة الاستبدال بعد أول تقديم.');
        }
    }

    private function mutationPayload(AcademicCalendarEvent $event, AcademicCalendarEventVersion $version): array
    {
        $event->load(['academicYear', 'semester', 'eventType', 'versions' => fn ($q) => $q->orderByDesc('version_number')]);
        return ['event' => $this->managementPayload($event), 'warnings' => $this->overlapWarnings($event, $version)];
    }

    private function overlapWarnings(AcademicCalendarEvent $event, AcademicCalendarEventVersion $version): array
    {
        $matches = AcademicCalendarEventVersion::query()
            ->join('academic_calendar_events as ace', 'ace.academic_calendar_event_id', '=', 'academic_calendar_event_versions.academic_calendar_event_id')
            ->where('academic_calendar_event_versions.publication_status', 'published')
            ->whereNull('ace.cancelled_at')
            ->where('ace.academic_year_id', $event->academic_year_id)
            ->where('ace.academic_calendar_event_type_id', $event->academic_calendar_event_type_id)
            ->where('ace.academic_calendar_event_id', '<>', $event->getKey())
            ->where('academic_calendar_event_versions.starts_at', '<=', $version->ends_at)
            ->where('academic_calendar_event_versions.ends_at', '>=', $version->starts_at)
            ->when($event->semester_id !== null, fn ($q) => $q->where(fn ($s) => $s->whereNull('ace.semester_id')->orWhere('ace.semester_id', $event->semester_id)))
            ->limit(10)->get(['ace.academic_calendar_event_id', 'academic_calendar_event_versions.title']);

        return $matches->map(fn ($match) => [
            'code' => 'same_type_overlap',
            'message' => 'يوجد حدث آخر من النوع نفسه يتداخل مع هذه الفترة.',
            'academic_calendar_event_id' => $match->academic_calendar_event_id,
            'title' => $match->title,
        ])->all();
    }

    private function publicPayload(AcademicCalendarEvent $event): array
    {
        $version = $event->versions->first();
        return [
            'academic_calendar_event_id' => $event->getKey(),
            'academic_year' => $this->yearPayload($event->academicYear),
            'semester' => $event->semester ? ['semester_id' => $event->semester->getKey(), 'semester_code' => $event->semester->semester_code, 'semester_name' => $event->semester->semester_name] : null,
            'event_type' => $this->typePayload($event->eventType),
            'title' => $version->title,
            'public_notes' => $version->public_notes,
            'starts_at' => $version->starts_at?->utc()->toIso8601String(),
            'ends_at' => $version->ends_at?->utc()->toIso8601String(),
            'student_registration_ends_at' => $version->student_registration_ends_at?->utc()->toIso8601String(),
            'advisor_approval_ends_at' => $version->advisor_approval_ends_at?->utc()->toIso8601String(),
            'is_enforcement' => (bool) $version->is_enforcement,
            'cancelled' => $event->cancelled_at !== null,
            'cancelled_at' => $event->cancelled_at?->utc()->toIso8601String(),
        ];
    }

    private function managementPayload(AcademicCalendarEvent $event): array
    {
        return [
            'academic_calendar_event_id' => $event->getKey(),
            'academic_year' => $this->yearPayload($event->academicYear),
            'semester' => $event->semester ? ['semester_id' => $event->semester->getKey(), 'semester_code' => $event->semester->semester_code, 'semester_name' => $event->semester->semester_name] : null,
            'event_type' => $this->typePayload($event->eventType),
            'cancelled' => $event->cancelled_at !== null,
            'cancelled_at' => $event->cancelled_at?->utc()->toIso8601String(),
            'cancellation_reason' => $event->cancellation_reason,
            'versions' => $event->versions->map(fn ($v) => [
                'academic_calendar_event_version_id' => $v->getKey(), 'version_number' => $v->version_number,
                'replaces_version_id' => $v->replaces_version_id, 'title' => $v->title, 'public_notes' => $v->public_notes,
                'starts_at' => $v->starts_at?->utc()->toIso8601String(), 'ends_at' => $v->ends_at?->utc()->toIso8601String(),
                'student_registration_ends_at' => $v->student_registration_ends_at?->utc()->toIso8601String(),
                'advisor_approval_ends_at' => $v->advisor_approval_ends_at?->utc()->toIso8601String(),
                'is_enforcement' => (bool) $v->is_enforcement, 'change_reason' => $v->change_reason,
                'publication_status' => $v->publication_status, 'created_by_user_id' => $v->created_by_user_id,
                'created_at' => $v->created_at?->utc()->toIso8601String(), 'published_by_user_id' => $v->published_by_user_id,
                'published_at' => $v->published_at?->utc()->toIso8601String(), 'superseded_at' => $v->superseded_at?->utc()->toIso8601String(),
            ])->values(),
        ];
    }

    private function yearPayload(AcademicYear $year): array
    {
        return ['academic_year_id' => $year->getKey(), 'year_name' => $year->year_name, 'is_current' => (bool) $year->is_current, 'is_active' => (bool) $year->is_active, 'calendar_lifecycle_status' => $year->calendar_lifecycle_status];
    }

    private function typePayload(AcademicCalendarEventType $type): array
    {
        return ['academic_calendar_event_type_id' => $type->getKey(), 'event_type_code' => $type->event_type_code, 'name_ar' => $type->name_ar, 'name_en' => $type->name_en, 'event_type_kind' => $type->event_type_kind];
    }

    private function auditYear(AcademicYear $year, string $from, string $to, User $user, string $reason, $occurredAt): void
    {
        AcademicCalendarYearLifecycleEvent::query()->create([
            'academic_year_id' => $year->getKey(), 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $user->user_id, 'reason' => trim($reason), 'occurred_at' => $occurredAt,
        ]);
    }
}
