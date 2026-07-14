import {
  FaHome, FaClipboardList, FaCheckDouble, FaExclamationTriangle,
  FaCalendarAlt, FaChartBar, FaUsers, FaCog, FaEdit, FaBook,
  FaLockOpen, FaBookOpen, FaTable,
} from 'react-icons/fa'

const examBoardNav = [
  {
    label: 'هيئة الامتحانات',
    items: [
      { to: '/exam-board',                  Icon: FaHome,                ar: 'الرئيسية',             en: 'Home',          end: true },
      { to: '/exam-board/grade-entry',      Icon: FaEdit,                ar: 'إدخال الدرجات',        en: 'Grade Entry'          },
      { to: '/exam-board/grade-sheet',      Icon: FaClipboardList,       ar: 'كشوف الدرجات',         en: 'Grade Sheets'         },
      { to: '/exam-board/approvals',        Icon: FaCheckDouble,         ar: 'اعتماد الدرجات',       en: 'Approvals'            },
      { to: '/exam-board/deprivation',      Icon: FaExclamationTriangle, ar: 'الحضور والحرمان',      en: 'Deprivation'          },
      { to: '/exam-board/supplementary',    Icon: FaCalendarAlt,         ar: 'الامتحانات التكميلية', en: 'Supplementary'        },
      { to: '/exam-board/results',          Icon: FaChartBar,            ar: 'النتائج والتقارير',    en: 'Results'              },
    ],
  },
  {
    label: 'المواد',
    items: [
      { to: '/exam-board/course-offerings',     Icon: FaLockOpen, ar: 'فتح المواد',      en: 'Course Offerings',     end: true },
      { to: '/exam-board/course-registration', Icon: FaBookOpen, ar: 'تسجيل المواد',    en: 'Course Registration', end: true },
      { to: '/exam-board/course-table',        Icon: FaTable,    ar: 'جدول المواد',     en: 'Course Table',        end: true },
      { to: '/exam-board/courses',             Icon: FaBook,     ar: 'المواد الدراسية', en: 'Courses',             end: true },
    ],
  },
  {
    label: 'الإدارة',
    items: [
      { to: '/exam-board/appeals',             Icon: FaUsers,              ar: 'التظلمات',             en: 'Appeals'              },
      { to: '/exam-board/settings',            Icon: FaCog,                ar: 'الإعدادات',            en: 'Settings'             },
    ],
  },
]

export default examBoardNav
