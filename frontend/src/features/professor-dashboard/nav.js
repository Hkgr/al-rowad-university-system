import { FaHome, FaCalendarCheck } from 'react-icons/fa'

const professorNav = [
  {
    label: 'بوابة الأستاذ',
    items: [
      { to: '/professor',            Icon: FaHome,          ar: 'الرئيسية',        en: 'Home',      end: true, permission: 'courses.view' },
      { to: '/professor/attendance', Icon: FaCalendarCheck, ar: 'الحضور ومتابعة الحرمان', en: 'Attendance', permission: 'attendance.manage' },
    ],
  },
]

export default professorNav
