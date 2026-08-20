<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Phase 1 supplementary examination period governance source contracts.
 */
class SupplementaryExamPeriodGovernanceContractTest extends TestCase
{
    public function test_supp_period_01_scientific_vp_with_decide_can_announce(): void
    {
        $decide = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'assertCanDecide');
        self::assertStringContainsString('isScientificVicePresident()', $decide);
        self::assertStringContainsString('holdsAssignedPermission(', $decide);
        self::assertStringContainsString('PERMISSION_DECIDE', $decide);

        $request = self::source('app/Http/Requests/VicePresidency/AnnounceSupplementaryExamPeriodRequest.php');
        self::assertStringContainsString('isScientificVicePresident()', $request);
        self::assertStringContainsString("effectivePermissions()->contains(SupplementaryExamPeriodGovernance::PERMISSION_DECIDE)", $request);
    }

    public function test_supp_period_02_scientific_vp_without_decide_cannot_announce(): void
    {
        $decide = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'assertCanDecide');
        self::assertStringContainsString('|| ! $this->holdsAssignedPermission($user, SupplementaryExamPeriodGovernance::PERMISSION_DECIDE)', $decide);
        self::assertStringContainsString('decisionForbidden()', $decide);
    }

    public function test_supp_period_03_generic_vice_president_denied(): void
    {
        $user = self::source('app/Models/User.php');
        self::assertStringContainsString("ROLE_SCIENTIFIC", $user);
        self::assertStringNotContainsString("hasRoleCode(VicePresidency::ROLE_LEGACY)", self::extractMethod($user, 'isScientificVicePresident'));

        $sql = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');
        self::assertStringNotContainsString("r.role_code = 'vice_president'\n  AND p.permission_code = 'supplementary_exams.periods.decide'", $sql);
        self::assertStringContainsString("r.role_code IN ('vice_president', 'vice_president_administrative', 'dean', 'super_admin')", $sql);
    }

    public function test_supp_period_04_administrative_vp_denied(): void
    {
        $decide = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'assertCanDecide');
        self::assertStringNotContainsString('isAdministrativeVicePresident()', $decide);

        $verify = self::source('database/sql/supplementary-exam-period-governance/02_verify.sql');
        self::assertStringContainsString("r.role_code = 'vice_president_administrative' AND p.permission_code = 'supplementary_exams.periods.decide'", $verify);
        self::assertStringContainsString('administrative_vp_no_decide', $verify);
    }

    public function test_supp_period_05_dean_denied(): void
    {
        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');
        self::assertStringContainsString("AND r.role_code = 'vice_president_scientific'", $apply);
        self::assertStringContainsString("p.permission_code = 'supplementary_exams.periods.decide'", $apply);
        self::assertStringNotContainsString("r.role_code IN ('vice_president_scientific', 'dean')\n  AND r.is_active = 1\n  AND p.permission_code = 'supplementary_exams.periods.decide'", $apply);

        $verify = self::source('database/sql/supplementary-exam-period-governance/02_verify.sql');
        self::assertStringContainsString('dean_no_decide', $verify);
    }

    public function test_supp_period_06_super_admin_virtual_has_permission_cannot_decide(): void
    {
        $holds = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'holdsAssignedPermission');
        self::assertStringContainsString('effectivePermissions()->contains($permission)', $holds);
        self::assertStringNotContainsString('$user->hasPermission(', $holds);

        $decide = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'assertCanDecide');
        self::assertStringNotContainsString('$user->hasPermission(', $decide);

        $request = self::source('app/Http/Requests/VicePresidency/AnnounceSupplementaryExamPeriodRequest.php');
        self::assertStringContainsString('effectivePermissions()', $request);
        self::assertStringNotContainsString('hasPermission(', $request);
    }

    public function test_supp_period_07_year_and_semester_are_canonical_identity(): void
    {
        $service = self::source('app/Services/SupplementaryExamPeriodGovernanceService.php');
        $inside = self::extractMethod($service, 'announceInsideTransaction');
        self::assertStringContainsString("->where('academic_year_id', \$yearId)", $inside);
        self::assertStringContainsString("->where('semester_id', \$semesterId)", $inside);
        self::assertStringContainsString('lockForUpdate()', $inside);

        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');
        self::assertStringContainsString('ADD UNIQUE KEY `uq_sep_year_semester` (`academic_year_id`, `semester_id`)', $apply);
    }

    public function test_supp_period_08_and_09_duplicate_and_legacy_identity_conflict(): void
    {
        $inside = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'announceInsideTransaction');
        self::assertStringContainsString('if ($existing !== null)', $inside);
        self::assertStringContainsString('identityExists()', $inside);
        self::assertStringNotContainsString("status === SupplementaryExamPeriodGovernance::STATUS_ANNOUNCED", $inside);
        self::assertStringContainsString('isUniqueIdentityViolation(', $inside);
    }

    public function test_supp_period_10_start_after_end_rejected(): void
    {
        $request = self::source('app/Http/Requests/VicePresidency/AnnounceSupplementaryExamPeriodRequest.php');
        self::assertStringContainsString("'end_date' => ['required', 'date', 'after_or_equal:start_date']", $request);
    }

    public function test_supp_period_11_through_14_client_system_fields_prohibited(): void
    {
        $request = self::source('app/Http/Requests/VicePresidency/AnnounceSupplementaryExamPeriodRequest.php');
        foreach (['status', 'is_active', 'opened_by_user_id', 'opened_at', 'created_at', 'updated_at'] as $field) {
            self::assertStringContainsString("'{$field}' => ['prohibited']", $request);
        }
    }

    public function test_supp_period_15_through_19_announced_period_and_event(): void
    {
        $inside = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'announceInsideTransaction');
        self::assertStringContainsString('STATUS_ANNOUNCED', $inside);
        self::assertStringContainsString('$period->is_active = true', $inside);
        self::assertStringContainsString('$period->opened_by_user_id = $user->user_id', $inside);
        self::assertStringContainsString('$period->opened_at = now()', $inside);
        self::assertStringContainsString('EVENT_ANNOUNCED', $inside);
        self::assertStringContainsString("'from_status' => null", $inside);
        self::assertStringContainsString("'to_status' => SupplementaryExamPeriodGovernance::STATUS_ANNOUNCED", $inside);
        self::assertStringContainsString("'actor_user_id' => \$user->user_id", $inside);
        self::assertSame(1, substr_count($inside, 'SupplementaryExamPeriodEvent::query()->create('));
    }

    public function test_supp_period_20_announcement_and_event_are_atomic(): void
    {
        $service = self::source('app/Services/SupplementaryExamPeriodGovernanceService.php');
        $announce = self::extractMethod($service, 'announce');
        self::assertStringContainsString('DB::transaction(fn () => $this->announceInsideTransaction($user, $payload))', $announce);

        $inside = self::extractMethod($service, 'announceInsideTransaction');
        self::assertStringContainsString('transactionLevel()', $inside);
        self::assertStringContainsString('transactionRequired()', $inside);
        self::assertGreaterThan(
            strpos($inside, '$period->save()'),
            strpos($inside, 'SupplementaryExamPeriodEvent::query()->create(')
        );
    }

    public function test_supp_period_21_through_23_generic_crud_writes_removed(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringNotContainsString("Route::apiResource('supplementary-exam-periods'", $routes);
        self::assertStringContainsString("Route::get('supplementary-exam-periods'", $routes);
        self::assertStringContainsString("Route::get('supplementary-exam-periods/{period}'", $routes);
        self::assertStringNotContainsString("Route::post('supplementary-exam-periods'", $routes);
        self::assertStringNotContainsString("Route::put('supplementary-exam-periods'", $routes);
        self::assertStringNotContainsString("Route::patch('supplementary-exam-periods'", $routes);
        self::assertStringNotContainsString("Route::delete('supplementary-exam-periods'", $routes);

        $controller = self::source('app/Http/Controllers/Api/SupplementaryExamPeriodController.php');
        self::assertStringContainsString('MethodNotAllowedHttpException', $controller);
        self::assertStringContainsString('Generic supplementary exam period writes are disabled.', $controller);

        $store = self::source('app/Http/Requests/SupplementaryExamPeriod/StoreSupplementaryExamPeriodRequest.php');
        $update = self::source('app/Http/Requests/SupplementaryExamPeriod/UpdateSupplementaryExamPeriodRequest.php');
        self::assertStringContainsString('return false;', $store);
        self::assertStringContainsString('return false;', $update);
    }

    public function test_supp_period_24_through_27_read_api_view_and_filters(): void
    {
        $view = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'assertCanView');
        self::assertStringContainsString('PERMISSION_VIEW', $view);

        $query = self::extractMethod(self::source('app/Services/SupplementaryExamPeriodGovernanceService.php'), 'periodQuery');
        self::assertStringContainsString("where('academic_year_id'", $query);
        self::assertStringContainsString("where('semester_id'", $query);
        self::assertStringContainsString("where('status'", $query);

        $generic = self::source('app/Http/Controllers/Api/SupplementaryExamPeriodController.php');
        self::assertStringContainsString("'academic_year_id'", $generic);
        self::assertStringContainsString("'semester_id'", $generic);
        self::assertStringContainsString("'status'", $generic);
        self::assertStringNotContainsString('scopeResourceQuery', $generic);
    }

    public function test_supp_period_28_through_30_no_offering_registration_or_results_mutation(): void
    {
        $service = self::source('app/Services/SupplementaryExamPeriodGovernanceService.php');
        self::assertStringNotContainsString('CourseOffering', $service);
        self::assertStringNotContainsString('StudentCourseRegistration', $service);
        self::assertStringNotContainsString('SupplementaryExamResult', $service);

        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`supplementary_exam_results`', $apply);
        self::assertStringNotContainsString('DELETE FROM `alrowad_uni_rust`.`supplementary_exam_results`', $apply);
        self::assertStringNotContainsString('UPDATE `alrowad_uni_rust`.`supplementary_exam_results`', $apply);
        self::assertStringNotContainsString('INSERT INTO `alrowad_uni_rust`.`supplementary_exam_periods`', $apply);
    }

    public function test_supp_period_31_legacy_rows_preserved_and_readable(): void
    {
        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');
        self::assertStringContainsString("SET `status` = ''legacy'' WHERE `status` IS NULL OR TRIM(`status`) = ''''", $apply);
        self::assertStringNotContainsString('SET `opened_by_user_id` =', $apply);
        self::assertStringNotContainsString('SET `opened_at` =', $apply);

        $verify = self::source('database/sql/supplementary-exam-period-governance/02_verify.sql');
        self::assertStringContainsString('legacy_not_attributed', $verify);
        self::assertStringContainsString("status = ''legacy'' AND (opened_by_user_id IS NOT NULL OR opened_at IS NOT NULL)", $verify);

        $resource = self::source('app/Http/Resources/SupplementaryExamPeriodResource.php');
        self::assertStringContainsString("'status' => \$this->status", $resource);
        self::assertStringContainsString("'period_name' => \$this->period_name", $resource);
    }

    public function test_supp_period_32_through_34_scientific_vp_ui(): void
    {
        $page = self::frontend('src/features/vice-presidency/pages/SupplementaryExamPeriods.jsx');
        $utils = self::frontend('src/features/vice-presidency/utils/supplementaryExamPeriods.js');
        $nav = self::frontend('src/features/vice-presidency/nav.js');
        $app = self::frontend('src/app/App.jsx');

        self::assertStringContainsString("to: '/vp/scientific/supplementary-exams'", $nav);
        self::assertStringContainsString("ar: 'الامتحانات التكميلية'", $nav);
        self::assertStringContainsString('roles: [ROLES.vicePresidentScientific]', $nav);
        self::assertStringContainsString("path=\"/vp/scientific/supplementary-exams\"", $app);

        self::assertStringContainsString('export function canAnnouncePeriod(period)', $utils);
        self::assertStringContainsString('return period == null', $utils);
        self::assertStringContainsString('canDecideSupplementaryExamPeriod', $utils);
        self::assertStringNotContainsString("import { hasPermission", $utils);
        self::assertStringContainsString("hasAssignedPermission('supplementary_exams.periods.decide'", $utils);

        $inactive = self::sliceJs($page, 'inactive ? (', ') : (');
        self::assertStringContainsString('فتح دورة تكميلية', $inactive);
        self::assertStringContainsString('غير مفعلة', $inactive);

        $active = self::sliceJs($page, ') : (', '{dialogSemester &&');
        self::assertStringContainsString('عرض التفاصيل', $active);
        self::assertStringContainsString('statusLabelAr(period.status)', $active);
        self::assertStringNotContainsString('فتح دورة تكميلية', $active);

        self::assertStringNotContainsString('name="status"', $page);
        self::assertStringNotContainsString('is_active', $page);
        self::assertStringNotContainsString('opened_by_user_id', $page);
        self::assertStringNotContainsString('period_name, start_date, end_date, decision_note, status', $page);
        self::assertStringContainsString('period_name: form.period_name', $page);
        self::assertStringContainsString('start_date: form.start_date', $page);
        self::assertStringContainsString('end_date: form.end_date', $page);
        self::assertStringContainsString('اعتماد فتح الدورة', $page);
        self::assertStringContainsString('سيؤدي اعتماد القرار إلى إنشاء دورة امتحانية تكميلية مرتبطة بهذا الفصل، وستصبح مرئية للجهات الأكاديمية المخولة.', $page);
    }

    public function test_supp_period_35_no_later_phase_implementation(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'app/Models/SupplementaryExamOffering.php',
            'app/Models/SupplementaryExamRegistration.php',
            'app/Services/SupplementaryExamEligibilityService.php',
            'app/Services/SupplementaryExamGradingService.php',
        ] as $path) {
            self::assertFileDoesNotExist($root.'/'.$path);
        }

        $page = self::frontend('src/features/vice-presidency/pages/SupplementaryExamPeriods.jsx');
        self::assertStringNotContainsString('eligibility', $page);
        self::assertStringNotContainsString('failed_theoretical', $page);
        self::assertStringNotContainsString('volitionally_deferred', $page);
        self::assertStringNotContainsString('StudentCourseRegistration', $page);
        self::assertStringNotContainsString('CourseOffering', $page);
        self::assertStringNotContainsString('theoretical_mark', $page);

        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');
        self::assertStringNotContainsString('supplementary_exam_offerings', $apply);
        self::assertStringNotContainsString('supplementary_exam_registrations', $apply);
    }

    public function test_sql_pack_and_rbac_layout(): void
    {
        $dir = dirname(__DIR__, 2).'/database/sql/supplementary-exam-period-governance';
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql', '03_rollback.sql', 'README.md'] as $file) {
            self::assertFileExists($dir.'/'.$file);
        }

        $preflight = self::source('database/sql/supplementary-exam-period-governance/00_preflight.sql');
        self::assertStringContainsString('READ ONLY', $preflight);
        self::assertStringContainsString('UNRESOLVED', $preflight);
        self::assertStringContainsString('F_duplicate_identities', $preflight);
        self::assertStringContainsString("'OVERALL' AS report_section", $preflight);
        self::assertStringContainsString('Do not use DATABASE()', $preflight);
        self::assertStringNotContainsString('INSERT INTO', $preflight);
        self::assertStringNotContainsString('UPDATE ', $preflight);
        self::assertStringNotContainsString('DELETE ', $preflight);
        self::assertStringNotContainsString('ALTER TABLE', $preflight);

        $rollback = self::source('database/sql/supplementary-exam-period-governance/03_rollback.sql');
        self::assertStringContainsString('BLOCKED_IN_USE', $rollback);
        self::assertStringContainsString('NEVER delete supplementary_exam_results', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`supplementary_exam_periods`', $rollback);
        self::assertStringNotContainsString('DROP TABLE `alrowad_uni_rust`.`supplementary_exam_results`', $rollback);
        self::assertStringContainsString("WHERE status = ''announced''", $rollback);
    }

    /**
     * SUPP-SQL-01: equivalent UNIQUE(academic_year_id, semester_id) under another name
     * is COMPATIBLE for preflight, apply completion, and verify.
     */
    public function test_supp_sql_01_identity_unique_is_semantic_not_name_based(): void
    {
        $preflight = self::source('database/sql/supplementary-exam-period-governance/00_preflight.sql');
        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');
        $verify = self::source('database/sql/supplementary-exam-period-governance/02_verify.sql');

        foreach ([$preflight, $apply, $verify] as $sql) {
            self::assertStringContainsString('SET @identity_unique_exists', $sql);
            self::assertStringContainsString("HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'academic_year_id,semester_id'", $sql);
            self::assertStringContainsString('AND non_unique = 0', $sql);
            self::assertStringContainsString("AND index_name <> 'PRIMARY'", $sql);
        }

        self::assertStringContainsString('WHEN @identity_unique_exists >= 1 THEN \'COMPATIBLE\'', $preflight);
        self::assertStringContainsString('WHEN @identity_unique_exists >= 1 THEN \'COMPATIBLE\'', $apply);

        $applyResult = self::sliceSql($apply, "SET @apply_status := IF(", "SELECT 'APPLY_RESULT'");
        self::assertStringContainsString('AND @identity_unique_exists >= 1', $applyResult);
        self::assertStringNotContainsString('@uq_name_exists = 1', $applyResult);

        self::assertStringContainsString('SET @uq_identity := IF(@identity_unique_exists >= 1, 1, 0)', $verify);
        self::assertStringNotContainsString("index_name = 'uq_sep_year_semester'", $verify);
        self::assertStringContainsString('ADD UNIQUE KEY `uq_sep_year_semester` (`academic_year_id`, `semester_id`)', $apply);
        self::assertStringContainsString('AND @identity_unique_exists = 0', $apply);
    }

    /**
     * SUPP-SQL-02: non-InnoDB pre-existing event table is CONFLICT/BLOCKED.
     */
    public function test_supp_sql_02_non_innodb_event_table_is_conflict(): void
    {
        $preflight = self::source('database/sql/supplementary-exam-period-governance/00_preflight.sql');
        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');

        self::assertStringContainsString("AND UPPER(IFNULL((SELECT t.engine FROM information_schema.tables t WHERE t.table_schema = 'alrowad_uni_rust' AND t.table_name = 'supplementary_exam_period_events' AND t.table_type = 'BASE TABLE'), '')) = 'INNODB'", $preflight);
        self::assertStringContainsString('SET @events_engine_ok', $preflight);
        self::assertStringContainsString('SET @events_full_ok', $preflight);
        self::assertStringContainsString('WHEN @events_full_ok = 1 THEN \'COMPATIBLE\'', $preflight);
        self::assertStringContainsString('ELSE \'CONFLICT\'', $preflight);
        self::assertStringContainsString('OR @events_state = \'CONFLICT\'', $preflight);
        self::assertStringContainsString("'BLOCKED'", $preflight);

        self::assertStringContainsString("AND UPPER(IFNULL((SELECT t.engine FROM information_schema.tables t WHERE t.table_schema = 'alrowad_uni_rust' AND t.table_name = 'supplementary_exam_period_events' AND t.table_type = 'BASE TABLE'), '')) = 'INNODB'", $apply);
        self::assertStringContainsString("AND @events_state = 'ABSENT'", $apply);
        self::assertStringContainsString('OR @events_state = \'CONFLICT\'', $apply);
        self::assertStringNotContainsString('ENGINE=MyISAM', $apply);
    }

    /**
     * SUPP-SQL-03: event table with correct column names but missing required FK is BLOCKED.
     */
    public function test_supp_sql_03_event_table_missing_fk_is_blocked(): void
    {
        $preflight = self::source('database/sql/supplementary-exam-period-governance/00_preflight.sql');
        $verify = self::source('database/sql/supplementary-exam-period-governance/02_verify.sql');

        self::assertStringContainsString('SET @events_fk_period_ok', $preflight);
        self::assertStringContainsString('SET @events_fk_actor_ok', $preflight);
        self::assertStringContainsString("AND k.referenced_table_name = 'supplementary_exam_periods'", $preflight);
        self::assertStringContainsString("AND k.referenced_column_name = 'supplementary_exam_period_id'", $preflight);
        self::assertStringContainsString("AND k.referenced_table_name = 'users'", $preflight);
        self::assertStringContainsString("AND k.referenced_column_name = 'user_id'", $preflight);
        self::assertStringContainsString('AND @events_fk_period_ok = 1', $preflight);
        self::assertStringContainsString('AND @events_fk_actor_ok = 1', $preflight);

        self::assertStringContainsString('SET @fk_event_period', $verify);
        self::assertStringContainsString('SET @fk_event_actor', $verify);
        self::assertStringContainsString('AND @fk_event_period = 1', $verify);
        self::assertStringContainsString('AND @fk_event_actor = 1', $verify);
        self::assertStringContainsString('@events_contract_ok', $verify);
    }

    /**
     * SUPP-SQL-04: event table with correct names but incompatible types/nullability is BLOCKED.
     */
    public function test_supp_sql_04_event_table_incompatible_types_are_blocked(): void
    {
        $preflight = self::source('database/sql/supplementary-exam-period-governance/00_preflight.sql');

        self::assertStringContainsString('SET @events_types_ok', $preflight);
        self::assertStringContainsString("LIKE '%auto_increment%'", $preflight);
        self::assertStringContainsString("'NO' AS is_nullable", $preflight);
        self::assertStringContainsString("c.is_nullable <> 'NO'", $preflight);
        self::assertStringContainsString('character_maximum_length', $preflight);
        self::assertStringContainsString("NOT IN ('timestamp', 'datetime')", $preflight);
        self::assertStringContainsString('AND @events_types_ok = 1', $preflight);
        self::assertStringContainsString('WHEN @events_full_ok = 1 THEN \'COMPATIBLE\'', $preflight);
        self::assertStringNotContainsString('SET @events_expected_cols', $preflight);
    }

    /**
     * SUPP-SQL-05: missing required event indexes is BLOCKED; apply does not rewrite unowned tables.
     */
    public function test_supp_sql_05_event_table_missing_indexes_is_blocked(): void
    {
        $preflight = self::source('database/sql/supplementary-exam-period-governance/00_preflight.sql');
        $apply = self::source('database/sql/supplementary-exam-period-governance/01_apply.sql');

        self::assertStringContainsString('SET @events_idx_period_ok', $preflight);
        self::assertStringContainsString('SET @events_idx_actor_ok', $preflight);
        self::assertStringContainsString('SET @events_idx_lookup_ok', $preflight);
        self::assertStringContainsString("HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') LIKE 'event_type,to_status%'", $preflight);
        self::assertStringContainsString('AND @events_idx_period_ok = 1', $preflight);
        self::assertStringContainsString('AND @events_idx_actor_ok = 1', $preflight);
        self::assertStringContainsString('AND @events_idx_lookup_ok = 1', $preflight);

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $apply);
        self::assertStringContainsString("AND @events_state = 'ABSENT'", $apply);
        self::assertStringNotContainsString('ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_period_events` ADD', $apply);
    }

    /**
     * SUPP-SQL-06 / SUPP-SQL-07: rollback does not drop compatible pre-existing
     * governance columns without the Phase 1 ownership marker.
     */
    public function test_supp_sql_06_and_07_rollback_preserves_adopted_governance_columns(): void
    {
        $rollback = self::source('database/sql/supplementary-exam-period-governance/03_rollback.sql');

        self::assertStringContainsString('SET @status_owned', $rollback);
        self::assertStringContainsString('SET @opened_by_owned', $rollback);
        self::assertStringContainsString('SET @opened_at_owned', $rollback);
        self::assertStringContainsString('SET @decision_note_owned', $rollback);
        self::assertStringContainsString("LIKE '%[phase1-supplementary-exam-period-governance]%'", $rollback);

        self::assertStringContainsString('AND @status_owned = 1', $rollback);
        self::assertStringContainsString('DROP COLUMN `status`', $rollback);
        self::assertStringContainsString('AND @opened_by_owned = 1', $rollback);
        self::assertStringContainsString('DROP COLUMN `opened_by_user_id`', $rollback);
        self::assertStringContainsString('AND @opened_at_owned = 1', $rollback);
        self::assertStringContainsString('DROP COLUMN `opened_at`', $rollback);
        self::assertStringContainsString('AND @decision_note_owned = 1', $rollback);
        self::assertStringContainsString('DROP COLUMN `decision_note`', $rollback);
        self::assertStringContainsString('ADOPTED_DO_NOT_DROP', $rollback);
    }

    /**
     * SUPP-SQL-08: rollback only drops a Phase-1-owned event table when data-safe.
     */
    public function test_supp_sql_08_rollback_only_drops_owned_empty_event_table(): void
    {
        $rollback = self::source('database/sql/supplementary-exam-period-governance/03_rollback.sql');

        self::assertStringContainsString('SET @events_owned', $rollback);
        self::assertStringContainsString('table_comment', $rollback);
        self::assertStringContainsString("LIKE '%[phase1-supplementary-exam-period-governance]%'", $rollback);
        self::assertStringContainsString('@rollback_status = \'READY\' AND @events_exist = 1 AND @events_owned = 1 AND @event_rows = 0', $rollback);
        self::assertStringContainsString('DROP TABLE IF EXISTS `alrowad_uni_rust`.`supplementary_exam_period_events`', $rollback);
        self::assertStringContainsString('BLOCKED_IN_USE', $rollback);
    }

    /**
     * SUPP-SQL-09: rollback preserves adopted equivalent identity indexes.
     */
    public function test_supp_sql_09_rollback_preserves_adopted_identity_indexes(): void
    {
        $rollback = self::source('database/sql/supplementary-exam-period-governance/03_rollback.sql');

        self::assertStringNotContainsString('DROP INDEX `uq_sep_year_semester`', $rollback);
        self::assertStringNotContainsString('DROP INDEX `idx_sep_status`', $rollback);
        self::assertStringNotContainsString('DROP INDEX `idx_sepe_period`', $rollback);
        self::assertStringNotContainsString('DROP INDEX `idx_sep_events_period`', $rollback);
        self::assertStringContainsString('Identity UNIQUE indexes are never dropped', $rollback);
    }

    public function test_schema_ready_is_fail_closed_on_event_contract(): void
    {
        $source = self::source('app/Support/SupplementaryExamPeriodGovernance.php');
        $service = self::source('app/Services/SupplementaryExamPeriodGovernanceService.php');

        self::assertStringContainsString('schemaReady(): bool', $source);
        self::assertStringContainsString('Schema::connection((string) config(\'database.default\'))', $source);
        self::assertStringContainsString('Illuminate\\Database\\Schema\\Builder', $source);
        self::assertStringContainsString('method_exists($builder, \'getIndexes\')', $source);
        self::assertStringContainsString('method_exists($builder, \'getForeignKeys\')', $source);
        self::assertStringContainsString('method_exists($builder, \'getColumns\')', $source);
        self::assertStringContainsString('$builder->hasTable(', $source);
        self::assertStringContainsString('$builder->hasColumn(', $source);
        self::assertStringContainsString('$builder->getIndexes(', $source);
        self::assertStringContainsString('$builder->getColumns(', $source);
        self::assertStringContainsString('$builder->getForeignKeys(', $source);
        self::assertStringNotContainsString("method_exists(Schema::class, 'getIndexes')", $source);
        self::assertStringNotContainsString("method_exists(Schema::class, 'getForeignKeys')", $source);
        self::assertStringNotContainsString("method_exists(Schema::class, 'getColumns')", $source);
        self::assertStringContainsString("'academic_year_id', 'semester_id'", $source);
        self::assertStringContainsString("'supplementary_exam_period_id'", $source);
        self::assertStringContainsString("=== 'event_type'", $source);
        self::assertStringContainsString("=== 'to_status'", $source);
        self::assertStringContainsString('columnIsDateTime', $source);
        self::assertStringContainsString('return false', $source);
        self::assertStringContainsString('$this->assertSchemaReady()', $service);
        self::assertStringContainsString('SupplementaryExamPeriodGovernance::schemaReady()', $service);
    }

    public function test_no_migrations_or_seeders_added(): void
    {
        $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*supplementary*') ?: [];
        self::assertSame([], $migrations);
        $seeders = glob(dirname(__DIR__, 2).'/database/seeders/*Supplementary*') ?: [];
        self::assertSame([], $seeders);
    }

    private static function sliceSql(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        $end = strpos($source, $endNeedle);
        self::assertNotFalse($start, "Expected SQL slice start {$startNeedle}.");
        self::assertNotFalse($end, "Expected SQL slice end {$endNeedle}.");
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }

    private static function sliceJs(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        $end = strpos($source, $endNeedle);
        self::assertNotFalse($start, "Expected slice start {$startNeedle}.");
        self::assertNotFalse($end, "Expected slice end {$endNeedle}.");
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }

    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    private static function frontend(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 3).'/frontend/'.$path);
    }

    private static function extractMethod(string $source, string $name): string
    {
        self::assertSame(
            1,
            preg_match(
                '/\n    (?:private|public|protected) function '.preg_quote($name, '/').'\(.*?(?=\n    (?:private|public|protected) function |\n\}\s*\z)/s',
                $source,
                $matches
            ),
            "Expected exactly one method {$name}()."
        );

        return $matches[0];
    }
}
