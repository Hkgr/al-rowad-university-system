<?php

namespace App\Services\HistoricalExams;

use App\Services\StudentCompletion\CompletionData;

/** v4 only. Source facts, value evidence and sitting chronology remain distinct. */
final class ExamReconciliationProjection
{
    public const CONTRACT = 'historical-exam-completion.review.v4';

    public function state(array $bundle, array $state): array
    {
        $state = (new ExamProjection)->index($state);
        $state['_exam_approved'] = (new ExamApprovedAuthority)->index($bundle['approved_authority']);
        $state['_exam_snapshot_programs'] = array_column($bundle['snapshot']['academic_programs'] ?? [], null, 'academic_program_id');
        return $state;
    }

    public function build(array $bundle, array $state, string $revision): array
    {
        $state = $this->state($bundle, $state);
        $rows = array_column($bundle['rows'], null, 'id');
        $projector = new ExamProjection;
        $observations = [];
        $supplementary = [];
        foreach ($rows as $id => $row) {
            $a = $projector->row($row, $state, $bundle['student_master'] ?? []);
            $a['original_source'] = $row;
            $a['source_decisions'] = [];
            $a['explicit_deferrals'] = $state['_exam_approved']['deferred'][$id] ?? [];
            if (in_array('deferred_identity:'.$id, $a['blockers'], true)) $a['explicit_deferrals'][] = 'existing_explicit_identity_deferral';
            $a['source_selection'] = 'unselected';
            if (isset($state['_exam_approved']['sitting_year'][$id])) {
                $sittingYear=$state['_exam_approved']['sitting_year'][$id];
                $years=array_values(array_filter($state['academic_years'],fn($y)=>CompletionData::year($y['year_name'])===CompletionData::year($sittingYear)));
                $a['supplementary_sitting'] = ['academic_year' => $state['_exam_approved']['sitting_year'][$id],
                    'academic_year_id'=>count($years)===1?(int)$years[0]['academic_year_id']:null,
                    'semester_id' => null, 'date' => null, 'basis' => 'explicit_user_assignment_not_parent_year_or_filename'];
                $a['blockers']=array_values(array_diff($a['blockers'],['supplementary_sitting_undated']));
                $a['blockers'][]=count($years)===1?'supplementary_semester_and_parent_not_proven':'approved_supplementary_year_missing_or_ambiguous';
                $a['required_next_action']='The approved sitting year is known separately. Establish the actual semester and parent attempt before creating a supplementary sitting; do not copy this year into the parent registration.';
            } elseif ($row['kind'] !== 'regular') $a['supplementary_sitting'] = ['academic_year' => null, 'semester_id' => null, 'date' => null, 'basis' => 'source_sitting_undated'];
            $observations[$id] = $a;
            if ($row['kind'] !== 'regular' && $this->identityKey($a) !== null && ! $a['owner']['blockers']) $supplementary[$this->identityKey($a)][] = $a;
        }
        foreach ($observations as $id => &$a) {
            $row = $rows[$id];
            if ($row['kind'] !== 'regular') continue;
            $original = $row['values'];
            $work = ExamProjection::cents($original['coursework'] ?? null);
            $exam = ExamProjection::cents($original['exam']);
            $total = ExamProjection::cents($original['total'] ?? null);
            $decision = $state['_exam_approved']['selected'][$id] ?? null;
            if ($decision) {
                if (! $this->matchesDecision($original, $decision['expected'])) $a['blockers'][] = 'approved_selected_source_values_changed';
                else $a['source_decisions'][] = ['kind' => 'explicit_selected_record', 'decision' => $decision['decision']];
            }
            $fold = null;
            if ($work !== null && $exam !== null && $total === $work + $exam) {
                $fold = ['kind' => 'intact_original_components', 'examination_reference' => $id, 'observed_exam' => $original['exam']];
            } elseif ($work !== null && $total !== null && CompletionData::text($original['supplementary'] ?? null) !== '') {
                $matches = [];
                foreach ($supplementary[$this->identityKey($a)] ?? [] as $candidate) {
                    $source = $candidate['original_source'];
                    if (CompletionData::text($source['values']['number']) !== CompletionData::text($original['number'])
                        || CompletionData::text($source['values']['code']) !== CompletionData::text($original['code'])) continue;
                    $value = ExamProjection::cents($source['values']['exam']);
                    if ($value !== null && $value <= 6000 && $value + $work === $total && $value >= ($exam ?? 0)) $matches[] = $candidate;
                }
                if ($matches && ! collect($matches)->contains(fn($m) => $m['explicit_deferrals'] !== [])) {
                    // Read the actual independent source cell; subtraction is only a consistency check, never an observed mark.
                    $row['values']['exam'] = $matches[0]['original_source']['values']['exam'];
                    $fold = ['kind' => 'traceable_already_combined_outcome_not_new_sitting', 'examination_reference' => array_column($matches, 'source_id'),
                        'observed_exam' => $row['values']['exam'], 'original_K' => $original['exam'], 'sitting_date_inferred' => false];
                }
            }
            if ($fold && ! in_array('approved_selected_source_values_changed', $a['blockers'], true)) {
                // The original Q/K/L remain in original_source. A sourced, already-combined value needs no invented sitting.
                $row['values']['supplementary'] = null;
                $fresh = $projector->row($row, $state, $bundle['student_master'] ?? []);
                $a = array_replace($a, $fresh);
                $a['source_values'] = $original;
                $a['reconciled_values'] = $row['values'];
                $a['source_decisions'][] = $fold;
            } else $a['reconciled_values'] = $original;
            $a['absence_evidence'] = isset($state['_exam_approved']['absent_final'][$id])
                && $work !== null && $work <= 4000 && CompletionData::text($original['exam']) === '-' && CompletionData::text($original['condition']) === 'F';
            $a['effective_final_absence']=$a['absence_evidence'] && ExamProjection::cents($a['reconciled_values']['exam'])===null && ($total===null||$total===$work);
            if($a['absence_evidence']&&!$a['effective_final_absence']&&ExamProjection::cents($a['reconciled_values']['exam'])===null)$a['blockers'][]='combined_outcome_exam_evidence_unproven';
            $a['ordinary_ungraded'] = $work === null && $exam === null && $total === null
                && (CompletionData::text($original['condition'] ?? null)==='غير مكتمل' || CompletionData::text($original['display_outcome'] ?? null)==='غير مكتمل');
            if($a['ordinary_ungraded'])$a['blockers']=array_values(array_diff($a['blockers'],['source_condition_not_supported_by_ordinary_completion']));
            $a['source_credits'] = filter_var($original['credits'], FILTER_VALIDATE_INT);
        }
        unset($a);

        // Only duplicate observations of an independently resolved attempt compete. Actual distinct years/periods are retained.
        $groups = [];
        foreach ($observations as $id => $a) {
            $key = $this->identityKey($a)===null ? 'unresolved-person-context/'.$id : ($a['attempt_key'] ?? 'unresolved-period/'.CompletionData::hash([
                $a['baseline']['student']['student_id'],$a['baseline']['course']['course_id'],$a['baseline']['program_id'],$a['interpreted']['kind'],$a['source_values']['year']??null]));
            $groups[$key][] = $id;
        }
        $actions = [];
        foreach ($groups as $key => $ids) {
            $deferred = array_filter($ids, fn($id) => $observations[$id]['explicit_deferrals'] !== []);
            $explicit = array_values(array_filter($ids, fn($id) => isset($state['_exam_approved']['selected'][$id])));
            $selected = null;
            $qualified=$this->identityKey($observations[$ids[0]])!==null;
            if ($qualified && ! $deferred && count($explicit) === 1) $selected = $explicit[0];
            elseif ($qualified && ! $deferred && count($explicit) === 0) {
                $numeric = array_values(array_filter($ids, fn($id) => $this->intact($observations[$id])));
                usort($numeric, fn($a,$b) => ExamProjection::cents($observations[$b]['reconciled_values']['total']) <=> ExamProjection::cents($observations[$a]['reconciled_values']['total']));
                if ($numeric) {
                    $best = ExamProjection::cents($observations[$numeric[0]]['reconciled_values']['total']);
                    $ties = array_values(array_filter($numeric, fn($id) => ExamProjection::cents($observations[$id]['reconciled_values']['total']) === $best));
                    $facts = array_unique(array_map(fn($id) => CompletionData::hash(array_intersect_key($observations[$id]['reconciled_values'], array_flip(['coursework','exam','total','condition','credits']))), $ties));
                    if (count($facts) === 1) $selected = $ties[0];
                } elseif (count($ids) === 1 || count(array_unique(array_map(fn($id) => CompletionData::hash($observations[$id]['interpreted']), $ids))) === 1) $selected = $ids[0];
            }
            if ($selected !== null && in_array('approved_selected_source_values_changed', $observations[$selected]['blockers'], true)) $selected = null;
            foreach ($ids as $id) {
                $a = $observations[$id];
                $a['selected_source_id'] = $selected;
                $a['source_selection'] = !$qualified ? 'identity_not_independently_resolved' : ($id === $selected ? 'selected_intact_record' : ($selected !== null ? 'retained_competing_observation' : 'unresolved_or_deferred_selection'));
                $a['eligible'] = false;
                $a['execution_kind'] = null;
                if ($a['explicit_deferrals']) $a['classification'] = 'explicitly_deferred';
                elseif ($deferred) {$a['classification'] = 'deferred_same_attempt';$a['blockers'][] = 'same_attempt_contains_explicitly_deferred_evidence';}
                elseif ($selected === null && $qualified) {$a['classification'] = 'source_selection_unresolved';$a['blockers'][] = 'highest_intact_source_record_not_uniquely_established';}
                elseif (!$qualified) $a['required_next_action']='Resolve the exact number/person and qualified academic identity before comparing source outcomes; do not select by number alone.';
                elseif ($selected !== $id) $a['classification'] = 'retained_source_history';
                else $a = $this->action($a);
                if($a['eligible']&&array_key_exists('_historical_exam_schema_ready',$state)&&!$state['_historical_exam_schema_ready']) {
                    $a['eligible']=false;$a['blockers'][]='historical_exam_schema_not_ready';
                }
                $a['references'] = [$rows[$id]['reference'] + ['row_hash' => $rows[$id]['row_hash']]];
                $a['action_id'] = CompletionData::hash([self::CONTRACT, $key, $a['references'], $a['reconciled_values'] ?? $a['source_values'], $a['source_decisions']]);
                $a['blockers'] = array_values(array_unique($a['blockers']));
                $actions[] = $a;
            }
        }
        $primary = []; $blockers = []; $deferredUnion = []; $eligible = 0; $noOp = 0;
        foreach ($actions as $a) {
            $primary[$a['classification']] = ($primary[$a['classification']] ?? 0) + 1;
            foreach ($a['blockers'] as $reason) $blockers[$reason] = ($blockers[$reason] ?? 0) + 1;
            if ($a['explicit_deferrals']) $deferredUnion[] = $a['source_id'];
            $eligible += (int)$a['eligible'];
            $noOp += (int)($a['classification'] === 'existing_equivalent');
        }
        ksort($primary); ksort($blockers); sort($deferredUnion);
        $counts = ['observations' => count($actions), 'actions' => count($actions), 'attempt_review_groups' => count($groups), 'eligible' => $eligible, 'no_op' => $noOp,
            'classifications' => $primary, 'overlapping_blockers' => $blockers, 'explicit_deferral_union' => count($deferredUnion),
            'students' => count(array_unique(array_filter(array_map(fn($a) => $a['baseline']['student']['student_id'] ?? null, $actions)))),
            'courses' => count(array_unique(array_filter(array_map(fn($a) => $a['baseline']['course']['course_id'] ?? null, $actions))))];
        $review = ['contract' => self::CONTRACT, 'actor_id'=>$state['_actor_id']??null,'schema_readiness_checked'=>array_key_exists('_historical_exam_schema_ready',$state),
            'sources' => $bundle['sources'], 'authority_hashes' => $bundle['approved_authority']['document_hashes'],
            'bundle_file_hash' => $bundle['artifact_file_sha256'] ?? null, 'bundle_digest' => CompletionData::hash($bundle), 'revision' => $revision, 'actions' => $actions];

        return ['status' => $eligible ? 'PARTIAL' : ($noOp === count($actions) ? 'COMPLETE' : 'BLOCKED'), 'writes_performed' => false,
            'counts' => $counts, 'explicit_deferral_register' => $deferredUnion, 'review' => $review, 'review_hash' => CompletionData::hash($review)];
    }

