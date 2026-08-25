import {
  FaBook, FaCalendarAlt, FaChalkboardTeacher, FaChartBar, FaClipboardCheck, FaClipboardList, FaHome, FaLockOpen, FaUsers,
} from 'react-icons/fa'

const deanNav = [
  {
    label: 'الرئيسية',
    items: [
      { to: '/dean', Icon: FaHome, ar: 'الرئيسية', en: 'Home', end: true },
    ],
  },
  {
    label: 'إدارة الكلية',
    items: [
      { to: '/dean/students', Icon: FaUsers, ar: 'الطلاب', en: 'Students' },
      { to: '/dean/teachers', Icon: FaChalkboardTeacher, ar: 'المدرسين', en: 'Teachers' },
      { to: '/dean/courses', Icon: FaBook, ar: 'المواد', en: 'Courses' },
      { to: '/dean/registration-offerings', Icon: FaLockOpen, ar: 'فتح المواد للتسجيل', en: 'Registration offerings' },
      { to: '/dean/registration-requests', Icon: FaClipboardList, ar: 'طلبات تسجيل الطلاب', en: 'Registration requests' },
      { to: '/dean/supplementary-exams', Icon: FaClipboardCheck, ar: 'الامتحانات التكميلية', en: 'Supplementary exams', allRoles: ['dean'], assignedPermissions: ['supplementary_exams.offerings.view'] },
    ],
  },
  {
    label: 'المتابعة',
    items: [
      { to: '/dean/reports', Icon: FaChartBar, ar: 'التقارير', en: 'Reports' },
      { to: '/dean/calendar', Icon: FaCalendarAlt, ar: 'التقويم', en: 'Calendar' },
    ],
  },
]

export default deanNav
