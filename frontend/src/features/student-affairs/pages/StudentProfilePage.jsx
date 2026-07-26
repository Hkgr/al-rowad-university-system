import { useState, useEffect, useRef } from 'react'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import html2canvas from 'html2canvas-pro'
import jsPDF from 'jspdf'
import {
  FaArrowRight, FaEdit, FaSpinner, FaUser,
  FaGraduationCap, FaChartBar, FaCalendarCheck, FaCheckCircle, FaFolderOpen, FaCamera,
  FaDownload,
} from 'react-icons/fa'
import StudentDocuments from '../components/StudentDocuments'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

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
  { id: 'documents',  ar: 'ملفات الطالب',        Icon: FaFolderOpen    },
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

const SEMESTER_ORDER = [
  { code: 'first',  ar: 'الفصل الأول',   accent: 'primary' },
  { code: 'second', ar: 'الفصل الثاني',  accent: 'blue'    },
  { code: 'summer', ar: 'الفصل الصيفي',  accent: 'amber'   },
]

const ACCENT = {
  primary: { header: 'bg-primary/[0.07] border-primary/15', label: 'text-primary-dark', badge: 'bg-primary/8 text-primary-dark border-primary/20' },
  blue:    { header: 'bg-blue-500/[0.06] border-blue-200',  label: 'text-blue-700',     badge: 'bg-blue-50 text-blue-700 border-blue-200'         },
  amber:   { header: 'bg-amber-500/[0.06] border-amber-200',label: 'text-amber-700',    badge: 'bg-amber-50 text-amber-700 border-amber-200'       },
}

