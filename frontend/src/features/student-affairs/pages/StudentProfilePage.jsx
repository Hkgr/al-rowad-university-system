import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaArrowRight, FaEdit, FaSpinner, FaUser,
  FaGraduationCap, FaChartBar, FaCalendarCheck, FaCheckCircle,
} from 'react-icons/fa'

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
  if (!iso) return 'â€”'
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
  { id: 'info',       ar: 'Ø§Ù„Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„Ø´Ø®ØµÙŠØ©', Icon: FaUser          },
  { id: 'transcript', ar: 'ÙƒØ´Ù Ø§Ù„Ø¯Ø±Ø¬Ø§Øª',        Icon: FaGraduationCap },
  { id: 'gpa',        ar: 'Ø§Ù„Ù…Ø¹Ø¯Ù„',              Icon: FaChartBar      },
  { id: 'attendance', ar: 'Ø§Ù„Ø­Ø¶ÙˆØ± ÙˆØ§Ù„ØºÙŠØ§Ø¨',      Icon: FaCalendarCheck },
]

// â”€â”€ All sub-components defined OUTSIDE the main component â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

function InfoField({ label, value }) {
  return (
    <div className="flex flex-col gap-1" dir="rtl">
      <span className="text-[11px] font-bold text-text-light uppercase tracking-wide">{label}</span>
      <span className="text-[14px] font-semibold text-text-dark">{value || 'â€”'}</span>
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
    { label: 'Ø§Ù„Ø§Ø³Ù… Ø§Ù„Ø£ÙˆÙ„',        value: profile.first_name },
    { label: 'Ø§Ø³Ù… Ø§Ù„Ø£Ø¨',           value: profile.father_name },
    { label: 'Ø§Ø³Ù… Ø§Ù„Ø£Ù…',           value: profile.mother_name },
    { label: 'Ø§Ù„Ù„Ù‚Ø¨ (Ø§Ù„ÙƒÙ†ÙŠØ©)',     value: profile.last_name },
    { label: 'ØªØ§Ø±ÙŠØ® Ø§Ù„Ù…ÙŠÙ„Ø§Ø¯',     value: fmt(profile.date_of_birth) },
    { label: 'Ø§Ù„Ø¬Ù†Ø³',              value: profile.gender === 'male' ? 'Ø°ÙƒØ±' : profile.gender === 'female' ? 'Ø£Ù†Ø«Ù‰' : profile.gender },
    { label: 'Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ',        value: profile.phone_number },
    { label: 'Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ', value: profile.email },
    { label: 'Ø§Ù„Ø¹Ù†ÙˆØ§Ù†',            value: profile.address },
    { label: 'Ø§Ù„Ø¬Ù†Ø³ÙŠØ©',            value: profile.nationality },
  ]
  const academic = [
    { label: 'Ø§Ù„Ø¨Ø±Ù†Ø§Ù…Ø¬ Ø§Ù„Ø£ÙƒØ§Ø¯ÙŠÙ…ÙŠ', value: profile.program?.program_name },
    { label: 'Ø§Ù„ÙƒÙ„ÙŠØ©',              value: profile.college?.college_name },
    { label: 'Ø§Ù„Ù‚Ø³Ù…',               value: profile.department?.department_name },
    { label: 'Ø§Ù„Ù…Ø³ØªÙˆÙ‰ Ø§Ù„Ø¯Ø±Ø§Ø³ÙŠ',    value: profile.academic_level?.level_name },
    { label: 'ØªØ§Ø±ÙŠØ® Ø§Ù„Ù‚Ø¨ÙˆÙ„',       value: fmt(profile.enrollment_date) },
    { label: 'Ø±Ù…Ø² Ø§Ù„Ø¨Ø±Ù†Ø§Ù…Ø¬',       value: profile.program?.program_code },
  ]
  return (
    <div className="space-y-7">
      <div>
        <SectionTitle ar="Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ø´Ø®ØµÙŠØ©" en="Personal Details" />
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
          {personal.map(f => <InfoField key={f.label} {...f} />)}
        </div>
      </div>
      <div>
        <SectionTitle ar="Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ø£ÙƒØ§Ø¯ÙŠÙ…ÙŠØ©" en="Academic Details" />
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
          {academic.map(f => <InfoField key={f.label} {...f} />)}
        </div>
      </div>
    </div>
  )
}

