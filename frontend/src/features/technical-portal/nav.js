import { FaUserShield, FaQuestionCircle, FaHistory, FaEnvelope } from 'react-icons/fa'
import { ACCESS } from '../auth/auth'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

// بوابة المكتب التقني.
import { reportNav } from '../portal-reports/reportNav'

const technicalNav = [
  {
    label: 'خدمات المكتب',
    items: [
      { to: '/technical/accounts', Icon: FaUserShield, ar: 'الحسابات والصلاحيات', en: 'Accounts & Permissions', ...ACCESS.technicalAccounts },
      { to: '/technical/university-email', Icon: FaEnvelope, ar: 'البريد الجامعي', en: 'University email', ...ACCESS.universityEmail },
    ],
  },
  {
    label: 'المتابعة',
    items: [
      { to: '/technical/activity', Icon: FaHistory, ar: 'سجل النشاط', en: 'Activity log', ...ACCESS.technicalActivity },
    ],
  },
  reportNav('technical'),
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.technical, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.technical }] },
]

export default technicalNav
