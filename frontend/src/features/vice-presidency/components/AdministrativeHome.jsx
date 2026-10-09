import { Link } from 'react-router-dom'
import { FaBuilding, FaCoins, FaSitemap, FaUserGraduate } from 'react-icons/fa'
import { canAccess } from '../../auth/auth'
import { administrativeVicePresidentNav } from '../nav'
import { ADMINISTRATIVE_HEAD, ADMINISTRATIVE_PORTAL_TITLE, administrativeDirectorates } from '../utils/administrativeOrganization'
import { canUseAdministrative } from '../utils/administrativeAccess'
import AdministrativeDashboard from './AdministrativeDashboard'
import OrganizationCode from './OrganizationCode'

const icons = { '71': FaBuilding, '72': FaCoins, '73': FaUserGraduate }

export default function AdministrativeHome({ identity, children }) {
  const authorizedItems = administrativeVicePresidentNav.flatMap(section => section.items).filter(item => canAccess(item, identity))
  const groups = administrativeDirectorates(authorizedItems)
  return (
    <div className="space-y-6" dir="rtl">
      <div><p className="mb-2 text-[11px] text-text-light">{ADMINISTRATIVE_PORTAL_TITLE} / الرئيسية</p><h2 className="text-2xl font-black text-text-dark">{ADMINISTRATIVE_PORTAL_TITLE}</h2></div>
      <section aria-labelledby="administrative-structure-title">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-[16px] border border-primary/15 bg-gradient-to-l from-white to-bg-light p-5 shadow-sm">
          <div className="flex items-center gap-3"><span className="rounded-xl bg-primary/10 p-3 text-primary-dark"><FaSitemap aria-hidden="true" /></span><div><h2 id="administrative-structure-title" className="text-[16px] font-black text-text-dark">{ADMINISTRATIVE_HEAD.title}</h2><p className="mt-1 text-[11px] text-text-light">المديريات والمكاتب التابعة</p></div></div>
          <OrganizationCode code={ADMINISTRATIVE_HEAD.code} />
        </div>
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
          {groups.map(group => {
            const Icon = icons[group.code]
            return (
              <article key={group.code} className="overflow-hidden rounded-[16px] border border-primary/15 bg-white shadow-sm" aria-labelledby={`administrative-home-${group.code}`}>
                <div className="flex items-start gap-3 border-b border-primary/10 bg-primary/5 p-4">
                  <span className="rounded-xl bg-primary/10 p-2.5 text-primary-dark"><Icon aria-hidden="true" /></span><div className="min-w-0 flex-1"><h3 id={`administrative-home-${group.code}`} className="text-[14px] font-black leading-7 text-text-dark">{group.title}</h3><p className="text-[10px] text-text-light">تتبع الرمز <b dir="ltr">{ADMINISTRATIVE_HEAD.code}</b></p></div><OrganizationCode code={group.code} />
                </div>
                <div className="divide-y divide-primary/10">
                  {group.offices.map(unit => (
                    <div key={unit.code} className="p-4">
                      <div className="flex items-start justify-between gap-2"><h4 className="min-w-0 text-[12px] font-bold leading-6 text-text-dark">{unit.title}</h4><OrganizationCode code={unit.code} /></div>
                      {unit.services.length ? <div className="mt-2 flex flex-wrap gap-2">{unit.services.map(item => <Link key={item.to} to={item.to} className="rounded-lg border border-primary/15 bg-primary/5 px-2.5 py-1.5 text-[11px] font-bold text-primary-dark hover:border-primary/40 hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">{item.ar}</Link>)}</div>
                        : <p className="mt-1 text-[10px] leading-6 text-text-light">لا توجد خدمات متاحة حاليًا</p>}
                    </div>
                  ))}
                </div>
              </article>
            )
          })}
        </div>
      </section>
      {canUseAdministrative('dashboard', identity) && <AdministrativeDashboard />}
      {children}
    </div>
  )
}