function SemesterTable({ courses, accentKey }) {
  const ac = ACCENT[accentKey]
  if (!courses.length) {
    return (
      <p className="text-[12px] text-text-light italic px-5 py-4" dir="rtl">
        لا يوجد مقررات مسجّلة في هذا الفصل
      </p>
    )
  }
  const totalHours = courses.reduce((s, c) => s + (c.credit_hours || 0), 0)
  return (
    <div>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr className="bg-[#fafaf9]">
              <th className="px-4 py-2.5 text-right  text-[11px] font-bold text-text-light" dir="rtl">المقرر</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الساعات</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">نظري</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">عملي</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">المجموع</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
            </tr>
          </thead>
          <tbody>
            {courses.map((c, ci) => (
              <tr key={ci} className="border-t border-primary/6 hover:bg-primary/[0.02] transition-colors">
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
                      : c.result_status?.status_code
                        ? 'bg-red-500/10 text-red-600 border-red-500/25'
                        : 'bg-gray-100 text-text-light border-gray-200'
                  }`} dir="rtl">
                    {c.result_status?.status_code === 'passed'
                      ? 'ناجح'
                      : c.result_status?.status_code === 'failed'
                        ? 'راسب'
                        : c.result_status?.status_name || 'قيد التسجيل'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-primary/10 bg-[#fafaf9]">
              <td className="px-4 py-2.5 text-[11.5px] font-bold text-text-gray" dir="rtl">
                الإجمالي — {courses.length} مقرر
              </td>
              <td className="px-4 py-2.5 text-center text-[12px] font-extrabold text-primary-dark">{totalHours}</td>
              <td colSpan={5} />
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  )
}

function SummaryStat({ label, value, accent = 'gray' }) {
  const colors = {
    gray:    'text-text-dark',
    green:   'text-green-600',
    primary: 'text-primary',
  }
  return (
    <div className="flex flex-col items-center justify-center gap-1 py-4 px-3 bg-[#fafaf9] border border-primary/10 rounded-[14px]" dir="rtl">
      <span className={`text-[22px] font-black leading-none ${colors[accent]}`}>{value}</span>
      <span className="text-[11px] font-semibold text-text-light text-center">{label}</span>
    </div>
  )
}

function TranscriptTab({ transcript, cgpa }) {
  if (!transcript?.terms?.length) {
    return (
      <div className="flex flex-col items-center justify-center py-16 gap-2" dir="rtl">
        <FaGraduationCap className="text-[40px] text-primary/20 mb-1" />
        <p className="text-[14px] font-semibold text-text-light">لا توجد بيانات دراسية بعد</p>
      </div>
    )
  }

  // Group terms by academic year, sorted chronologically
  const byYear = {}
  transcript.terms.forEach(term => {
    const yr = term.academic_year?.year_name ?? '—'
    if (!byYear[yr]) byYear[yr] = { year: term.academic_year, semesters: {} }
    const code = term.semester?.semester_code ?? 'unknown'
    byYear[yr].semesters[code] = term.courses
  })

  const sortedYears = Object.keys(byYear).sort()

  // Overall totals across the full academic record
  const allCourses    = transcript.terms.flatMap(t => t.courses)
  const passedCourses = allCourses.filter(c => c.result_status?.status_code === 'passed')
  const totalHours    = allCourses.reduce((s, c) => s + (c.credit_hours || 0), 0)
  const passedHours   = passedCourses.reduce((s, c) => s + (c.credit_hours || 0), 0)
  const cgpaVal       = cgpa?.cgpa ?? null

  return (
    <div className="space-y-6">
      {/* Overall summary strip */}
      <div>
        <SectionTitle ar="ملخص السجل الأكاديمي" en="Academic Record Summary" />
        <div className="grid grid-cols-4 max-[640px]:grid-cols-2 gap-3">
          <SummaryStat label="الأعوام الدراسية"  value={sortedYears.length} />
          <SummaryStat label="إجمالي المقررات"   value={allCourses.length} />
          <SummaryStat label="المقررات الناجحة"  value={passedCourses.length} accent="green" />
          <SummaryStat label="الساعات المكتسبة"  value={passedHours} accent="primary" />
        </div>
        {cgpaVal !== null && (
          <div className="mt-3 flex items-center gap-4 bg-primary/[0.045] border border-primary/15 rounded-[14px] px-6 py-4" dir="rtl">
            <span className="text-[34px] font-black text-primary leading-none">{Number(cgpaVal).toFixed(2)}</span>
            <div>
              <p className="text-[13px] font-extrabold text-text-dark">المعدل التراكمي</p>
              <p className="text-[11.5px] text-text-light">
                {cgpa?.total_included_credit_hours ?? passedHours} ساعة معتمدة محسوبة من أصل {totalHours} ساعة
              </p>
            </div>
          </div>
        )}
      </div>

      {sortedYears.map(yearName => {
        const { year, semesters } = byYear[yearName]
        const yearTotal = SEMESTER_ORDER.reduce((sum, s) => {
          const courses = semesters[s.code] ?? []
          return sum + courses.reduce((h, c) => h + (c.credit_hours || 0), 0)
        }, 0)
        const yearCourseCount = SEMESTER_ORDER.reduce((sum, s) => sum + (semesters[s.code]?.length ?? 0), 0)

        return (
          <div key={yearName} className="border border-primary/15 rounded-[16px] overflow-hidden shadow-[0_1px_8px_rgba(26,46,16,0.05)]">
            {/* Year header */}
            <div className="flex items-center justify-between px-5 py-3.5 bg-text-dark" dir="rtl">
              <div className="flex items-center gap-3">
                <FaGraduationCap className="text-white/60 text-[14px]" />
                <span className="text-[15px] font-extrabold text-white">
                  العام الدراسي {yearName}
                </span>
              </div>
              <span className="text-[12px] text-white/60">
                {yearCourseCount} مقرر • {yearTotal} ساعة
              </span>
            </div>

            {/* 3 semester sections */}
            <div className="divide-y divide-primary/8">
              {SEMESTER_ORDER.map(sem => {
                const courses = semesters[sem.code] ?? []
                const ac = ACCENT[sem.accent]
                const semHours = courses.reduce((h, c) => h + (c.credit_hours || 0), 0)
                return (
                  <div key={sem.code}>
                    {/* Semester sub-header */}
                    <div className={`flex items-center justify-between px-5 py-2.5 border-b ${ac.header}`} dir="rtl">
                      <span className={`text-[13px] font-extrabold ${ac.label}`}>{sem.ar}</span>
                      {courses.length > 0 && (
                        <span className={`text-[11px] font-semibold px-2.5 py-0.5 rounded-full border ${ac.badge}`}>
                          {courses.length} مقرر • {semHours} ساعة
                        </span>
                      )}
                    </div>
                    <SemesterTable courses={courses} accentKey={sem.accent} />
                  </div>
                )
              })}
            </div>
          </div>
        )
      })}
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
  const [searchParams] = useSearchParams()

  const [loading,        setLoading]        = useState(true)
  const [error,          setError]          = useState('')
  const [profile,        setProfile]        = useState(null)
  const [transcript,     setTranscript]     = useState(null)
  const [cgpa,           setCgpa]           = useState(null)
  const [attendance,     setAttendance]     = useState(null)
  const [academicYears,  setAcademicYears]  = useState([])
  const [semesters,      setSemesters]      = useState([])
  const initialTab = TABS.some(t => t.id === searchParams.get('tab')) ? searchParams.get('tab') : 'info'
  const [activeTab,      setActiveTab]      = useState(initialTab)
  const [photoUrl,       setPhotoUrl]       = useState(null)
  const [photoTypeId,    setPhotoTypeId]    = useState(null)
  const [avatarUploading, setAvatarUploading] = useState(false)
  const [avatarError,    setAvatarError]    = useState('')
  const avatarInputRef = useRef(null)
  const [pdfLoading,     setPdfLoading]     = useState(false)
  const [pdfError,       setPdfError]       = useState('')
  const pdfContentRef = useRef(null)

  useEffect(() => {
    let objectUrl = null
    async function loadPhoto() {
      try {
        const [docsRes, typesRes] = await Promise.all([
          get(`${API}/students/${id}/documents?per_page=100`),
          get(`${API}/document-types?per_page=100`),
        ])
        if (typesRes.success) {
          const types = typesRes.data?.data ?? []
          const photoType = types.find(t => t.type_code === 'personal_photo')
          if (photoType) setPhotoTypeId(photoType.document_type_id)
        }
        if (!docsRes.success) return
        const docs = docsRes.data?.data ?? []
        const photoDoc = docs.find(d => d.document_type?.type_code === 'personal_photo')
        if (!photoDoc) return
        const res = await fetch(photoDoc.download_url, { headers: authHeaders() })
        if (!res.ok) return
        const blob = await res.blob()
        if (!blob.type.startsWith('image/')) return
        objectUrl = URL.createObjectURL(blob)
        setPhotoUrl(objectUrl)
      } catch {
        // silently keep the fallback avatar icon
      }
    }
    loadPhoto()
    return () => { if (objectUrl) URL.revokeObjectURL(objectUrl) }
  }, [id])

  async function handleAvatarFileChange(e) {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setAvatarError('')
    if (!photoTypeId) { setAvatarError('نوع الملف "صورة شخصية" غير متوفر'); return }
    if (!file.type.startsWith('image/')) { setAvatarError('يرجى اختيار صورة (jpg أو png)'); return }
    if (file.size > 5 * 1024 * 1024) { setAvatarError('حجم الصورة يتجاوز 5 ميغابايت'); return }

    setAvatarUploading(true)
    try {
      const formData = new FormData()
      formData.append('document_type_id', photoTypeId)
      formData.append('file', file)
      const res = await fetch(`${API}/students/${id}/documents`, {
        method: 'POST',
        headers: authHeaders(),
        body: formData,
      })
      const json = await res.json()
      if (json.success) {
        setPhotoUrl(prev => {
          if (prev) URL.revokeObjectURL(prev)
          return URL.createObjectURL(file)
        })
      } else {
        setAvatarError(json.message || 'فشل رفع الصورة')
      }
    } catch {
      setAvatarError('تعذّر الاتصال بالخادم')
    } finally {
      setAvatarUploading(false)
    }
  }

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

  async function handleDownloadTranscriptPdf() {
    const el = pdfContentRef.current
    if (!el) return
    setPdfLoading(true)
    setPdfError('')
    try {
      const canvas = await html2canvas(el, { scale: 1.5, backgroundColor: '#ffffff', useCORS: true })
      const imgData = canvas.toDataURL('image/jpeg', 0.92)
      const pdf = new jsPDF({ orientation: 'p', unit: 'pt', format: 'a4', compress: true })
      const pageWidth  = pdf.internal.pageSize.getWidth()
      const pageHeight = pdf.internal.pageSize.getHeight()
      const imgWidth  = pageWidth
      const imgHeight = (canvas.height * imgWidth) / canvas.width

      let heightLeft = imgHeight
      let position = 0
      pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight)
      heightLeft -= pageHeight
      while (heightLeft > 0) {
        position -= pageHeight
        pdf.addPage()
        pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight)
        heightLeft -= pageHeight
      }

      pdf.save(`كشف_الدرجات_${profile?.student_number || id}.pdf`)
    } catch (e) {
      console.error('PDF export failed:', e)
      setPdfError('تعذّر إنشاء ملف PDF. يرجى المحاولة مجددًا')
    } finally {
      setPdfLoading(false)
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
            <div
              className="flex items-center gap-2 px-4 py-2 bg-purple-500/10 border border-purple-500/25 text-purple-700 rounded-[10px] text-[13px] font-bold hover:bg-purple-500/18 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              title="التخريج متوقف مؤقتاً حتى اعتماد قواعد الأهلية المؤسسية"
              dir="rtl"
            >
              <FaGraduationCap className="text-[12px]" />
              <span>التخريج متوقف مؤقتاً</span>
            </div>
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

      {avatarError && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">
          ⚠ {avatarError}
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
          <div className="relative w-[68px] h-[68px] flex-shrink-0">
            <div className="w-full h-full rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-[28px] text-primary overflow-hidden">
              {photoUrl
                ? <img src={photoUrl} alt={profile.full_name} className="w-full h-full object-cover" />
                : <FaUser />}
            </div>
            <input
              ref={avatarInputRef}
              type="file"
              accept="image/png,image/jpeg,image/jpg"
              className="hidden"
              onChange={handleAvatarFileChange}
            />
            <button
              type="button"
              title="تغيير الصورة الشخصية"
              onClick={() => avatarInputRef.current?.click()}
              disabled={avatarUploading}
              className="absolute -bottom-1 -left-1 w-[26px] h-[26px] rounded-full bg-primary text-white flex items-center justify-center text-[11px] border-2 border-white shadow-[0_1px_6px_rgba(0,0,0,0.25)] hover:bg-primary-dark transition-colors disabled:opacity-60"
            >
              {avatarUploading ? <FaSpinner className="animate-spin text-[10px]" /> : <FaCamera />}
            </button>
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
          {activeTab === 'info' && <PersonalInfoTab profile={profile} />}
          {activeTab === 'transcript' && (
            <>
              <div className="flex items-center justify-between mb-5 gap-3 flex-wrap" dir="rtl">
                <p className="text-[12px] text-text-light">السجل الأكاديمي الكامل للطالب، مرتّب حسب السنة والفصل الدراسي</p>
                <button
                  className="flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:enabled:bg-primary-dark transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  onClick={handleDownloadTranscriptPdf}
                  disabled={pdfLoading || !transcript?.terms?.length}
                >
                  {pdfLoading ? <FaSpinner className="animate-spin text-[12px]" /> : <FaDownload className="text-[12px]" />}
                  <span>{pdfLoading ? 'جارٍ التجهيز…' : 'تحميل PDF'}</span>
                </button>
              </div>
              {pdfError && (
                <p className="mb-4 text-[12.5px] text-red-600" dir="rtl">⚠ {pdfError}</p>
              )}
              <TranscriptTab transcript={transcript} cgpa={cgpa} />
            </>
          )}
          {activeTab === 'gpa'        && <GPATab studentId={id} cgpa={cgpa} academicYears={academicYears} semesters={semesters} />}
          {activeTab === 'attendance' && <AttendanceTab attendance={attendance} />}
          {activeTab === 'documents'  && <StudentDocuments studentId={id} />}
        </motion.div>
      </div>

      {/* Off-screen printable transcript document, captured for PDF export */}
      {profile && (
        <div style={{ position: 'fixed', left: '-10000px', top: 0, width: '794px', zIndex: -1 }} aria-hidden="true">
          <div ref={pdfContentRef} className="bg-white p-10">
            <div className="flex items-center justify-between border-b-2 border-primary pb-4 mb-6" dir="rtl">
              <div>
                <h1 className="text-[22px] font-black text-text-dark">كشف الدرجات الأكاديمي</h1>
                <p className="text-[12px] text-text-light mt-1">Academic Transcript</p>
              </div>
              <p className="text-[11px] text-text-light">تاريخ الإصدار: {fmt(new Date().toISOString())}</p>
            </div>
            <div className="grid grid-cols-2 gap-x-8 gap-y-3 mb-7 text-[12.5px]" dir="rtl">
              <div><span className="font-bold text-text-dark">الاسم: </span>{profile.full_name}</div>
              <div><span className="font-bold text-text-dark">الرقم الجامعي: </span>{profile.student_number}</div>
              <div><span className="font-bold text-text-dark">البرنامج: </span>{profile.program?.program_name || '—'}</div>
              <div><span className="font-bold text-text-dark">الكلية: </span>{profile.college?.college_name || '—'}</div>
              <div><span className="font-bold text-text-dark">القسم: </span>{profile.department?.department_name || '—'}</div>
              <div><span className="font-bold text-text-dark">المستوى الدراسي: </span>{profile.academic_level?.level_name || '—'}</div>
            </div>
            <TranscriptTab transcript={transcript} cgpa={cgpa} />
          </div>
        </div>
      )}
    </>
  )
}
