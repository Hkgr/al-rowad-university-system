import OwnerPayroll from '../../owner-portal/pages/OwnerPayroll'
import { createPayrollApi } from '../../owner-portal/lib/ownerApi'
import { PayrollApiProvider } from '../../owner-portal/lib/PayrollApiContext'
import { canAccess } from '../../auth/auth'
import { canHr, payrollAccess } from '../lib/hrOffice'
import { Link } from 'react-router-dom'
import { HR } from '../lib/hrOffice'
const api = createPayrollApi('/v1/vice-presidency/administrative')
export default function AdministrativePayroll() {
  return <PayrollApiProvider value={api}><div dir="rtl" className="mb-4 flex flex-wrap items-center justify-between gap-3 text-[12.5px]"><p className="text-text-light">مديرية الشؤون المالية 72 / مكتب المحاسبة 721</p><div className="flex flex-wrap gap-3 text-primary font-bold">{canHr('view') && <Link to="/vp/administrative/hr">مكتب الموارد البشرية</Link>}{canAccess(payrollAccess('owner_payroll.employees.manage')) && canAccess({ assignedPermissions: [HR.payrollLink] }) && <Link to="/vp/administrative/payroll/link">ربط الملفات</Link>}</div></div><OwnerPayroll authorize={permission => canAccess(payrollAccess(permission))} /></PayrollApiProvider>
}
