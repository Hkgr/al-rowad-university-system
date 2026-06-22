import { useState, useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaGraduationCap, FaUniversity, FaBook,
  FaUserPlus, FaUsers, FaSpinner,
  FaCheckCircle, FaSnowflake, FaBan, FaUserTimes, FaPauseCircle,
} from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
  }
}

// Paginated response helper: { success, data: { data: [], meta: {} } }
const toArr  = (d) => Array.isArray(d) ? d : (Array.isArray(d?.data) ? d.data : [])
const toMeta = (d) => d?.meta ?? d ?? {}

const STATUS_CONFIG = {
  active:     { ar: 'مقيّد',      color: '#22c55e', bg: 'rgba(34,197,94,0.1)',    Icon: FaCheckCircle  },
  frozen:     { ar: 'منقطع',      color: '#3b82f6', bg: 'rgba(59,130,246,0.1)',   Icon: FaSnowflake    },
  graduated:  { ar: 'خرّيج',      color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)',   Icon: FaGraduationCap},
  withdrawn:  { ar: 'مسحوب',      color: '#f59e0b', bg: 'rgba(245,158,11,0.1)',   Icon: FaUserTimes    },
  dismissed:  { ar: 'مفصول',      color: '#ef4444', bg: 'rgba(239,68,68,0.1)',    Icon: FaBan          },
  suspended:  { ar: 'موقوف',      color: '#f97316', bg: 'rgba(249,115,22,0.1)',   Icon: FaPauseCircle  },
}

