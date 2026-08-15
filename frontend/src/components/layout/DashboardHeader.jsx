import { useEffect, useMemo, useRef, useState } from 'react'
import { FaBars, FaBell, FaChevronDown, FaSignOutAlt } from 'react-icons/fa'

const ROLE_LABELS = {
  super_admin: 'مدير النظام',
  registration_officer: 'موظف القبول والتسجيل',
  student: 'طالب',
  professor: 'عضو الهيئة التدريسية',
}

function getUserDetails(user) {
  const labelledRoles = (user.roles || []).map(role => ROLE_LABELS[role]).filter(Boolean)
  const asScopeText = value => {
    if (typeof value === 'string') return value.trim()
    if (value && typeof value === 'object') return asScopeText(value.name)
    return ''
  }
  const scope = [
    user.department_name,
    user.college_name,
    user.organizational_unit,
    user.access_scope,
  ].map(asScopeText).find(Boolean)

  return {
    name: user.full_name || user.name || user.username || 'المستخدم',
    role: user.job_title || user.position_name || labelledRoles.join('، ') || 'مستخدم النظام',
    scope,
  }
}

function useDismissible(open, onClose) {
  const ref = useRef(null)

  useEffect(() => {
    if (!open) return undefined

    const handlePointerDown = event => {
      if (!ref.current?.contains(event.target)) onClose()
    }
    const handleKeyDown = event => {
      if (event.key === 'Escape') onClose()
    }

    document.addEventListener('pointerdown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open, onClose])

  return ref
}

function Avatar({ user }) {
  const { name } = getUserDetails(user)
  const initials = name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase()
  const photo = user.avatar_url || user.avatar || user.photo_url

  return photo
    ? <img src={photo} alt="" className="user-avatar object-cover" />
    : <span className="user-avatar" aria-hidden="true">{initials}</span>
}

function Notifications({ notifications = [], unreadCount = 0 }) {
  const [open, setOpen] = useState(false)
  const close = () => setOpen(false)
  const ref = useDismissible(open, close)

  return (
    <div className="header-menu-anchor" ref={ref}>
      <button className="header-tool" onClick={() => setOpen(!open)} aria-label="الإشعارات" aria-expanded={open}>
        <FaBell />
        {unreadCount > 0 && <span className="notification-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>}
      </button>

      {open && (
        <div className="header-popover notification-popover" dir="rtl">
          <div className="popover-heading">
            <div><strong>الإشعارات</strong><small>{unreadCount ? `${unreadCount} إشعار غير مقروء` : 'آخر التنبيهات الخاصة بحسابك'}</small></div>
          </div>
          {notifications.length > 0 ? (
            <div className="notification-list">
              {notifications.map(notification => (
                <div className="notification-item" key={notification.id}>
                  <strong>{notification.title}</strong>
                  {notification.message && <p>{notification.message}</p>}
                  {notification.time && <small>{notification.time}</small>}
                </div>
              ))}
            </div>
          ) : (
            <div className="empty-notifications">
              <span className="empty-notifications-icon"><FaBell /></span>
              <strong>لا توجد إشعارات جديدة</strong>
              <small>ستظهر تنبيهات النظام هنا عند توفرها</small>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function UserMenu({ user, logout }) {
  const [open, setOpen] = useState(false)
  const close = () => setOpen(false)
  const ref = useDismissible(open, close)
  const info = getUserDetails(user)

  return (
    <div className="header-menu-anchor" ref={ref}>
      <button className="user-trigger" onClick={() => setOpen(!open)} aria-expanded={open}>
        <Avatar user={user} />
        <span className="user-trigger-copy"><strong>{info.name}</strong><small>{info.role}</small></span>
        <FaChevronDown className={open ? 'rotate-180' : ''} />
      </button>

      {open && (
        <div className="header-popover user-popover" dir="rtl">
          <div className="user-summary">
            <Avatar user={user} />
            <div><strong>{info.name}</strong><small>{info.role}</small>{info.scope && <span>{info.scope}</span>}</div>
          </div>
          <button onClick={logout}><FaSignOutAlt /><span>تسجيل الخروج</span></button>
        </div>
      )}
    </div>
  )
}

export default function DashboardHeader({ appTitle, pageTitle, activeItem, user, toggleMenu, logout }) {
  const [now, setNow] = useState(new Date())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 30000)
    return () => window.clearInterval(timer)
  }, [])

  const date = useMemo(() => new Intl.DateTimeFormat('ar-SY', {
    weekday: 'long', day: 'numeric', month: 'long',
  }).format(now), [now])
  const time = useMemo(() => new Intl.DateTimeFormat('ar-SY', {
    hour: 'numeric', minute: '2-digit',
  }).format(now), [now])

  return (
    <header className="dashboard-header print-hidden" dir="rtl">
      <div className="page-context">
        <button className="header-tool mobile-menu" onClick={toggleMenu} aria-label="فتح القائمة"><FaBars /></button>
        <img src="/logo.png" alt="جامعة الرواد" />
        <div className="page-context-copy">
          <p>{appTitle}</p>
          <div className="page-title-row">
            <h1>{pageTitle}</h1>
            {activeItem?.en && <small lang="en" dir="ltr">{activeItem.en}</small>}
          </div>
        </div>
      </div>

      <div className="header-actions" dir="ltr">
        <div className="header-date" dir="rtl"><strong>{date}</strong><span aria-hidden="true">·</span><time>{time}</time></div>
        <div className="header-controls"><Notifications /><UserMenu user={user} logout={logout} /></div>
      </div>
    </header>
  )
}
