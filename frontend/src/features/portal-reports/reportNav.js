import { FaChartBar } from 'react-icons/fa'
import { reportAccess, REPORT_PATHS } from './reports'
export const reportNav = portal => ({label:'التقارير',items:[{to:REPORT_PATHS[portal],Icon:FaChartBar,ar:portal==='admissions'?'تقارير القبول والتسجيل':'التقارير',en:'Reports',...reportAccess(portal)}]})
