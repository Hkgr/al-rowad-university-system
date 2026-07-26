<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core authorization conventions
    |--------------------------------------------------------------------------
    */

    'super_admin_role' => 'super_admin',
    'student_role' => 'student',
    'active_account_status' => 'active',
    'manage_implies_view' => true,
    'faculty_scoped_role' => 'doctor_instructor',
    'faculty_scope_bypass_roles' => [
        'super_admin',
        'dean',
        'head_of_department',
        'academic_advisor',
        'registration_officer',
        'exam_officer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard access map
    |--------------------------------------------------------------------------
    |
    | Access is granted when the user is a super administrator, has one of the
    | listed roles, or has one of the listed permissions. Profile-bound
    | dashboards additionally require the corresponding profile foreign key.
    |
    */

    'dashboards' => [
        'student-affairs' => [
            'path' => '/student-affairs',
            'roles' => ['registration_officer', 'academic_advisor'],
            'permissions' => ['students.manage', 'registration.manage'],
        ],
        'exam-board' => [
            'path' => '/exam-board',
            'roles' => ['exam_officer'],
            'permissions' => ['exams.manage'],
        ],
        'academic-structure' => [
            'path' => '/academic-structure',
            'roles' => ['dean', 'head_of_department'],
            'permissions' => ['academic_structure.manage'],
        ],
        'hr' => [
            'path' => '/hr',
            'roles' => ['hr_officer'],
            'permissions' => ['hr.manage'],
        ],
        'professor' => [
            'path' => '/professor',
            'roles' => ['doctor_instructor'],
            'permissions' => [],
            'required_profile' => 'employee_id',
        ],
        'student' => [
            'path' => '/student',
            'roles' => ['student'],
            'permissions' => [],
            'required_profile' => 'student_id',
        ],
    ],

    'dashboard_priority' => [
        'student-affairs',
        'exam-board',
        'academic-structure',
        'hr',
        'professor',
        'student',
    ],
];