export default function StudentAffairsHome() {
  const navigate = useNavigate()
  const user     = JSON.parse(localStorage.getItem('user') || '{}')

  const [stats,    setStats]    = useState({ total: 0, programs: 0, colleges: 0 })
  const [colleges, setColleges] = useState([])
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState('')

  useEffect(() => {
    let cancelled = false

    async function load() {
      try {
        const [studentsRes, collegesRes, departmentsRes, programsRes] = await Promise.all([
          fetch(`${API}/students?per_page=1`,              { headers: authHeaders() }),
          fetch(`${API}/colleges?per_page=100`,            { headers: authHeaders() }),
          fetch(`${API}/departments?per_page=100`,         { headers: authHeaders() }),
          fetch(`${API}/academic-programs?per_page=100`,   { headers: authHeaders() }),
        ])

        if ([studentsRes, collegesRes, departmentsRes, programsRes].some(r => r.status === 401)) {
          navigate('/login')
          return
        }

        const [sJson, cJson, dJson, pJson] = await Promise.all([
          studentsRes.json(), collegesRes.json(), departmentsRes.json(), programsRes.json(),
        ])

        if (cancelled) return

        const collegeList     = toArr(cJson.data)
        const departmentList  = toArr(dJson.data)
        const programList     = toArr(pJson.data)
        const totalStudents   = toMeta(sJson.data).total ?? 0

        // Build map: college_id → { college, programs[] }
        const deptToCollege = {}
        departmentList.forEach(d => { deptToCollege[d.department_id] = d.college_id })

        const collegePrograms = {}
        collegeList.forEach(c => { collegePrograms[c.college_id] = [] })

        programList.forEach(p => {
          const cid = deptToCollege[p.department_id]
          if (cid && collegePrograms[cid]) {
            collegePrograms[cid].push(p)
          }
        })

        const enriched = collegeList.map(c => ({
          ...c,
          programs: collegePrograms[c.college_id] ?? [],
        }))

        setStats({ total: totalStudents, programs: programList.length, colleges: collegeList.length })
        setColleges(enriched)
        setError('')
      } catch {
        if (!cancelled) setError('تعذّر الاتصال بالخادم. تأكد أن php artisan serve يعمل.')
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()
    return () => { cancelled = true }
  }, [navigate])

  const dateStr = new Date().toLocaleDateString('ar-SY', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
  })

  const TOP_STATS = [
    { Icon: FaGraduationCap, ar: 'إجمالي الطلاب', en: 'Total Students',     value: stats.total,    color: '#569933', bg: 'rgba(86,153,51,0.1)'  },
    { Icon: FaBook,          ar: 'البرامج الأكاديمية', en: 'Academic Programs', value: stats.programs, color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)' },
    { Icon: FaUniversity,    ar: 'الكليات',        en: 'Colleges',          value: stats.colleges, color: '#3b82f6', bg: 'rgba(59,130,246,0.1)'  },
  ]

  return (
    <>
      {/* Welcome banner */}
      <motion.div
        className="flex items-center justify-between gap-4 bg-white border border-primary/15 rounded-[18px] px-7 py-[22px] mb-7 relative overflow-hidden shadow-[0_4px_24px_rgba(86,153,51,0.08)]"
        initial={{ opacity: 0, y: -14 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.45 }}
      >
        <div className="absolute top-0 left-0 right-0 h-1 animate-[barFlow_4s_linear_infinite]"
          style={{ background: 'linear-gradient(90deg, #569933, #7ab356, #a8d68a, #7ab356, #417327, #569933)', backgroundSize: '250% 100%' }}
        />
        <div dir="rtl">
          <h2 className="text-[21px] font-black text-text-dark mb-1">
            شؤون الطلاب
            <span className="text-[14px] font-medium text-text-light mr-2">Student Affairs</span>
          </h2>
          <p className="text-[12.5px] text-text-light">{dateStr}</p>
        </div>
        <div className="flex items-center gap-2 py-2 px-4 rounded-[12px] bg-primary/7 border border-primary/16 flex-shrink-0" dir="rtl">
          <div className="w-8 h-8 rounded-full flex items-center justify-center text-[13px] font-black text-white" style={{ background: 'linear-gradient(135deg, #569933, #2d5c18)' }}>
            {(user.username?.[0] ?? 'U').toUpperCase()}
          </div>
          <div className="flex flex-col">
            <span className="text-[12px] font-bold text-primary-dark">{user.username}</span>
            <span className="text-[9px] text-text-light">موظف شؤون الطلاب</span>
          </div>
        </div>
      </motion.div>

      {/* Error */}
      {error && (
        <div className="flex items-center gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-5 py-3.5 mb-6 text-[13.5px] text-red-600" dir="rtl">
          <span>⚠ {error}</span>
        </div>
      )}

      {/* Top stats */}
      <div className="grid grid-cols-3 max-[820px]:grid-cols-2 max-[500px]:grid-cols-1 gap-5 mb-8">
        {TOP_STATS.map(({ Icon, ar, en, value, color, bg }, i) => (
          <motion.div
            key={i}
            className="bg-white border border-primary/12 rounded-[16px] px-[18px] py-5 flex items-center gap-4 relative overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)] transition-all duration-[220ms] hover:-translate-y-[3px] hover:shadow-[0_8px_28px_rgba(26,46,16,0.1)]"
            initial={{ opacity: 0, y: 18 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.06 + i * 0.08 }}
          >
            <div className="absolute left-0 top-0 bottom-0 w-1" style={{ background: color }} />
            <div className="w-[52px] h-[52px] rounded-[13px] flex items-center justify-center text-[22px] flex-shrink-0" style={{ background: bg, color }}>
              {loading ? <FaSpinner className="animate-[spin_0.7s_linear_infinite]" /> : <Icon />}
            </div>
            <div className="flex flex-col" dir="rtl">
              <span className="text-[28px] font-black text-text-dark leading-[1.1] mb-[3px]">
                {loading ? '…' : value}
              </span>
              <span className="text-[13px] font-bold text-text-dark">{ar}</span>
              <span className="text-[10px] text-text-light mt-px">{en}</span>
            </div>
          </motion.div>
        ))}
      </div>

      {/* Colleges + Programs section */}
      <motion.section
        className="mb-8"
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.45, delay: 0.3 }}
      >
        <div className="flex items-baseline gap-2.5 mb-4" dir="rtl">
          <h3 className="text-[16.5px] font-extrabold text-text-dark">الكليات والبرامج</h3>
          <span className="text-[11.5px] text-text-light">Colleges &amp; Programs</span>
        </div>

        {loading ? (
          <div className="flex items-center justify-center gap-3 py-14 text-primary-light text-[14px] font-medium">
            <FaSpinner className="text-[24px] animate-[spin_0.7s_linear_infinite]" />
            <span>جاري التحميل…</span>
          </div>
        ) : colleges.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-14 gap-2 text-text-light bg-white rounded-[16px] border border-dashed border-primary/20">
            <FaUniversity className="text-[40px] text-[#c8dab8]" />
            <p className="text-[14px] font-semibold text-text-gray" dir="rtl">لم يتم إضافة كليات بعد</p>
            <p className="text-[11.5px]">No colleges added yet</p>
          </div>
        ) : (
          <div className="grid grid-cols-2 max-[820px]:grid-cols-1 gap-5">
            {colleges.map((college, i) => (
              <motion.div
                key={college.college_id}
                className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)] transition-all duration-[220ms] hover:-translate-y-[2px] hover:shadow-[0_6px_24px_rgba(86,153,51,0.1)]"
                initial={{ opacity: 0, y: 14 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.38, delay: 0.35 + i * 0.07 }}
              >
                {/* College header */}
                <div className="flex items-center gap-3 mb-4 pb-3.5 border-b border-primary/10" dir="rtl">
                  <div className="w-[44px] h-[44px] rounded-[11px] bg-primary/10 flex items-center justify-center text-[18px] text-primary flex-shrink-0">
                    <FaUniversity />
                  </div>
                  <div className="flex-1 min-w-0">
                    <h4 className="text-[14.5px] font-black text-text-dark leading-tight truncate">{college.college_name}</h4>
                    <span className="text-[10px] font-mono text-primary-light bg-primary/8 border border-primary/15 rounded-[5px] px-1.5 py-[2px] inline-block mt-0.5">{college.college_code}</span>
                  </div>
                  <div className="text-right flex-shrink-0" dir="rtl">
                    <span className="text-[20px] font-black text-primary">{college.programs.length}</span>
                    <span className="block text-[9.5px] text-text-light">برنامج</span>
                  </div>
                </div>

                {/* Programs list */}
                {college.programs.length === 0 ? (
                  <p className="text-[12px] text-text-light text-center py-3" dir="rtl">لا توجد برامج مضافة بعد</p>
                ) : (
                  <div className="flex flex-col gap-2">
                    {college.programs.map(prog => (
                      <div
                        key={prog.academic_program_id}
                        className="flex items-center justify-between gap-3 px-3.5 py-2.5 bg-primary/[0.035] border border-primary/10 rounded-[10px]"
                        dir="rtl"
                      >
                        <div className="flex items-center gap-2.5 min-w-0">
                          <FaBook className="text-primary-light text-[13px] flex-shrink-0" />
                          <div className="min-w-0">
                            <p className="text-[13px] font-semibold text-text-dark truncate">{prog.program_name}</p>
                            <p className="text-[10px] text-text-light">{prog.degree_level} · {prog.duration_years} سنوات</p>
                          </div>
                        </div>
                        <span className="text-[10px] font-mono text-text-light bg-white border border-primary/15 rounded-[5px] px-1.5 py-[2px] flex-shrink-0">{prog.program_code}</span>
                      </div>
                    ))}
                  </div>
                )}
              </motion.div>
            ))}
          </div>
        )}
      </motion.section>

      {/* Student statuses legend */}
      <motion.section
        className="mb-8"
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.45, delay: 0.45 }}
      >
        <div className="flex items-baseline gap-2.5 mb-4" dir="rtl">
          <h3 className="text-[16.5px] font-extrabold text-text-dark">حالات الطلاب</h3>
          <span className="text-[11.5px] text-text-light">Student Statuses</span>
        </div>
        <div className="grid grid-cols-3 max-[700px]:grid-cols-2 max-[430px]:grid-cols-1 gap-3">
          {Object.entries(STATUS_CONFIG).map(([code, { ar, color, bg, Icon }], i) => (
            <motion.div
              key={code}
              className="flex items-center gap-3 px-4 py-3 bg-white border border-primary/10 rounded-[12px] shadow-[0_1px_6px_rgba(26,46,16,0.04)]"
              dir="rtl"
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ duration: 0.3, delay: 0.5 + i * 0.05 }}
            >
              <div className="w-9 h-9 rounded-[9px] flex items-center justify-center text-[15px] flex-shrink-0" style={{ background: bg, color }}>
                <Icon />
              </div>
              <div dir="rtl">
                <p className="text-[13px] font-bold text-text-dark">{ar}</p>
                <p className="text-[10px] text-text-light capitalize">{code}</p>
              </div>
            </motion.div>
          ))}
        </div>
      </motion.section>

      {/* Quick actions */}
      <motion.section
        initial={{ opacity: 0, y: 14 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.45, delay: 0.55 }}
      >
        <div className="flex items-baseline gap-2.5 mb-4" dir="rtl">
          <h3 className="text-[16.5px] font-extrabold text-text-dark">الإجراءات السريعة</h3>
          <span className="text-[11.5px] text-text-light">Quick Actions</span>
        </div>
        <div className="grid grid-cols-2 max-[500px]:grid-cols-1 gap-3.5">
          {[
            { Icon: FaUserPlus, ar: 'إضافة طالب جديد', en: 'Add New Student', to: '/student-affairs/students/add', color: '#569933' },
            { Icon: FaUsers,    ar: 'قائمة الطلاب',    en: 'Students List',   to: '/student-affairs/students',     color: '#3b82f6' },
          ].map(({ Icon, ar, en, to, color }, i) => (
            <Link
              key={i}
              to={to}
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
