import { FaUserShield } from 'react-icons/fa'
import { ACCESS } from '../auth/auth'

// بوابة المكتب التقني: عنصر واحد فقط في هذه المرحلة.
const technicalNav = [
  {
    label: 'المكتب التقني',
    items: [
      { to: '/technical/accounts', Icon: FaUserShield, ar: 'الحسابات والصلاحيات', en: 'Accounts & Permissions', ...ACCESS.technicalAccounts },
    ],
  },
]

export default technicalNav
