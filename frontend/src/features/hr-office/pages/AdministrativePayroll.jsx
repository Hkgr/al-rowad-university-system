import MonthlyPayroll from '../../owner-portal/pages/MonthlyPayroll'
import { canAccess } from '../../auth/auth'
import { payrollAccess } from '../lib/hrOffice'
export default function AdministrativePayroll() {
  return <MonthlyPayroll office="administrative" authorize={permission => canAccess(payrollAccess(permission))} />
}
