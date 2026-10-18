<?php

namespace App\Support;

use App\Services\HistoricalExams\{ExamComponentMapping, ExamProjection};
use App\Services\StudentCompletion\CompletionData;

/** An explicit historical assessment, never a guessed interpretation of legacy result parts. */
final class HistoricalAggregateAssessment
{
    public const CONTRACT = 'historical-source-aggregates.v1';

    public static function inspect(array $values, array $base): array
    {
        $b = [];
        if ($base['components'] || $base['marks']) $b[] = 'actual_assessment_evidence_requires_component_workflow';
        $j = ExamProjection::cents($values['coursework'] ?? null);
        $k = ExamProjection::cents($values['exam'] ?? null);
        $total = ExamProjection::cents($values['total'] ?? null);
        if ($j === null || $k === null || $total === null || $j > 4000 || $k > 6000 || $j + $k !== $total) $b[] = 'historical_aggregate_source_incomplete_or_inconsistent';
        $policy = $base['policy'];
        // This is an explicit bridge to the reviewed 40/60 profile, not a relabelling of stored totals.
        if (!$policy || ExamProjection::cents($policy['theoretical_max_mark'] ?? null) !== 6000 || ExamProjection::cents($policy['practical_max_mark'] ?? null) !== 4000) $b[] = 'historical_aggregate_policy_incompatible';
        $min = [];
        foreach (['theoretical' => 6000, 'practical' => 4000, 'final' => 10000] as $part => $cap) {
            $min[$part] = ExamProjection::cents($policy['minimum_'.$part.'_mark'] ?? null);
            if ($min[$part] === null || $min[$part] > $cap) $b[] = 'historical_aggregate_policy_incompatible';
        }
        $status = $b ? null : (($k < $min['theoretical'] || $j < $min['practical'] || $total < $min['final']) ? 'failed' : 'passed');
        $condition = CompletionData::text($values['condition'] ?? null);
        if (!in_array($condition, ['', 'T', 'F'], true)) $b[] = 'historical_aggregate_source_condition_unsupported';
        if ($condition === 'F' && $status === 'passed') $b[] = 'source_outcome_not_representable_by_current_policy';
        return ['contract' => self::CONTRACT, 'profile' => ExamComponentMapping::PROFILE,
            'coursework_cents' => $j, 'examination_cents' => $k, 'total_cents' => $total,
            'caps_cents' => ['coursework' => 4000, 'examination' => 6000], 'minima_cents' => $min,
            'canonical_outcome' => $status, 'blockers' => array_values(array_unique($b)),
            'representation' => 'sourced_aggregates_without_activity_definitions',
            'legacy_result_parts_reinterpreted' => false, 'weights_applied' => false];
    }

    /** Validate immutable adopted evidence independently of today's policy and legacy part columns. */
    public static function valid(array $e, array $snapshot): bool
    {
        if (($e['contract'] ?? null) !== self::CONTRACT || ($e['profile'] ?? null) !== ExamComponentMapping::PROFILE
            || ($e['blockers'] ?? null) !== [] || ($e['legacy_result_parts_reinterpreted'] ?? null) !== false
            || ($e['weights_applied'] ?? null) !== false || ($snapshot['marks'] ?? null) !== [] || count($snapshot['results'] ?? []) !== 1) return false;
        foreach (['coursework_cents' => 4000, 'examination_cents' => 6000, 'total_cents' => 10000] as $key => $cap)
            if (!isset($e[$key]) || !is_int($e[$key]) || $e[$key] < 0 || $e[$key] > $cap) return false;
        if ($e['coursework_cents'] + $e['examination_cents'] !== $e['total_cents']) return false;
        if (!is_array($e['provenance']['reconciled_values'] ?? null) || !is_array($e['provenance']['policy'] ?? null)) return false;
        $reviewed = self::inspect($e['provenance']['reconciled_values'], ['components'=>[], 'marks'=>[], 'policy'=>$e['provenance']['policy']]);
        if (!hash_equals(CompletionData::hash($reviewed), CompletionData::hash(array_intersect_key($e, $reviewed)))) return false;
        foreach (['theoretical' => 6000, 'practical' => 4000, 'final' => 10000] as $part => $cap)
            if (!isset($e['minima_cents'][$part]) || !is_int($e['minima_cents'][$part]) || $e['minima_cents'][$part] < 0 || $e['minima_cents'][$part] > $cap) return false;
        $outcome = $e['examination_cents'] < $e['minima_cents']['theoretical'] || $e['coursework_cents'] < $e['minima_cents']['practical'] || $e['total_cents'] < $e['minima_cents']['final'] ? 'failed' : 'passed';
        $r = $snapshot['results'][0];
        return $e['canonical_outcome'] === $outcome && ExamProjection::cents($r['final_mark']) === $e['total_cents']
            && !(bool)$r['is_deprived'] && (int)$r['result_status_id'] === (int)($e['result_status_id'] ?? 0)
            && isset($e['provenance']['source_reference'], $e['provenance']['source_row_hash'], $e['provenance']['authority_hashes'], $e['provenance']['source_decisions']);
    }

    public static function summary(array $e): array
    {
        // Deliberately excludes internal identities, original cells, decision references and actors.
        return ['kind' => self::CONTRACT, 'coursework_mark' => $e['coursework_cents'] / 100, 'coursework_max' => 40,
            'examination_mark' => $e['examination_cents'] / 100, 'examination_max' => 60];
    }
}
