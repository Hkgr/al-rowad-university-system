import { FaCalendarAlt, FaChalkboardTeacher, FaChartBar, FaClipboardCheck, FaClipboardList, FaHome, FaUnlock, FaQuestionCircle, FaUniversity, FaUserTie } from 'react-icons/fa'

import { ACCESS, PERMISSIONS, ROLES } from '../auth/auth'
import { WORKSPACE_ACCESS } from '../scientific-programs/workspace'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'
import { ADMINISTRATIVE_ACCESS, ADMINISTRATIVE_PATHS } from './utils/administrativeAccess'
import { HR, hrAccess, payrollAccess } from '../hr-office/lib/hrOffice'
import studentAffairsNav from '../student-affairs/nav'
import examBoardNav from '../exam-board/nav'
import technicalNav from '../technical-portal/nav'
import { reportNav } from '../portal-reports/reportNav'
import { administrativeOfficeAccess, administrativeOfficePath } from './utils/administrativeOfficePaths'

const officeServices = [studentAffairsNav, examBoardNav, technicalNav, [reportNav('hr')]].flatMap(nav => nav.flatMap(section => section.items))
  .filter(item => !['/student-affairs', '/exam-board', '/student-affairs/calendar', '/exam-board/calendar', '/exam-board/appeals', '/exam-board/settings'].includes(item.to) && !item.to.endsWith('/guide'))
  .map(item => ({ ...item, ...administrativeOfficeAccess(item), to: administrativeOfficePath(item.to, '/vp/administrative') }))

export const scientificVicePresidentNav = [
  {
    label: 'الشؤون العلمية',
    items: [
      {
        to: '/vp/scientific',
        Icon: FaHome,
        ar: 'الرئيسية',
        en: 'Home',
        end: true,
        permissions: [PERMISSIONS.vicePresidencyScientificAccess],
      },
    ],
  },
  {
    label: 'إدارة التعليم الجامعي',
    items: [
      { to: '/vp/scientific/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Academic calendar' },
      { to: '/vp/scientific/programs-courses', Icon: FaClipboardList, ar: 'البرامج والمواد', en: 'Programs and courses', ...WORKSPACE_ACCESS },
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
        ar: 'الفتح الاستثنائي للشعب',
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
  {
    label: 'التقارير والإحصاءات',
    items: [{
      to: '/vp/scientific/reports',
      Icon: FaChartBar,
      ar: 'التقارير والإحصاءات',
      en: 'Reports & analytics',
      allRoles: [ROLES.vicePresidentScientific],
      assignedPermissions: [PERMISSIONS.vicePresidencyScientificAccess],
      actualUniversityScope: true,
    }],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.vpScientific, Icon: FaQuestionCircle, ar: 'طريقة الاستخدام', en: 'User guide', ...GUIDE_ACCESS.vpScientific }] },
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
        ...ACCESS.administrativeVicePresident,
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
      ...officeServices,
      { to: '/vp/administrative/student-affairs/ministry-placements', Icon: FaClipboardList, ar: 'القبول والمفاضلات', en: 'Admissions', ...administrativeOfficeAccess({ assignedPermissions: ['admissions.view'] }) },
      { to: '/vp/administrative/personnel/employees', Icon: FaUserTie, ar: 'سجلات الموظفين', en: 'Personnel records', ...administrativeOfficeAccess({ assignedPermissions: ['hr.view'] }) },
      { to: '/vp/administrative/personnel/positions', Icon: FaUserTie, ar: 'المناصب المسجلة', en: 'Recorded positions', ...administrativeOfficeAccess({ assignedPermissions: ['hr.view'] }) },
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
