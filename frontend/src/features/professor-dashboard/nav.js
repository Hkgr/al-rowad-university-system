import { FaHome, FaCalendarCheck, FaEdit } from 'react-icons/fa'

const professorNav = [
  {
    label: 'بوابة الأستاذ',
    items: [
      { to: '/professor',            Icon: FaHome,          ar: 'الرئيسية',        en: 'Home',      end: true, permissions: ['grades.manage', 'attendance.manage'] },
      { to: '/professor/attendance', Icon: FaCalendarCheck, ar: 'الحضور والحرمان', en: 'Attendance', permissions: ['attendance.manage'] },
      { to: '/professor/grades',     Icon: FaEdit,          ar: 'إدارة العلامات',  en: 'Grades',     permissions: ['grades.manage'] },
    ],
  },
]

export default professorNav
