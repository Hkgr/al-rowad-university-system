import {
  FaHome, FaClipboardList, FaChartBar, FaCalendarCheck, FaPlusSquare, FaCalendarAlt, FaTasks, FaQuestionCircle, FaEnvelope,
} from 'react-icons/fa'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

import { reportNav } from '../portal-reports/reportNav'

const studentNav = [
  {
    label: 'الرئيسية',
    items: [
      { to: '/student',              Icon: FaHome,          ar: 'الرئيسية',       en: 'Home',              end: true },
      { to: '/student/calendar',     Icon: FaCalendarAlt,   ar: 'التقويم الدراسي', en: 'Calendar',         end: true },
      { to: '/student/university-email', Icon: FaEnvelope, ar: 'بريدي الجامعي', en: 'University email', end: true, studentIdentity: true, allRoles: ['student'] },
    ],
  },
  {
    label: 'الدراسة والتسجيل',
    items: [
      { to: '/student/requirements', Icon: FaTasks,         ar: 'الخطة والتقدم',  en: 'Academic Progress', end: true },
      { to: '/student/registration', Icon: FaPlusSquare,    ar: 'تسجيل المواد',   en: 'Registration',      end: true },
      { to: '/student/supplementary-exams', Icon: FaClipboardList, ar: 'الامتحانات التكميلية', en: 'Supplementary Exams', end: true, allRoles: ['student'], assignedPermissions: ['supplementary_exams.deferrals.self', 'supplementary_exams.registrations.self'] },
    ],
  },
  {
    label: 'السجل الأكاديمي',
    items: [
      { to: '/student/transcript',   Icon: FaClipboardList, ar: 'كشف الدرجات',    en: 'Transcript',        end: true },
      { to: '/student/gpa',          Icon: FaChartBar,      ar: 'المعدل',          en: 'GPA',               end: true },
      { to: '/student/attendance',   Icon: FaCalendarCheck, ar: 'الحضور والغياب', en: 'Attendance',        end: true },
    ],
  },
  reportNav('student'),
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.student, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.student }] },
]

export default studentNav
