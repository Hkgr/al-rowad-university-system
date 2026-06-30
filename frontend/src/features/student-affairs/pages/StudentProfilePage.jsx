import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaArrowRight, FaEdit, FaSpinner, FaUser,
  FaGraduationCap, FaChartBar, FaCalendarCheck, FaCheckCircle,
} from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
  }
}

async function get(url) {
  const r = await fetch(url, { headers: authHeaders() })
  return r.json()
}

function fmt(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('ar-SY', { year: 'numeric', month: 'long', day: 'numeric' })
}

function gradeColor(letter) {
  if (!letter) return 'text-text-gray'
  const l = letter.toUpperCase()
  if (l.startsWith('A')) return 'text-green-600'
  if (l.startsWith('B')) return 'text-blue-600'
  if (l.startsWith('C')) return 'text-amber-600'
  if (l.startsWith('D')) return 'text-orange-500'
  return 'text-red-600'
}

const TABS = [
  { id: 'info',       ar: 'المعلومات الشخصية', Icon: FaUser          },
  { id: 'transcript', ar: 'كشف الدرجات',        Icon: FaGraduationCap },
  { id: 'gpa',        ar: 'المعدل',              Icon: FaChartBar      },
  { id: 'attendance', ar: 'الحضور والغياب',      Icon: FaCalendarCheck },
]

// ── All sub-components defined OUTSIDE the main component ─────────────────────

function InfoField({ label, value }) {
  return (
    <div className="flex flex-col gap-1" dir="rtl">
      <span className="text-[11px] font-bold text-text-light uppercase tracking-wide">{label}</span>
      <span className="text-[14px] font-semibold text-text-dark">{value || '—'}</span>
    </div>
  )
}

function SectionTitle({ ar, en }) {
  return (
    <div className="flex items-baseline gap-2 mb-4 pb-2.5 border-b border-primary/12" dir="rtl">
      <h3 className="text-[15px] font-extrabold text-text-dark">{ar}</h3>
      {en && <span className="text-[11px] text-text-light">{en}</span>}
    </div>
  )
}

function PersonalInfoTab({ profile }) {
  const personal = [
    { label: 'الاسم الأول',        value: profile.first_name },
    { label: 'اسم الأب',           value: profile.father_name },
    { label: 'اسم الأم',           value: profile.mother_name },
    { label: 'اللقب (الكنية)',     value: profile.last_name },
    { label: 'تاريخ الميلاد',     value: fmt(profile.date_of_birth) },
    { label: 'الجنس',              value: profile.gender === 'male' ? 'ذكر' : profile.gender === 'female' ? 'أنثى' : profile.gender },
    { label: 'رقم الهاتف',        value: profile.phone_number },
    { label: 'البريد الإلكتروني', value: profile.email },
    { label: 'العنوان',            value: profile.address },
    { label: 'الجنسية',            value: profile.nationality },
  ]
  const academic = [
    { label: 'البرنامج الأكاديمي', value: profile.program?.program_name },
    { label: 'الكلية',              value: profile.college?.college_name },
    { label: 'القسم',               value: profile.department?.department_name },
    { label: 'المستوى الدراسي',    value: profile.academic_level?.level_name },
    { label: 'تاريخ القبول',       value: fmt(profile.enrollment_date) },
    { label: 'رمز البرنامج',       value: profile.program?.program_code },
  ]
  return (
    <div className="space-y-7">
      <div>
        <SectionTitle ar="البيانات الشخصية" en="Personal Details" />
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
          {personal.map(f => <InfoField key={f.label} {...f} />)}
        </div>
      </div>
      <div>
        <SectionTitle ar="البيانات الأكاديمية" en="Academic Details" />
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
          {academic.map(f => <InfoField key={f.label} {...f} />)}
        </div>
      </div>
    </div>
  )
}

