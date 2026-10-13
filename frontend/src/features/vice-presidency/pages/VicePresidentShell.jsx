import { getIdentity } from '../../auth/auth'
import { canAccessExecutiveReports } from '../../executive-reports/access'
import ExecutiveOverview from '../../executive-reports/components/ExecutiveOverview'
import AdministrativeHome from '../components/AdministrativeHome'
import ScientificHome from '../components/ScientificHome'

function scopeLabel(scope) {
  if (!scope) return ''
  if (typeof scope === 'string') return scope
  if (scope.type) return scope.type
  return ''
}

export default function VicePresidentShell({ office }) {
  const identity = getIdentity()
  const roles = Array.isArray(identity?.roles) ? identity.roles.join('، ') : '—'
  const scopes = Array.isArray(identity?.access_scopes) && identity.access_scopes.length > 0
    ? identity.access_scopes.map(scopeLabel).filter(Boolean).join('، ')
    : '—'
  const unitName = identity?.organizational_unit?.name || identity?.organizational_unit || '—'

  const reportsAllowed = canAccessExecutiveReports(office, identity)
  const identityDetails = (
    <details className="bg-white border border-black/5 rounded-[16px] p-5 shadow-sm">
      <summary className="cursor-pointer text-[12px] font-bold text-text-light">تفاصيل الهوية والنطاق</summary>
      <dl className="grid gap-2 text-[13px] text-text-dark">
        <div className="flex justify-between gap-4"><dt className="text-text-light">المستخدم</dt><dd>{identity?.username || identity?.email || '—'}</dd></div>
        <div className="flex justify-between gap-4"><dt className="text-text-light">الأدوار</dt><dd>{roles || '—'}</dd></div>
        <div className="flex justify-between gap-4"><dt className="text-text-light">الوحدة التنظيمية</dt><dd>{typeof unitName === 'string' ? unitName : unitName?.name || '—'}</dd></div>
        <div className="flex justify-between gap-4"><dt className="text-text-light">نطاق الوصول</dt><dd>{scopes}</dd></div>
      </dl>
    </details>
  )

  if (office === 'administrative') return <AdministrativeHome identity={identity}>{identityDetails}</AdministrativeHome>

  return (
    <ScientificHome identity={identity}>
      <p className="text-[13px] text-text-light">نطاق العمل: الجامعة كاملة. الصلاحيات تُحدد لكل إجراء على حدة.</p>
      {reportsAllowed && <ExecutiveOverview office={office} />}
      {identityDetails}
    </ScientificHome>
  )
}
