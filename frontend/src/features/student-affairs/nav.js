import {
  FaHome, FaGraduationCap, FaUserPlus, FaArchive, FaUsers, FaClipboardCheck, FaBookOpen, FaCalendarAlt,
} from 'react-icons/fa'

const studentAffairsNav = [
  {
    label: 'الرئيسية',
    items: [
      { to: '/student-affairs/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Calendar' },
      { to: '/student-affairs', Icon: FaHome, ar: 'الرئيسية', en: 'Home', end: true },
    ],
  },
  {
    label: 'الطلاب',
    items: [
      { to: '/student-affairs/students',          Icon: FaUsers,         ar: 'قائمة الطلاب',      en: 'Students'     },
      { to: '/student-affairs/students/add',      Icon: FaUserPlus,      ar: 'إضافة طالب',        en: 'Add Student', end: true, permissions: ['students.manage'], roles: ['registration_officer'] },
      { to: '/student-affairs/students/archived', Icon: FaArchive,       ar: 'الطلاب الموقوفون',  en: 'Suspended',   end: true, permissions: ['students.manage'], roles: ['registration_officer'] },
    ],
  },
  {
    label: 'التسجيل',
    items: [
      { to: '/student-affairs/supplementary-exams', Icon: FaBookOpen, ar: 'التسجيل التكميلي', en: 'Supplementary registration', end: true, permissions: ['supplementary_exams.registrations.view'] },
      { to: '/student-affairs/approved-registration-requests', Icon: FaClipboardCheck, FaBookOpen, ar: 'طلبات التسجيل المعتمدة', en: 'Approved requests', end: true, permissions: ['registration.view'] },
    ],
  },
  {
    label: 'الخريجون',
    items: [
      { to: '/student-affairs/graduates', Icon: FaGraduationCap, ar: 'قائمة الخريجين', en: 'Graduates', end: true },
    ],
  },
]

export default studentAffairsNav
