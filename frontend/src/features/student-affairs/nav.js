import {
  FaHome, FaGraduationCap, FaUserPlus, FaArchive, FaUsers, FaClipboardCheck, FaBookOpen, FaCalendarAlt, FaFileExcel,
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
      { to: '/student-affairs/ministry-placements', Icon: FaFileExcel, ar: 'استيراد مفاضلة الوزارة', en: 'Ministry placement', end: true, assignedPermissions: ['admissions.view'], actualUniversityScope: true },
      { to: '/student-affairs/supplementary-exams', Icon: FaBookOpen, ar: 'التسجيل التكميلي', en: 'Supplementary registration', end: true, allRoles: ['registration_officer'], assignedPermissions: ['supplementary_exams.registrations.view'] },
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

export const ministryPlacementNav = [
  {
    label: 'القبول',
    items: [
      { to: '/student-affairs/ministry-placements', Icon: FaFileExcel, ar: 'استيراد مفاضلة الوزارة', en: 'Ministry placement', end: true, assignedPermissions: ['admissions.view'], actualUniversityScope: true },
    ],
  },
]

export default studentAffairsNav
