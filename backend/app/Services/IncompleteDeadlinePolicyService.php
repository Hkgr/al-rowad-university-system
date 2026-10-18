<?php

namespace App\Services;

use App\Models\StudentCourseRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IncompleteDeadlinePolicyService
{
    public function suggest(StudentCourseRegistration $registration): array
    {
        $unavailable = ['available' => false, 'reason' => 'manual_deadline_required', 'deadline_at' => null, 'calendar_snapshot' => null];
        foreach (['academic_calendar_events', 'academic_calendar_event_versions', 'academic_calendar_event_types'] as $table) if (!Schema::hasTable($table)) return $unavailable;
        $source = $registration->courseOffering;
        $years = DB::table('academic_years')->orderBy('start_date')->orderBy('academic_year_id')->get();
        $semesters = DB::table('semesters')->orderBy('semester_order')->get();
        $year = $years->firstWhere('academic_year_id', $source->academic_year_id);
        $semester = $semesters->firstWhere('semester_id', $source->semester_id);
        if (!$year || !$semester || $semesters->pluck('semester_order')->duplicates()->isNotEmpty() || $years->pluck('start_date')->duplicates()->isNotEmpty()) return $unavailable;
        $next = $semesters->first(fn ($s) => $s->semester_order > $semester->semester_order);
        $nextYear = $next ? $year : $years->first(fn ($y) => $y->start_date > $year->start_date);
        $next ??= $semesters->first();
        if (!$next || !$nextYear) return $unavailable;
        if (!(bool) ($next->is_active ?? true) || !(bool) ($nextYear->is_active ?? true) || ($nextYear->calendar_lifecycle_status ?? null) === 'closed') return $unavailable;
        $type = DB::table('academic_calendar_event_types')->where('event_type_code', 'study_period')->where('is_active', 1)->get();
        if ($type->count() !== 1) return $unavailable;
        // Published study periods are intentionally NOT enforcement windows and may belong to the next year.
        $versions = DB::table('academic_calendar_events as e')->join('academic_calendar_event_versions as v', 'v.academic_calendar_event_id', '=', 'e.academic_calendar_event_id')
            ->where('e.academic_calendar_event_type_id', $type->first()->academic_calendar_event_type_id)
            ->where('e.academic_year_id', $nextYear->academic_year_id)->where('e.semester_id', $next->semester_id)
            ->whereNull('e.cancelled_at')->where('v.publication_status', 'published')->whereNull('v.superseded_at')
            ->whereNotNull('v.published_at')->get(['e.academic_calendar_event_id', 'e.academic_year_id', 'e.semester_id', 'v.academic_calendar_event_version_id', 'v.version_number', 'v.starts_at', 'v.ends_at', 'v.published_at']);
        if ($versions->count() !== 1) return $unavailable;
        $v = $versions->first();
        $start = CarbonImmutable::parse($v->starts_at, 'UTC')->setTimezone('Asia/Damascus');
        if ($start->utc()->gt(CarbonImmutable::parse($v->ends_at, 'UTC')) || CarbonImmutable::parse($v->published_at, 'UTC')->isFuture()) return $unavailable;
        $deadline = $start->startOfDay()->addDays(20)->endOfDay()->setMicrosecond(0)->utc();
        return ['available' => true, 'reason' => null, 'deadline_at' => $deadline->toIso8601String(),
            'calendar_snapshot' => (array) $v + ['timezone' => 'Asia/Damascus', 'rule' => 'study_start_local_date_plus_20_days_end_of_day', 'deadline_utc' => $deadline->format('Y-m-d H:i:s')]];
    }
}