    private function identityKey(array $action): ?string
    {
        if($action['owner']['blockers'] || ($action['program_evidence']['blockers']??[]))return null;
        return isset($action['baseline']['student']['student_id'], $action['baseline']['course']['course_id'], $action['baseline']['program_id'])
            ? implode('/', [$action['baseline']['student']['student_id'], $action['baseline']['course']['course_id'], $action['baseline']['program_id']]) : null;
    }

    private function intact(array $a): bool
    {
        $v = $a['reconciled_values'] ?? $a['source_values'];
        $j = ExamProjection::cents($v['coursework'] ?? null); $k = ExamProjection::cents($v['exam']); $l = ExamProjection::cents($v['total'] ?? null);
        return $a['interpreted']['kind'] === 'regular' && $j !== null && $j <= 4000 && $k !== null && $k <= 6000 && $l === $j + $k;
    }

    private function matchesDecision(array $values, array $expected): bool
    {
        $names = ['examination' => 'exam', 'final_total' => 'total', 'source_condition' => 'condition', 'supp_flag' => 'supplementary'];
        foreach ($expected as $key => $value) {
            $field = $names[$key] ?? $key;
            if (in_array($field, ['coursework','exam','total'], true)) {
                if (ExamProjection::cents($values[$field] ?? null) !== ExamProjection::cents($value)) return false;
            } elseif (in_array($field, ['condition','supplementary','term','year'], true) && CompletionData::text($values[$field] ?? null) !== CompletionData::text($value)) return false;
        }
        return true;
    }

