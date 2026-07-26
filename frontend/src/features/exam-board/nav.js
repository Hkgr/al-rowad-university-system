import {
  FaHome, FaClipboardList, FaCheckDouble, FaExclamationTriangle,
  FaCalendarAlt, FaChartBar, FaUsers, FaCog, FaEdit, FaBook,
  FaLockOpen, FaTable,
} from 'react-icons/fa'

const examBoardNav = [
  {
    label: 'هيئة الامتحانات',
    items: [
      { to: '/exam-board',                  Icon: FaHome,                ar: 'الرئيسية',             en: 'Home',          end: true, permission: 'exams.view' },
      { to: '/exam-board/grade-entry',      Icon: FaEdit,                ar: 'إدخال الدرجات',        en: 'Grade Entry',          permission: 'grades.manage' },
      { to: '/exam-board/grade-sheet',      Icon: FaClipboardList,       ar: 'كشوف الدرجات',         en: 'Grade Sheets',         permission: 'grades.view' },
      { to: '/exam-board/approvals',        Icon: FaCheckDouble,         ar: 'اعتماد الدرجات',       en: 'Approvals',            permission: 'grades.manage' },
      { to: '/exam-board/deprivation',      Icon: FaExclamationTriangle, ar: 'الحضور والحرمان',      en: 'Deprivation',          permission: 'exams.manage' },
      { to: '/exam-board/supplementary',    Icon: FaCalendarAlt,         ar: 'الامتحانات التكميلية', en: 'Supplementary',        permission: 'exams.manage' },
      { to: '/exam-board/results',          Icon: FaChartBar,            ar: 'النتائج والتقارير',    en: 'Results',              permission: 'grades.view' },
    ],
  },
  {
    label: 'المواد',
    items: [
      { to: '/exam-board/course-offerings', Icon: FaLockOpen, ar: 'فتح المواد',      en: 'Course Offerings', end: true, permission: 'courses.manage' },
      { to: '/exam-board/course-table',     Icon: FaTable,    ar: 'جدول المواد',     en: 'Course Table',     end: true, permission: 'courses.manage' },
      { to: '/exam-board/courses',          Icon: FaBook,     ar: 'المواد الدراسية', en: 'Courses',          end: true, permission: 'courses.manage' },
    ],
  },
  {
    label: 'الإدارة',
    items: [
      { to: '/exam-board/appeals',  Icon: FaUsers, ar: 'التظلمات',  en: 'Appeals',  permission: 'exams.manage' },
      { to: '/exam-board/settings', Icon: FaCog,   ar: 'الإعدادات', en: 'Settings', permission: 'system_settings.manage' },
    ],
  },
]

export default examBoardNav
