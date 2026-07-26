import {
  FaHome, FaGraduationCap, FaUserPlus, FaArchive, FaUsers, FaBookOpen,
} from 'react-icons/fa'

const studentAffairsNav = [
  {
    label: 'الرئيسية',
    items: [
      { to: '/student-affairs', Icon: FaHome, ar: 'الرئيسية', en: 'Home', end: true, permission: 'students.view' },
    ],
  },
  {
    label: 'الطلاب',
    items: [
      { to: '/student-affairs/students',          Icon: FaUsers,         ar: 'قائمة الطلاب',      en: 'Students',     permission: 'students.view' },
      { to: '/student-affairs/students/add',      Icon: FaUserPlus,      ar: 'إضافة طالب',        en: 'Add Student', end: true, permission: 'students.manage' },
      { to: '/student-affairs/students/archived', Icon: FaArchive,       ar: 'الطلاب الموقوفون',  en: 'Suspended',   end: true, permission: 'students.manage' },
      { to: '/student-affairs/course-registration', Icon: FaBookOpen,    ar: 'تسجيل المواد',      en: 'Course Registration', end: true, permission: 'registration.manage' },
    ],
  },
  {
    label: 'الخريجون',
    items: [
      { to: '/student-affairs/graduates', Icon: FaGraduationCap, ar: 'قائمة الخريجين', en: 'Graduates', end: true, permission: 'students.view' },
    ],
  },
]

export default studentAffairsNav
