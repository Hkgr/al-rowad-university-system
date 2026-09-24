import { FaCalendarAlt, FaChalkboardTeacher, FaChartBar, FaClipboardCheck, FaClipboardList, FaHome, FaUnlock, FaQuestionCircle } from 'react-icons/fa'

import { PERMISSIONS, ROLES } from '../auth/auth'
import { CATALOG_ACCESS } from '../scientific-courses/catalog'
import { PROGRAM_ACCESS } from '../scientific-programs/programs'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

export const scientificVicePresidentNav = [
  {
    label: 'نيابة الشؤون العلمية',
    items: [
      { to: '/vp/scientific/courses', Icon: FaClipboardList, ar: 'إدارة المواد', en: 'Course catalog', ...CATALOG_ACCESS },
      { to: '/vp/scientific/programs', Icon: FaClipboardList, ar: 'البرامج الأكاديمية', en: 'Academic programs', ...PROGRAM_ACCESS },
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
        to: '/vp/scientific/reports',
        Icon: FaChartBar,
        ar: 'التقارير والإحصاءات',
        en: 'Reports & analytics',
        allRoles: [ROLES.vicePresidentScientific],
        assignedPermissions: [PERMISSIONS.vicePresidencyScientificAccess],
        actualUniversityScope: true,
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
        to: '/vp/scientific/semester-offerings/minimum-enrollment',
        Icon: FaClipboardCheck,
        ar: 'قرارات الحد الأدنى للطروحات',
        en: 'Minimum enrollment decisions',
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
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.vpScientific, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.vpScientific }] },
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
        to: '/vp/administrative/reports',
        Icon: FaChartBar,
        ar: 'التقارير والإحصاءات',
        en: 'Reports & analytics',
        allRoles: [ROLES.vicePresidentAdministrative],
        assignedPermissions: [PERMISSIONS.vicePresidencyAdministrativeAccess],
        actualUniversityScope: true,
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
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.vpAdministrative, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.vpAdministrative }] },
]