function TranscriptTab({ transcript }) {
  if (!transcript?.terms?.length) {
    return (
      <div className="flex flex-col items-center justify-center py-16 gap-2" dir="rtl">
        <FaGraduationCap className="text-[40px] text-primary/20 mb-1" />
        <p className="text-[14px] font-semibold text-text-light">لا توجد بيانات دراسية بعد</p>
      </div>
    )
  }
  return (
    <div className="space-y-5">
      {transcript.terms.map((term, ti) => (
        <div key={ti} className="border border-primary/12 rounded-[14px] overflow-hidden">
          <div className="flex items-center justify-between px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
            <div className="flex items-center gap-2">
              <span className="text-[14px] font-extrabold text-primary-dark">{term.academic_year?.year_name}</span>
              <span className="text-text-light text-[12px]">•</span>
              <span className="text-[13px] font-semibold text-text-dark">{term.semester?.semester_name}</span>
            </div>
            <span className="text-[11.5px] text-text-light">
              {term.courses.reduce((s, c) => s + (c.credit_hours || 0), 0)} ساعة معتمدة
            </span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[13px]">
              <thead>
                <tr className="bg-[#fafaf8]">
                  <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">المقرر</th>
                  <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الساعات</th>
                  <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">نظري</th>
                  <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">عملي</th>
                  <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">المجموع</th>
                  <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
                  <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
                </tr>
              </thead>
              <tbody>
                {term.courses.map((c, ci) => (
                  <tr key={ci} className="border-t border-primary/6 hover:bg-primary/[0.025] transition-colors">
                    <td className="px-4 py-3" dir="rtl">
                      <div className="font-semibold text-text-dark text-[13.5px]">{c.course_name}</div>
                      <div className="text-[11px] text-text-light font-mono mt-0.5">{c.course_code}</div>
                    </td>
                    <td className="px-4 py-3 text-center font-bold text-text-dark">{c.credit_hours}</td>
                    <td className="px-4 py-3 text-center text-text-gray">{c.theoretical_mark ?? '—'}</td>
                    <td className="px-4 py-3 text-center text-text-gray">{c.practical_mark ?? '—'}</td>
                    <td className="px-4 py-3 text-center font-bold text-text-dark">{c.final_mark ?? '—'}</td>
                    <td className={`px-4 py-3 text-center text-[16px] font-black ${gradeColor(c.letter_grade)}`}>
                      {c.letter_grade || '—'}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${
                        c.result_status?.status_code === 'passed'
                          ? 'bg-green-500/10 text-green-700 border-green-500/25'
                          : 'bg-red-500/10 text-red-600 border-red-500/25'
                      }`} dir="rtl">
                        {c.result_status?.status_code === 'passed' ? 'ناجح' : (c.result_status?.status_name || '—')}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ))}
    </div>
  )
}

function GPATab({ studentId, cgpa, academicYears, semesters }) {
  const [yearId, setYearId]           = useState('')
  const [semId, setSemId]             = useState('')
  const [termGPA, setTermGPA]         = useState(null)
  const [gpaLoading, setGpaLoading]   = useState(false)
  const [gpaError, setGpaError]       = useState('')

  async function calcGPA() {
    if (!yearId || !semId) return
    setGpaLoading(true)
    setGpaError('')
    setTermGPA(null)
    try {
      const d = await get(`${API}/students/${studentId}/gpa?academic_year_id=${yearId}&semester_id=${semId}`)
      if (d.success) setTermGPA(d.data)
      else setGpaError(d.message || 'فشل احتساب المعدل')
    } catch { setGpaError('تعذّر الاتصال') }
    finally { setGpaLoading(false) }
  }

  const cgpaVal   = cgpa?.cgpa ?? null
  const cgpaHours = cgpa?.total_included_credit_hours ?? 0
  const cgpaColor = cgpaVal === null ? 'text-text-gray'
    : cgpaVal >= 3.7 ? 'text-green-600'
    : cgpaVal >= 3.0 ? 'text-blue-600'
    : cgpaVal >= 2.0 ? 'text-amber-600'
    : 'text-red-600'

  return (
    <div className="space-y-7">
      {/* CGPA */}
      <div>
        <SectionTitle ar="المعدل التراكمي" en="Cumulative GPA" />
        <div className="flex items-stretch gap-4 flex-wrap">
          <div className="flex-1 min-w-[220px] bg-gradient-to-br from-primary/[0.06] to-primary/[0.02] border border-primary/15 rounded-[16px] px-6 py-5 flex items-center gap-5" dir="rtl">
            <div className={`text-[52px] font-black leading-none ${cgpaColor}`}>
              {cgpaVal !== null ? Number(cgpaVal).toFixed(2) : '—'}
            </div>
            <div className="flex flex-col gap-1">
              <span className="text-[13px] font-extrabold text-text-dark">المعدل التراكمي</span>
              <span className="text-[12px] text-text-light">Cumulative GPA (out of 4.0)</span>
              <span className="text-[12px] text-text-gray mt-1">{cgpaHours} ساعة معتمدة</span>
            </div>
          </div>
          <div className="flex flex-col justify-center items-center gap-1 px-6 py-4 border border-primary/12 rounded-[14px] bg-white" dir="rtl">
            <span className="text-[24px] font-black text-primary">{cgpa?.included_courses_count ?? 0}</span>
            <span className="text-[11px] text-text-light text-center">مقرر محتسب</span>
          </div>
        </div>
      </div>

      {/* Term GPA */}
      <div>
        <SectionTitle ar="معدل الفصل الدراسي" en="Term GPA" />
        <div className="flex items-end gap-3 flex-wrap" dir="rtl">
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">العام الدراسي</label>
            <select
              className="px-3 py-2 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] min-w-[160px]"
              value={yearId}
              onChange={e => { setYearId(e.target.value); setTermGPA(null) }}
              dir="rtl"
            >
              <option value="">اختر العام</option>
              {academicYears.map(y => (
                <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">الفصل الدراسي</label>
            <select
              className="px-3 py-2 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] min-w-[160px]"
              value={semId}
              onChange={e => { setSemId(e.target.value); setTermGPA(null) }}
              dir="rtl"
            >
              <option value="">اختر الفصل</option>
              {semesters.map(s => (
                <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>
              ))}
            </select>
          </div>
          <button
            className="flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-[10px] text-[13.5px] font-bold disabled:opacity-40 disabled:cursor-not-allowed hover:enabled:bg-primary-dark transition-colors"
            disabled={!yearId || !semId || gpaLoading}
            onClick={calcGPA}
          >
            {gpaLoading && <FaSpinner className="animate-spin" />}
            احتساب
          </button>
        </div>
        {gpaError && (
          <p className="mt-2.5 text-[12.5px] text-red-600" dir="rtl">⚠ {gpaError}</p>
        )}
        {termGPA && (
          <div className="mt-4 flex items-center gap-5 bg-blue-50 border border-blue-500/20 rounded-[14px] px-6 py-4" dir="rtl">
            <div className="text-[44px] font-black text-blue-600 leading-none">
              {Number(termGPA.gpa).toFixed(2)}
            </div>
            <div>
              <p className="text-[13px] font-extrabold text-text-dark">معدل الفصل</p>
              <p className="text-[12px] text-text-light mt-0.5">{termGPA.total_credit_hours} ساعة معتمدة</p>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

function AttendanceTab({ attendance }) {
  const courses = attendance?.courses || []
  if (!courses.length) {
    return (
      <div className="flex flex-col items-center justify-center py-16 gap-2" dir="rtl">
        <FaCalendarCheck className="text-[40px] text-primary/20 mb-1" />
        <p className="text-[14px] font-semibold text-text-light">لا توجد بيانات حضور بعد</p>
      </div>
    )
  }
  return (
    <div className="space-y-4">
      {courses.map((c, i) => {
        const pct      = c.absence_percentage || 0
        const deprived = c.deprivation_status === 'deprived'
        const warning  = !deprived && pct > 10
        return (
          <div
            key={i}
            className={`border rounded-[14px] p-5 ${
              deprived ? 'border-red-500/30 bg-red-500/[0.025]'
              : warning ? 'border-amber-500/30 bg-amber-500/[0.025]'
              : 'border-primary/12 bg-white'
            }`}
          >
            <div className="flex items-start justify-between gap-3 mb-3" dir="rtl">
              <div>
                <div className="font-bold text-[14px] text-text-dark">{c.course_name}</div>
                <div className="text-[11.5px] text-text-light font-mono mt-0.5">
                  {c.course_code} — {c.academic_year?.year_name} / {c.semester?.semester_name}
                </div>
              </div>
              {deprived && (
                <span className="flex-shrink-0 px-2.5 py-1 bg-red-500/10 border border-red-500/25 text-red-600 text-[11px] font-bold rounded-full">محروم</span>
              )}
              {warning && (
                <span className="flex-shrink-0 px-2.5 py-1 bg-amber-500/10 border border-amber-500/25 text-amber-700 text-[11px] font-bold rounded-full">تحذير غياب</span>
              )}
            </div>
            <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden mb-3">
              <div
                className={`h-full rounded-full transition-all duration-500 ${deprived ? 'bg-red-500' : warning ? 'bg-amber-400' : 'bg-primary'}`}
                style={{ width: `${Math.min(pct, 100)}%` }}
              />
            </div>
            <div className="flex items-center gap-5 text-[12.5px] flex-wrap" dir="rtl">
              <span className="text-text-gray">إجمالي: <strong className="text-text-dark">{c.total_sessions}</strong></span>
              <span className="text-green-600">حضور: <strong>{c.present_count}</strong></span>
              <span className="text-red-500">غياب: <strong>{c.absent_count}</strong></span>
              <span className={`font-bold ${deprived ? 'text-red-600' : warning ? 'text-amber-600' : 'text-text-dark'}`}>
                نسبة الغياب: {Number(pct).toFixed(1)}%
              </span>
            </div>
          </div>
        )
      })}
    </div>
  )
}

// ── Status badge helper ────────────────────────────────────────────────────────

const STATUS_STYLES = {
  active:    { bg: 'bg-green-500/10',  text: 'text-green-700',  border: 'border-green-500/25',  ar: 'نشط'     },
  inactive:  { bg: 'bg-gray-100',      text: 'text-gray-500',   border: 'border-gray-200',       ar: 'غير نشط' },
  suspended: { bg: 'bg-red-500/10',    text: 'text-red-600',    border: 'border-red-500/25',     ar: 'موقوف'   },
  graduated: { bg: 'bg-blue-500/10',   text: 'text-blue-700',   border: 'border-blue-500/25',    ar: 'خريج'    },
}

// ── Main page ──────────────────────────────────────────────────────────────────

export default function StudentProfilePage() {
  const { id }   = useParams()
  const navigate = useNavigate()

  const [loading,        setLoading]        = useState(true)
  const [error,          setError]          = useState('')
  const [profile,        setProfile]        = useState(null)
  const [transcript,     setTranscript]     = useState(null)
  const [cgpa,           setCgpa]           = useState(null)
  const [attendance,     setAttendance]     = useState(null)
  const [academicYears,  setAcademicYears]  = useState([])
  const [semesters,      setSemesters]      = useState([])
  const [activeTab,      setActiveTab]      = useState('info')
  const [graduating,     setGraduating]     = useState(false)
  const [graduateError,  setGraduateError]  = useState('')

  useEffect(() => {
    async function load() {
      setLoading(true)
      setError('')
      try {
        const [prof, trans, cgpaRes, att, years, sems] = await Promise.all([
          get(`${API}/students/${id}/profile`),
          get(`${API}/students/${id}/transcript`),
          get(`${API}/students/${id}/cgpa`),
          get(`${API}/students/${id}/attendance`),
          get(`${API}/academic-years`),
          get(`${API}/semesters`),
        ])
        if (!prof.success) { setError(prof.message || 'الطالب غير موجود'); return }
        setProfile(prof.data)
        setTranscript(trans.success ? trans.data : null)
        setCgpa(cgpaRes.success ? cgpaRes.data : null)
        setAttendance(att.success ? att.data : null)
        setAcademicYears(years.success ? (years.data?.data ?? years.data ?? []) : [])
        setSemesters(sems.success ? (sems.data?.data ?? sems.data ?? []) : [])
      } catch {
        setError('تعذّر الاتصال بالخادم. تأكد أن php artisan serve يعمل.')
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [id])

  async function handleGraduate() {
    if (!window.confirm(`هل تريد تخريج الطالب "${profile?.full_name}"؟\nسيتم تغيير حالته إلى "خريج".`)) return
    setGraduating(true)
    setGraduateError('')
    try {
      const res  = await fetch(`${API}/students/${id}`, {
        method:  'PUT',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body:    JSON.stringify({ student_status_id: 3 }),
      })
      const json = await res.json()
      if (json.success) {
        setProfile(prev => ({
          ...prev,
          student_status: { status_code: 'graduated', status_name: 'Graduated' },
        }))
      } else {
        setGraduateError(json.message || 'فشلت العملية')
      }
    } catch {
      setGraduateError('تعذّر الاتصال بالخادم')
    } finally {
      setGraduating(false)
    }
  }

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-primary-light">
        <FaSpinner className="text-[30px] animate-[spin_0.7s_linear_infinite]" />
        <span className="text-[14px] font-medium">جاري تحميل بيانات الطالب…</span>
      </div>
    )
  }

  if (error || !profile) {
    return (
      <div className="flex flex-col items-center justify-center gap-4 py-24" dir="rtl">
        <p className="text-[15px] text-red-600 font-bold">⚠ {error || 'الطالب غير موجود'}</p>
        <button
          className="px-5 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
          onClick={() => navigate('/student-affairs/students')}
        >
          العودة إلى قائمة الطلاب
        </button>
      </div>
    )
  }

  const sc = STATUS_STYLES[profile.student_status?.status_code] || {
    bg: 'bg-gray-100', text: 'text-gray-600', border: 'border-gray-200',
    ar: profile.student_status?.status_name,
  }

  const cgpaVal = cgpa?.cgpa ?? null

  return (
    <>
      {/* Top bar: back + actions */}
      <div className="flex items-center justify-between mb-5 gap-3 flex-wrap">
        <button
          className="flex items-center gap-2 text-[13.5px] font-semibold text-text-gray hover:text-primary transition-colors"
          onClick={() => navigate('/student-affairs/students')}
          dir="rtl"
        >
          <FaArrowRight />
          <span>قائمة الطلاب</span>
        </button>
        <div className="flex items-center gap-2 flex-wrap">
          {sc.ar !== 'خريج' && (
            <button
              className="flex items-center gap-2 px-4 py-2 bg-purple-500/10 border border-purple-500/25 text-purple-700 rounded-[10px] text-[13px] font-bold hover:bg-purple-500/18 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              onClick={handleGraduate}
              disabled={graduating}
              dir="rtl"
            >
              {graduating ? <FaSpinner className="animate-spin text-[12px]" /> : <FaGraduationCap className="text-[12px]" />}
              <span>تخريج الطالب</span>
            </button>
          )}
          {sc.ar === 'خريج' && (
            <span className="flex items-center gap-1.5 px-3 py-1.5 bg-purple-50 border border-purple-200 text-purple-700 rounded-[10px] text-[12px] font-bold" dir="rtl">
              <FaCheckCircle className="text-[11px]" /> تم التخريج
            </span>
          )}
          <button
            className="flex items-center gap-2 px-4 py-2 bg-amber-500/10 border border-amber-500/25 text-amber-700 rounded-[10px] text-[13px] font-bold hover:bg-amber-500/18 transition-colors"
            onClick={() => navigate(`/student-affairs/students/${id}/edit`)}
            dir="rtl"
          >
            <FaEdit />
            <span>تعديل البيانات</span>
          </button>
        </div>
      </div>

      {graduateError && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">
          ⚠ {graduateError}
        </div>
      )}

      {/* Student header card */}
      <motion.div
        className="bg-white border border-primary/12 rounded-[18px] px-6 py-5 mb-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)]"
        initial={{ opacity: 0, y: -12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        <div className="flex items-center gap-5 flex-wrap" dir="rtl">
          <div className="w-[68px] h-[68px] rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-[28px] text-primary flex-shrink-0">
            <FaUser />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap mb-1">
              <h2 className="text-[20px] font-black text-text-dark">{profile.full_name}</h2>
              <span className={`inline-flex items-center px-3 py-0.5 rounded-full text-[12px] font-bold border ${sc.bg} ${sc.text} ${sc.border}`}>
                {sc.ar}
              </span>
            </div>
            <div className="flex items-center gap-2.5 flex-wrap text-[12.5px] text-text-gray">
              <span className="font-mono bg-primary/7 border border-primary/15 px-2 py-0.5 rounded-[6px] text-primary-dark font-bold text-[12px]">
                {profile.student_number}
              </span>
              <span className="text-primary/30">•</span>
              <span>{profile.program?.program_name}</span>
              <span className="text-primary/30">•</span>
              <span>{profile.college?.college_name}</span>
              <span className="text-primary/30">•</span>
              <span>{profile.academic_level?.level_name}</span>
            </div>
          </div>
          {cgpaVal !== null && (
            <div className="flex flex-col items-center px-5 py-3 border border-primary/15 rounded-[14px] bg-primary/[0.035] flex-shrink-0" dir="rtl">
              <span className="text-[26px] font-black text-primary leading-none">{Number(cgpaVal).toFixed(2)}</span>
              <span className="text-[10.5px] text-text-light mt-0.5">المعدل التراكمي</span>
            </div>
          )}
        </div>
      </motion.div>

      {/* Tabbed content */}
      <div className="bg-white border border-primary/12 rounded-[18px] shadow-[0_2px_16px_rgba(26,46,16,0.06)] overflow-hidden">
        {/* Tab bar */}
        <div className="flex border-b border-primary/10 overflow-x-auto" dir="rtl">
          {TABS.map(tab => (
            <button
              key={tab.id}
              className={`flex items-center gap-2 px-5 py-3.5 text-[13px] font-bold whitespace-nowrap border-b-2 transition-all duration-[180ms] ${
                activeTab === tab.id
                  ? 'text-primary border-primary bg-primary/[0.04]'
                  : 'text-text-gray border-transparent hover:text-text-dark hover:bg-primary/[0.02]'
              }`}
              onClick={() => setActiveTab(tab.id)}
            >
              <tab.Icon />
              <span>{tab.ar}</span>
            </button>
          ))}
        </div>

        {/* Tab content */}
        <motion.div
          key={activeTab}
          className="p-6"
          initial={{ opacity: 0, y: 6 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.2 }}
        >
          {activeTab === 'info'       && <PersonalInfoTab profile={profile} />}
          {activeTab === 'transcript' && <TranscriptTab transcript={transcript} />}
          {activeTab === 'gpa'        && <GPATab studentId={id} cgpa={cgpa} academicYears={academicYears} semesters={semesters} />}
          {activeTab === 'attendance' && <AttendanceTab attendance={attendance} />}
        </motion.div>
      </div>
    </>
  )
}
