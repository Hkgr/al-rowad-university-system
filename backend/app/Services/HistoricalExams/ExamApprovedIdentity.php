<?php

namespace App\Services\HistoricalExams;

use App\Services\CatalogCompletion\FollowupCourseIdentity;
use App\Services\StudentCompletion\CompletionData;

/** Bounded subject/context reconciliation; no fuzzy person matching or global code substitutions. */
final class ExamApprovedIdentity
{
    // Ids in the inventory describe its analysis snapshot only. Resolve live programs by
    // their independently verified canonical codes, even if a later snapshot reuses an id.
    private const PROGRAMS = [28=>'RU2-FBER-CE',30=>'RU2-FIE-BR',31=>'RU2-FED-TCH',33=>'RU-FED-PSC',
        39=>'RU2-FL',40=>'RU2-ES-TS',41=>'RU1-TIF-TIFM',44=>'RU1-TI-CE',47=>'RU1-CDI-ESI'];
    public function student(array $row, array $state): array
    {
        $ordinary = (new ExamIdentity)->student($row, $state);
        if($ordinary['blockers']===['student_number_person_conflict'] && $ordinary['decision']===null && $ordinary['student']) {
            $student=$ordinary['student'];
            $labels=[$student['first_name'].' '.$student['last_name'],$student['first_name'].' '.($student['father_name']??'').' '.$student['last_name']];
            if(in_array(ExamApprovedAuthority::label($row['values']['name']),array_map(ExamApprovedAuthority::label(...),$labels),true)) {
                return array_replace($ordinary,['blockers'=>[],'decision'=>'delegated_number_and_exact_normalized_person_label']);
            }
        }
        $rule = $state['_exam_approved']['owners'][CompletionData::text($row['values']['number'])] ?? null;
        if (! $rule || ! $ordinary['blockers']) return $ordinary;
        $source = ExamApprovedAuthority::label($row['values']['name']);
        $aliases = array_map(ExamApprovedAuthority::label(...), $rule['source_name_aliases']);
        if (! in_array($source, $aliases, true)) return $ordinary;
        $matches = array_values(array_filter($state['students'], fn ($s) => $s['student_number'] === $rule['student_number']));
        if (count($matches) !== 1 || ! empty($matches[0]['deleted_at'])) return $ordinary;
        $student = $matches[0];
        $labels = [$student['first_name'].' '.$student['last_name'], $student['first_name'].' '.($student['father_name'] ?? '').' '.$student['last_name']];
        if (isset($rule['retained_system_name'])) {
            if (ExamApprovedAuthority::label($labels[0]) !== ExamApprovedAuthority::label($rule['retained_system_name'])
                || ExamApprovedAuthority::label($student['father_name'] ?? '') !== ExamApprovedAuthority::label($rule['retained_system_father_name'])) return $ordinary;
        } elseif (! array_intersect(array_map(ExamApprovedAuthority::label(...), $labels), $aliases)) return $ordinary;

        return array_replace($ordinary, ['student' => $student, 'number' => $student['student_number'], 'blockers' => [],
            'decision' => 'pinned_approved_number_and_person_alias', 'authority_revision' => ExamApprovedAuthority::REVISION]);
    }

