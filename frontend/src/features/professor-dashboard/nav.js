import { FaHome, FaCalendarAlt, FaCalendarCheck, FaEdit } from 'react-icons/fa'

const professorNav = [
  {
    label: 'بوابة الأستاذ',
    items: [
      { to: '/professor/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Calendar' },
      { to: '/professor',            Icon: FaHome,          ar: 'الرئيسية',        en: 'Home',      end: true, permissions: ['grades.manage', 'attendance.manage'] },
      { to: '/professor/attendance', Icon: FaCalendarCheck, ar: 'الحضور والحرمان', en: 'Attendance', permissions: ['attendance.manage'] },
      { to: '/professor/grades',     Icon: FaEdit,          ar: 'إدارة العلامات',  en: 'Grades',     permissions: ['grades.manage'] },
      { to: '/professor/supplementary-exams', Icon: FaEdit, ar: 'الامتحانات التكميلية', en: 'Supplementary', permissions: ['supplementary_exams.grades.view'] },
    ],
  },
]

export default professorNav
