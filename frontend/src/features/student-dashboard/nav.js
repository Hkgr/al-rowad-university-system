import {
  FaHome, FaClipboardList, FaChartBar, FaCalendarCheck, FaPlusSquare,
} from 'react-icons/fa'

const studentNav = [
  {
    label: 'بوابة الطالب',
    items: [
      { to: '/student',              Icon: FaHome,          ar: 'الرئيسية',       en: 'Home',         end: true },
      { to: '/student/registration', Icon: FaPlusSquare,    ar: 'تسجيل المواد',   en: 'Registration', end: true },
      { to: '/student/transcript',   Icon: FaClipboardList, ar: 'كشف الدرجات',    en: 'Transcript',   end: true },
      { to: '/student/gpa',          Icon: FaChartBar,      ar: 'المعدل',          en: 'GPA',          end: true },
      { to: '/student/attendance',   Icon: FaCalendarCheck, ar: 'الحضور والغياب', en: 'Attendance',   end: true },
    ],
  },
]

export default studentNav