    public function course(array $row, int $programId, array $state, ?int $studentId): array
    {
        $v = $row['values'];
        $legacy = (new ExamIdentity)->course($v, $programId, $state, $studentId);
        if ($legacy['course']) return $legacy;
        $evidence = $state['_exam_approved']['inventory'][$row['id']] ?? null;
        $program = collect($state['academic_programs'])->firstWhere('academic_program_id', $programId);
        if (! $program) return $legacy;
        $code = preg_replace('/\s+/u', '', CompletionData::text($v['code']));
        $label = ExamApprovedAuthority::label($v['course']);
        $credits = filter_var($v['credits'], FILTER_VALIDATE_INT);
        if ($credits === false || $credits < 1 || $credits > 30) return $legacy;
        if ($evidence) foreach (['college', 'program', 'code', 'name' => 'course', 'credits'] as $key => $field) {
            if (is_int($key)) $key = $field;
            if (CompletionData::text($evidence[$key]) !== CompletionData::text($v[$field])) return $this->blocked($legacy, 'approved_course_source_context_changed');
        }
        if (($required = $evidence['required_program_id'] ?? null) !== null
            && (self::PROGRAMS[$required] ?? null) !== $program['program_code']) return $this->blocked($legacy, 'approved_course_program_context_changed');
        $departments = array_column($state['departments'], null, 'department_id');
        $college = collect($state['colleges'])->firstWhere('college_id', $departments[$program['department_id']]['college_id'] ?? null);
        if (($required = $evidence['required_college_code'] ?? null) !== null && ($college['college_code'] ?? null) !== $required) return $this->blocked($legacy, 'approved_course_college_context_changed');
        $target = $evidence['target'] ?? null;
        $basis = 'reviewed_subject_alias_revalidated_against_live_scope';
        $catalogApproval = null;
        // Existing approved institute identities outrank the provisional old inventory.
        $catalog = new FollowupCourseIdentity;
        foreach (['أصول البحث العلمي', 'مدخل إلى الحياة الجامعية', 'فيزياء عامة', 'كيمياء للمهندسين', 'رسم هندسي',
            'علم السكون – استاتيك', 'جيولوجيا هندسية', 'منشأ بيتون مسلح (2)', 'منشأ بيتون مسلح (1)', 'ميكانيك الإنشاءات (2)'] as $subject) {
            if ($label !== ExamApprovedAuthority::label($subject)) continue;
            $mapping = $catalog->historicalMapping($program['program_code'], $code, $subject, $credits);
            if ($mapping) {
                $catalogApproval = $mapping;
                $target = ['course_code' => $mapping['system_code'], 'course_name' => $subject];
                $basis = 'existing_program_qualified_catalog_approval';
                break;
            }
        }
        $decisions = $state['_exam_approved']['authority']['documents']['approved-decisions.json']['decisions'];
        $bounded = $decisions[15];
        if (in_array($row['id'], $bounded['source_references'], true)) {
            if ($code !== $bounded['source_code'] || $label !== ExamApprovedAuthority::label($bounded['source_subject']) || $credits !== $bounded['credit_hours']
                || $program['program_code'] !== 'RU2-FIE-BR' || (int)$v['term'] !== $bounded['source_guidance_term']) return $this->blocked($legacy, 'approved_bounded_subject_context_changed');
            $target = ['course_code' => $bounded['target_code'], 'course_name' => $bounded['target_subject']];
            $basis = 'explicit_six_reference_BR325_alias';
        }
        // Civil Arabic-1 autofill is bounded by subject, credits, guidance term and program.
        if ($program['program_code'] === 'RU2-FBER-CE' && ($college['college_code'] ?? null) === 'FBER'
            && preg_match('/^RU(11[1-9]|12[0-9]|13[01])$/D', $code) && $label === ExamApprovedAuthority::label('اللغة العربية (1)') && $credits === 2 && (int)$v['term'] === 1) {
            $target = ['course_code' => 'RU111', 'course_name' => 'لغة عربية (1)'];
            $basis = 'civil_arabic1_bounded_autofill_sequence';
        }
        // A provisional numeric discrepancy is not authority to reject proven source hours.
        // Only exact reviewed subject identity (not merely the existing code) may supply a target.
        if (! $target && isset($evidence['existing_code_target'])
            && $label === ExamApprovedAuthority::label($evidence['existing_code_target']['course_name'])) $target = $evidence['existing_code_target'];
        if (! $target) return $legacy;
        $matches = [];
        foreach ($state['courses'] as $course) {
            if (isset($catalogApproval['course_id']) && (int)$course['course_id'] !== $catalogApproval['course_id']) continue;
            if ($course['course_code'] !== $target['course_code'] || ! (bool)($course['is_active'] ?? true)
                || ExamApprovedAuthority::label($course['course_name']) !== ExamApprovedAuthority::label($target['course_name'])) continue;
            $contexts = $catalog->contexts($state, (int)$course['course_id']);
            $university = collect($contexts)->contains(fn ($c) => $c['kind'] === 'university_membership');
            $inProgram = collect($contexts)->contains(fn ($c) => (int)$c['department_id'] === (int)$program['department_id']);
            $historical = false;
            $offerings = array_column($state['course_offerings'], null, 'course_offering_id');
            foreach ($state['_student_course_registrations'][$studentId] ?? [] as $registration) {
                $offering = $offerings[$registration['course_offering_id']] ?? null;
                if ($offering && (int)$offering['course_id'] === (int)$course['course_id'] && (int)$offering['academic_program_id'] === $programId) $historical = true;
            }
            if (! $university && ! $inProgram && ! $historical) continue;
            if (in_array($target['course_code'], ['RU111','RU112','RU113','RU121','RU122','RU123','RUS211','RUS221'], true) && ! $university) continue;
            $matches[] = ['course' => $course, 'contexts' => $contexts];
        }
        if (count($matches) !== 1) return $this->blocked($legacy, 'reviewed_course_target_changed_or_scope_unproven');

        return ['course' => $matches[0]['course'], 'mapping' => ['original_code' => $code, 'program_id' => $programId,
            'target_code' => $target['course_code'], 'basis' => $basis, 'review_inventory' => $evidence,
            'verified_contexts' => $matches[0]['contexts'], 'applicable_attempt_credit_hours' => $credits,
            'credit_authority' => 'approved_source_attempt_not_global_origin_mutation'], 'blockers' => []];
    }

    private function blocked(array $legacy, string $code): array
    {
        return array_replace($legacy, ['course' => null, 'blockers' => [$code]]);
    }
}
