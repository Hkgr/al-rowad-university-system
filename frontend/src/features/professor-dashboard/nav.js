import { FaHome, FaCalendarAlt, FaCalendarCheck, FaEdit, FaQuestionCircle } from 'react-icons/fa'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

const professorNav = [
  {
    label: 'بوابة الأستاذ',
    items: [
      { to: '/professor/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Calendar' },
      { to: '/professor',            Icon: FaHome,          ar: 'الرئيسية',        en: 'Home',      end: true, permissions: ['grades.manage', 'attendance.manage'] },
      { to: '/professor/attendance', Icon: FaCalendarCheck, ar: 'الحضور والحرمان', en: 'Attendance', permissions: ['attendance.manage'] },
      { to: '/professor/grades',     Icon: FaEdit,          ar: 'إدارة العلامات',  en: 'Grades',     permissions: ['grades.manage'] },
      { to: '/professor/supplementary-exams', Icon: FaEdit, ar: 'الامتحانات التكميلية', en: 'Supplementary', allRoles: ['doctor_instructor'], assignedPermissions: ['supplementary_exams.grades.view'] },
    ],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.professor, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.professor }] },
]

export default professorNav
