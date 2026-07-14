import { FaHome, FaCalendarCheck } from 'react-icons/fa'

const professorNav = [
  {
    label: 'بوابة الأستاذ',
    items: [
      { to: '/professor',            Icon: FaHome,          ar: 'الرئيسية',        en: 'Home',      end: true },
      { to: '/professor/attendance', Icon: FaCalendarCheck, ar: 'الحضور والحرمان', en: 'Attendance'           },
    ],
  },
]

export default professorNav
