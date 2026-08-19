<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts for Dean bulk CourseOffering preparation.
 * These tests do not boot Laravel or query MariaDB.
 */
class BulkDeanOfferingPrepareContractTest extends TestCase
{
    public function test_bulk_01_advisory_semester_selects_matching_recommended_semester_across_levels(): void
    {
        $select = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectProgramCoursesForBulkPrepare'
        );

        self::assertStringContainsString("\$mode === 'advisory_semester'", $select);
        self::assertStringContainsString("where('recommended_semester_id', \$semesterId)", $select);
        self::assertStringContainsString("where('academic_program_id', \$programId)", $select);
        self::assertStringContainsString("where('is_active', true)", $select);
        self::assertStringContainsString('orderBy(\'program_course_id\')', $select);
        self::assertStringNotContainsString("where('academic_level_id'", $this->advisorySemesterBranch($select));
    }

    public function test_bulk_02_advisory_semester_does_not_include_other_recommended_semesters(): void
    {
        $select = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectProgramCoursesForBulkPrepare'
        );
        $branch = $this->advisorySemesterBranch($select);

        self::assertStringContainsString("where('recommended_semester_id', \$semesterId)", $branch);
        self::assertStringNotContainsString('orWhere', $branch);
        self::assertStringNotContainsString('whereNull', $branch);
    }

    public function test_bulk_03_null_recommended_semester_excluded_from_advisory_semester(): void
    {
        $select = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectProgramCoursesForBulkPrepare'
        );
        $level = $this->advisoryLevelBranch($select);

        self::assertStringContainsString("where('recommended_semester_id', \$semesterId)", $this->advisorySemesterBranch($select));
        self::assertStringContainsString("where('recommended_semester_id', \$semesterId)", $level);
        self::assertStringContainsString("where('academic_level_id', \$academicLevelId)", $level);
        self::assertStringNotContainsString('orWhereNull(\'recommended_semester_id\')', $select);
        self::assertStringNotContainsString('whereNull(\'recommended_semester_id\')', $this->advisorySemesterBranch($select));
        self::assertStringNotContainsString('whereNull(\'recommended_semester_id\')', $level);
    }

    public function test_bulk_04_all_curriculum_includes_active_rows_including_null_recommended_semester(): void
    {
        $select = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectProgramCoursesForBulkPrepare'
        );

        self::assertStringContainsString("\$mode !== 'all_curriculum'", $select);
        self::assertStringContainsString("where('is_active', true)", $select);
        $afterAll = substr($select, (int) strpos($select, "\$mode !== 'all_curriculum'"));
        self::assertStringNotContainsString("where('recommended_semester_id'", $afterAll);
    }

    public function test_bulk_05_selected_mode_can_prepare_a_course_recommended_for_another_semester(): void
    {
        $selected = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectedProgramCoursesForBulkPrepare'
        );

        self::assertStringContainsString('whereIn(\'program_course_id\', $requested)', $selected);
        self::assertStringNotContainsString('recommended_semester_id', $selected);
        self::assertStringContainsString('(int) $row->academic_program_id !== $programId', $selected);
        self::assertStringContainsString('! $row->is_active', $selected);
    }

    public function test_bulk_06_new_offerings_are_created_closed(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );
        $one = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'prepareOneClosedOffering'
        );
        $create = self::extractMethod(
            self::source('app/Services/CourseOfferingContextService.php'),
            'createOffering'
        );

        self::assertStringContainsString("'status' => self::STATUS_CLOSED", $find);
        self::assertStringContainsString('$this->offeringContext->createOffering(', $find);
        self::assertStringContainsString("\$payload['status'] = CourseOfferingOpeningService::STATUS_CLOSED", $create);
        self::assertStringNotContainsString('normalOpen(', $bulk);
        self::assertStringNotContainsString('normalOpen(', $one);
        self::assertStringNotContainsString('STATUS_OPEN', $bulk);
        self::assertStringNotContainsString('STATUS_OPEN', $find);
        self::assertStringNotContainsString("'status' => 'open'", $find);
        self::assertStringNotContainsString("'status' => 'open'", $bulk);
    }

    public function test_bulk_07_existing_open_offering_remains_open(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );

        self::assertStringContainsString("'created' => false", $find);
        self::assertStringNotContainsString('$offering->status =', $find);
        self::assertStringNotContainsString('$offering->update(', $find);
        self::assertStringNotContainsString('normalOpen(', $find);
        self::assertStringNotContainsString('normalOpen(', $bulk);
        self::assertStringNotContainsString('->opening->', $bulk);
    }

    public function test_bulk_08_existing_closed_offering_remains_closed(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );
        $open = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'openFromProgramCourse'
        );

        self::assertStringContainsString("if (\$offering !== null) {\n            return [\n                'offering' => \$offering,\n                'created' => false,", $find);
        self::assertStringContainsString('$this->opening->normalOpen($offering, $user)', $open);
        self::assertGreaterThan(
            strpos($open, "if (\$resolved['created'])"),
            strpos($open, 'normalOpen(')
        );
    }

    public function test_bulk_09_repeat_bulk_request_is_idempotent_and_uses_duplicate_handler(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );
        $one = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'prepareOneClosedOffering'
        );

        self::assertStringContainsString('CourseOfferingContextException::DUPLICATE_OFFERING', $find);
        self::assertStringContainsString("->where('course_id', \$courseId)", $find);
        self::assertStringContainsString("->where('academic_year_id', \$yearId)", $find);
        self::assertStringContainsString("->where('semester_id', \$semesterId)", $find);
        self::assertStringContainsString("->where('academic_program_id', \$programId)", $find);
        self::assertStringContainsString('lockForUpdate()', $find);
        self::assertStringContainsString("'result' => \$resolved['created'] ? 'created' : 'existing'", $one);
        self::assertStringNotContainsString('catch (QueryException', $find);
        self::assertStringNotContainsString('catch (QueryException', $one);
        self::assertStringNotContainsString('catch (\Illuminate\Database\QueryException', $find);
    }

    public function test_bulk_10_out_of_scope_program_course_is_rejected(): void
    {
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );
        $selected = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectedProgramCoursesForBulkPrepare'
        );
        $select = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectProgramCoursesForBulkPrepare'
        );

        self::assertStringContainsString('assertProgramInAccessibleCollege(', $bulk);
        self::assertStringContainsString('assertCanManage($user)', $bulk);
        self::assertStringContainsString('بعض المواد المحددة غير موجودة', $selected);
        self::assertStringContainsString('بعض المواد المحددة غير نشطة في خطة البرنامج', $selected);
        self::assertStringContainsString('المستوى الدراسي المحدد ليس ضمن خطة هذا البرنامج', $select);
    }

    public function test_bulk_11_program_course_from_another_program_cannot_be_injected(): void
    {
        $selected = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'selectedProgramCoursesForBulkPrepare'
        );

        self::assertStringContainsString('(int) $row->academic_program_id !== $programId', $selected);
        self::assertStringContainsString('لا يمكن تجهيز مادة من برنامج أكاديمي آخر', $selected);
        self::assertStringNotContainsString('$payload[\'college_id\']', $selected);
        self::assertStringNotContainsString('$payload[\'course_id\']', $selected);
    }

    public function test_bulk_12_client_cannot_submit_status_open_or_bypass_flags(): void
    {
        $request = self::source('app/Http/Requests/Dean/BulkPrepareDeanRegistrationOfferingRequest.php');
        $routes = self::source('routes/api.php');

        self::assertStringContainsString("'status' => ['prohibited']", $request);
        self::assertStringContainsString("'exceptional' => ['prohibited']", $request);
        self::assertStringContainsString("'force' => ['prohibited']", $request);
        self::assertStringContainsString("'skip_coverage' => ['prohibited']", $request);
        self::assertStringContainsString("'bypass' => ['prohibited']", $request);
        self::assertStringContainsString("'college_id' => ['prohibited']", $request);
        self::assertStringContainsString("'department_id' => ['prohibited']", $request);
        self::assertStringContainsString("'faculty_member_id' => ['prohibited']", $request);
        self::assertStringContainsString("'course_id' => ['prohibited']", $request);
        self::assertStringContainsString('advisory_semester', $request);
        self::assertStringContainsString('advisory_level', $request);
        self::assertStringContainsString('all_curriculum', $request);
        self::assertStringContainsString('selected', $request);
        self::assertStringContainsString("dean/registration-offerings/bulk-prepare", $routes);

        $bulkRoutePos = strpos($routes, "dean/registration-offerings/bulk-prepare");
        $paramRoutePos = strpos($routes, "dean/registration-offerings/{courseOffering}/open");
        self::assertNotFalse($bulkRoutePos);
        self::assertNotFalse($paramRoutePos);
        self::assertLessThan($paramRoutePos, $bulkRoutePos);
    }

    public function test_bulk_13_no_instructor_assignment_is_created_by_bulk_preparation(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );
        $one = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'prepareOneClosedOffering'
        );
        $create = self::extractMethod(
            self::source('app/Services/CourseOfferingContextService.php'),
            'createOffering'
        );

        self::assertStringContainsString("'faculty_member_id' => null", $find);
        self::assertStringContainsString("\$payload['faculty_member_id'] = null", $create);
        self::assertStringNotContainsString('TeachingAssignment', $find);
        self::assertStringNotContainsString('TeachingAssignment', $bulk);
        self::assertStringNotContainsString('TeachingAssignment', $one);
        self::assertStringNotContainsString('auto-assign', $find);
        self::assertStringNotContainsString('approve(', $bulk);
    }

    public function test_bulk_14_offering_identity_uses_actual_year_and_semester(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );

        self::assertStringContainsString('resolveFromProgramCourse(', $find);
        self::assertStringContainsString('$yearId', $find);
        self::assertStringContainsString('$semesterId', $find);
        self::assertStringContainsString("->where('academic_year_id', \$yearId)", $find);
        self::assertStringContainsString("->where('semester_id', \$semesterId)", $find);
        self::assertStringContainsString('ProgramCourse.recommended_semester_id is advisory metadata only', $find);
        self::assertStringNotContainsString("where('recommended_semester_id'", $find);
        self::assertStringNotContainsString('$programCourse->recommended_semester_id', $find);
        self::assertStringContainsString("(int) \$payload['academic_year_id']", $bulk);
        self::assertStringContainsString("(int) \$payload['semester_id']", $bulk);
        self::assertStringNotContainsString('recommended_semester_id', $bulk);
    }

    public function test_bulk_15_no_destructive_delete_path_is_introduced(): void
    {
        $service = self::source('app/Services/DeanRegistrationOfferingService.php');
        $bulk = self::extractMethod($service, 'bulkPrepare');
        $one = self::extractMethod($service, 'prepareOneClosedOffering');
        $request = self::source('app/Http/Requests/Dean/BulkPrepareDeanRegistrationOfferingRequest.php');
        $controller = self::source('app/Http/Controllers/Api/DeanRegistrationOfferingController.php');
        $routes = self::source('routes/api.php');
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');

        self::assertStringNotContainsString('forceDelete', $bulk);
        self::assertStringNotContainsString('forceDelete', $one);
        self::assertStringNotContainsString('forceDelete', $request);
        self::assertStringNotContainsString('->delete(', $bulk);
        self::assertStringNotContainsString('->delete(', $one);
        self::assertStringNotContainsString('truncate', $bulk);
        self::assertStringNotContainsString('truncate', $one);
        self::assertStringNotContainsString('bulk-delete', $routes);
        self::assertStringNotContainsString('bulk-close', $routes);
        self::assertStringNotContainsString('forceDelete', $controller);
        self::assertStringNotContainsString('تفريغ', $dean);
        self::assertStringNotContainsString('حذف الطروحات', $dean);
        self::assertStringNotContainsString('bulk-delete', $dean);
        self::assertStringContainsString('إجراءات جماعية', $dean);
        self::assertStringContainsString('تجهيز الفصل حسب الخطة الإرشادية', $dean);
        self::assertStringContainsString('تجهيز جميع مواد البرنامج', $dean);
        self::assertStringContainsString('تجهيز المواد المحددة', $dean);
        self::assertStringContainsString('تحديد المواد يدويًا', $dean);
        self::assertStringContainsString('selectionMode', $dean);
        self::assertStringContainsString('mode: \'advisory_semester\'', $dean);
        self::assertStringContainsString('بما فيها المواد الموصى بها إرشاديًا لفصول أخرى', $dean);
        self::assertStringContainsString('تم تجهيز ${created} طروحات', $dean);
        self::assertStringContainsString('/v1/dean/registration-offerings/bulk-prepare', $dean);
        self::assertStringContainsString('reloadCatalog()', $dean);
        self::assertStringNotContainsString('window.location.reload', $dean);
        self::assertStringNotContainsString('status: \'open\'', $dean);
        self::assertStringNotContainsString("status: 'open'", $dean);
        self::assertStringNotContainsString('skip_coverage', $dean);
        self::assertStringNotContainsString('bypass', $dean);
    }

    public function test_auth_bulk_01_dean_with_course_offerings_manage_is_allowed_by_gate(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );

        self::assertStringContainsString('if (! $user->isDean())', $canManage);
        self::assertStringContainsString('return false;', $canManage);
        self::assertStringContainsString('$user->effectivePermissions()', $canManage);
        self::assertStringContainsString("\$permissions->contains('course_offerings.manage')", $canManage);
        self::assertGreaterThan(
            strpos($canManage, 'if (! $user->isDean())'),
            strpos($canManage, "\$permissions->contains('course_offerings.manage')")
        );
    }

    public function test_auth_bulk_02_dean_with_courses_manage_is_allowed_by_gate(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );

        self::assertStringContainsString('if (! $user->isDean())', $canManage);
        self::assertStringContainsString('$user->effectivePermissions()', $canManage);
        self::assertStringContainsString("\$permissions->contains('courses.manage')", $canManage);
        self::assertStringContainsString(
            "return \$permissions->contains('course_offerings.manage')\n            || \$permissions->contains('courses.manage');",
            $canManage
        );
    }

    public function test_auth_bulk_03_dean_role_without_assigned_mutation_permission_is_denied(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );

        self::assertStringContainsString('if (! $user->isDean())', $canManage);
        self::assertStringContainsString('$user->effectivePermissions()', $canManage);
        self::assertStringNotContainsString('return true;', $canManage);
        self::assertDoesNotMatchRegularExpression(
            '/isDean\(\)\)\s*\{\s*return true;/',
            $canManage
        );
        self::assertStringContainsString("\$permissions->contains('course_offerings.manage')", $canManage);
        self::assertStringContainsString("\$permissions->contains('courses.manage')", $canManage);
    }

    public function test_auth_bulk_04_permission_without_dean_role_is_denied(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );

        self::assertStringContainsString('if (! $user->isDean())', $canManage);
        self::assertStringContainsString('return false;', $canManage);
        self::assertGreaterThan(
            strpos($canManage, 'if (! $user->isDean())'),
            strpos($canManage, '$user->effectivePermissions()')
        );
        self::assertStringNotContainsString('hasPermission(', $canManage);
        self::assertStringNotContainsString('hasRoleCode(', $canManage);
    }

    public function test_auth_bulk_05_super_admin_only_is_denied_by_dean_mutation_gate(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );
        $assert = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'assertCanManage'
        );

        self::assertStringContainsString('$user->isDean()', $canManage);
        self::assertStringContainsString('$user->effectivePermissions()', $canManage);
        self::assertStringNotContainsString('hasPermission(', $canManage);
        self::assertStringNotContainsString('effectiveRoles()', $canManage);
        self::assertStringNotContainsString("'super_admin'", $canManage);
        self::assertStringNotContainsString('isSuperAdmin', $canManage);
        self::assertStringContainsString('canManage($user)', $assert);
        self::assertStringNotContainsString('hasPermission(', $assert);
        self::assertStringNotContainsString("'super_admin'", $assert);
    }

    public function test_auth_bulk_06_super_admin_virtual_has_permission_does_not_satisfy_dean_gate(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );
        $hasPermission = self::extractMethod(
            self::source('app/Models/User.php'),
            'hasPermission'
        );

        self::assertStringContainsString('effectivePermissions()->contains($permission)', $hasPermission);
        self::assertStringContainsString("effectiveRoles()->contains('super_admin')", $hasPermission);
        self::assertStringNotContainsString('hasPermission(', $canManage);
        self::assertStringContainsString('effectivePermissions()', $canManage);
        self::assertStringContainsString('isDean()', $canManage);
    }

    public function test_auth_bulk_07_bulk_prepare_and_normal_dean_mutations_share_the_hardened_gate(): void
    {
        $service = self::source('app/Services/DeanRegistrationOfferingService.php');
        $open = self::extractMethod($service, 'openFromProgramCourse');
        $bulk = self::extractMethod($service, 'bulkPrepare');
        $reopen = self::extractMethod($service, 'reopenOffering');
        $close = self::extractMethod($service, 'closeOffering');
        $assert = self::extractMethod($service, 'assertCanManage');
        $canManage = self::extractMethod($service, 'canManage');
        $catalog = self::extractMethod($service, 'catalog');
        $controllerBulk = self::extractMethod(
            self::source('app/Http/Controllers/Api/DeanRegistrationOfferingController.php'),
            'bulkPrepare'
        );
        $controllerView = self::extractMethod(
            self::source('app/Http/Controllers/Api/DeanRegistrationOfferingController.php'),
            'assertCanView'
        );

        self::assertStringContainsString('assertCanManage($user)', $open);
        self::assertStringContainsString('assertCanManage($user)', $bulk);
        self::assertStringContainsString('assertCanManage($user)', $reopen);
        self::assertStringContainsString('assertCanManage($user)', $close);
        self::assertStringContainsString('canManage($user)', $assert);
        self::assertStringContainsString('$user->isDean()', $canManage);
        self::assertStringContainsString('effectivePermissions()', $canManage);
        self::assertStringNotContainsString('hasPermission(', $canManage);
        self::assertStringNotContainsString('hasPermission(', $open);
        self::assertStringNotContainsString('hasPermission(', $bulk);
        self::assertStringNotContainsString('hasPermission(', $reopen);
        self::assertStringNotContainsString('hasPermission(', $close);
        self::assertStringContainsString("'can_manage' => \$this->canManage(\$user)", $catalog);
        self::assertStringContainsString("hasPermission('courses.view')", $controllerView);
        self::assertStringContainsString('assertCanView($request)', $controllerBulk);
        self::assertStringContainsString('$request->validated()', $controllerBulk);
    }

    public function test_bulk_response_summary_shape_and_no_sqlstate(): void
    {
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );
        $one = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'prepareOneClosedOffering'
        );

        self::assertStringContainsString("'selected_count'", $bulk);
        self::assertStringContainsString("'created_count'", $bulk);
        self::assertStringContainsString("'existing_count'", $bulk);
        self::assertStringContainsString("'failed_count'", $bulk);
        self::assertStringContainsString("'items'", $bulk);
        self::assertStringContainsString("'program_course_id'", $one);
        self::assertStringContainsString("'course_id'", $one);
        self::assertStringContainsString("'course_code'", $one);
        self::assertStringContainsString("'course_name'", $one);
        self::assertStringContainsString("'course_offering_id'", $one);
        self::assertStringContainsString("'result'", $one);
        self::assertStringContainsString("'error_code'", $one);
        self::assertStringNotContainsString('SQLSTATE', $bulk);
        self::assertStringNotContainsString('SQLSTATE', $one);
        self::assertStringNotContainsString('errorInfo', $one);
        self::assertStringNotContainsString('uq_course_offering_program_term', $one);
    }

    public function test_ui_bulk_checkboxes_appear_only_in_selection_mode(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $card = self::extractJsFunction($dean, 'CourseCard');

        self::assertStringContainsString('selectionMode = false', $card);
        self::assertStringContainsString('{selectionMode ? (', $card);
        self::assertStringContainsString('type="checkbox"', $card);
        self::assertStringContainsString('تحديد الكل', $dean);
        self::assertStringContainsString('إلغاء التحديد', $dean);
        self::assertStringContainsString('مادة مطابقة', $dean);
        self::assertStringContainsString('طروحات موجودة', $dean);
        self::assertStringContainsString('طروحات سيتم إنشاؤها', $dean);
        self::assertStringContainsString('إدارة تكليف المدرسين', $card);
    }

    private function advisorySemesterBranch(string $select): string
    {
        $start = strpos($select, "if (\$mode === 'advisory_semester')");
        self::assertNotFalse($start);
        $end = strpos($select, '} elseif ($mode === \'advisory_level\')', $start);
        self::assertNotFalse($end);

        return substr($select, $start, $end - $start);
    }

    private function advisoryLevelBranch(string $select): string
    {
        $start = strpos($select, '} elseif ($mode === \'advisory_level\')');
        self::assertNotFalse($start);
        $end = strpos($select, '} elseif ($mode !== \'all_curriculum\')', $start);
        self::assertNotFalse($end);

        return substr($select, $start, $end - $start);
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

    private static function extractJsFunction(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name.'(');
        self::assertNotFalse($start, "Expected function {$name}().");
        if (! preg_match('/\n(?:export default )?function /', $source, $matches, PREG_OFFSET_CAPTURE, $start + 1)) {
            return substr($source, $start);
        }

        return substr($source, $start, $matches[0][1] - $start);
    }
}
