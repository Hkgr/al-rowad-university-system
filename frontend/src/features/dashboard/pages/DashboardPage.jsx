import { motion } from 'framer-motion'
import { Link } from 'react-router-dom'
import {
  FaGraduationCap, FaUsers, FaBook, FaCalendarAlt,
  FaUserPlus, FaSearch, FaChartLine, FaClipboardList,
} from 'react-icons/fa'

const STATS = [
  { Icon: FaGraduationCap, labelAr: 'إجمالي الطلاب',     labelEn: 'Total Students',     value: '—', color: '#569933', bg: 'rgba(86,153,51,0.1)'   },
  { Icon: FaUsers,         labelAr: 'الموظفون',           labelEn: 'Employees',           value: '—', color: '#3b82f6', bg: 'rgba(59,130,246,0.1)'  },
  { Icon: FaBook,          labelAr: 'البرامج الأكاديمية', labelEn: 'Academic Programs',   value: '—', color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)'  },
  { Icon: FaCalendarAlt,   labelAr: 'العام الدراسي',      labelEn: 'Academic Year',       value: '2025 / 2026', color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
]

const ACTIONS = [
  { Icon: FaUserPlus,      ar: 'إضافة طالب',   en: 'Add Student',    to: '/students/add', color: '#569933' },
  { Icon: FaSearch,        ar: 'بحث عن طالب',  en: 'Search Student', to: '/students',     color: '#3b82f6' },
  { Icon: FaChartLine,     ar: 'التقارير',      en: 'Reports',        to: '/reports',      color: '#8b5cf6' },
  { Icon: FaClipboardList, ar: 'سجل النشاط',   en: 'Activity Log',   to: '/reports',      color: '#f59e0b' },
]

export default function DashboardPage() {
  const user = JSON.parse(localStorage.getItem('user') || '{}')
  const dateStr = new Date().toLocaleDateString('ar-SY', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
  })

  return (
    <>

      {/* Welcome banner */}
      <motion.div
        className="flex items-center justify-between gap-4 bg-white border border-primary/15 rounded-[18px] px-7 py-[22px] mb-[26px] relative overflow-hidden shadow-[0_4px_24px_rgba(86,153,51,0.08),0_1px_4px_rgba(0,0,0,0.04)]"
        initial={{ opacity: 0, y: -14 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
      >
        {/* Animated top bar */}
        <div
          className="absolute top-0 left-0 right-0 h-1 animate-[barFlow_4s_linear_infinite]"
          style={{
            background: 'linear-gradient(90deg, #569933, #7ab356, #a8d68a, #7ab356, #417327, #569933)',
            backgroundSize: '250% 100%',
          }}
        />
        <div dir="rtl">
          <h2 className="text-[22px] font-black text-text-dark mb-1">مرحباً، {user.username} 👋</h2>
          <p className="text-[13px] text-text-gray" dir="rtl">{dateStr}</p>
        </div>
        <div className="flex flex-col items-end px-[18px] py-2.5 rounded-[12px] bg-primary/7 border border-primary/16 flex-shrink-0">
          <span className="text-[12.5px] font-bold text-primary-dark" dir="rtl">نظام إدارة الجامعة</span>
          <span className="text-[10px] text-primary-light mt-0.5">University Management System</span>
        </div>
      </motion.div>

      {/* Stats grid */}
      <div className="grid grid-cols-4 max-[1100px]:grid-cols-2 max-[580px]:grid-cols-2 gap-[18px] mb-7">
        {STATS.map(({ Icon, labelAr, labelEn, value, color, bg }, i) => (
          <motion.div
            key={i}
            className="bg-white border border-primary/12 rounded-[16px] px-[18px] py-5 flex items-center gap-3.5 relative overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)] transition-all duration-[220ms] hover:-translate-y-[3px] hover:shadow-[0_8px_28px_rgba(26,46,16,0.1)]"
            initial={{ opacity: 0, y: 22 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.08 + i * 0.07 }}
          >
            {/* Left color stripe */}
            <div className="absolute left-0 top-0 bottom-0 w-1" style={{ background: color }} />
            <div className="w-[50px] h-[50px] rounded-[13px] flex items-center justify-center text-[21px] flex-shrink-0" style={{ background: bg, color }}>
              <Icon />
            </div>
            <div className="flex flex-col" dir="rtl">
              <span className="text-[24px] font-black text-text-dark leading-[1.1] mb-[3px]">{value}</span>
              <span className="text-[12.5px] font-bold text-text-dark">{labelAr}</span>
              <span className="text-[10px] text-text-light mt-px">{labelEn}</span>
            </div>
          </motion.div>
        ))}
      </div>

      {/* Quick actions */}
      <motion.section
        className="mb-7"
        initial={{ opacity: 0, y: 18 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.42 }}
      >
        <div className="flex items-baseline gap-2.5 mb-3.5" dir="rtl">
          <h3 className="text-[16.5px] font-extrabold text-text-dark">الإجراءات السريعة</h3>
          <span className="text-[11.5px] text-text-light">Quick Actions</span>
        </div>

        <div className="grid grid-cols-4 max-[1100px]:grid-cols-2 max-[500px]:grid-cols-2 gap-3.5">
          {ACTIONS.map(({ Icon, ar, en, to, color }, i) => (
            <Link
              key={i}
              to={to}
              className="bg-white border border-primary/12 rounded-[14px] px-3.5 py-4 flex items-center gap-3 no-underline relative overflow-hidden shadow-[0_2px_8px_rgba(26,46,16,0.04)] transition-all duration-[220ms] group hover:border-[var(--ac)] hover:-translate-y-[3px] hover:shadow-[0_6px_20px_rgba(26,46,16,0.1)]"
              style={{ '--ac': color, direction: 'rtl' }}
            >
              {/* Bottom accent bar on hover */}
              <div className="absolute bottom-0 left-0 right-0 h-[3px] scale-x-0 transition-transform duration-[220ms] group-hover:scale-x-100" style={{ background: color }} />
              <div className="w-[42px] h-[42px] rounded-[11px] flex items-center justify-center text-[17px] flex-shrink-0" style={{ background: `${color}1a`, color }}>
                <Icon />
              </div>
              <div className="flex flex-col gap-0.5 flex-1 min-w-0">
                <span className="text-[13px] font-bold text-text-dark">{ar}</span>
                <span className="text-[10px] text-text-light">{en}</span>
              </div>
              <span className="text-[14px] text-[#c8dabb] transition-all duration-200 flex-shrink-0 group-hover:text-[var(--ac)] group-hover:-translate-x-1">←</span>
            </Link>
          ))}
        </div>
      </motion.section>

      {/* Info note */}
      <motion.div
        className="flex items-center justify-center gap-2.5 text-[11.5px] text-text-light bg-primary/4 border border-dashed border-primary/20 rounded-[10px] px-3.5 py-3.5"
        dir="rtl"
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.5, delay: 0.6 }}
      >
        <span className="w-1 h-1 rounded-full bg-primary/40 inline-block flex-shrink-0" />
        أرقام الإحصائيات ستظهر تلقائياً عند ربط البيانات من الـ API
        <span className="w-1 h-1 rounded-full bg-primary/40 inline-block flex-shrink-0" />
      </motion.div>

    </>
  )
}
