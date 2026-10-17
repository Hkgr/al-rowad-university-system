<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use Illuminate\Support\Facades\{DB, Schema};

final class AcademicPlacementSchema
{
    public static function inspect(): array
    {
        if (DB::getDriverName() === 'mysql') {
            try { $row = (array) DB::selectOne(file_get_contents(database_path('sql/elective-advisory-placement/02_verify.sql'))); }
            catch (\Illuminate\Database\QueryException $e) {
                // Schema inspection only; never swallow or retry an academic mutation.
                if (!in_array((int) ($e->errorInfo[1] ?? 0), [1054,1146], true)) throw $e;
                $row = ['result' => 'FAIL', 'detail' => 'placement_schema_contract_unreadable'];
            }
            return ['ready' => ($row['result'] ?? null) === 'PASS', 'reason' => $row['detail'] ?? 'placement_contract_unavailable',
                'unplaced_elective_memberships' => isset($row['unplaced_elective_memberships']) ? (int)$row['unplaced_elective_memberships'] : null,
                'partial_placements' => isset($row['partial_placements']) ? (int)$row['partial_placements'] : null,
                'mandatory_without_placement' => isset($row['mandatory_without_placement']) ? (int)$row['mandatory_without_placement'] : null,
                'package' => 'elective-advisory-placement', 'verification' => 'mariadb_schema_contract'];
        }
        // SQLite is test/pure-logic evidence, not MariaDB upgrade or concurrency verification.
        $columns = collect(Schema::getColumns('program_courses'))->keyBy('name');
        return ['ready' => ($columns['academic_level_id']['nullable'] ?? false) && ($columns['recommended_semester_id']['nullable'] ?? false),
            'reason' => 'sqlite_nullable_columns_only', 'package' => 'elective-advisory-placement', 'verification' => 'sqlite_test_only'];
    }

    public static function assertReady(): void
    {
        $state = self::inspect();
        if (!$state['ready']) throw new AcademicPlanException('يلزم تثبيت حزمة التوزيع الإرشادي للبدائل الاختيارية والتحقق منها قبل استكمال الخطط؛ لم تبدأ مرحلة جديدة.',
            'academic_placement_schema_not_ready', 503);
    }
}
