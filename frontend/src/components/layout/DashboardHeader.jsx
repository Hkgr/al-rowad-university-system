import { useEffect, useMemo, useRef, useState } from 'react'
import { FaBars, FaBell, FaChevronDown, FaSignOutAlt } from 'react-icons/fa'

const roleNames = { super_admin: 'مدير النظام', registration_officer: 'موظف القبول والتسجيل', student: 'طالب', professor: 'عضو الهيئة التدريسية' }
const identity = user => ({
  name: user.full_name || user.name || user.username || 'المستخدم',
  role: user.job_title || user.position_name || (user.roles || []).map(role => roleNames[role] || role).join('، ') || 'مستخدم النظام',
  scope: user.department_name || user.college_name || user.organizational_unit || user.access_scope,
})
function useDismiss(open, close) {
  const ref = useRef(null)
  useEffect(() => {
    if (!open) return
    const handler = event => { if (!ref.current?.contains(event.target)) close() }
    document.addEventListener('pointerdown', handler)
    return () => document.removeEventListener('pointerdown', handler)
  }, [open, close])
  return ref
}
function Avatar({ user }) {
  const { name } = identity(user)
  const initials = name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase()
  const photo = user.avatar_url || user.avatar || user.photo_url
  return photo ? <img src={photo} alt="" className="user-avatar object-cover" /> : <span className="user-avatar">{initials}</span>
}
function Notifications() {
  const [open, setOpen] = useState(false)
  const close = () => setOpen(false)
  const ref = useDismiss(open, close)
  return <div className="relative" ref={ref}>
    <button className="header-tool" onClick={() => setOpen(!open)} aria-label="الإشعارات" aria-expanded={open}><FaBell /><i aria-hidden="true" /></button>
    {open && <div className="header-popover notification-popover" dir="rtl">
      <div className="popover-heading"><div><strong>الإشعارات</strong><small>آخر التنبيهات الخاصة بحسابك</small></div><span>0 جديد</span></div>
      <div className="empty-notifications"><b><FaBell /></b><strong>لا توجد إشعارات جديدة</strong><small>ستظهر تنبيهات النظام هنا عند توفرها</small></div>
    </div>}
  </div>
}
function UserMenu({ user, logout }) {
  const [open, setOpen] = useState(false)
  const close = () => setOpen(false)
  const ref = useDismiss(open, close)
  const info = identity(user)
  return <div className="relative" ref={ref}>
    <button className="user-trigger" onClick={() => setOpen(!open)} aria-expanded={open}><Avatar user={user} /><span><strong>{info.name}</strong><small>{info.role}</small></span><FaChevronDown className={open ? 'rotate-180' : ''} /></button>
    {open && <div className="header-popover user-popover" dir="rtl">
      <div className="user-summary"><Avatar user={user} /><div><strong>{info.name}</strong><small>{info.role}</small>{info.scope && <em>{info.scope}</em>}</div></div>
      <button onClick={logout}><FaSignOutAlt /> تسجيل الخروج</button>
    </div>}
  </div>
}
export default function DashboardHeader({ appTitle, pageTitle, activeItem, user, toggleMenu, logout }) {
  const [now, setNow] = useState(new Date())
  useEffect(() => { const timer = setInterval(() => setNow(new Date()), 30000); return () => clearInterval(timer) }, [])
  const date = useMemo(() => new Intl.DateTimeFormat('ar-SY', { weekday: 'long', day: 'numeric', month: 'long' }).format(now), [now])
  const time = useMemo(() => new Intl.DateTimeFormat('ar-SY', { hour: 'numeric', minute: '2-digit' }).format(now), [now])
  return <header className="dashboard-header" dir="rtl">
    <div className="page-context"><button className="header-tool mobile-menu" onClick={toggleMenu} aria-label="فتح القائمة"><FaBars /></button><img src="/logo.png" alt="جامعة الرواد" /><div><p>{appTitle}{activeItem && <><i>/</i><span>{activeItem.ar}</span></>}</p><h1>{pageTitle}</h1></div></div>
    <div className="header-actions" dir="ltr"><div className="header-date" dir="rtl"><strong>{date}</strong><span>{time}</span></div><Notifications /><UserMenu user={user} logout={logout} /></div>
  </header>
}
