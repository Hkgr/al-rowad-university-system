<?php

namespace App\Console\Commands;

use App\Support\IncompleteOutcomeGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Schema};

final class CheckCourseResultSymbols extends Command
{
    protected $signature = 'course-results:check';
    protected $description = 'Read-only schema and study-calendar readiness; never infers grants or repairs data.';
    public function handle(): int
    {
        $ready = IncompleteOutcomeGuard::ready();
        if (DB::getDriverName() === 'mysql') {
            $this->line('Database: '.DB::selectOne('SELECT VERSION() AS version')->version);
            $nullable = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'student_course_results')
                ->whereIn('COLUMN_NAME', ['theoretical_total', 'practical_total', 'final_mark'])->where('IS_NULLABLE', 'YES')->count();
            $ready = $ready && $nullable === 3;
        }
        $this->line('SCHEMA | '.($ready ? 'READY' : 'BLOCKED'));
        $calendar = Schema::hasTable('academic_calendar_events') && Schema::hasTable('academic_calendar_event_versions') && Schema::hasTable('academic_calendar_event_types')
            && DB::table('academic_calendar_events as e')->join('academic_calendar_event_types as t', 't.academic_calendar_event_type_id', '=', 'e.academic_calendar_event_type_id')
                ->join('academic_calendar_event_versions as v', 'v.academic_calendar_event_id', '=', 'e.academic_calendar_event_id')
                ->where('t.event_type_code', 'study_period')->where('t.is_active', 1)->whereNull('e.cancelled_at')->where('v.publication_status', 'published')->whereNull('v.superseded_at')->whereNotNull('v.published_at')->exists();
        $this->line('CALENDAR | '.($calendar ? 'PER_CASE_VALIDATION_REQUIRED' : 'MANUAL_DEADLINE_REQUIRED'));
        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
