import {
  FaHome, FaGraduationCap, FaUserPlus, FaArchive, FaUsers,
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
    label: 'الخريجون',
    items: [
      { to: '/student-affairs/graduates', Icon: FaGraduationCap, ar: 'قائمة الخريجين', en: 'Graduates', end: true },
    ],
  },
]

export default studentAffairsNav
