import { useState } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import {
  FaHome, FaGraduationCap, FaUsers, FaBook,
  FaChartBar, FaCog, FaSignOutAlt, FaBars, FaChevronRight, FaBell,
} from 'react-icons/fa'

const NAV_MAIN = [
  { to: '/dashboard', Icon: FaHome,          ar: 'الرئيسية',  en: 'Dashboard' },
  { to: '/students',  Icon: FaGraduationCap, ar: 'الطلاب',    en: 'Students'  },
  { to: '/employees', Icon: FaUsers,         ar: 'الموظفون',  en: 'Employees' },
  { to: '/courses',   Icon: FaBook,          ar: 'المقررات',  en: 'Courses'   },
]

const NAV_OTHER = [
  { to: '/reports',  Icon: FaChartBar, ar: 'التقارير',  en: 'Reports'  },
  { to: '/settings', Icon: FaCog,      ar: 'الإعدادات', en: 'Settings' },
]

export default function DashboardLayout({ children, pageTitle = '' }) {
  const [collapsed, setCollapsed]   = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const navigate = useNavigate()
  const user = JSON.parse(localStorage.getItem('user') || '{}')

  const logout = () => {
    localStorage.removeItem('token')
    localStorage.removeItem('user')
    navigate('/login')
  }

  // Spelled out fully so Tailwind scans both values
  const sidebarWidth    = collapsed ? 'w-[76px]'              : 'w-[272px]'
  const mobileTranslate = mobileOpen ? 'max-[820px]:translate-x-0' : 'max-[820px]:translate-x-full'

  const renderLinks = (items) =>
    items.map(({ to, Icon, ar, en }) => (
      <NavLink
        key={to}
        to={to}
        title={collapsed ? ar : undefined}
        className={({ isActive }) => [
          'flex items-center gap-3 px-3.5 py-[11px] rounded-[10px] no-underline overflow-hidden whitespace-nowrap relative transition-all duration-200 border-r-[3px]',
          isActive
            ? 'border-r-primary-light text-white shadow-[inset_0_0_24px_rgba(86,153,51,0.1),0_2px_10px_rgba(86,153,51,0.1)]'
            : 'border-r-transparent text-white/86 hover:bg-primary/12 hover:text-white hover:-translate-x-[3px]',
        ].join(' ')}
        style={({ isActive }) => ({
          direction: 'rtl',
          background: isActive
            ? 'linear-gradient(to left, rgba(86,153,51,0.32) 0%, rgba(86,153,51,0.08) 100%)'
            : undefined,
        })}
      >
        {({ isActive }) => (
          <>
            <Icon className={`text-[16px] flex-shrink-0 w-[18px] text-center transition-all duration-200 ${isActive ? 'text-primary-light [filter:drop-shadow(0_0_6px_rgba(122,179,86,0.7))]' : ''}`} />
            {!collapsed && (
              <span className="flex flex-col gap-px flex-1 min-w-0 overflow-hidden">
                <span className={`text-[13.5px] ${isActive ? 'font-bold' : 'font-semibold'}`}>{ar}</span>
                <span className={`text-[9.5px] tracking-[0.2px] ${isActive ? 'text-white/48' : 'text-white/40'}`}>{en}</span>
              </span>
            )}
            <span
              className={`block w-1.5 h-1.5 rounded-full flex-shrink-0 transition-opacity duration-200 bg-primary-light ${isActive ? 'opacity-100 shadow-[0_0_8px_rgba(122,179,86,0.9)] animate-[dotPulse_2s_ease-in-out_infinite]' : 'opacity-0'}`}
              aria-hidden
            />
          </>
        )}
      </NavLink>
    ))

  return (
    <div className="flex min-h-screen bg-[#f0f5ec]" dir="rtl">

      {/* Mobile overlay */}
      {mobileOpen && (
        <div
          className="fixed inset-0 bg-black/52 z-40 backdrop-blur-[4px]"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* ── Sidebar ── */}
      <aside
        className={[
          'h-screen sticky top-0 flex-shrink-0 flex flex-col overflow-hidden z-50',
          'transition-[width] duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]',
          sidebarWidth,
          // Mobile: fixed, slides from right
          'max-[820px]:fixed max-[820px]:right-0 max-[820px]:top-0 max-[820px]:w-[272px] max-[820px]:transition-transform',
          mobileTranslate,
        ].join(' ')}
        style={{ background: 'linear-gradient(175deg, #243d16 0%, #1a2e10 48%, #0f2007 100%)' }}
      >
        {/* Dot-grid pattern */}
        <div
          className="absolute inset-0 pointer-events-none z-0"
          style={{
            backgroundImage: 'radial-gradient(circle, rgba(86,153,51,0.1) 1px, transparent 1px)',
            backgroundSize: '22px 22px',
          }}
        />

        {/* All sidebar content sits above the pattern */}
        <div className="relative z-[1] flex flex-col h-full">

          {/* Animated accent bar */}
          <div
            className="h-1 flex-shrink-0 animate-[barFlow_3.5s_linear_infinite]"
            style={{
              background: 'linear-gradient(90deg, #417327, #7ab356, #a8d68a, #7ab356, #417327)',
              backgroundSize: '200% 100%',
            }}
          />

          {/* Logo + brand */}
          <div className="flex items-center gap-3 px-4 py-[18px] min-h-[82px] border-b border-white/6 overflow-hidden" dir="rtl">
            <div
              className="flex-shrink-0 w-[46px] h-[46px] rounded-full p-[2px] animate-[ringRotate_6s_linear_infinite] shadow-[0_0_18px_rgba(86,153,51,0.45)]"
              style={{ background: 'conic-gradient(from 0deg, #569933, #7ab356, #a8d68a, #569933)' }}
            >
              <img src="/logo.png" alt="Logo" className="w-full h-full rounded-full object-contain bg-white p-1 block" />
            </div>
            {!collapsed && (
              <div className="flex flex-col min-w-0 overflow-hidden">
                <span className="text-[12.5px] font-black text-white whitespace-nowrap overflow-hidden text-ellipsis tracking-[0.3px]">
                  جامعة الرواد للعلوم والتقانة
                </span>
                <span className="text-[9.5px] font-normal text-white/40 whitespace-nowrap overflow-hidden text-ellipsis mt-[3px] tracking-[0.4px]">
                  Alrowad University
                </span>
              </div>
            )}
          </div>

          {/* Collapse button */}
          <button
            className="flex items-center justify-center my-[7px] mx-auto w-8 h-8 border border-white/9 rounded-[9px] bg-white/4 text-white/40 cursor-pointer transition-all duration-[220ms] flex-shrink-0 hover:bg-primary/20 hover:border-primary/38 hover:text-white hover:shadow-[0_0_14px_rgba(86,153,51,0.22)]"
            onClick={() => setCollapsed(v => !v)}
            title={collapsed ? 'توسيع القائمة' : 'طي القائمة'}
          >
            <FaChevronRight className={`text-[13px] transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] ${!collapsed ? 'rotate-180' : ''}`} />
          </button>

          {/* Navigation */}
          <nav
            className="flex-1 px-[10px] pt-2 flex flex-col gap-0 overflow-y-auto overflow-x-hidden [&::-webkit-scrollbar]:w-[3px] [&::-webkit-scrollbar-thumb]:bg-white/7 [&::-webkit-scrollbar-thumb]:rounded-[3px]"
            dir="rtl"
          >
            <div className="flex flex-col gap-0.5 pb-0.5">
              {!collapsed && (
                <span className="text-[9px] font-bold uppercase tracking-[1.2px] text-white/25 px-3.5 pt-2.5 pb-1 block" dir="rtl">
                  القائمة الرئيسية
                </span>
              )}
              {renderLinks(NAV_MAIN)}
            </div>

            <div className="h-px bg-white/6 my-2 mx-3" />

            <div className="flex flex-col gap-0.5 pb-0.5">
              {!collapsed && (
                <span className="text-[9px] font-bold uppercase tracking-[1.2px] text-white/25 px-3.5 pt-2.5 pb-1 block" dir="rtl">
                  أخرى
                </span>
              )}
              {renderLinks(NAV_OTHER)}
            </div>
          </nav>

          {/* Nav bottom fade */}
          <div
            className="h-7 flex-shrink-0 pointer-events-none"
            style={{ background: 'linear-gradient(to top, #0f2007 0%, transparent 100%)' }}
          />

          {/* User card + logout */}
          <div className="px-[10px] pb-3.5 pt-2 border-t border-white/6 flex flex-col gap-2" dir="rtl">
            <div className="flex items-center gap-[11px] px-[11px] py-[9px] rounded-[12px] bg-white/5 border border-white/8 backdrop-blur-[8px] overflow-hidden min-h-[56px] transition-colors duration-200 hover:bg-white/9">
              <div className="relative flex-shrink-0">
                <div
                  className="w-9 h-9 rounded-full flex items-center justify-center text-[15px] font-black text-white shadow-[0_2px_10px_rgba(86,153,51,0.5)]"
                  style={{ background: 'linear-gradient(135deg, #569933, #2d5c18)' }}
                >
                  {(user.username?.[0] ?? 'U').toUpperCase()}
                </div>
                <span
                  className="absolute bottom-0 left-0 w-2.5 h-2.5 rounded-full bg-[#22c55e] border-2 border-[#1a2e10] animate-[onlinePulse_2.5s_ease-in-out_infinite]"
                  title="متصل"
                />
              </div>
              {!collapsed && (
                <div className="flex flex-col min-w-0 overflow-hidden">
                  <span className="text-[12.5px] font-bold text-white whitespace-nowrap overflow-hidden text-ellipsis">
                    {user.username}
                  </span>
                  <span className="text-[9.5px] text-white/40 whitespace-nowrap overflow-hidden text-ellipsis mt-0.5">
                    موظف · Employee
                  </span>
                </div>
              )}
            </div>

            <button
              className="flex items-center justify-center gap-[9px] px-3.5 py-[9px] rounded-[11px] bg-red-500/8 border border-red-500/18 text-red-300 text-[13px] font-bold cursor-pointer transition-all duration-[220ms] w-full overflow-hidden whitespace-nowrap hover:bg-red-500/18 hover:border-red-500/38 hover:text-red-200 hover:shadow-[0_2px_14px_rgba(239,68,68,0.18)]"
              onClick={logout}
              title="تسجيل الخروج"
              dir="rtl"
            >
              <FaSignOutAlt />
              {!collapsed && <span>تسجيل الخروج</span>}
            </button>
          </div>

        </div>
      </aside>

      {/* ── Main area ── */}
      <div className="flex-1 min-w-0 flex flex-col" dir="ltr">

        {/* Top bar */}
        <header
          className="h-[66px] flex items-center gap-3.5 px-[26px] sticky top-0 z-30 flex-shrink-0 max-[820px]:px-4"
          style={{
            background: 'linear-gradient(to bottom, #ffffff 0%, #f8fcf5 100%)',
            boxShadow: '0 1px 0 rgba(86,153,51,0.1), 0 4px 20px rgba(26,46,16,0.07)',
          }}
        >
          {/* Hamburger — only visible on mobile */}
          <button
            className="hidden max-[820px]:flex bg-transparent border-none text-[19px] text-primary-dark cursor-pointer p-2 rounded-[9px] transition-colors duration-200 flex-shrink-0 hover:bg-primary/8"
            onClick={() => setMobileOpen(v => !v)}
            aria-label="القائمة"
          >
            <FaBars />
          </button>

          <h2 className="flex-1 text-[17px] font-extrabold text-text-dark text-right pr-0.5" dir="rtl">
            {pageTitle}
          </h2>

          <div className="flex items-center gap-2.5 flex-shrink-0">
            {/* Bell */}
            <button className="relative w-10 h-10 rounded-[11px] bg-primary/7 border border-primary/14 text-primary-dark text-[16px] cursor-pointer flex items-center justify-center transition-all duration-[220ms] hover:bg-primary/14 hover:scale-[1.07]">
              <FaBell />
              <span className="absolute top-[-5px] right-[-5px] w-[18px] h-[18px] rounded-full bg-red-500 text-white text-[10px] font-extrabold flex items-center justify-center border-2 border-white animate-[badgePulse_2s_ease-in-out_infinite] leading-none">
                3
              </span>
            </button>

            {/* User chip */}
            <div className="flex items-center gap-2 py-1 pl-3.5 pr-1 rounded-[30px] bg-primary/6 border border-primary/14 transition-all duration-[220ms] cursor-default hover:bg-primary/11 hover:border-primary/26">
              <div
                className="w-[30px] h-[30px] rounded-full flex items-center justify-center text-[12px] font-black text-white"
                style={{ background: 'linear-gradient(135deg, #569933, #2d5c18)' }}
              >
                {(user.username?.[0] ?? 'U').toUpperCase()}
              </div>
              <span className="text-[13px] font-semibold text-text-dark max-[820px]:hidden" dir="rtl">
                {user.username}
              </span>
            </div>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 px-8 pt-7 pb-12 min-w-0 max-[820px]:px-4 max-[820px]:pt-5 max-[820px]:pb-10" dir="rtl">
          {children}
        </main>

      </div>
    </div>
  )
}
