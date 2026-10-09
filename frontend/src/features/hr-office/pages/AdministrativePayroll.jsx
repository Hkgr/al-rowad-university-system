import OwnerPayroll from '../../owner-portal/pages/OwnerPayroll'
import { createPayrollApi } from '../../owner-portal/lib/ownerApi'
import { PayrollApiProvider } from '../../owner-portal/lib/PayrollApiContext'
import { canAccess } from '../../auth/auth'
import { payrollAccess } from '../lib/hrOffice'
import { Link } from 'react-router-dom'
import { HR } from '../lib/hrOffice'
const api = createPayrollApi('/v1/vice-presidency/administrative')
export default function AdministrativePayroll() {
  return <PayrollApiProvider value={api}>{canAccess(payrollAccess('owner_payroll.employees.manage')) && canAccess({ assignedPermissions: [HR.payrollLink] }) && <div className="mb-4 text-[13px]"><Link className="font-bold text-primary" to="/vp/administrative/payroll/link">ربط ملف رواتب بموظف موجود — دون تغيير المبالغ</Link></div>}<OwnerPayroll authorize={permission => canAccess(payrollAccess(permission))} /></PayrollApiProvider>
}
