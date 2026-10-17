<?php

namespace App\Support;

use App\Models\StudentCourseRegistration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\{DB, Schema};

/** Recorded references, not a rewrite of Course or of academic evidence. No date inference. */
final class HistoricalCourseCode
{
    public static function attach(iterable $registrations): void
    {
        $rows = new Collection(collect($registrations)->filter(fn ($r) => $r instanceof StudentCourseRegistration && !$r->relationLoaded('historicalCourseCode'))->values()->all());
        if ($rows->isEmpty()) return;
        $rows->loadMissing('courseOffering.course');
        $events = Schema::hasTable('academic_plan_events') ? DB::table('academic_plan_events')
            ->where('action', 'catalog_completion_code_applied')->orderBy('academic_plan_event_id')->get() : collect();
        foreach ($rows as $row) {
            $course = $row->courseOffering?->course; $current = $course?->course_code;
            $value = ['course_code' => $current, 'current_course_code' => $current, 'recorded_course_code' => null,
                'original_plan_course_code' => null, 'course_code_reference' => 'current_catalog', 'course_code_correction_event_id' => null];
            foreach ($events as $event) {
                $proof = json_decode($event->context, true, flags: JSON_THROW_ON_ERROR);
                if (($proof['manifest_hash'] ?? null) !== \App\Services\CatalogCompletion\ApprovedCatalogManifest::SHA256) continue;
                if ((int) ($proof['drawing_id'] ?? 0) !== 780 || (int) ($proof['chemistry_id'] ?? 0) !== 892) continue;
                $index = match ((int) $course?->getKey()) { 780 => 0, 892 => 1, default => null };
                if ($index === null) continue;
                $before = $proof['before'][$index] ?? null;
                $after = $proof['after'][$index] ?? null;
                if (!$before || !$after || $after['course_code'] !== $current) continue;
                $member = collect($proof['affected_memberships'] ?? [])->first(fn ($m) =>
                    (int) $m['course_id'] === (int) $course->getKey() && $row->academic_plan_version_id !== null
                    && (int) ($m['academic_plan_version_id'] ?? 0) === (int) $row->academic_plan_version_id
                    && (int) $m['program_course_id'] === (int) $row->plan_program_course_id);
                $recorded = collect($proof['recorded_registrations'] ?? [])->first(fn ($r) =>
                    (int) $r['student_course_registration_id'] === (int) $row->getKey()
                    && (int) $r['course_id'] === (int) $course->getKey()
                    && ($r['academic_plan_version_id'] === null ? null : (int) $r['academic_plan_version_id']) === ($row->academic_plan_version_id === null ? null : (int) $row->academic_plan_version_id)
                    && ($r['plan_program_course_id'] === null ? null : (int) $r['plan_program_course_id']) === ($row->plan_program_course_id === null ? null : (int) $row->plan_program_course_id));
                if (!$member && !$recorded) continue;
                $value['original_plan_course_code'] = $member ? $before['course_code'] : null;
                $value['course_code_correction_event_id'] = (int) $event->academic_plan_event_id;
                $value['course_code_reference'] = $recorded ? 'recorded_registration' : 'corrected_catalog_with_pinned_original';
                if ($recorded) $value['course_code'] = $value['recorded_course_code'] = $before['course_code'];
            }
            $row->setRelation('historicalCourseCode', collect($value));
        }
    }

    public static function fields(StudentCourseRegistration $registration): array
    {
        if (!$registration->relationLoaded('historicalCourseCode')) self::attach([$registration]);
        return $registration->getRelation('historicalCourseCode')->all();
    }

    /** Bounded page adapter for SQL-based official-result readers. Never loads marks or people. */
    public static function index(iterable $registrationIds): array
    {
        $ids = collect($registrationIds)->filter()->unique()->values();
        if ($ids->isEmpty()) return [];
        $rows = StudentCourseRegistration::whereKey($ids)->with('courseOffering.course')->get();
        self::attach($rows);
        return $rows->mapWithKeys(fn ($row) => [$row->getKey() => self::fields($row)])->all();
    }
}
