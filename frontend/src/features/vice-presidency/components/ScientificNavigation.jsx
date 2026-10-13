import { useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { FaChevronDown, FaGraduationCap, FaUniversity } from 'react-icons/fa'
import { SCIENTIFIC_HEAD, partitionScientificSections, scientificActiveDepartment, scientificActiveService, scientificDepartments } from '../utils/scientificOrganization'
import OrganizationCode from './OrganizationCode'
import './scientificPortal.css'

const icons = { '81': FaUniversity, '82': FaGraduationCap }
const focus = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-light focus-visible:ring-inset'

function ServiceLink({ item, activePath, collapsed, onNavigate, nested = false }) {
  const { Icon } = item
  return (
    <NavLink to={item.to} end={item.end ?? item.to !== activePath} onClick={onNavigate} title={collapsed ? item.ar : undefined}
      className={({ isActive }) => `flex items-center gap-2 rounded-[10px] border-r-[3px] px-3 py-2.5 text-[12px] leading-6 no-underline ${focus} ${isActive ? 'border-primary-light bg-primary/20 font-bold text-white' : 'border-transparent text-white/85 hover:bg-primary/12 hover:text-white'} ${nested ? 'mr-2' : ''}`}>
      <Icon aria-hidden="true" className="w-[18px] shrink-0 text-[15px]" />
      {!collapsed && <span className="min-w-0 break-words">{item.ar}</span>}
    </NavLink>
  )
}

function DepartmentGroup({ group, activePath, initiallyOpen, collapsed, expandSidebar, onNavigate }) {
  const [open, setOpen] = useState(initiallyOpen)
  const Icon = icons[group.code]
  const id = `scientific-nav-${group.code}`
  return (
    <div>
      <button type="button" title={`${group.title} — الرمز ${group.code}`}
        aria-label={`${group.title} — الرمز ${group.code}`} aria-expanded={!collapsed && open} aria-controls={id}
        className={`flex w-full items-center gap-2 rounded-[10px] py-3 text-right text-[12px] font-bold leading-6 text-white/85 hover:bg-primary/12 ${focus} ${collapsed ? 'flex-col px-1' : 'px-3'}`}
        onClick={() => { if (collapsed) { expandSidebar(); setOpen(true) } else setOpen(value => !value) }}>
        <Icon aria-hidden="true" className="w-[18px] shrink-0 text-[15px]" />
        {!collapsed && <span className="min-w-0 flex-1 break-words">{group.title}</span>}
        <OrganizationCode code={group.code} dark />
        {!collapsed && <FaChevronDown aria-hidden="true" className={`shrink-0 text-[9px] motion-safe:transition-transform ${open ? 'rotate-180' : ''}`} />}
      </button>
      <div id={id} hidden={collapsed || !open} className="mr-3 border-r border-white/10 pr-3">
        {group.sharedServices.length > 0 && <div className="border-b border-white/10 py-2"><p className="pr-1 text-[9px] leading-6 text-white/40">خدمة مشتركة</p>{group.sharedServices.map(item => <ServiceLink key={item.to} item={item} activePath={activePath} onNavigate={onNavigate} />)}</div>}
        {group.sections.map(unit => (
          <div key={unit.code} className="py-2">
            <div className="flex items-start justify-between gap-2 pr-1"><p className="min-w-0 break-words text-[11px] font-bold leading-6 text-white/85">{unit.title}</p><OrganizationCode code={unit.code} dark /></div>
            {unit.services.length ? <div className="mt-1 space-y-0.5">{unit.services.map(item => <ServiceLink key={item.to} item={item} activePath={activePath} nested onNavigate={onNavigate} />)}</div>
              : <p className="pr-1 text-[9px] leading-6 text-white/40">غير متاح حاليًا</p>}
          </div>
        ))}
      </div>
    </div>
  )
}

export default function ScientificNavigation({ sections, collapsed, expandSidebar, onNavigate, renderSection }) {
  const { pathname } = useLocation()
  const { items, otherSections } = partitionScientificSections(sections)
  const groups = scientificDepartments(items)
  const activeGroup = scientificActiveDepartment(pathname, groups)
  const activePath = scientificActiveService(pathname, items)?.to
  const home = items.find(item => item.to === '/vp/scientific')
  const reports = items.find(item => item.to === '/vp/scientific/reports')
  const guide = items.find(item => item.to === '/vp/scientific/guide')
  return (
    <>
      <div className="space-y-1" aria-label="تنقل الشؤون العلمية">
        <div className={`flex items-start gap-2 py-2 ${collapsed ? 'justify-center px-1' : 'justify-between px-3'}`}>
          {!collapsed && <p className="min-w-0 text-[10px] font-bold leading-6 text-white/60">{SCIENTIFIC_HEAD.title}</p>}<OrganizationCode code={SCIENTIFIC_HEAD.code} dark />
        </div>
        {home && <ServiceLink item={home} activePath={activePath} collapsed={collapsed} onNavigate={onNavigate} />}
        {/* Only disclosure state resets on route changes; page/editor keys and drafts are untouched. */}
        {groups.map(group => <DepartmentGroup key={`${pathname}:${group.code}`} group={group} activePath={activePath} initiallyOpen={activeGroup === group.code || (pathname === '/vp/scientific' && group.code === '81')} collapsed={collapsed} expandSidebar={expandSidebar} onNavigate={onNavigate} />)}
        {reports && <ServiceLink item={reports} activePath={activePath} collapsed={collapsed} onNavigate={onNavigate} />}
        {guide && <ServiceLink item={guide} activePath={activePath} collapsed={collapsed} onNavigate={onNavigate} />}
      </div>
      {/* Keep the existing administrator portal shortcuts separate, with their original access rules. */}
      {otherSections.map((section, index) => <div key={section.label} className="mt-3 border-t border-white/10 pt-2">{renderSection(section, index)}</div>)}
    </>
  )
}
