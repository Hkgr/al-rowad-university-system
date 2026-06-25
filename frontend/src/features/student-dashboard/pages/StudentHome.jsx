import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaGraduationCap, FaChartBar, FaCalendarCheck, FaClipboardList,
  FaSpinner, FaUniversity,
} from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function getStudentId() {
  return JSON.parse(localStorage.getItem('user') || '{}').student_id
}

const QUICK_LINKS = [
  { to: '/student/transcript', Icon: FaClipboardList, ar: 'كشف الدرجات',    en: 'Transcript',  color: '#569933' },
  { to: '/student/gpa',        Icon: FaChartBar,      ar: 'المعدل',          en: 'GPA',         color: '#3b82f6' },
  { to: '/student/attendance', Icon: FaCalendarCheck, ar: 'الحضور والغياب', en: 'Attendance',  color: '#f59e0b' },
]

export default function StudentHome() {
  const [profile,    setProfile]    = useState(null)
  const [cgpa,       setCgpa]       = useState(null)
  const [attendance, setAttendance] = useState(null)
  const [loading,    setLoading]    = useState(true)

  useEffect(() => {
    const id = getStudentId()
    if (!id) return
    Promise.all([
      fetch(`${API}/students/${id}/profile`,    { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/students/${id}/cgpa`,       { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/students/${id}/attendance`, { headers: authHeaders() }).then(r => r.json()),
    ]).then(([prof, cgpaRes, attRes]) => {
      if (prof.success)    setProfile(prof.data)
      if (cgpaRes.success) setCgpa(cgpaRes.data)
      if (attRes.success)  setAttendance(attRes.data)
    }).finally(() => setLoading(false))
  }, [])

  const user      = JSON.parse(localStorage.getItem('user') || '{}')
  const dateStr   = new Date().toLocaleDateString('ar-SY', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
  const cgpaVal   = cgpa?.cgpa ?? null
  const cgpaColor = cgpaVal === null ? '#569933' : cgpaVal >= 3.7 ? '#16a34a' : cgpaVal >= 3.0 ? '#3b82f6' : cgpaVal >= 2.0 ? '#f59e0b' : '#ef4444'

  const totalCourses  = attendance?.courses?.length ?? 0
  const deprivedCount = attendance?.courses?.filter(c => c.deprivation_status === 'deprived').length ?? 0

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24 gap-3 text-primary-light">
        <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" />
        <span className="text-[14px] font-medium">جاري تحميل بياناتك…</span>
      </div>
    )
  }

  return (
    <>
      {/* Welcome banner */}
      <motion.div
        className="relative bg-white border border-primary/15 rounded-[18px] px-7 py-6 mb-6 overflow-hidden shadow-[0_4px_24px_rgba(86,153,51,0.08)]"
        initial={{ opacity: 0, y: -14 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.45 }}
      >
        <div className="absolute top-0 left-0 right-0 h-1 animate-[barFlow_4s_linear_infinite]"
          style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327,#569933)', backgroundSize: '250% 100%' }} />
        <div className="flex items-center justify-between gap-4 flex-wrap" dir="rtl">
          <div>
            <h2 className="text-[22px] font-black text-text-dark mb-1">
              مرحباً، {profile?.first_name || user.username} 👋
            </h2>
            <p className="text-[13px] text-text-gray">{dateStr}</p>
            {profile && (
              <div className="flex items-center gap-2 mt-2 flex-wrap">
                <span className="text-[12px] text-text-light bg-primary/6 border border-primary/15 px-2.5 py-1 rounded-full font-mono font-bold text-primary-dark">
                  {profile.student_number}
                </span>
                <span className="text-[12px] text-text-gray">{profile.program?.program_name}</span>
                <span className="text-primary/30">•</span>
                <span className="text-[12px] text-text-gray">{profile.academic_level?.level_name}</span>
              </div>
            )}
          </div>
          <div className="flex flex-col items-center px-5 py-3.5 rounded-[14px] bg-primary/[0.05] border border-primary/15 flex-shrink-0">
            <FaUniversity className="text-[22px] text-primary mb-1" />
            <span className="text-[11.5px] font-bold text-primary-dark">جامعة الرواد</span>
            <span className="text-[10px] text-text-light">Al-Rowad University</span>
          </div>
        </div>
      </motion.div>

      {/* Stat cards */}
      <div className="grid grid-cols-3 max-[700px]:grid-cols-1 gap-4 mb-6">
        {/* CGPA */}
        <motion.div
          className="bg-white border border-primary/12 rounded-[16px] px-5 py-5 flex items-center gap-4 relative overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)]"
          initial={{ opacity: 0, y: 18 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4, delay: 0.08 }}
        >
          <div className="absolute left-0 top-0 bottom-0 w-1 rounded-r-full" style={{ background: cgpaColor }} />
          <div className="w-[52px] h-[52px] rounded-[13px] flex items-center justify-center text-[22px] flex-shrink-0" style={{ background: `${cgpaColor}18`, color: cgpaColor }}>
            <FaChartBar />
          </div>
          <div dir="rtl">
            <div className="text-[26px] font-black leading-none mb-0.5" style={{ color: cgpaColor }}>
              {cgpaVal !== null ? Number(cgpaVal).toFixed(2) : '—'}
            </div>
            <div className="text-[12.5px] font-bold text-text-dark">المعدل التراكمي</div>
            <div className="text-[10.5px] text-text-light">Cumulative GPA / 4.0</div>
          </div>
        </motion.div>

        {/* Courses */}
        <motion.div
          className="bg-white border border-primary/12 rounded-[16px] px-5 py-5 flex items-center gap-4 relative overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)]"
          initial={{ opacity: 0, y: 18 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4, delay: 0.15 }}
        >
          <div className="absolute left-0 top-0 bottom-0 w-1 rounded-r-full bg-blue-500" />
          <div className="w-[52px] h-[52px] rounded-[13px] flex items-center justify-center text-[22px] flex-shrink-0 bg-blue-500/10 text-blue-600">
            <FaGraduationCap />
          </div>
          <div dir="rtl">
            <div className="text-[26px] font-black text-blue-600 leading-none mb-0.5">{totalCourses}</div>
            <div className="text-[12.5px] font-bold text-text-dark">المقررات المسجّلة</div>
            <div className="text-[10.5px] text-text-light">Registered Courses</div>
          </div>
        </motion.div>

        {/* Attendance warning */}
        <motion.div
          className={`bg-white border rounded-[16px] px-5 py-5 flex items-center gap-4 relative overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)] ${deprivedCount > 0 ? 'border-red-500/20' : 'border-primary/12'}`}
          initial={{ opacity: 0, y: 18 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4, delay: 0.22 }}
        >
          <div className={`absolute left-0 top-0 bottom-0 w-1 rounded-r-full ${deprivedCount > 0 ? 'bg-red-500' : 'bg-amber-400'}`} />
          <div className={`w-[52px] h-[52px] rounded-[13px] flex items-center justify-center text-[22px] flex-shrink-0 ${deprivedCount > 0 ? 'bg-red-500/10 text-red-500' : 'bg-amber-400/15 text-amber-500'}`}>
            <FaCalendarCheck />
          </div>
          <div dir="rtl">
            <div className={`text-[26px] font-black leading-none mb-0.5 ${deprivedCount > 0 ? 'text-red-500' : 'text-amber-500'}`}>
              {deprivedCount}
            </div>
            <div className="text-[12.5px] font-bold text-text-dark">مقررات محرومة</div>
            <div className="text-[10.5px] text-text-light">Deprived Courses</div>
          </div>
        </motion.div>
      </div>

      {/* Quick links */}
      <motion.div
        initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4, delay: 0.3 }}
      >
        <div className="flex items-baseline gap-2 mb-3.5" dir="rtl">
          <h3 className="text-[16px] font-extrabold text-text-dark">الوصول السريع</h3>
          <span className="text-[11px] text-text-light">Quick Access</span>
        </div>
        <div className="grid grid-cols-3 max-[600px]:grid-cols-1 gap-3.5">
          {QUICK_LINKS.map(({ to, Icon, ar, en, color }) => (
            <Link
              key={to}
              to={to}
              className="bg-white border border-primary/12 rounded-[14px] px-4 py-4 flex items-center gap-3 no-underline relative overflow-hidden shadow-[0_2px_8px_rgba(26,46,16,0.04)] transition-all duration-[220ms] group hover:-translate-y-[3px] hover:shadow-[0_6px_20px_rgba(26,46,16,0.1)]"
              style={{ '--ac': color, direction: 'rtl' }}
            >
              <div className="absolute bottom-0 left-0 right-0 h-[3px] scale-x-0 transition-transform duration-[220ms] group-hover:scale-x-100" style={{ background: color }} />
              <div className="w-[44px] h-[44px] rounded-[11px] flex items-center justify-center text-[18px] flex-shrink-0" style={{ background: `${color}18`, color }}>
                <Icon />
              </div>
              <div>
                <div className="text-[13.5px] font-bold text-text-dark">{ar}</div>
                <div className="text-[10.5px] text-text-light">{en}</div>
              </div>
              <span className="mr-auto text-[14px] text-[#c8dabb] transition-all duration-200 group-hover:text-[var(--ac)] group-hover:-translate-x-1">←</span>
            </Link>
          ))}
        </div>
      </motion.div>
    </>
  )
}
