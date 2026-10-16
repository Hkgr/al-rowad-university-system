<?php

namespace App\Services\CatalogCompletion;

use App\Models\{AcademicProgram, Course};
use App\Services\{AcademicCatalogHistory, AcademicCatalogTransaction, AcademicPlanContext, ScientificPlanChangeService};
use Illuminate\Support\Facades\DB;

/** Read-only reconciliation. Every executable identity has an exact before-image. */
final class CatalogCompletionProjection
{
    public function __construct(private AcademicCatalogTransaction $transaction, private ScientificPlanChangeService $workspace) {}

    public function build(ApprovedCatalogManifest $manifest): array
    {
        $catalog = Course::orderBy('course_id')->get()->keyBy('course_id');
        $byCode = $catalog->keyBy('course_code');
        $origins = DB::table('user_activity_logs')->where('action_code', 'catalog_completion.origin')->get(['description'])
            ->map(fn ($r) => json_decode($r->description, true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn ($r) => $r['manifest_hash'] === $manifest->hash)->keyBy('key');
        $rows = []; $programs = [];
        foreach ($manifest->programs() as $definition) {
            $id = (int) $definition['program_id'];
            $program = AcademicProgram::find($id);
            $reasons = [];
            if (!$program || (int) $program->department_id !== (int) $definition['department_id']
                || !DB::table('departments')->where('department_id', $definition['department_id'])->where('college_id', $definition['college_id'])->exists()
                || !in_array($program->program_name, [$definition['current_name'], $definition['target_name']], true)) $reasons[] = 'program_identity_mismatch';
            if (DB::table('academic_plan_versions')->where('academic_program_id', $id)->where('status', 'draft')->exists()) $reasons[] = 'existing_draft_requires_review';
            if ($program?->plan_state === 'legacy' && DB::table('academic_plan_versions')->where('academic_program_id', $id)->exists()) $reasons[] = 'legacy_program_has_saved_versions';
            foreach ($manifest->rows('Curriculum', $id) as $row) {
                if ($row['entry_type'] === 'CHOICE_SLOT') {
                    $rows[$row['row_key']] = ['key' => $row['row_key'], 'program_id' => $id, 'state' => 'budget_only', 'source' => $row];
                    continue;
                }
                $rows[$row['row_key']] = $this->resolve($manifest, $row, $catalog, $byCode, $origins);
            }
            foreach ($manifest->rows('Electives', $id) as $e) {
                $row = ['row_key' => 'Electives:'.$e['_row'], 'program_id' => $id, 'target_code' => $e['course_code'],
                    'target_name' => $e['course_name'], 'target_credits' => $e['credit_hours'],
                    'identity_candidate_id' => $e['existing_course_id'], 'action' => $e['action'] === 'CREATE_REFERENCE_OPTION' ? 'MATCH_OR_CREATE' : 'REUSE_EXISTING',
                    'requirement_scope' => $e['requirement_scope'], 'course_type' => 'elective',
                    'theoretical_hours' => $e['theoretical_hours'], 'practical_hours' => $e['practical_hours'],
                    'study_year' => null, 'semester_order' => null, 'source_id' => $e['source_id'], 'elective_source' => $e];
                $rows[$row['row_key']] = $this->resolve($manifest, $row, $catalog, $byCode, $origins);
            }
            $programs[$id] = ['academic_program_id' => $id, 'definition' => $definition, 'blockers' => $reasons,
                'student_count' => DB::table('students')->where('academic_program_id', $id)->count(),
                'archived_student_count' => DB::table('students')->where('academic_program_id', $id)->whereNotNull('deleted_at')->count(),
                'default_version_id' => $program?->default_academic_plan_version_id];
        }
        // Resolve edges ONLY through this program's already reconciled source identities.
        $edges = [];
        foreach ($manifest->rows('Prerequisites') as $edge) {
            $pid = (int) $edge['program_id']; $map = [];
            foreach ($rows as $r) if ($r['program_id'] === $pid && isset($r['identity'])) $map[$r['source']['target_code']] = $r['identity'];
            $from = $map[$edge['target_course_code']] ?? null;
            $to = $map[trim($edge['source_prerequisite'])] ?? null;
            $reason = !$from || !$to ? 'prerequisite_identity_unresolved' : ($from === $to ? 'prerequisite_self_edge' : null);
            if (!$reason && str_starts_with($from, 'id:')) {
                $existing = DB::table('course_prerequisites')->where('course_id', substr($from, 3))->where('prerequisite_course_id', str_starts_with($to, 'id:') ? substr($to, 3) : -1)->exists();
                // Existing minimum-result semantics stay exactly as stored. Never modify a fixed origin's graph.
                if (!$existing && app(AcademicCatalogHistory::class)->courseUsed((int) substr($from, 3))) $reason = 'used_origin_prerequisite_change';
            }
            if ($reason) $programs[$pid]['blockers'][] = 'Prerequisites:'.$edge['_row'].':'.$reason;
            $edges[] = ['from' => $from, 'to' => $to, 'source' => $edge, 'blocker' => $reason];
        }
        foreach ($rows as $r) if ($r['state'] === 'blocked') $programs[$r['program_id']]['blockers'][] = $r['key'].':'.$r['blocker'];
        $graph = [];
        foreach (DB::table('course_prerequisites')->get(['course_id', 'prerequisite_course_id']) as $e) $graph['id:'.$e->course_id][] = 'id:'.$e->prerequisite_course_id;
        $existingCycle = $this->cycleNodes($graph);
        foreach ($edges as $e) if (!$e['blocker'] && !$programs[(int) $e['source']['program_id']]['blockers']) $graph[$e['from']][] = $e['to'];
        $proposedCycle = $this->cycleNodes($graph);
        // The existing canonical catalog writer rejects cycles in the complete graph, even pre-existing ones.
        foreach ($programs as $id => &$p) {
            $identities = array_column(array_filter($rows, fn ($r) => $r['program_id'] === $id), 'identity');
            if ($existingCycle || array_intersect($identities, $proposedCycle)) $p['blockers'][] = 'prerequisite_graph_cycle_requires_review';
        } unset($p);
        foreach ($programs as &$p) $p['blockers'] = array_values(array_unique($p['blockers'])); unset($p);
        return ['manifest_id' => $manifest->data['manifest_id'], 'manifest_hash' => $manifest->hash, 'revision' => $this->transaction->revision(),
            'programs' => $programs, 'rows' => $rows, 'prerequisites' => $edges,
            'baseline' => ['sql_sha256' => $manifest->data['baseline_sql_sha256'],
                'expected_students' => array_sum(array_column($manifest->programs(), 'students_baseline')),
                'actual_students' => array_sum(array_column($programs, 'student_count'))]];
    }

    private function resolve(ApprovedCatalogManifest $manifest, array $row, $catalog, $byCode, $origins): array
    {
        $pid = (int) $row['program_id']; $key = 'course:'.$row['target_code'];
        $result = ['key' => $row['row_key'], 'program_id' => $pid, 'source' => $row];
        $id = ApprovedCatalogManifest::nullableInt($row['identity_candidate_id'] ?? null);
        if (!$id && $row['action'] !== 'MATCH_OR_CREATE') {
            $matches = array_filter($manifest->rows('Catalog'), fn ($c) => self::name($c['course_name']) === self::name($row['target_name'])
                && (int) $c['credit_hours'] === (int) $row['target_credits']
                && in_array($pid, json_decode($c['program_ids'] ?? '[]', true), true));
            if (count($matches) === 1) $id = (int) reset($matches)['course_id'];
        }
        if (!$id && isset($origins[$key])) {
            $proof = $origins[$key]; $course = $catalog->get($proof['course_id']);
            if ($course && $this->fields($course) === $proof['after'] && $course->course_name === $row['target_name']
                && (int) $course->credit_hours === (int) $row['target_credits']) return $result + ['state' => 'reuse', 'identity' => 'id:'.$course->getKey(), 'course_id' => (int) $course->getKey(), 'provenance' => 'completion_receipt'];
            return $result + ['state' => 'blocked', 'blocker' => 'completion_origin_changed'];
        }
        if ($id) {
            $before = $manifest->baselineCourse($id); $actual = $catalog->get($id);
            if (!$before || !$actual || (int) $before['credit_hours'] !== (int) $row['target_credits']) return $result + ['state' => 'blocked', 'blocker' => 'candidate_credit_or_identity_mismatch'];
            $expected = $this->fields($before);
            // D05 is the sole permitted used-origin code correction; names/hours never swap.
            $approvedCode = in_array($row['row_key'], ['P28-R0114', 'P28-R0115'], true) ? $row['target_code'] : $expected['course_code'];
            $current = $this->fields($actual);
            $corrected = array_replace($expected, ['course_code' => $approvedCode]);
            if ($current !== $expected && $current !== $corrected) return $result + ['state' => 'blocked', 'blocker' => 'catalog_before_image_changed', 'expected' => $expected];
            return $result + ['state' => 'reuse', 'identity' => 'id:'.$id, 'course_id' => $id, 'expected' => $expected,
                'retained_code' => $current['course_code'], 'source_code_alias' => $row['target_code'],
                'code_correction' => $current['course_code'] !== $approvedCode ? $approvedCode : null,
                'retained_contact_hours' => [$actual->theoretical_hours, $actual->practical_hours]];
        }
        if ($row['action'] !== 'MATCH_OR_CREATE') return $result + ['state' => 'blocked', 'blocker' => 'identity_unresolved'];
        if (isset($byCode[$row['target_code']])) return $result + ['state' => 'blocked', 'blocker' => 'unproven_existing_code'];
        return $result + ['state' => 'create', 'identity' => 'new:'.$key, 'new_course_key' => $key,
            'definition' => ['key' => $key, 'course_code' => $row['target_code'], 'course_name' => $row['target_name'],
                'credit_hours' => (int) $row['target_credits'], 'theoretical_hours' => ApprovedCatalogManifest::nullableInt($row['theoretical_hours']),
                'practical_hours' => ApprovedCatalogManifest::nullableInt($row['practical_hours']), 'is_active' => true]];
    }

    public function fields(mixed $course): array
    {
        $get = fn ($key) => is_array($course) ? $course[$key] : $course->{$key};
        return ['course_code' => $get('course_code'), 'course_name' => $get('course_name'), 'credit_hours' => (int) $get('credit_hours'),
            'theoretical_hours' => ApprovedCatalogManifest::nullableInt($get('theoretical_hours')), 'practical_hours' => ApprovedCatalogManifest::nullableInt($get('practical_hours')),
            'is_active' => (bool) $get('is_active')];
    }

    private static function name(string $name): string
    {
        return preg_replace('/[\s\p{P}\p{M}]+/u', '', strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ـ' => '']));
    }

    private function cycleNodes(array $graph): array
    {
        $visited = []; $active = []; $cycles = [];
        $visit = function (string $id) use (&$visit, &$visited, &$active, &$cycles, $graph): void {
            if (isset($active[$id])) {
                $path = array_keys($active);
                foreach (array_slice($path, array_search($id, $path, true)) as $node) $cycles[$node] = true;
                return;
            }
            if (isset($visited[$id])) return;
            $active[$id] = true;
            foreach (array_unique($graph[$id] ?? []) as $next) $visit($next);
            unset($active[$id]); $visited[$id] = true;
        };
        foreach (array_keys($graph) as $id) $visit($id);
        return array_keys($cycles);
    }
}
