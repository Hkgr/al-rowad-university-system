import {
  FaHome, FaUsers, FaChalkboardTeacher, FaBriefcase, FaUserPlus,
} from 'react-icons/fa'

const hrNav = [
  {
    label: 'الموارد البشرية',
    items: [
      { to: '/hr',                 Icon: FaHome,               ar: 'الرئيسية',            en: 'Home',      end: true },
      { to: '/hr/employees',       Icon: FaUsers,              ar: 'الموظفون',             en: 'Employees'           },
      { to: '/hr/employees/add',   Icon: FaUserPlus,           ar: 'إضافة موظف',           en: 'Add Employee', end: true },
      { to: '/hr/faculty',         Icon: FaChalkboardTeacher,  ar: 'هيئة التدريس',         en: 'Faculty'             },
    ],
  },
  {
    label: 'الإدارة',
    items: [
      { to: '/hr/positions', Icon: FaBriefcase, ar: 'المناصب', en: 'Positions' },
    ],
  },
]

export default hrNav
