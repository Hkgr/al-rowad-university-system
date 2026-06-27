import {
  FaHome, FaClipboardList, FaCheckDouble, FaExclamationTriangle,
  FaCalendarAlt, FaChartBar, FaUsers, FaCog,
} from 'react-icons/fa'

const examBoardNav = [
  {
    label: 'هيئة الامتحانات',
    items: [
      { to: '/exam-board',                  Icon: FaHome,               ar: 'الرئيسية',             en: 'Home',          end: true },
      { to: '/exam-board/grade-sheet',      Icon: FaClipboardList,      ar: 'كشوف الدرجات',         en: 'Grade Sheets'         },
      { to: '/exam-board/approvals',        Icon: FaCheckDouble,        ar: 'اعتماد الدرجات',       en: 'Approvals'            },
      { to: '/exam-board/deprivation',      Icon: FaExclamationTriangle, ar: 'الحضور والحرمان',     en: 'Deprivation'          },
      { to: '/exam-board/supplementary',    Icon: FaCalendarAlt,        ar: 'الامتحانات التكميلية', en: 'Supplementary'        },
      { to: '/exam-board/results',          Icon: FaChartBar,           ar: 'النتائج والتقارير',    en: 'Results'              },
    ],
  },
  {
    label: 'الإدارة',
    items: [
      { to: '/exam-board/appeals',          Icon: FaUsers,              ar: 'التظلمات',             en: 'Appeals'              },
      { to: '/exam-board/settings',         Icon: FaCog,                ar: 'الإعدادات',            en: 'Settings'             },
    ],
  },
]

export default examBoardNav
