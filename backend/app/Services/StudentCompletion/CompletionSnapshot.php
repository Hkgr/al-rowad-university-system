<?php

namespace App\Services\StudentCompletion;

/** Data-only dump reader. No SQL execution, connection, eval, DDL or stored routine handling. */
final class CompletionSnapshot
{
    public const TABLES = ['students', 'student_statuses', 'academic_levels', 'academic_years', 'semesters',
        'academic_programs', 'departments', 'colleges', 'academic_plan_versions', 'student_academic_plan_assignments',
        'course_offerings', 'courses', 'registration_statuses', 'student_course_registrations', 'student_course_results',
        'student_academic_terms', 'student_registration_requests', 'student_registration_modification_requests',
        'student_registration_replacement_requests', 'student_registration_withdrawal_requests', 'grade_appeals',
        'appeal_statuses', 'supplementary_exam_registrations', 'supplementary_exam_materializations',
        'student_progression_decisions', 'student_graduation_decisions', 'result_statuses','grade_approvals','approval_statuses'];

    public function read(string $file): array
    {
        $sql = file_get_contents($file);
        if ($sql === false) throw new \InvalidArgumentException('Snapshot is unreadable.');
        $tables = array_fill_keys(self::TABLES, []);
        // The lexical terminator respects SQL strings, including escaped semicolons/newlines.
        preg_match_all('/^INSERT INTO `([^`]+)` \(([^\r\n]+)\) VALUES\s*/m', $sql, $headers, PREG_OFFSET_CAPTURE);
        foreach ($headers[0] as $i => [$header, $offset]) {
            $table = $headers[1][$i][0];
            if (!array_key_exists($table, $tables)) continue;
            preg_match_all('/`([^`]+)`/', $headers[2][$i][0], $columns);
            $position = $offset + strlen($header);
            foreach ($this->tuples($sql, $position) as $values) {
                if (count($values) !== count($columns[1])) throw new \InvalidArgumentException('Invalid snapshot row: '.$table);
                $row = array_combine($columns[1], $values);
                if ($table === 'students') $row = array_intersect_key($row, array_flip(['student_id','student_number','first_name',
                    'last_name','father_name','academic_program_id','current_academic_level_id','enrollment_date',
                    'student_status_id','deleted_at','deregistration_reason','created_at','updated_at']));
                $tables[$table][] = $row;
            }
        }
        foreach (['students','student_statuses','academic_years','academic_programs'] as $required)
            if (!$tables[$required]) throw new \InvalidArgumentException('Snapshot missing required data: '.$required);
        return $tables;
    }

    private function tuples(string $sql, int &$p): \Generator
    {
        $n = strlen($sql);
        while ($p < $n) {
            while ($p < $n && (ctype_space($sql[$p]) || $sql[$p] === ',')) $p++;
            if (($sql[$p] ?? null) === ';') return;
            if (($sql[$p++] ?? null) !== '(') throw new \InvalidArgumentException('Unsupported snapshot INSERT.');
            $values = [];
            do {
                while ($p < $n && ctype_space($sql[$p])) $p++;
                if (($sql[$p] ?? null) === "'") {
                    $p++; $value = ''; $closed = false;
                    while ($p < $n) {
                        $c = $sql[$p++];
                        if ($c === '\\') {
                            $next = $sql[$p++] ?? '';
                            $value .= match ($next) {'n'=>"\n",'r'=>"\r",'t'=>"\t",'0'=>"\0", default=>$next};
                        } elseif ($c === "'") {
                            if (($sql[$p] ?? null) === "'") { $value .= "'"; $p++; }
                            else { $closed = true; break; }
                        } else $value .= $c;
                    }
                    if (!$closed) throw new \InvalidArgumentException('Unclosed snapshot string.');
                } else {
                    $start = $p;
                    while ($p < $n && !in_array($sql[$p], [',',')'], true)) $p++;
                    $token = trim(substr($sql, $start, $p - $start));
                    if ($token !== 'NULL' && !preg_match('/^-?\d+(?:\.\d+)?$/', $token)) throw new \InvalidArgumentException('Unsupported snapshot value.');
                    $value = $token === 'NULL' ? null : $token;
                }
                $values[] = $value;
                while ($p < $n && ctype_space($sql[$p])) $p++;
                $delimiter = $sql[$p++] ?? '';
                if (!in_array($delimiter, [',',')'], true)) throw new \InvalidArgumentException('Invalid snapshot delimiter.');
            } while ($delimiter !== ')');
            yield $values;
        }
        throw new \InvalidArgumentException('Unterminated snapshot INSERT.');
    }
}
