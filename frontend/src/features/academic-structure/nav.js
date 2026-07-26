import {
  FaHome, FaUniversity, FaBuilding, FaGraduationCap,
} from 'react-icons/fa'

const academicStructureNav = [
  {
    label: 'الهيكل الأكاديمي',
    items: [
      { to: '/academic-structure',            Icon: FaHome,          ar: 'الرئيسية',  en: 'Home',        end: true, permission: 'academic_structure.view' },
      { to: '/academic-structure/colleges',    Icon: FaUniversity,    ar: 'الكليات',    en: 'Colleges',               permission: 'academic_structure.manage' },
      { to: '/academic-structure/departments', Icon: FaBuilding,      ar: 'الأقسام',    en: 'Departments',            permission: 'academic_structure.manage' },
      { to: '/academic-structure/programs',    Icon: FaGraduationCap, ar: 'الاختصاصات', en: 'Programs',               permission: 'academic_structure.manage' },
    ],
  },
]

export default academicStructureNav
