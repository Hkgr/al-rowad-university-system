import {
  FaHome, FaGraduationCap, FaUserPlus, FaArchive, FaUsers, FaClipboardCheck, FaBookOpen, FaCalendarAlt, FaQuestionCircle,
} from 'react-icons/fa'
import { ACCESS } from '../auth/auth'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

import { reportNav } from '../portal-reports/reportNav'

const studentAffairsNav = [
  reportNav('student-affairs'),
  {
    label: 'الرئيسية',
    items: [
      { to: '/student-affairs/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Calendar', ...ACCESS.studentAffairs },
      { to: '/student-affairs', Icon: FaHome, ar: 'الرئيسية', en: 'Home', end: true, ...ACCESS.studentAffairs },
    ],
  },
  {
    label: 'الطلاب',
    items: [
      { to: '/student-affairs/students',          Icon: FaUsers,         ar: 'قائمة الطلاب',      en: 'Students', ...ACCESS.studentAffairs },
      { to: '/student-affairs/students/add',      Icon: FaUserPlus,      ar: 'إضافة طالب',        en: 'Add Student', end: true, ...ACCESS.studentAffairsAddStudent },
      { to: '/student-affairs/students/archived', Icon: FaArchive,       ar: 'الطلاب الموقوفون',  en: 'Suspended',   end: true, ...ACCESS.studentAffairsArchivedStudents },
    ],
  },
  {
    label: 'التسجيل',
    items: [
      { to: '/student-affairs/supplementary-exams', Icon: FaBookOpen, ar: 'التسجيل التكميلي', en: 'Supplementary registration', end: true, allPermissions: ['students.view'], allRoles: ['registration_officer'], assignedPermissions: ['supplementary_exams.registrations.view'] },
      { to: '/student-affairs/approved-registration-requests', Icon: FaClipboardCheck, FaBookOpen, ar: 'طلبات التسجيل المعتمدة', en: 'Approved requests', end: true, ...ACCESS.studentAffairsApprovedRegistrationRequests },
    ],
  },
  {
    label: 'الخريجون',
    items: [
      { to: '/student-affairs/graduates', Icon: FaGraduationCap, ar: 'قائمة الخريجين', en: 'Graduates', end: true, ...ACCESS.studentAffairs },
    ],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.studentAffairs, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.studentAffairs }] },
]

export default studentAffairsNav
