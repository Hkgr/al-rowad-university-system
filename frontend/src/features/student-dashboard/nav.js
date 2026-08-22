import {
  FaHome, FaClipboardList, FaChartBar, FaCalendarCheck, FaPlusSquare, FaCalendarAlt, FaTasks,
} from 'react-icons/fa'

const studentNav = [
  {
    label: 'بوابة الطالب',
    items: [
      { to: '/student',              Icon: FaHome,          ar: 'الرئيسية',       en: 'Home',              end: true },
      { to: '/student/registration', Icon: FaPlusSquare,    ar: 'تسجيل المواد',   en: 'Registration',      end: true },
      { to: '/student/requirements', Icon: FaTasks,         ar: 'الخطة والتقدم',  en: 'Academic Progress', end: true },
      { to: '/student/supplementary-exams', Icon: FaClipboardList, ar: 'الامتحانات التكميلية', en: 'Supplementary Exams', end: true },
      { to: '/student/calendar',     Icon: FaCalendarAlt,   ar: 'التقويم الدراسي', en: 'Calendar',         end: true },
      { to: '/student/transcript',   Icon: FaClipboardList, ar: 'كشف الدرجات',    en: 'Transcript',        end: true },
      { to: '/student/gpa',          Icon: FaChartBar,      ar: 'المعدل',          en: 'GPA',               end: true },
      { to: '/student/attendance',   Icon: FaCalendarCheck, ar: 'الحضور والغياب', en: 'Attendance',        end: true },
    ],
  },
]

export default studentNav
