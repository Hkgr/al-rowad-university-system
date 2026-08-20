<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Advisory ProgramCourse semester/level is presentational only.
 * These are source contracts; they do not boot Laravel or query MariaDB.
 */
class AdvisorySemesterOfferingContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_advsem_01_dean_open_uses_actual_semester_not_advisory_recommendation(): void
    {
        $service = self::source('app/Services/DeanRegistrationOfferingService.php');
        $open = self::extractMethod($service, 'openFromProgramCourse');
        $findOrCreate = self::extractMethod($service, 'findOrCreateClosedOffering');
        $catalog = self::extractMethod($service, 'curriculumLevels');
        $match = self::extractMethod($service, 'matchingOfferings');

        self::assertStringContainsString("(int) \$payload['semester_id']", $open);
        self::assertStringContainsString('findOrCreateClosedOffering(', $open);
        self::assertStringContainsString('ProgramCourse.recommended_semester_id is advisory metadata only', $open);
        self::assertStringContainsString('resolveFromProgramCourse(', $findOrCreate);
        self::assertStringContainsString("->where('semester_id', \$semesterId)", $findOrCreate);
        self::assertStringContainsString('ProgramCourse.recommended_semester_id is advisory metadata only', $findOrCreate);
        self::assertStringNotContainsString('recommended_semester_id ===', $open);
        self::assertStringNotContainsString("where('recommended_semester_id'", $open);
        self::assertStringNotContainsString("where('recommended_semester_id'", $findOrCreate);
        self::assertStringNotContainsString("where('recommended_semester_id'", $catalog);
        self::assertStringNotContainsString("where('recommended_semester_id'", $match);
        self::assertStringContainsString("where('is_active', true)", $catalog);
        self::assertStringContainsString("'advisory_plan' => \$this->advisoryPlan(\$row)", $catalog);
    }

    public function test_advsem_02_null_recommended_semester_remains_valid_for_dean_create(): void
    {
        $request = self::source('app/Http/Requests/Dean/OpenDeanRegistrationOfferingRequest.php');
        $guard = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'assertActiveCurriculumRow'
        );
        $plan = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'advisoryPlan'
        );

        self::assertStringContainsString("'semester_id' => ['required', 'integer', 'min:1', 'exists:semesters,semester_id']", $request);
        self::assertStringNotContainsString('recommended_semester', $request);
        self::assertStringNotContainsString('recommended_semester', $guard);
        self::assertStringContainsString('$row->recommended_semester_id === null', $plan);
        self::assertStringContainsString("'recommended_semester_id' =>", $plan);
    }

    public function test_advsem_03_student_available_courses_use_actual_open_offering_semester(): void
    {
        $registration = self::source('app/Services/RegistrationService.php');
        $available = self::extractMethod($registration, 'getAvailableCourses');
        $self = self::extractMethod($registration, 'getSelfRegistrationOfferings');
        $constrain = self::extractMethod($registration, 'constrainSelfRegistrationOfferings');

        self::assertStringContainsString("where('status', 'open')", $available);
        self::assertStringContainsString("where('semester_id', \$semesterId)", $available);
        self::assertStringContainsString("where('semester_id', \$semesterId)", $self);
        self::assertStringContainsString("where('is_active', true)", $constrain);
        self::assertStringNotContainsString("where('recommended_semester_id'", $available);
        self::assertStringNotContainsString("where('recommended_semester_id'", $self);
        self::assertStringNotContainsString("where('recommended_semester_id'", $constrain);
        self::assertStringNotContainsString('recommended_semester_id', $registration);
    }

    public function test_advsem_04_eligibility_has_no_advisory_semester_rejection(): void
    {
        $annotate = self::extractMethod(
            self::source('app/Services/RegistrationService.php'),
            'annotateOfferingEligibility'
        );
        $requirements = self::source('app/Services/AcademicRequirementService.php');
        $resource = self::source('app/Http/Resources/AvailableCourseOfferingResource.php');

        self::assertStringContainsString('already_registered', $annotate);
        self::assertStringContainsString('missing_prerequisites', $annotate);
        self::assertStringContainsString('no_available_seats', $annotate);
        self::assertStringContainsString('credit_limit_exceeded', $annotate);
        self::assertStringContainsString('evaluateRegistrationCandidate', $annotate);
        self::assertStringNotContainsString('recommended_semester', $annotate);
        self::assertStringNotContainsString('advisory', $annotate);
        self::assertStringContainsString('REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM', $requirements);
        self::assertStringNotContainsString('recommended_semester', $requirements);
        self::assertStringContainsString('Does not affect eligibility', $resource);
        self::assertStringContainsString("'advisory_plan' => \$this->advisoryPlan()", $resource);
    }

    public function test_advsem_05_changing_only_recommended_semester_cannot_change_eligibility_inputs(): void
    {
        $requirements = self::source('app/Services/AcademicRequirementService.php');
        $evaluate = self::extractMethod($requirements, 'evaluateRegistrationCandidate');
        $annotate = self::extractMethod(
            self::source('app/Services/RegistrationService.php'),
            'annotateOfferingEligibility'
        );

        self::assertStringContainsString('curriculum_by_course_id', $evaluate);
        self::assertStringContainsString('REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM', $evaluate);
        self::assertStringNotContainsString('recommended_semester_id', $evaluate);
        self::assertStringNotContainsString('recommended_semester_id', $annotate);
        self::assertStringNotContainsString('recommended_semester_id', $requirements);
    }

    public function test_advsem_06_advisory_academic_level_mismatch_is_not_an_eligibility_rule(): void
    {
        $annotate = self::extractMethod(
            self::source('app/Services/RegistrationService.php'),
            'annotateOfferingEligibility'
        );
        $evaluate = self::extractMethod(
            self::source('app/Services/AcademicRequirementService.php'),
            'evaluateRegistrationCandidate'
        );
        $constrain = self::extractMethod(
            self::source('app/Services/RegistrationService.php'),
            'constrainSelfRegistrationOfferings'
        );

        self::assertStringNotContainsString('current_academic_level_id', $annotate);
        self::assertStringNotContainsString('current_academic_level_id', $evaluate);
        self::assertStringNotContainsString('academic_level_id', $constrain);
        self::assertStringNotContainsString("where('academic_level_id'", $evaluate);
    }

    public function test_ui_advsem_01_dean_matching_semester_shows_recommended_badge(): void
    {
        $planner = self::frontend('src/features/dean-dashboard/utils/deanOfferingPlanner.js');
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        self::assertStringContainsString('export function advisorySemesterLabel(', $planner);
        self::assertStringContainsString('إرشاديًا: ${name}', $planner);
        self::assertStringContainsString('export function recommendedSemesterMatches(', $planner);
        self::assertStringContainsString('recommendedId === Number(selectedSemesterId)', $planner);
        self::assertStringContainsString('advisorySemesterLabel(row, selectedSemesterId)', $dean);
    }

    public function test_ui_advsem_02_dean_mismatched_semester_keeps_opening_enabled(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $dialog = self::extractJsFunction($dean, 'AddCourseDialog');
        self::assertStringContainsString('+ إضافة مادة', $dean);
        self::assertStringContainsString('const blocked = persisted || added', $dialog);
        self::assertDoesNotMatchRegularExpression('/disabled=\{[^}]*advisory/', $dialog);
        self::assertStringNotContainsString('disabled={blocked || recommended', $dialog);
        self::assertStringNotContainsString('فتح استثنائي', $dialog);
    }

    public function test_ui_advsem_03_student_other_open_plan_courses_remain_addable_when_eligible(): void
    {
        $student = self::frontend('src/features/student-dashboard/pages/StudentRegistration.jsx');
        $row = self::extractJsFunction($student, 'CourseRow');
        self::assertStringContainsString('مواد أخرى مفتوحة من خطتك', $student);
        self::assertStringContainsString('من الخطة — موصى بها في فصل آخر', $student);
        self::assertStringContainsString('يمكنك تسجيلها إذا استوفيت بقية الشروط', $student);
        self::assertStringNotContainsString('غير موصى بها', $student);
        self::assertStringContainsString('disabled={!eligible || busy}', $row);
        self::assertStringContainsString("const eligible = course.eligibility_status === 'eligible'", $row);
        self::assertDoesNotMatchRegularExpression('/disabled=\{[^}]*advisoryMode/', $row);
    }

    public function test_ui_advsem_04_missing_recommended_semester_stays_visible_and_openable(): void
    {
        $planner = self::frontend('src/features/dean-dashboard/utils/deanOfferingPlanner.js');
        $student = self::frontend('src/features/student-dashboard/pages/StudentRegistration.jsx');
        self::assertStringContainsString("return 'الفصل الإرشادي غير محدد'", $planner);
        self::assertStringContainsString('export function advisorySemesterLabel(', $planner);
        self::assertStringContainsString('من الخطة — الفصل الإرشادي غير محدد', $student);
        self::assertStringContainsString('function splitAdvisoryCourses(', $student);
        self::assertStringContainsString('else other.push(course)', $student);
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
