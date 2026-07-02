import { useState, useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaUsers, FaChalkboardTeacher, FaBriefcase, FaUserPlus,
  FaSpinner, FaCheckCircle, FaPauseCircle, FaBan, FaMoon,
} from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const TYPE_AR = {
  academic: 'أكاديمي', administrative: 'إداري', technical: 'تقني',
  service: 'خدمات', board_member: 'عضو مجلس',
}
const STATUS_CONFIG = {
  active:     { ar: 'نشط',            color: '#22c55e', bg: 'rgba(34,197,94,0.1)',   Icon: FaCheckCircle  },
  inactive:   { ar: 'غير نشط',        color: '#94a3b8', bg: 'rgba(148,163,184,0.1)', Icon: FaMoon         },
  on_leave:   { ar: 'في إجازة',       color: '#f59e0b', bg: 'rgba(245,158,11,0.1)',  Icon: FaPauseCircle  },
  terminated: { ar: 'منتهي الخدمة',   color: '#ef4444', bg: 'rgba(239,68,68,0.1)',   Icon: FaBan          },
}

export default function HRHome() {
  const navigate = useNavigate()
  const user = JSON.parse(localStorage.getItem('user') || '{}')
  const [stats, setStats] = useState({ employees: 0, faculty: 0, positions: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    Promise.all([
      fetch(`${API}/employees?per_page=1`,      { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/faculty-members?per_page=1`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/positions?per_page=1`,       { headers: authHeaders() }).then(r => r.json()),
    ]).then(([e, f, p]) => {
      if (e.success === false && e.message?.includes('Unauthenticated')) { navigate('/login'); return }
      setStats({
        employees: e.data?.meta?.total ?? e.data?.total ?? 0,
        faculty:   f.data?.meta?.total ?? f.data?.total ?? 0,
        positions: p.data?.meta?.total ?? p.data?.total ?? 0,
      })
    }).catch(() => setError('تعذّر الاتصال بالخادم'))
    .finally(() => setLoading(false))
  }, [navigate])

  const dateStr = new Date().toLocaleDateString('ar-SY', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
  })

  const TOP_STATS = [
    { Icon: FaUsers,             ar: 'إجمالي الموظفين',   en: 'Total Employees',  value: stats.employees, color: '#569933', bg: 'rgba(86,153,51,0.1)'   },
    { Icon: FaChalkboardTeacher, ar: 'هيئة التدريس',       en: 'Faculty Members',  value: stats.faculty,   color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)'  },
    { Icon: FaBriefcase,         ar: 'المناصب الوظيفية',  en: 'Positions',         value: stats.positions, color: '#3b82f6', bg: 'rgba(59,130,246,0.1)'  },
  ]

  return (
    <>
      <motion.div
        className="flex items-center justify-between gap-4 bg-white border border-primary/15 rounded-[18px] px-7 py-[22px] mb-7 relative overflow-hidden shadow-[0_4px_24px_rgba(86,153,51,0.08)]"
        initial={{ opacity: 0, y: -14 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45 }}
      >
        <div className="absolute top-0 left-0 right-0 h-1 animate-[barFlow_4s_linear_infinite]"
          style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327,#569933)', backgroundSize: '250% 100%' }} />
        <div dir="rtl">
          <h2 className="text-[21px] font-black text-text-dark mb-1">
            الموارد البشرية
            <span className="text-[14px] font-medium text-text-light mr-2">Human Resources</span>
          </h2>
          <p className="text-[12.5px] text-text-light">{dateStr}</p>
        </div>
        <div className="flex items-center gap-2 py-2 px-4 rounded-[12px] bg-primary/7 border border-primary/16 flex-shrink-0" dir="rtl">
          <div className="w-8 h-8 rounded-full flex items-center justify-center text-[13px] font-black text-white" style={{ background: 'linear-gradient(135deg,#569933,#2d5c18)' }}>
            {(user.username?.[0] ?? 'U').toUpperCase()}
          </div>
          <div className="flex flex-col">
            <span className="text-[12px] font-bold text-primary-dark">{user.username}</span>
            <span className="text-[9px] text-text-light">موظف الموارد البشرية</span>
          </div>
        </div>
      </motion.div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-6 text-[13px] text-red-600" dir="rtl">⚠ {error}</div>
      )}

      <div className="grid grid-cols-3 max-[820px]:grid-cols-2 max-[500px]:grid-cols-1 gap-5 mb-8">
        {TOP_STATS.map(({ Icon, ar, en, value, color, bg }, i) => (
          <motion.div key={i}
            className="bg-white border border-primary/12 rounded-[16px] px-[18px] py-5 flex items-center gap-4 relative overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)] transition-all duration-[220ms] hover:-translate-y-[3px] hover:shadow-[0_8px_28px_rgba(26,46,16,0.1)]"
            initial={{ opacity: 0, y: 18 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4, delay: 0.06 + i * 0.08 }}
          >
            <div className="absolute left-0 top-0 bottom-0 w-1" style={{ background: color }} />
            <div className="w-[52px] h-[52px] rounded-[13px] flex items-center justify-center text-[22px] flex-shrink-0" style={{ background: bg, color }}>
              {loading ? <FaSpinner className="animate-spin" /> : <Icon />}
            </div>
            <div className="flex flex-col" dir="rtl">
              <span className="text-[28px] font-black text-text-dark leading-[1.1] mb-[3px]">{loading ? '…' : value}</span>
              <span className="text-[13px] font-bold text-text-dark">{ar}</span>
              <span className="text-[10px] text-text-light mt-px">{en}</span>
            </div>
          </motion.div>
        ))}
      </div>

      <motion.section className="mb-8" initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45, delay: 0.3 }}>
        <div className="flex items-baseline gap-2.5 mb-4" dir="rtl">
          <h3 className="text-[16.5px] font-extrabold text-text-dark">حالات الموظفين</h3>
          <span className="text-[11.5px] text-text-light">Employee Statuses</span>
        </div>
        <div className="grid grid-cols-4 max-[700px]:grid-cols-2 max-[430px]:grid-cols-1 gap-3">
          {Object.entries(STATUS_CONFIG).map(([code, { ar, color, bg, Icon }], i) => (
            <motion.div key={code}
              className="flex items-center gap-3 px-4 py-3 bg-white border border-primary/10 rounded-[12px] shadow-[0_1px_6px_rgba(26,46,16,0.04)]"
              dir="rtl"
              initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.3, delay: 0.4 + i * 0.06 }}
            >
              <div className="w-9 h-9 rounded-[9px] flex items-center justify-center text-[15px] flex-shrink-0" style={{ background: bg, color }}>
                <Icon />
              </div>
              <div>
                <p className="text-[13px] font-bold text-text-dark">{ar}</p>
                <p className="text-[10px] text-text-light">{code}</p>
              </div>
            </motion.div>
          ))}
        </div>
      </motion.section>

      <motion.section initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45, delay: 0.45 }}>
        <div className="flex items-baseline gap-2.5 mb-4" dir="rtl">
          <h3 className="text-[16.5px] font-extrabold text-text-dark">الإجراءات السريعة</h3>
          <span className="text-[11.5px] text-text-light">Quick Actions</span>
        </div>
        <div className="grid grid-cols-2 max-[500px]:grid-cols-1 gap-3.5">
          {[
            { Icon: FaUserPlus,          ar: 'إضافة موظف جديد',  en: 'Add New Employee', to: '/hr/employees/add', color: '#569933' },
            { Icon: FaUsers,             ar: 'قائمة الموظفين',   en: 'Employees List',   to: '/hr/employees',     color: '#3b82f6' },
            { Icon: FaChalkboardTeacher, ar: 'هيئة التدريس',      en: 'Faculty Members',  to: '/hr/faculty',       color: '#8b5cf6' },
            { Icon: FaBriefcase,         ar: 'المناصب الوظيفية', en: 'Positions',         to: '/hr/positions',     color: '#f59e0b' },
          ].map(({ Icon, ar, en, to, color }, i) => (
            <Link key={i} to={to}
              className="bg-white border border-primary/12 rounded-[14px] px-5 py-4 flex items-center gap-3.5 no-underline relative overflow-hidden shadow-[0_2px_8px_rgba(26,46,16,0.04)] transition-all duration-[220ms] group hover:border-[var(--ac)] hover:-translate-y-[3px] hover:shadow-[0_6px_20px_rgba(26,46,16,0.1)]"
              style={{ '--ac': color, direction: 'rtl' }}
            >
              <div className="absolute bottom-0 left-0 right-0 h-[3px] scale-x-0 transition-transform duration-[220ms] group-hover:scale-x-100" style={{ background: color }} />
              <div className="w-[44px] h-[44px] rounded-[12px] flex items-center justify-center text-[18px] flex-shrink-0" style={{ background: `${color}1a`, color }}>
                <Icon />
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-[14px] font-bold text-text-dark">{ar}</span>
                <span className="text-[10.5px] text-text-light">{en}</span>
              </div>
              <span className="mr-auto text-[16px] text-[#c8dabb] transition-all duration-200 group-hover:text-[var(--ac)] group-hover:-translate-x-1">←</span>
            </Link>
          ))}
        </div>
      </motion.section>
    </>
  )
}
