<?php

namespace Tests\Feature;

use App\Exceptions\AcademicCalendarException;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicCalendarEventVersion;
use App\Models\AcademicYear;
use App\Models\User;
use App\Services\AcademicCalendarService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicCalendarPhase2ServiceTest extends TestCase
{
    private AcademicCalendarService $calendar;
    private User $manager;
    private User $ordinary;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedReferenceData();
        $this->calendar = app(AcademicCalendarService::class);
        $this->manager = User::query()->findOrFail(1);
        $this->ordinary = User::query()->findOrFail(2);
    }

    public function test_authenticated_read_returns_published_and_never_draft_or_superseded_content(): void
    {
        [$event, $v1] = $this->draft();
        self::assertCount(0, $this->calendar->publicEvents([]));
        $this->calendar->publish($this->manager, $event, $v1);
        $this->calendar->createReplacementDraft($this->manager, $event, $this->replacementPayload('نسخة سرية'));

        $public = $this->calendar->publicEvents([]);
        self::assertCount(1, $public);
        self::assertSame('حدث أول', $public->first()['title']);
        self::assertArrayNotHasKey('versions', $public->first());
        self::assertArrayNotHasKey('change_reason', $public->first());
    }

    public function test_only_actual_scientific_vp_with_assigned_permission_can_mutate(): void
    {
        $this->expectException(AcademicCalendarException::class);
        $this->calendar->createDraft($this->ordinary, $this->draftPayload());
    }

    public function test_scientific_vp_role_without_assigned_permission_cannot_mutate(): void
    {
        DB::table('role_permissions')->delete();
        $this->expectException(AcademicCalendarException::class);
        $this->calendar->createDraft($this->manager, $this->draftPayload());
    }

    public function test_manager_can_create_and_initially_publish_a_draft(): void
    {
        [$event, $version] = $this->draft();
        self::assertSame('draft', $version->publication_status);
        $result = $this->calendar->publish($this->manager, $event, $version);
        self::assertSame('published', $result['event']['versions'][0]['publication_status']);
    }

    public function test_replacement_draft_preserves_public_v1_until_transactional_publish(): void
    {
        [$event, $v1] = $this->draft();
        $this->calendar->publish($this->manager, $event, $v1);
        $replacement = $this->calendar->createReplacementDraft($this->manager, $event, $this->replacementPayload());
        $v2Id = $replacement['event']['versions'][0]['academic_calendar_event_version_id'];
        self::assertSame('حدث أول', $this->calendar->publicEvents([])->first()['title']);

        $this->calendar->publish($this->manager, $event, AcademicCalendarEventVersion::query()->findOrFail($v2Id));
        self::assertSame('حدث معدل', $this->calendar->publicEvents([])->first()['title']);
        self::assertSame('superseded', AcademicCalendarEventVersion::query()->findOrFail($v1->getKey())->publication_status);
        self::assertNotNull(AcademicCalendarEventVersion::query()->findOrFail($v1->getKey())->published_at);
    }

    public function test_stale_replacement_publication_returns_conflict(): void
    {
        [$event, $v1] = $this->draft();
        $this->calendar->publish($this->manager, $event, $v1);
        $replacement = $this->calendar->createReplacementDraft($this->manager, $event, $this->replacementPayload());
        $v2 = AcademicCalendarEventVersion::query()->findOrFail($replacement['event']['versions'][0]['academic_calendar_event_version_id']);
        $this->calendar->publish($this->manager, $event, $v2);

        $this->expectException(AcademicCalendarException::class);
        $this->calendar->publish($this->manager, $event, $v2);
    }

    public function test_published_logical_academic_context_is_immutable(): void
    {
        [$event, $v1] = $this->draft();
        $this->calendar->publish($this->manager, $event, $v1);
        $replacement = $this->calendar->createReplacementDraft($this->manager, $event, $this->replacementPayload());
        $v2 = AcademicCalendarEventVersion::query()->findOrFail($replacement['event']['versions'][0]['academic_calendar_event_version_id']);
        $this->expectException(AcademicCalendarException::class);
        $this->calendar->editDraft($this->manager, $event, $v2, ['semester_id' => null, 'change_reason' => 'تغيير السياق']);
    }

    public function test_published_revision_cannot_be_deleted_but_replacement_draft_can(): void
    {
        [$event, $v1] = $this->draft();
        $this->calendar->publish($this->manager, $event, $v1);
        try {
            $this->calendar->deleteDraft($this->manager, $event, $v1);
            self::fail('Published revision was deleted.');
        } catch (AcademicCalendarException) {
            self::assertDatabaseHas('academic_calendar_event_versions', ['academic_calendar_event_version_id' => $v1->getKey()]);
        }
        $replacement = $this->calendar->createReplacementDraft($this->manager, $event, $this->replacementPayload());
        $v2 = AcademicCalendarEventVersion::query()->findOrFail($replacement['event']['versions'][0]['academic_calendar_event_version_id']);
        $this->calendar->deleteDraft($this->manager, $event, $v2);
        self::assertDatabaseHas('academic_calendar_event_versions', ['academic_calendar_event_version_id' => $v1->getKey(), 'publication_status' => 'published']);
    }

    public function test_never_published_only_draft_deletion_removes_logical_event(): void
    {
        [$event, $version] = $this->draft();
        $this->calendar->deleteDraft($this->manager, $event, $version);
        self::assertDatabaseMissing('academic_calendar_events', ['academic_calendar_event_id' => $event->getKey()]);
    }

    public function test_cancellation_requires_reason_conflicts_with_pending_draft_and_remains_public(): void
    {
        [$event, $v1] = $this->draft();
        $this->calendar->publish($this->manager, $event, $v1);
        try {
            $this->calendar->cancel($this->manager, $event, ' ');
            self::fail('Blank cancellation reason accepted.');
        } catch (AcademicCalendarException) {
            self::assertTrue(true);
        }
        $replacement = $this->calendar->createReplacementDraft($this->manager, $event, $this->replacementPayload());
        $v2 = AcademicCalendarEventVersion::query()->findOrFail($replacement['event']['versions'][0]['academic_calendar_event_version_id']);
        try {
            $this->calendar->cancel($this->manager, $event, 'سبب');
            self::fail('Cancellation silently discarded a replacement draft.');
        } catch (AcademicCalendarException) {
            $this->calendar->deleteDraft($this->manager, $event, $v2);
        }
        $this->calendar->cancel($this->manager, $event, 'قرار الإلغاء');
        $public = $this->calendar->publicEvents([])->first();
        self::assertTrue($public['cancelled']);
        self::assertArrayNotHasKey('cancellation_reason', $public);
        self::assertNotNull($public['cancelled_at']);
        self::assertSame('قرار الإلغاء', $this->calendar->managementEvents($this->manager, [])->first()['cancellation_reason']);
    }

    public function test_same_type_overlap_warns_without_blocking_and_different_type_does_not(): void
    {
        [$event, $v1] = $this->draft();
        $this->calendar->publish($this->manager, $event, $v1);
        $overlap = $this->calendar->createDraft($this->manager, $this->draftPayload(['title' => 'متداخل', 'semester_id' => null]));
        self::assertNotEmpty($overlap['warnings']);
        $different = $this->calendar->createDraft($this->manager, $this->draftPayload(['title' => 'نوع آخر', 'academic_calendar_event_type_id' => 2]));
        self::assertSame([], $different['warnings']);
    }

    public function test_year_wide_overlap_is_a_semester_wildcard(): void
    {
        [$event, $v1] = $this->draft(['semester_id' => null]);
        $this->calendar->publish($this->manager, $event, $v1);
        $semesterDraft = $this->calendar->createDraft($this->manager, $this->draftPayload(['semester_id' => 1]));
        self::assertNotEmpty($semesterDraft['warnings']);
        self::assertCount(1, $this->calendar->publicEvents(['semester_id' => 1]));
    }

    public function test_closed_year_blocks_mutation_and_reopen_requires_reason_without_becoming_current(): void
    {
        $closed = AcademicYear::query()->findOrFail(2);
        try {
            $this->calendar->createDraft($this->manager, $this->draftPayload(['academic_year_id' => 2]));
            self::fail('Closed year accepted mutation.');
        } catch (AcademicCalendarException) {
            self::assertTrue(true);
        }
        try {
            $this->calendar->transitionYear($this->manager, $closed, 'reopen', ' ');
            self::fail('Blank lifecycle reason accepted.');
        } catch (AcademicCalendarException) {
            $result = $this->calendar->transitionYear($this->manager, $closed, 'reopen', 'تصحيح تاريخي');
            self::assertSame('draft', $result['calendar_lifecycle_status']);
            self::assertFalse((bool) $result['is_current']);
        }
    }

    public function test_activation_preserves_exactly_one_lifecycle_active_and_current_year_with_audit(): void
    {
        $draftYear = AcademicYear::query()->findOrFail(3);
        $this->calendar->transitionYear($this->manager, $draftYear, 'activate', 'بدء السنة الجديدة');
        self::assertSame(1, AcademicYear::query()->where('is_current', true)->count());
        self::assertSame(1, AcademicYear::query()->where('calendar_lifecycle_status', 'active')->count());
        self::assertSame(2, DB::table('academic_calendar_year_lifecycle_events')->count());
        self::assertTrue((bool) AcademicYear::query()->findOrFail(1)->is_active);
    }

    public function test_unauthorized_role_cannot_use_lifecycle_actions(): void
    {
        $this->expectException(AcademicCalendarException::class);
        $this->calendar->transitionYear($this->ordinary, AcademicYear::query()->findOrFail(3), 'activate', 'سبب');
    }

    private function draft(array $overrides = []): array
    {
        $result = $this->calendar->createDraft($this->manager, $this->draftPayload($overrides));
        $event = AcademicCalendarEvent::query()->findOrFail($result['event']['academic_calendar_event_id']);
        $version = $event->versions()->firstOrFail();
        return [$event, $version];
    }

    private function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'academic_year_id' => 1, 'semester_id' => 1, 'academic_calendar_event_type_id' => 1,
            'title' => 'حدث أول', 'public_notes' => 'ملاحظات عامة',
            'starts_at' => '2026-09-01T08:00:00Z', 'ends_at' => '2026-09-05T16:00:00Z', 'is_enforcement' => true,
        ], $overrides);
    }

    private function replacementPayload(string $title = 'حدث معدل'): array
    {
        return ['title' => $title, 'starts_at' => '2026-09-02T08:00:00Z', 'ends_at' => '2026-09-06T16:00:00Z', 'change_reason' => 'تحديث المواعيد'];
    }

    private function seedReferenceData(): void
    {
        DB::table('users')->insert([['user_id' => 1, 'username' => 'vp'], ['user_id' => 2, 'username' => 'ordinary']]);
        DB::table('roles')->insert([['role_id' => 1, 'role_code' => 'vice_president_scientific', 'is_active' => 1], ['role_id' => 2, 'role_code' => 'student', 'is_active' => 1]]);
        DB::table('permissions')->insert(['permission_id' => 1, 'permission_code' => 'academic_calendar.manage', 'is_active' => 1]);
        DB::table('role_permissions')->insert(['role_permission_id' => 1, 'role_id' => 1, 'permission_id' => 1]);
        DB::table('user_roles')->insert([['user_role_id' => 1, 'user_id' => 1, 'role_id' => 1, 'is_active' => 1], ['user_role_id' => 2, 'user_id' => 2, 'role_id' => 2, 'is_active' => 1]]);
        DB::table('academic_years')->insert([
            ['academic_year_id' => 1, 'year_name' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'is_current' => 1, 'is_active' => 1, 'calendar_lifecycle_status' => 'active'],
            ['academic_year_id' => 2, 'year_name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'is_current' => 0, 'is_active' => 1, 'calendar_lifecycle_status' => 'closed'],
            ['academic_year_id' => 3, 'year_name' => '2027-2028', 'start_date' => '2027-09-01', 'end_date' => '2028-08-31', 'is_current' => 0, 'is_active' => 1, 'calendar_lifecycle_status' => 'draft'],
        ]);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_code' => 'first', 'semester_name' => 'الفصل الأول', 'semester_order' => 1, 'is_active' => 1]);
        DB::table('academic_calendar_event_types')->insert([
            ['academic_calendar_event_type_id' => 1, 'event_type_code' => 'course_registration', 'name_ar' => 'تسجيل المقررات', 'name_en' => 'Registration', 'event_type_kind' => 'system', 'default_is_enforcement' => 1, 'is_active' => 1],
            ['academic_calendar_event_type_id' => 2, 'event_type_code' => 'holiday', 'name_ar' => 'عطلة', 'name_en' => 'Holiday', 'event_type_kind' => 'general', 'default_is_enforcement' => 0, 'is_active' => 1],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', fn (Blueprint $t) => $t->increments('user_id')->string('username'));
        Schema::create('roles', function (Blueprint $t) { $t->increments('role_id'); $t->string('role_code'); $t->boolean('is_active'); });
        Schema::create('permissions', function (Blueprint $t) { $t->increments('permission_id'); $t->string('permission_code'); $t->boolean('is_active'); });
        Schema::create('role_permissions', function (Blueprint $t) { $t->increments('role_permission_id'); $t->integer('role_id'); $t->integer('permission_id'); });
        Schema::create('user_roles', function (Blueprint $t) { $t->increments('user_role_id'); $t->integer('user_id'); $t->integer('role_id'); $t->boolean('is_active'); });
        Schema::create('academic_years', function (Blueprint $t) { $t->increments('academic_year_id'); $t->string('year_name'); $t->date('start_date'); $t->date('end_date'); $t->boolean('is_current'); $t->boolean('is_active'); $t->string('calendar_lifecycle_status'); $t->integer('calendar_active_slot')->nullable(); $t->timestamps(); });
        Schema::create('semesters', function (Blueprint $t) { $t->increments('semester_id'); $t->string('semester_code'); $t->string('semester_name'); $t->integer('semester_order'); $t->boolean('is_active'); });
        Schema::create('academic_calendar_event_types', function (Blueprint $t) { $t->increments('academic_calendar_event_type_id'); $t->string('event_type_code'); $t->string('name_ar'); $t->string('name_en'); $t->string('event_type_kind'); $t->boolean('default_is_enforcement'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('academic_calendar_events', function (Blueprint $t) { $t->increments('academic_calendar_event_id'); $t->integer('academic_year_id'); $t->integer('semester_id')->nullable(); $t->integer('academic_calendar_event_type_id'); $t->integer('created_by_user_id'); $t->dateTime('created_at'); $t->integer('cancelled_by_user_id')->nullable(); $t->dateTime('cancelled_at')->nullable(); $t->text('cancellation_reason')->nullable(); });
        Schema::create('academic_calendar_event_versions', function (Blueprint $t) { $t->increments('academic_calendar_event_version_id'); $t->integer('academic_calendar_event_id'); $t->integer('version_number'); $t->integer('replaces_version_id')->nullable(); $t->string('title'); $t->text('public_notes')->nullable(); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->boolean('is_enforcement'); $t->text('change_reason'); $t->integer('created_by_user_id'); $t->dateTime('created_at'); $t->string('publication_status'); $t->integer('published_by_user_id')->nullable(); $t->dateTime('published_at')->nullable(); $t->dateTime('superseded_at')->nullable(); $t->integer('published_event_slot')->nullable(); $t->unique(['academic_calendar_event_id', 'version_number']); });
        Schema::create('academic_calendar_year_lifecycle_events', function (Blueprint $t) { $t->increments('academic_calendar_year_lifecycle_event_id'); $t->integer('academic_year_id'); $t->string('from_status')->nullable(); $t->string('to_status'); $t->integer('actor_user_id'); $t->text('reason'); $t->dateTime('occurred_at'); });
    }
}
