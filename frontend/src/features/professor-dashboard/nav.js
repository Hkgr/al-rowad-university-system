import { FaHome, FaEdit, FaCalendarCheck } from 'react-icons/fa'

const professorNav = [
  {
    label: 'بوابة الأستاذ',
    items: [
      { to: '/professor',             Icon: FaHome,          ar: 'الرئيسية',       en: 'Home',       end: true },
      { to: '/professor/grade-entry', Icon: FaEdit,          ar: 'إدخال الدرجات',  en: 'Grade Entry'           },
      { to: '/professor/attendance',  Icon: FaCalendarCheck, ar: 'الحضور والحرمان', en: 'Attendance'            },
    ],
  },
]

export default professorNav
