import {
  FaHome, FaUniversity, FaBuilding, FaGraduationCap, FaCalendarAlt, FaQuestionCircle,
} from 'react-icons/fa'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

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
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.academicStructure, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.academicStructure }] },
]

export default academicStructureNav
