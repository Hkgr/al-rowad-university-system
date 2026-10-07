import { FaHome, FaMoneyCheckAlt } from 'react-icons/fa'
import { ACCESS } from '../auth/auth'

// بوابة مالك الجامعة: عنصران فقط (الرئيسية، الرواتب). التنقل لا يمنح أي صلاحية؛ كل مسار محمي في App.jsx والخادم.
const ownerNav = [
  {
    label: 'مالك الجامعة',
    items: [
      { to: '/owner', Icon: FaHome, ar: 'الرئيسية', en: 'Home', end: true, ...ACCESS.ownerHome },
      { to: '/owner/payroll', Icon: FaMoneyCheckAlt, ar: 'الرواتب', en: 'Payroll', ...ACCESS.ownerPayroll },
    ],
  },
]

export default ownerNav
