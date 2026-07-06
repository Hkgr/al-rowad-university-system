import {
  FaHome, FaGraduationCap, FaUserPlus, FaArchive, FaUsers, FaBookOpen, FaLockOpen,
} from 'react-icons/fa'

const studentAffairsNav = [
  {
    label: 'الرئيسية',
    items: [
      { to: '/student-affairs', Icon: FaHome, ar: 'الرئيسية', en: 'Home', end: true },
    ],
  },
  {
    label: 'الطلاب',
    items: [
      { to: '/student-affairs/students',          Icon: FaUsers,         ar: 'قائمة الطلاب',      en: 'Students'     },
      { to: '/student-affairs/students/add',      Icon: FaUserPlus,      ar: 'إضافة طالب',        en: 'Add Student', end: true },
      { to: '/student-affairs/students/archived', Icon: FaArchive,       ar: 'الطلاب المؤرشفون',  en: 'Archived',    end: true },
    ],
  },
  {
    label: 'التسجيل',
    items: [
      { to: '/student-affairs/course-offerings',     Icon: FaLockOpen, ar: 'فتح المواد',   en: 'Course Offerings',     end: true },
      { to: '/student-affairs/course-registration', Icon: FaBookOpen, ar: 'تسجيل المواد', en: 'Course Registration', end: true },
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
