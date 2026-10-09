import { FaCalendarAlt, FaChalkboardTeacher, FaChartBar, FaClipboardCheck, FaClipboardList, FaHome, FaUnlock, FaQuestionCircle, FaUniversity, FaUserTie } from 'react-icons/fa'

import { PERMISSIONS, ROLES } from '../auth/auth'
import { WORKSPACE_ACCESS } from '../scientific-programs/workspace'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'
import { ADMINISTRATIVE_ACCESS, ADMINISTRATIVE_PATHS } from './utils/administrativeAccess'
import { HR, hrAccess, payrollAccess } from '../hr-office/lib/hrOffice'

export const scientificVicePresidentNav = [
  {
    label: 'نيابة الشؤون العلمية',
    items: [
      { to: '/vp/scientific/programs-courses', Icon: FaClipboardList, ar: 'البرامج والمواد', en: 'Programs and courses', ...WORKSPACE_ACCESS },
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
    label: 'الشؤون الإدارية',
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
        ar: 'التقارير',
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
      { to: '/vp/administrative/hr', Icon: FaUserTie, ar: 'العاملون والاحتياجات', en: 'HR office', ...hrAccess(HR.view) },
      { to: '/vp/administrative/payroll', Icon: FaUserTie, ar: 'الرواتب', en: 'Payroll', ...payrollAccess(PERMISSIONS.ownerPayrollView) },
      { to: ADMINISTRATIVE_PATHS.faculty, Icon: FaUserTie, ar: 'إدارة المدرسين', en: 'Teachers', ...ADMINISTRATIVE_ACCESS.facultyView },
      { to: ADMINISTRATIVE_PATHS.deans, Icon: FaUniversity, ar: 'عمداء الكليات', en: 'College deans', ...ADMINISTRATIVE_ACCESS.deansView },
      {
        to: '/vp/administrative/exceptional-openings',
        Icon: FaUnlock,
        ar: 'الفتح الاستثنائي',
        en: 'Exceptional opening',
        permissions: [PERMISSIONS.exceptionalOpenView],
      },
    ],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.vpAdministrative, Icon: FaQuestionCircle, ar: 'طريقة الاستخدام', en: 'User guide', ...GUIDE_ACCESS.vpAdministrative }] },
]
