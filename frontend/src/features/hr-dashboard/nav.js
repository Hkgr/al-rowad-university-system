import {
  FaHome, FaUsers, FaChalkboardTeacher, FaBriefcase, FaUserPlus,
} from 'react-icons/fa'

const hrNav = [
  {
    label: 'الموارد البشرية',
    items: [
      { to: '/hr',               Icon: FaHome,              ar: 'الرئيسية',    en: 'Home',         end: true, permission: 'hr.view' },
      { to: '/hr/employees',     Icon: FaUsers,             ar: 'الموظفون',     en: 'Employees',               permission: 'hr.view' },
      { to: '/hr/employees/add', Icon: FaUserPlus,          ar: 'إضافة موظف',   en: 'Add Employee', end: true, permission: 'hr.manage' },
      { to: '/hr/faculty',       Icon: FaChalkboardTeacher, ar: 'هيئة التدريس', en: 'Faculty',                 permission: 'hr.view' },
    ],
  },
  {
    label: 'الإدارة',
    items: [
      { to: '/hr/positions', Icon: FaBriefcase, ar: 'المناصب', en: 'Positions', permission: 'hr.manage' },
    ],
  },
]

export default hrNav
