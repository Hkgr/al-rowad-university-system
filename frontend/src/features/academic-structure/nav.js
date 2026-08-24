import {
  FaHome, FaUniversity, FaBuilding, FaGraduationCap, FaCalendarAlt,
} from 'react-icons/fa'

const academicStructureNav = [
  {
    label: 'الهيكل الأكاديمي',
    items: [
      { to: '/academic-structure/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Calendar' },
      { to: '/academic-structure',            Icon: FaHome,          ar: 'الرئيسية',   en: 'Home',        end: true },
      { to: '/academic-structure/colleges',    Icon: FaUniversity,    ar: 'الكليات',     en: 'Colleges'               },
      { to: '/academic-structure/departments', Icon: FaBuilding,      ar: 'الأقسام',     en: 'Departments'            },
      { to: '/academic-structure/programs',    Icon: FaGraduationCap, ar: 'الاختصاصات',  en: 'Programs'               },
    ],
  },
]

export default academicStructureNav
