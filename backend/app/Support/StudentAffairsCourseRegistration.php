<?php

namespace App\Support;

/**
 * Optional student-affairs data entry for courses already confirmed on paper.
 * The permission is not on registration_officer. Super admin grants and revokes
 * the dedicated role from the accounts screen.
 */
final class StudentAffairsCourseRegistration
{
    public const PERMISSION = 'student_affairs.course_registration.manage';

    public const ROLE = 'student_affairs_course_registrar';

    public const NOTE = 'تسجيل من شؤون الطلاب بعد اعتماد المرشد الأكاديمي على الورق.';
}
