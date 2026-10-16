<?php

namespace App\Services\CatalogCompletion;

use App\Exceptions\AcademicPlanException;
use App\Models\{Course, User};
use App\Support\ScientificCourseAccess;
use Illuminate\Support\Facades\DB;

/** Exact D05 permutation only. Ordinary catalog writers keep their existing guard. */
final class ApprovedCatalogCodeCorrection
{
    public function apply(User $actor, ApprovedCatalogManifest $manifest, string $operationId, array $rows): void
    {
        if (DB::transactionLevel() < 1 || count($rows) !== 2) $this->fail('catalog_completion_code_context_invalid');
        $access = app(ScientificCourseAccess::class); $access->authorize($actor, true);
        $started = DB::table('user_activity_logs')->where('action_code', 'catalog_completion.started')->where('user_id', $actor->getKey())
            ->where('description->operation_id', $operationId)->where('description->manifest_hash', $manifest->hash)->exists();
        if (!$started) $this->fail('catalog_completion_code_context_invalid');
        $this->assertReady();
        $map = collect($rows)->keyBy('key');
        $drawing = $map->get('P28-R0114'); $chemistry = $map->get('P28-R0115');
        if (!$drawing || !$chemistry) $this->fail('catalog_completion_code_context_invalid');
        $ids = [$drawing['course_id'], $chemistry['course_id']]; sort($ids);
        $courses = Course::whereIn('course_id', $ids)->orderBy('course_id')->lockForUpdate()->get()->keyBy('course_id');
        $d = $courses->get($drawing['course_id']); $c = $courses->get($chemistry['course_id']);
        if (!$d || !$c || $d->getKey() === $c->getKey() || $d->course_code !== 'FE114' || $c->course_code !== 'FE113'
            || (int) $d->credit_hours !== 4 || (int) $c->credit_hours !== 3) $this->fail('catalog_completion_code_before_image_changed');
        foreach ([$d, $c] as $course) abort_unless($access->canEditOrigin($actor, $course), 403);
        $fields = app(CatalogCompletionProjection::class);
        if ($fields->fields($d) !== $drawing['expected'] || $fields->fields($c) !== $chemistry['expected']) $this->fail('catalog_completion_code_before_image_changed');
        $context = ['manifest_hash' => $manifest->hash, 'operation_id' => $operationId, 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() id')->id,
            'drawing_id' => $d->getKey(), 'chemistry_id' => $c->getKey(), 'drawing_code_before' => 'FE114', 'chemistry_code_before' => 'FE113',
            'before' => [$fields->fields($d), $fields->fields($c)],
            'affected_memberships' => DB::table('program_courses')->whereIn('course_id', $ids)->orderBy('program_course_id')->get()->all(),
            'excluded_program_effect' => 'Shared drawing code changes in existing architecture references only; no architecture curriculum completion or reassignment.'];
        $event = DB::table('academic_plan_events')->insertGetId(['academic_program_id' => 28, 'academic_plan_version_id' => null,
            'action' => 'catalog_completion_code_permit', 'actor_user_id' => $actor->getKey(), 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => now()], 'academic_plan_event_id');
        $scratch = '__D05_'.$event;
        if (Course::where('course_code', $scratch)->exists()) $this->fail('catalog_completion_code_collision');
        DB::statement('SET @catalog_completion_permit_event_id = ?', [$event]);
        try {
            // Both installed guards verify the durable permit, connection, before state and exact next step.
            DB::table('courses')->where('course_id', $d->getKey())->update(['course_code' => $scratch, 'updated_at' => $d->getRawOriginal('updated_at')]);
            DB::table('courses')->where('course_id', $c->getKey())->update(['course_code' => 'FE114', 'updated_at' => $c->getRawOriginal('updated_at')]);
            DB::table('courses')->where('course_id', $d->getKey())->update(['course_code' => 'FE113', 'updated_at' => $d->getRawOriginal('updated_at')]);
            DB::table('academic_plan_events')->insert(['academic_program_id' => 28, 'academic_plan_version_id' => null,
                'action' => 'catalog_completion_code_applied', 'actor_user_id' => $actor->getKey(), 'created_at' => now(),
                'context' => json_encode($context + ['permit_event_id' => $event, 'after' => [$fields->fields($d->fresh()), $fields->fields($c->fresh())]], JSON_THROW_ON_ERROR)]);
        } finally { DB::statement('SET @catalog_completion_permit_event_id = NULL'); }
    }

    public function assertReady(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->fail('catalog_completion_code_schema_not_ready');
        $sql = file_get_contents(database_path('sql/approved-catalog-completion/01_apply.sql'));
        foreach (['sc_cat_courses_u', 'sc_plan_course_u'] as $name) {
            preg_match('/CREATE OR REPLACE TRIGGER '.$name.' BEFORE UPDATE ON courses FOR EACH ROW\s*(.*?)\/\//s', $sql, $match);
            $actual = DB::selectOne('SELECT action_statement body,event_object_table target,event_manipulation operation,action_timing timing FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?', [$name]);
            if (!$actual || $actual->target !== 'courses' || $actual->operation !== 'UPDATE' || $actual->timing !== 'BEFORE'
                || $this->normalize($actual->body) !== $this->normalize($match[1])) $this->fail('catalog_completion_code_schema_not_ready');
        }
        preg_match('/CREATE OR REPLACE FUNCTION sc_completion_code_allowed\(.*?READS SQL DATA\s*(.*?)\/\//s', $sql, $match);
        $actual = DB::selectOne("SELECT routine_definition body,data_type type FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_name='sc_completion_code_allowed' AND routine_type='FUNCTION'");
        if (!$actual || $actual->type !== 'tinyint' || $this->normalize($actual->body) !== $this->normalize($match[1])) $this->fail('catalog_completion_code_schema_not_ready');
        $parameters = DB::select("SELECT ordinal_position position,parameter_name name,data_type type,character_maximum_length capacity FROM information_schema.parameters WHERE specific_schema=DATABASE() AND specific_name='sc_completion_code_allowed' ORDER BY ordinal_position");
        $expected = [[0, null, 'tinyint', null], [1, 'course_key', 'int', null], [2, 'previous_code', 'varchar', 50], [3, 'next_code', 'varchar', 50]];
        if (count($parameters) !== count($expected)) $this->fail('catalog_completion_code_schema_not_ready');
        foreach ($parameters as $index => $parameter) {
            if ([(int) $parameter->position, $parameter->name, $parameter->type, $parameter->capacity === null ? null : (int) $parameter->capacity] !== $expected[$index]) $this->fail('catalog_completion_code_schema_not_ready');
        }
    }

    private function normalize(string $sql): string { return preg_replace('/\s+/', ' ', trim($sql)); }
    private function fail(string $code): never { throw AcademicPlanException::conflict($code, 'The exact approved code-correction context/SQL protection is not ready.'); }
}
