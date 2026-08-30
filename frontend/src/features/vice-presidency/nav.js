import { FaCalendarAlt, FaChalkboardTeacher, FaClipboardCheck, FaClipboardList, FaHome, FaUnlock } from 'react-icons/fa'

import { PERMISSIONS, ROLES } from '../auth/auth'

export const scientificVicePresidentNav = [
  {
    label: 'نيابة الشؤون العلمية',
    items: [
      { to: '/vp/scientific/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Academic calendar' },
      {
        to: '/vp/scientific',
        Icon: FaHome,
        ar: 'الرئيسية',
        en: 'Home',
        end: true,
        permissions: [PERMISSIONS.vicePresidencyScientificAccess],
      },
      {
        to: '/vp/scientific/semester-offerings',
        Icon: FaClipboardCheck,
        ar: 'اعتماد الطروحات الفصلية',
        en: 'Semester offerings',
        assignedPermissions: [PERMISSIONS.semesterOfferingGovernanceView],
        allRoles: [ROLES.vicePresidentScientific],
        actualUniversityScope: true,
      },
      {
        to: '/vp/scientific/teaching-assignments',
        Icon: FaChalkboardTeacher,
        ar: 'تكليفات المدرسين',
        en: 'Teaching assignments',
        permissions: [PERMISSIONS.teachingAssignmentsView],
      },
      {
        to: '/vp/scientific/exceptional-openings',
        Icon: FaUnlock,
        ar: 'الفتح الاستثنائي',
        en: 'Exceptional opening',
        permissions: [PERMISSIONS.exceptionalOpenView],
      },
      {
        to: '/vp/scientific/supplementary-exams',
        Icon: FaClipboardList,
        ar: 'الامتحانات التكميلية',
        en: 'Supplementary exams',
        allPermissions: [PERMISSIONS.supplementaryExamsPeriodsView],
        roles: [ROLES.vicePresidentScientific],
      },
    ],
  },
]

export const administrativeVicePresidentNav = [
  {
    label: 'نيابة الشؤون الإدارية',
    items: [
      { to: '/vp/administrative/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Academic calendar' },
      {
        to: '/vp/administrative',
        Icon: FaHome,
        ar: 'الرئيسية',
        en: 'Home',
        end: true,
        permissions: [PERMISSIONS.vicePresidencyAdministrativeAccess],
      },
      {
        to: '/vp/administrative/teaching-assignments',
        Icon: FaChalkboardTeacher,
        ar: 'تكليفات المدرسين',
        en: 'Teaching assignments',
        permissions: [PERMISSIONS.teachingAssignmentsView],
      },
      {
        to: '/vp/administrative/exceptional-openings',
        Icon: FaUnlock,
        ar: 'الفتح الاستثنائي',
        en: 'Exceptional opening',
        permissions: [PERMISSIONS.exceptionalOpenView],
      },
    ],
  },
]
