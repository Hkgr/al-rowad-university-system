import {
  FaHome, FaUsers, FaChalkboardTeacher, FaBriefcase, FaUserPlus, FaCalendarAlt, FaQuestionCircle,
} from 'react-icons/fa'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

const hrNav = [
  {
    label: 'الموارد البشرية',
    items: [
      { to: '/hr/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Calendar' },
      { to: '/hr',                 Icon: FaHome,               ar: 'الرئيسية',            en: 'Home',      end: true },
      { to: '/hr/employees',       Icon: FaUsers,              ar: 'الموظفون',             en: 'Employees'           },
      { to: '/hr/employees/add',   Icon: FaUserPlus,           ar: 'إضافة موظف',           en: 'Add Employee', end: true, permissions: ['hr.manage'] },
      { to: '/hr/faculty',         Icon: FaChalkboardTeacher,  ar: 'هيئة التدريس',         en: 'Faculty'             },
    ],
  },
  {
    label: 'الإدارة',
    items: [
      { to: '/hr/positions', Icon: FaBriefcase, ar: 'المناصب', en: 'Positions' },
    ],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.hr, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.hr }] },
]

export default hrNav