const SEMESTER_ORDER = [
  { code: 'first',  ar: 'Ø§Ù„ÙØµÙ„ Ø§Ù„Ø£ÙˆÙ„',   accent: 'primary' },
  { code: 'second', ar: 'Ø§Ù„ÙØµÙ„ Ø§Ù„Ø«Ø§Ù†ÙŠ',  accent: 'blue'    },
  { code: 'summer', ar: 'Ø§Ù„ÙØµÙ„ Ø§Ù„ØµÙŠÙÙŠ',  accent: 'amber'   },
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
        Ù„Ø§ ÙŠÙˆØ¬Ø¯ Ù…Ù‚Ø±Ø±Ø§Øª Ù…Ø³Ø¬Ù‘Ù„Ø© ÙÙŠ Ù‡Ø°Ø§ Ø§Ù„ÙØµÙ„
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
              <th className="px-4 py-2.5 text-right  text-[11px] font-bold text-text-light" dir="rtl">Ø§Ù„Ù…Ù‚Ø±Ø±</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">Ø§Ù„Ø³Ø§Ø¹Ø§Øª</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">Ù†Ø¸Ø±ÙŠ</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">Ø¹Ù…Ù„ÙŠ</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">Ø§Ù„Ù…Ø¬Ù…ÙˆØ¹</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">Ø§Ù„ØªÙ‚Ø¯ÙŠØ±</th>
              <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">Ø§Ù„Ø­Ø§Ù„Ø©</th>
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
                <td className="px-4 py-3 text-center text-text-gray">{c.theoretical_mark ?? 'â€”'}</td>
                <td className="px-4 py-3 text-center text-text-gray">{c.practical_mark ?? 'â€”'}</td>
                <td className="px-4 py-3 text-center font-bold text-text-dark">{c.final_mark ?? 'â€”'}</td>
                <td className={`px-4 py-3 text-center text-[16px] font-black ${gradeColor(c.letter_grade)}`}>
                  {c.letter_grade || 'â€”'}
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
                      ? 'Ù†Ø§Ø¬Ø­'
                      : c.result_status?.status_name || 'Ù‚ÙŠØ¯ Ø§Ù„ØªØ³Ø¬ÙŠÙ„'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-primary/10 bg-[#fafaf9]">
              <td className="px-4 py-2.5 text-[11.5px] font-bold text-text-gray" dir="rtl">
                Ø§Ù„Ø¥Ø¬Ù…Ø§Ù„ÙŠ â€” {courses.length} Ù…Ù‚Ø±Ø±
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

function TranscriptTab({ transcript }) {
  if (!transcript?.terms?.length) {
    return (
      <div className="flex flex-col items-center justify-center py-16 gap-2" dir="rtl">
        <FaGraduationCap className="text-[40px] text-primary/20 mb-1" />
        <p className="text-[14px] font-semibold text-text-light">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¨ÙŠØ§Ù†Ø§Øª Ø¯Ø±Ø§Ø³ÙŠØ© Ø¨Ø¹Ø¯</p>
      </div>
    )
  }

  // Group terms by academic year, sorted chronologically
  const byYear = {}
  transcript.terms.forEach(term => {
    const yr = term.academic_year?.year_name ?? 'â€”'
    if (!byYear[yr]) byYear[yr] = { year: term.academic_year, semesters: {} }
    const code = term.semester?.semester_code ?? 'unknown'
    byYear[yr].semesters[code] = term.courses
  })

  const sortedYears = Object.keys(byYear).sort()

  return (
    <div className="space-y-6">
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
                  Ø§Ù„Ø¹Ø§Ù… Ø§Ù„Ø¯Ø±Ø§Ø³ÙŠ {yearName}
                </span>
              </div>
              <span className="text-[12px] text-white/60">
                {yearCourseCount} Ù…Ù‚Ø±Ø± â€¢ {yearTotal} Ø³Ø§Ø¹Ø©
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
                          {courses.length} Ù…Ù‚Ø±Ø± â€¢ {semHours} Ø³Ø§Ø¹Ø©
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
      else setGpaError(d.message || 'ÙØ´Ù„ Ø§Ø­ØªØ³Ø§Ø¨ Ø§Ù„Ù…Ø¹Ø¯Ù„')
    } catch { setGpaError('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„') }
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
        <SectionTitle ar="Ø§Ù„Ù…Ø¹Ø¯Ù„ Ø§Ù„ØªØ±Ø§ÙƒÙ…ÙŠ" en="Cumulative GPA" />
        <div className="flex items-stretch gap-4 flex-wrap">
          <div className="flex-1 min-w-[220px] bg-gradient-to-br from-primary/[0.06] to-primary/[0.02] border border-primary/15 rounded-[16px] px-6 py-5 flex items-center gap-5" dir="rtl">
            <div className={`text-[52px] font-black leading-none ${cgpaColor}`}>
              {cgpaVal !== null ? Number(cgpaVal).toFixed(2) : 'â€”'}
            </div>
            <div className="flex flex-col gap-1">
              <span className="text-[13px] font-extrabold text-text-dark">Ø§Ù„Ù…Ø¹Ø¯Ù„ Ø§Ù„ØªØ±Ø§ÙƒÙ…ÙŠ</span>
              <span className="text-[12px] text-text-light">Cumulative GPA (out of 4.0)</span>
              <span className="text-[12px] text-text-gray mt-1">{cgpaHours} Ø³Ø§Ø¹Ø© Ù…Ø¹ØªÙ…Ø¯Ø©</span>
            </div>
          </div>
          <div className="flex flex-col justify-center items-center gap-1 px-6 py-4 border border-primary/12 rounded-[14px] bg-white" dir="rtl">
            <span className="text-[24px] font-black text-primary">{cgpa?.included_courses_count ?? 0}</span>
            <span className="text-[11px] text-text-light text-center">Ù…Ù‚Ø±Ø± Ù…Ø­ØªØ³Ø¨</span>
          </div>
        </div>
      </div>

      {/* Term GPA */}
      <div>
        <SectionTitle ar="Ù…Ø¹Ø¯Ù„ Ø§Ù„ÙØµÙ„ Ø§Ù„Ø¯Ø±Ø§Ø³ÙŠ" en="Term GPA" />
        <div className="flex items-end gap-3 flex-wrap" dir="rtl">
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">Ø§Ù„Ø¹Ø§Ù… Ø§Ù„Ø¯Ø±Ø§Ø³ÙŠ</label>
            <select
              className="px-3 py-2 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] min-w-[160px]"
              value={yearId}
              onChange={e => { setYearId(e.target.value); setTermGPA(null) }}
              dir="rtl"
            >
              <option value="">Ø§Ø®ØªØ± Ø§Ù„Ø¹Ø§Ù…</option>
              {academicYears.map(y => (
                <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">Ø§Ù„ÙØµÙ„ Ø§Ù„Ø¯Ø±Ø§Ø³ÙŠ</label>
            <select
              className="px-3 py-2 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] min-w-[160px]"
              value={semId}
              onChange={e => { setSemId(e.target.value); setTermGPA(null) }}
              dir="rtl"
            >
              <option value="">Ø§Ø®ØªØ± Ø§Ù„ÙØµÙ„</option>
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
            Ø§Ø­ØªØ³Ø§Ø¨
          </button>
        </div>
        {gpaError && (
          <p className="mt-2.5 text-[12.5px] text-red-600" dir="rtl">âš  {gpaError}</p>
        )}
        {termGPA && (
          <div className="mt-4 flex items-center gap-5 bg-blue-50 border border-blue-500/20 rounded-[14px] px-6 py-4" dir="rtl">
            <div className="text-[44px] font-black text-blue-600 leading-none">
              {Number(termGPA.gpa).toFixed(2)}
            </div>
            <div>
              <p className="text-[13px] font-extrabold text-text-dark">Ù…Ø¹Ø¯Ù„ Ø§Ù„ÙØµÙ„</p>
              <p className="text-[12px] text-text-light mt-0.5">{termGPA.total_credit_hours} Ø³Ø§Ø¹Ø© Ù…Ø¹ØªÙ…Ø¯Ø©</p>
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
        <p className="text-[14px] font-semibold text-text-light">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¨ÙŠØ§Ù†Ø§Øª Ø­Ø¶ÙˆØ± Ø¨Ø¹Ø¯</p>
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
                  {c.course_code} â€” {c.academic_year?.year_name} / {c.semester?.semester_name}
                </div>
              </div>
              {deprived && (
                <span className="flex-shrink-0 px-2.5 py-1 bg-red-500/10 border border-red-500/25 text-red-600 text-[11px] font-bold rounded-full">Ù…Ø­Ø±ÙˆÙ…</span>
              )}
              {warning && (
                <span className="flex-shrink-0 px-2.5 py-1 bg-amber-500/10 border border-amber-500/25 text-amber-700 text-[11px] font-bold rounded-full">ØªØ­Ø°ÙŠØ± ØºÙŠØ§Ø¨</span>
              )}
            </div>
            <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden mb-3">
              <div
                className={`h-full rounded-full transition-all duration-500 ${deprived ? 'bg-red-500' : warning ? 'bg-amber-400' : 'bg-primary'}`}
                style={{ width: `${Math.min(pct, 100)}%` }}
              />
            </div>
            <div className="flex items-center gap-5 text-[12.5px] flex-wrap" dir="rtl">
              <span className="text-text-gray">Ø¥Ø¬Ù…Ø§Ù„ÙŠ: <strong className="text-text-dark">{c.total_sessions}</strong></span>
              <span className="text-green-600">Ø­Ø¶ÙˆØ±: <strong>{c.present_count}</strong></span>
              <span className="text-red-500">ØºÙŠØ§Ø¨: <strong>{c.absent_count}</strong></span>
              <span className={`font-bold ${deprived ? 'text-red-600' : warning ? 'text-amber-600' : 'text-text-dark'}`}>
                Ù†Ø³Ø¨Ø© Ø§Ù„ØºÙŠØ§Ø¨: {Number(pct).toFixed(1)}%
              </span>
            </div>
          </div>
        )
      })}
    </div>
  )
}

// â”€â”€ Status badge helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

const STATUS_STYLES = {
  active:    { bg: 'bg-green-500/10',  text: 'text-green-700',  border: 'border-green-500/25',  ar: 'Ù†Ø´Ø·'     },
  inactive:  { bg: 'bg-gray-100',      text: 'text-gray-500',   border: 'border-gray-200',       ar: 'ØºÙŠØ± Ù†Ø´Ø·' },
  suspended: { bg: 'bg-red-500/10',    text: 'text-red-600',    border: 'border-red-500/25',     ar: 'Ù…ÙˆÙ‚ÙˆÙ'   },
  graduated: { bg: 'bg-blue-500/10',   text: 'text-blue-700',   border: 'border-blue-500/25',    ar: 'Ø®Ø±ÙŠØ¬'    },
}

// â”€â”€ Main page â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
        if (!prof.success) { setError(prof.message || 'Ø§Ù„Ø·Ø§Ù„Ø¨ ØºÙŠØ± Ù…ÙˆØ¬ÙˆØ¯'); return }
        setProfile(prof.data)
        setTranscript(trans.success ? trans.data : null)
        setCgpa(cgpaRes.success ? cgpaRes.data : null)
        setAttendance(att.success ? att.data : null)
        setAcademicYears(years.success ? (years.data?.data ?? years.data ?? []) : [])
        setSemesters(sems.success ? (sems.data?.data ?? sems.data ?? []) : [])
      } catch {
        setError('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…. ØªØ£ÙƒØ¯ Ø£Ù† php artisan serve ÙŠØ¹Ù…Ù„.')
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [id])

  async function handleGraduate() {
    if (!window.confirm(`Ù‡Ù„ ØªØ±ÙŠØ¯ ØªØ®Ø±ÙŠØ¬ Ø§Ù„Ø·Ø§Ù„Ø¨ "${profile?.full_name}"ØŸ\nØ³ÙŠØªÙ… ØªØºÙŠÙŠØ± Ø­Ø§Ù„ØªÙ‡ Ø¥Ù„Ù‰ "Ø®Ø±ÙŠØ¬".`)) return
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
        setGraduateError(json.message || 'ÙØ´Ù„Øª Ø§Ù„Ø¹Ù…Ù„ÙŠØ©')
      }
    } catch {
      setGraduateError('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…')
    } finally {
      setGraduating(false)
    }
  }

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-primary-light">
        <FaSpinner className="text-[30px] animate-[spin_0.7s_linear_infinite]" />
        <span className="text-[14px] font-medium">Ø¬Ø§Ø±ÙŠ ØªØ­Ù…ÙŠÙ„ Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ø·Ø§Ù„Ø¨â€¦</span>
      </div>
    )
  }

  if (error || !profile) {
    return (
      <div className="flex flex-col items-center justify-center gap-4 py-24" dir="rtl">
        <p className="text-[15px] text-red-600 font-bold">âš  {error || 'Ø§Ù„Ø·Ø§Ù„Ø¨ ØºÙŠØ± Ù…ÙˆØ¬ÙˆØ¯'}</p>
        <button
          className="px-5 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
          onClick={() => navigate('/student-affairs/students')}
        >
          Ø§Ù„Ø¹ÙˆØ¯Ø© Ø¥Ù„Ù‰ Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ø·Ù„Ø§Ø¨
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
          <span>Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ø·Ù„Ø§Ø¨</span>
        </button>
        <div className="flex items-center gap-2 flex-wrap">
          {sc.ar !== 'Ø®Ø±ÙŠØ¬' && (
            <button
              className="flex items-center gap-2 px-4 py-2 bg-purple-500/10 border border-purple-500/25 text-purple-700 rounded-[10px] text-[13px] font-bold hover:bg-purple-500/18 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              onClick={handleGraduate}
              disabled={graduating}
              dir="rtl"
            >
              {graduating ? <FaSpinner className="animate-spin text-[12px]" /> : <FaGraduationCap className="text-[12px]" />}
              <span>ØªØ®Ø±ÙŠØ¬ Ø§Ù„Ø·Ø§Ù„Ø¨</span>
            </button>
          )}
          {sc.ar === 'Ø®Ø±ÙŠØ¬' && (
            <span className="flex items-center gap-1.5 px-3 py-1.5 bg-purple-50 border border-purple-200 text-purple-700 rounded-[10px] text-[12px] font-bold" dir="rtl">
              <FaCheckCircle className="text-[11px]" /> ØªÙ… Ø§Ù„ØªØ®Ø±ÙŠØ¬
            </span>
          )}
          <button
            className="flex items-center gap-2 px-4 py-2 bg-amber-500/10 border border-amber-500/25 text-amber-700 rounded-[10px] text-[13px] font-bold hover:bg-amber-500/18 transition-colors"
            onClick={() => navigate(`/student-affairs/students/${id}/edit`)}
            dir="rtl"
          >
            <FaEdit />
            <span>ØªØ¹Ø¯ÙŠÙ„ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª</span>
          </button>
        </div>
      </div>

      {graduateError && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">
          âš  {graduateError}
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
              <span className="text-primary/30">â€¢</span>
              <span>{profile.program?.program_name}</span>
              <span className="text-primary/30">â€¢</span>
              <span>{profile.college?.college_name}</span>
              <span className="text-primary/30">â€¢</span>
              <span>{profile.academic_level?.level_name}</span>
            </div>
          </div>
          {cgpaVal !== null && (
            <div className="flex flex-col items-center px-5 py-3 border border-primary/15 rounded-[14px] bg-primary/[0.035] flex-shrink-0" dir="rtl">
              <span className="text-[26px] font-black text-primary leading-none">{Number(cgpaVal).toFixed(2)}</span>
              <span className="text-[10.5px] text-text-light mt-0.5">Ø§Ù„Ù…Ø¹Ø¯Ù„ Ø§Ù„ØªØ±Ø§ÙƒÙ…ÙŠ</span>
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

