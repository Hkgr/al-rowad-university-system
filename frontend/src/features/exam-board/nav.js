import {
  FaHome, FaClipboardList, FaCheckDouble, FaExclamationTriangle,
  FaCalendarAlt, FaChartBar, FaUsers, FaCog, FaBook,
  FaLockOpen, FaBookOpen, FaTable,
} from 'react-icons/fa'
import { ACCESS } from '../auth/auth'

const examBoardNav = [
  {
    label: 'هيئة الامتحانات',
    items: [
      { to: '/exam-board',                  Icon: FaHome,                ar: 'الرئيسية',             en: 'Home',          end: true, permissions: ['exams.view', 'grades.view'] },
      { to: '/exam-board/grade-sheet',      Icon: FaClipboardList,       ar: 'كشوف الدرجات',         en: 'Grade Sheets', permissions: ['grades.view'] },
      { to: '/exam-board/approvals',        Icon: FaCheckDouble,         ar: 'اعتماد الدرجات',       en: 'Approvals', permissions: ['exams.manage'] },
      { to: '/exam-board/deprivation',      Icon: FaExclamationTriangle, ar: 'الحضور والحرمان',      en: 'Deprivation', permissions: ['exams.manage'] },
      { to: '/exam-board/supplementary',    Icon: FaCalendarAlt,         ar: 'الامتحانات التكميلية', en: 'Supplementary', permissions: ['exams.view'] },
      { to: '/exam-board/results',          Icon: FaChartBar,            ar: 'النتائج والتقارير',    en: 'Results', permissions: ['grades.view'] },
    ],
  },
  {
    label: 'المواد',
    items: [
      { to: '/exam-board/course-offerings',     Icon: FaLockOpen, ar: 'فتح المواد',      en: 'Course Offerings',     end: true, ...ACCESS.courseManagement },
      { to: '/exam-board/course-registration', Icon: FaBookOpen, ar: 'تسجيل المواد',    en: 'Course Registration', end: true, ...ACCESS.courseRegistration },
      { to: '/exam-board/course-table',        Icon: FaTable,    ar: 'جدول المواد',     en: 'Course Table',        end: true, ...ACCESS.courseManagement },
      { to: '/exam-board/courses',             Icon: FaBook,     ar: 'المواد الدراسية', en: 'Courses',             end: true, ...ACCESS.courseManagement },
    ],
  },
  {
    label: 'الإدارة',
    items: [
      { to: '/exam-board/appeals',             Icon: FaUsers,              ar: 'التظلمات',             en: 'Appeals', permissions: ['exams.view'] },
      { to: '/exam-board/settings',            Icon: FaCog,                ar: 'الإعدادات',            en: 'Settings', permissions: ['exams.view'] },
    ],
  },
]

export default examBoardNav
