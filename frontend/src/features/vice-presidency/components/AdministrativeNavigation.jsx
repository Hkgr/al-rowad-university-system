import { useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { FaBuilding, FaChevronDown, FaCoins, FaUserGraduate } from 'react-icons/fa'
import { ADMINISTRATIVE_PORTAL_TITLE, administrativeActiveDirectorate, administrativeDirectorates, partitionAdministrativeSections } from '../utils/administrativeOrganization'
import OrganizationCode from './OrganizationCode'
import './administrativePortal.css'

const icons = { '71': FaBuilding, '72': FaCoins, '73': FaUserGraduate }
const focus = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-light focus-visible:ring-inset'

function ServiceLink({ item, collapsed, onNavigate, nested = false }) {
  const { Icon } = item
  return (
    <NavLink to={item.to} end={item.end ?? false} onClick={onNavigate} title={collapsed ? item.ar : undefined}
      className={({ isActive }) => `flex items-center gap-2 rounded-[10px] border-r-[3px] px-3 py-2.5 text-[12px] leading-6 no-underline ${focus} ${isActive ? 'border-primary-light bg-primary/20 font-bold text-white' : 'border-transparent text-white/85 hover:bg-primary/12 hover:text-white'} ${nested ? 'mr-2' : ''}`}>
      <Icon aria-hidden="true" className="w-[18px] shrink-0 text-[15px]" />
      {!collapsed && <span className="min-w-0 break-words">{item.ar}</span>}
    </NavLink>
  )
}

function DirectorateGroup({ group, initiallyOpen, collapsed, expandSidebar, onNavigate }) {
  const [open, setOpen] = useState(initiallyOpen)
  const Icon = icons[group.code]
  const id = `administrative-nav-${group.code}`
  return (
    <div>
      <button type="button" title={collapsed ? `${group.title} — الرمز ${group.code}` : undefined}
        aria-label={`${group.title} — الرمز ${group.code}`} aria-expanded={!collapsed && open} aria-controls={id}
        className={`flex w-full items-center gap-2 rounded-[10px] px-3 py-3 text-right text-[12px] font-bold leading-6 text-white/85 hover:bg-primary/12 ${focus}`}
        onClick={() => { if (collapsed) { expandSidebar(); setOpen(true) } else setOpen(value => !value) }}>
        <Icon aria-hidden="true" className="w-[18px] shrink-0 text-[15px]" />
        {!collapsed && <><span className="min-w-0 flex-1 break-words">{group.title}</span><OrganizationCode code={group.code} dark /><FaChevronDown aria-hidden="true" className={`shrink-0 text-[9px] motion-safe:transition-transform ${open ? 'rotate-180' : ''}`} /></>}
      </button>
      <div id={id} hidden={collapsed || !open}>
        {group.offices.map(unit => (
          <div key={unit.code} className="mr-3 border-r border-white/10 py-2 pr-3">
            <div className="flex items-start justify-between gap-2 pr-1"><p className="min-w-0 text-[11px] font-bold leading-6 text-white/85">{unit.title}</p><OrganizationCode code={unit.code} dark /></div>
            {unit.services.length ? <div className="mt-1 space-y-0.5">{unit.services.map(item => <ServiceLink key={item.to} item={item} nested onNavigate={onNavigate} />)}</div>
              : <p className="pr-1 text-[9px] leading-6 text-white/40">لا توجد خدمات متاحة حاليًا</p>}
          </div>
        ))}
      </div>
    </div>
  )
}

export default function AdministrativeNavigation({ sections, collapsed, expandSidebar, onNavigate, renderSection }) {
  const { pathname } = useLocation()
  const { items, otherSections } = partitionAdministrativeSections(sections)
  const groups = administrativeDirectorates(items)
  const activeGroup = administrativeActiveDirectorate(pathname, groups)
  const home = items.find(item => item.to === '/vp/administrative')
  const reports = items.find(item => item.to === '/vp/administrative/reports')
  const guide = items.find(item => item.to === '/vp/administrative/guide')
  // Global administrator portal shortcuts stay separate, never become office services.
  return (
    <>
      <div className="space-y-1" aria-label="تنقل الشؤون الإدارية">
        {home && <ServiceLink item={home} collapsed={collapsed} onNavigate={onNavigate} />}
        {groups.map(group => <DirectorateGroup key={`${pathname}:${group.code}`} group={group} initiallyOpen={activeGroup === group.code || (pathname === '/vp/administrative' && group.code === '71')} collapsed={collapsed} expandSidebar={expandSidebar} onNavigate={onNavigate} />)}
        {reports && <ServiceLink item={reports} collapsed={collapsed} onNavigate={onNavigate} />}
        {guide && <ServiceLink item={guide} collapsed={collapsed} onNavigate={onNavigate} />}
      </div>
      {otherSections.map((section, index) => <div key={section.label} className="mt-3 border-t border-white/10 pt-2">{renderSection({ ...section, items: section.items.map(item => item.to === '/vp/administrative' ? { ...item, ar: ADMINISTRATIVE_PORTAL_TITLE } : item) }, index)}</div>)}
    </>
  )
}
