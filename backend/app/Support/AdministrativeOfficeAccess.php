<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataScopeService;

/** Explicit implemented-office authority; never a system-wide permission or a fabricated staff role. */
final class AdministrativeOfficeAccess
{
    public const ROLE = VicePresidency::ROLE_ADMINISTRATIVE;

    // Matches the existing 7 -> 71/72/73 organizational contract. Empty offices have no implemented entry.
    public const OFFICES = [
        '711' => ['administrative_hr.view', 'administrative_hr.recruitment.manage', 'administrative_hr.classification.manage',
            'administrative_hr.relationships.prepare', 'administrative_hr.relationships.review', 'administrative_hr.relationships.correct',
            'administrative_hr.relationships.cancel', 'administrative_hr.workers.export', 'administrative_hr.work_time.manage',
            'vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
            'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage',
            'teaching_assignments.view', 'teaching_assignments.review_administrative', 'course_offerings.exceptional_open.view',
            'course_offerings.exceptional_open.review_administrative', 'course_offerings.closure.view', 'course_offerings.closure.review_administrative',
            'hr.view', 'hr.manage', 'teaching_staff.view', 'teaching_staff.manage'],
        '712' => [], '713' => [], '714' => [],
        '715' => ['technical_portal.access', 'user_accounts.view', 'user_accounts.manage', 'user_accounts.holder_name.manage', 'system_activity.view',
            'university_email.view', 'university_email.manage', 'university_email.check_connection', 'university_email.provision',
            'university_email.issue_receipt', 'university_email.reissue_initial_password', 'university_email.reset_password',
            'university_email.suspend', 'university_email.activate', 'university_email.link_existing', 'university_email.delete'],
        '721' => ['administrative_hr.payroll.access', 'owner_payroll.view', 'owner_payroll.bodies.manage', 'owner_payroll.amounts.edit',
            'owner_payroll.config.manage', 'owner_payroll.export', 'owner_payroll.payments.manage', 'owner_payroll.periods.manage', 'owner_payroll.periods.correct'],
        '722' => [], '723' => [],
        '731' => [], // The implemented academic-calendar entry is public read, not the scientific publication workflow.
        '732' => ['students.view', 'students.manage', 'admissions.view', 'admissions.manage', 'registration.view', 'registration.manage',
            'registration_requests.view', 'academic_structure.view', 'courses.view', 'system_settings.view',
            'supplementary_exams.registrations.view', 'supplementary_exams.registrations.manage', 'supplementary_exams.registrations.window'],
        '733' => ['students.view', 'students.manage', 'grades.view'],
        '734' => [],
        '735' => ['exams.view', 'exams.manage', 'grades.view', 'grades.manage', 'attendance.view', 'attendance.manage',
            'courses.view', 'courses.manage', 'course_offerings.manage', 'academic_structure.view', 'system_settings.view',
            'supplementary_exams.registrations.view', 'supplementary_exams.eligibility.view', 'supplementary_exams.grades.view',
            'supplementary_exams.grades.assign', 'supplementary_exams.grades.review', 'supplementary_exams.grades.publish', 'supplementary_exams.results.materialize'],
        '736' => ['academic_records.view', 'academic_records.finalize', 'academic_progression.view', 'academic_progression.review',
            'graduation_decisions.view', 'graduation_decisions.review'],
    ];

    public const STAFF_ROLES = ['hr_officer' => '711', 'technical_team' => '715', 'finance_officer' => '721',
        'registration_officer' => '732', 'exam_officer' => '735'];

    /** Only existing office records/catalog lookups unsupported by the generic academic scoper. Unknown tables stay closed. */
    public const RESOURCE_PERMISSIONS = [
        'employees' => 'hr.view', 'employee_types' => 'hr.view', 'employee_statuses' => 'hr.view',
        'employee_positions' => 'hr.view', 'employee_unit_assignments' => 'hr.view', 'positions' => 'hr.view',
        'organizational_units' => 'organizational_structure.view', 'organizational_unit_types' => 'organizational_structure.view',
        'student_statuses' => 'students.view', 'academic_years' => 'academic_structure.view', 'semesters' => 'system_settings.view',
        'result_statuses' => 'grades.view', 'grading_statuses' => 'grades.view',
    ];

    public static function permissions(): array
    {
        return array_values(array_unique(array_merge([VicePresidency::PERMISSION_ADMINISTRATIVE_ACCESS, 'dashboards.view', 'organizational_structure.view'], ...array_values(self::OFFICES))));
    }

    public static function hasAuthority(?User $user): bool
    {
        return $user !== null && $user->accountStatus?->status_code === 'active'
            && $user->effectiveRoles()->contains(self::ROLE)
            && app(DataScopeService::class)->hasActualUniversityScope($user);
    }

    public static function actsForOffice(?User $user, string $staffRole): bool
    {
        return isset(self::STAFF_ROLES[$staffRole]) && self::hasAuthority($user);
    }

    /** Factual server identity: UI may satisfy office-operator gates, not personal/dean/VP-scientific roles. */
    public static function identity(User $user): ?array
    {
        if (! self::hasAuthority($user)) {
            return null;
        }

        return ['role' => self::ROLE, 'directorates' => ['71', '72', '73'], 'offices' => array_map('strval', array_keys(self::OFFICES)),
            'operator_roles' => self::STAFF_ROLES, 'permissions' => $user->effectivePermissions()->intersect(self::permissions())->values()->all()];
    }

    /** Missing codes with existing registration mechanisms may be registered; all other missing prerequisites fail closed. */
    public static function registrablePermissions(): array
    {
        $items = [];
        foreach ([VicePresidency::MODULE_CODE => HrOffice::PERMISSIONS + [
            VicePresidency::PERMISSION_ADMINISTRATIVE_ACCESS => 'الدخول إلى نيابة الشؤون الإدارية',
            AdministrativeGovernance::FACULTY_VIEW => 'عرض ملفات المدرسين', AdministrativeGovernance::FACULTY_MANAGE => 'إدارة ملفات المدرسين',
            AdministrativeGovernance::DEANS_VIEW => 'عرض تكليفات العمداء', AdministrativeGovernance::DEANS_MANAGE => 'إدارة تكليفات العمداء'],
            OwnerPortal::MODULE => OwnerPortal::PERMISSIONS,
            AccountAdministration::MODULE_CODE => UniversityEmailAccess::PERMISSIONS + UniversityEmailAccess::PHASE2_PERMISSIONS + UniversityEmailAccess::PHASE3_PERMISSIONS
                + [AccountAdministration::PORTAL_ACCESS => 'الدخول إلى المكتب التقني', AccountAdministration::VIEW => 'عرض الحسابات',
                    AccountAdministration::MANAGE => 'إدارة الحسابات ضمن الضوابط', AccountAdministration::HOLDER_NAME_MANAGE => 'تصحيح اسم صاحب الحساب',
                    AccountAdministration::ACTIVITY_VIEW => 'عرض النشاط المسموح']] as $module => $definitions) {
            foreach ($definitions as $code => $label) {
                if (in_array($code, self::permissions(), true)) {
                    $items[$code] = ['module' => $module, 'label' => $label];
                }
            }
        }

        return $items;
    }
}
