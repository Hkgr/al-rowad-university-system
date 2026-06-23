import {
  FaHome, FaGraduationCap, FaUserPlus,
  FaChartBar, FaCog,
} from 'react-icons/fa'

/**
 * Nav config for شؤون الطلاب (Student Affairs) dashboard.
 * To add more links: add an object to the items array.
 * 'end: true' means the link only highlights on that exact URL (not sub-pages).
 */
const studentAffairsNav = [
  {
    label: 'شؤون الطلاب',
    items: [
      { to: '/student-affairs',              Icon: FaHome,          ar: 'الرئيسية',     en: 'Home',        end: true },
      { to: '/student-affairs/students',     Icon: FaGraduationCap, ar: 'قائمة الطلاب', en: 'Students', end: true  },
      { to: '/student-affairs/students/add', Icon: FaUserPlus,      ar: 'إضافة طالب',   en: 'Add Student', end: true },
    ],
  },
  {
    label: 'أخرى',
    items: [
      { to: '/student-affairs/reports',  Icon: FaChartBar, ar: 'التقارير',  en: 'Reports'  },
      { to: '/student-affairs/settings', Icon: FaCog,      ar: 'الإعدادات', en: 'Settings' },
    ],
  },
]

export default studentAffairsNav