    private function action(array $a): array
    {
        $base = $a['baseline'];
        $identityReady = $this->identityKey($a) !== null && ! $a['owner']['blockers'] && ! ($a['program_evidence']['blockers'] ?? []) && $a['period']['academic_year_id'] && $a['period']['semester_id'];
        if (! $identityReady || $a['interpreted']['kind'] !== 'regular') return $a;
        $protected = array_intersect($a['blockers'], ['incomplete_lifecycle_owned_all_states','registration_status_protected','symbol_requires_existing_authorized_workflow','supplementary_registration_requires_review',
            'repeated_attempt_semester_not_proven','offering_ambiguous','existing_registration_other_program_requires_review','approved_selected_source_values_changed']);
        if ($protected) {$a['required_next_action'] = 'Use the existing dedicated lifecycle/registration/supplementary authorization; no reconciliation bypass.';return $a;}
        $credits=$base['credit_evidence']??[];usort($credits,fn($x,$y)=>(int)$y['revision']<=>(int)$x['revision']);
        $planCredits=array_values(array_filter($base['plan_credit_evidence']??[],fn($e)=>(int)$e['program_course_id']===(int)($base['registration']['plan_program_course_id']??0)));
        usort($planCredits,fn($x,$y)=>(int)$y['revision']<=>(int)$x['revision']);
        $actualCredits=\App\Support\ApplicableCourseCredits::choose(isset($credits[0])?(int)$credits[0]['credit_hours']:null,isset($planCredits[0])?(int)$planCredits[0]['credit_hours']:null,(int)$base['course']['credit_hours']);
        if($a['classification']==='existing_equivalent' && $base['registration'] && $a['source_credits']!==$actualCredits) {
            $a['classification']='credit_context_review';$a['execution_kind']='stage';$a['eligible']=true;
            $a['required_next_action']='Review applicable attempt credits separately; preserve the equivalent result and all its timestamps.';
        } elseif ($a['ordinary_ungraded'] || $a['absence_evidence'] || in_array('aggregate_known_component_breakdown_missing', $a['blockers'], true)
            || ($this->intact($a)&&array_intersect($a['blockers'],['component_definitions_require_canonical_preparation_preview','component_semantics_not_faithfully_representable']))) {
            $a['classification'] = $a['ordinary_ungraded'] ? 'ungraded_followup' : ($a['effective_final_absence'] ? 'final_absence_review' : 'aggregate_incomplete_followup');
            $a['execution_kind'] = 'stage';
            $a['eligible'] = true;
            $a['required_next_action'] = $a['effective_final_absence'] ? 'Explicit authorized settlement review; retain the observed total, but do not manufacture a numeric examination mark.' : 'Scoped Exam Affairs follow-up. Supply actual missing activities or independently traced combined-exam evidence through the canonical path; no automatic I grant.';
        } elseif ($a['classification'] === 'existing_value_conflict' && $this->intact($a) && $a['component_mapping']['complete']
            && ! array_diff($a['blockers'], ['existing_values_require_separate_correction_review','official_or_submitted_result_locked','existing_component_submission_or_approval_protected'])) {
            $a['classification'] = 'correction_review'; $a['execution_kind'] = 'stage'; $a['eligible'] = true;
            $a['required_next_action'] = 'Stage only. The current official outcome remains unchanged until a separately reviewed authorized correction is committed.';
        } elseif (! $a['blockers'] && $a['classification'] !== 'existing_equivalent' && ($a['proposed_components'] || ! $base['registration'])) {
            $a['execution_kind'] = 'draft'; $a['eligible'] = true;
        }
        return $a;
    }
}
