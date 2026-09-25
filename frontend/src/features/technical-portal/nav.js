import { FaUserShield, FaQuestionCircle, FaHistory } from 'react-icons/fa'
import { ACCESS } from '../auth/auth'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

// بوابة المكتب التقني.
const technicalNav = [
  {
    label: 'المكتب التقني',
    items: [
      { to: '/technical/accounts', Icon: FaUserShield, ar: 'الحسابات والصلاحيات', en: 'Accounts & Permissions', ...ACCESS.technicalAccounts },
      { to: '/technical/activity', Icon: FaHistory, ar: 'سجل النشاط', en: 'Activity log', ...ACCESS.technicalActivity },
    ],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.technical, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.technical }] },
]

export default technicalNav
