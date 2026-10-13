import { Link } from 'react-router-dom'
import { FaGraduationCap, FaSitemap, FaUniversity } from 'react-icons/fa'
import { canAccess } from '../../auth/auth'
import { scientificVicePresidentNav } from '../nav'
import { SCIENTIFIC_HEAD, SCIENTIFIC_PORTAL_TITLE, scientificDepartments } from '../utils/scientificOrganization'
import OrganizationCode from './OrganizationCode'
import './scientificPortal.css'

const icons = { '81': FaUniversity, '82': FaGraduationCap }
const serviceClass = 'max-w-full rounded-lg border border-primary/15 bg-primary/5 px-2.5 py-1.5 text-[11px] font-bold leading-6 text-primary-dark hover:border-primary/40 hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40'

export default function ScientificHome({ identity, children }) {
  const authorizedItems = scientificVicePresidentNav.flatMap(group => group.items).filter(item => canAccess(item, identity))
  const groups = scientificDepartments(authorizedItems)
  return (
    <div className="min-w-0 space-y-6" dir="rtl">
      <div><p className="mb-2 text-[11px] text-text-light">{SCIENTIFIC_PORTAL_TITLE} / الرئيسية</p><h2 className="text-2xl font-black text-text-dark">{SCIENTIFIC_PORTAL_TITLE}</h2></div>
      <section aria-labelledby="scientific-structure-title">
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[16px] border border-primary/15 bg-gradient-to-l from-white to-bg-light p-5 shadow-sm">
          <div className="flex min-w-0 items-center gap-3"><span className="shrink-0 rounded-xl bg-primary/10 p-3 text-primary-dark"><FaSitemap aria-hidden="true" /></span><div className="min-w-0"><h2 id="scientific-structure-title" className="break-words text-[16px] font-black leading-7 text-text-dark">{SCIENTIFIC_HEAD.title}</h2><p className="mt-1 text-[11px] text-text-light">الإدارات والأقسام التابعة</p></div></div>
          <OrganizationCode code={SCIENTIFIC_HEAD.code} />
        </div>
        <div className="scientific-tree-stem" aria-hidden="true" />
        <div className="scientific-organization-groups">
          {groups.map(group => {
            const Icon = icons[group.code]
            return (
              <article key={group.code} className="scientific-department min-w-0 rounded-[16px] border border-primary/15 bg-white shadow-sm" aria-labelledby={`scientific-home-${group.code}`}>
                <div className="flex items-start gap-3 rounded-t-[16px] border-b border-primary/10 bg-primary/5 p-4">
                  <span className="shrink-0 rounded-xl bg-primary/10 p-2.5 text-primary-dark"><Icon aria-hidden="true" /></span><div className="min-w-0 flex-1"><h3 id={`scientific-home-${group.code}`} className="break-words text-[14px] font-black leading-7 text-text-dark">{group.title}</h3><p className="text-[10px] text-text-light">تتبع الرمز <b dir="ltr">{SCIENTIFIC_HEAD.code}</b></p></div><OrganizationCode code={group.code} />
                </div>
                {group.sharedServices.length > 0 && <div className="flex flex-wrap items-center justify-between gap-2 border-b border-primary/10 px-4 py-3"><p className="text-[10px] text-text-light">خدمة مشتركة داخل الإدارة</p>{group.sharedServices.map(item => <Link key={item.to} to={item.to} className={serviceClass}>{item.ar}</Link>)}</div>}
                <div className="scientific-children divide-y divide-primary/10">
                  {group.sections.map(unit => (
                    <div key={unit.code} className="scientific-child-node py-3">
                      <div className="flex items-start justify-between gap-2"><h4 className="min-w-0 break-words text-[12px] font-bold leading-6 text-text-dark">{unit.title}</h4><OrganizationCode code={unit.code} /></div>
                      <p className="mt-0.5 text-[10px] leading-6 text-text-light">تتبع الرمز <b dir="ltr">{group.code}</b></p>
                      {unit.services.length ? <div className="mt-2 flex flex-wrap gap-2">{unit.services.map(item => <Link key={item.to} to={item.to} className={serviceClass}>{item.ar}</Link>)}</div>
                        : <p className="mt-1 text-[10px] leading-6 text-text-light">غير متاح حاليًا</p>}
                    </div>
                  ))}
                </div>
              </article>
            )
          })}
        </div>
      </section>
      {children}
    </div>
  )
}
