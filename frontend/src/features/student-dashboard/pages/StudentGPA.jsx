import { useState, useEffect } from 'react'
import { FaSpinner } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function getStudentId() {
  return JSON.parse(localStorage.getItem('user') || '{}').student_id
}

export default function StudentGPA() {
  const [cgpa,          setCgpa]          = useState(null)
  const [academicYears, setAcademicYears] = useState([])
  const [semesters,     setSemesters]     = useState([])
  const [loading,       setLoading]       = useState(true)

  const [yearId,     setYearId]     = useState('')
  const [semId,      setSemId]      = useState('')
  const [termGPA,    setTermGPA]    = useState(null)
  const [gpaLoading, setGpaLoading] = useState(false)
  const [gpaError,   setGpaError]   = useState('')

  useEffect(() => {
    const id = getStudentId()
    if (!id) return
    Promise.all([
      fetch(`${API}/students/${id}/cgpa`,   { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/academic-years`,        { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/semesters`,             { headers: authHeaders() }).then(r => r.json()),
    ]).then(([cgpaRes, years, sems]) => {
      if (cgpaRes.success) setCgpa(cgpaRes.data)
      setAcademicYears(years.success ? (years.data?.data ?? []) : [])
      setSemesters(sems.success ? (sems.data?.data ?? []) : [])
    }).finally(() => setLoading(false))
  }, [])

  async function calcGPA() {
    const id = getStudentId()
    if (!id || !yearId || !semId) return
    setGpaLoading(true)
    setGpaError('')
    setTermGPA(null)
    try {
      const res  = await fetch(`${API}/students/${id}/gpa?academic_year_id=${yearId}&semester_id=${semId}`, { headers: authHeaders() })
      const json = await res.json()
      if (json.success) setTermGPA(json.data)
      else setGpaError(json.message || 'فشل احتساب المعدل')
    } catch { setGpaError('تعذّر الاتصال') }
    finally { setGpaLoading(false) }
  }

  if (loading) return (
    <div className="flex items-center justify-center py-24 gap-3 text-primary-light">
      <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" />
    </div>
  )

  const cgpaVal   = cgpa?.cgpa ?? null
  const cgpaColor = cgpaVal === null ? '#569933' : cgpaVal >= 3.7 ? '#16a34a' : cgpaVal >= 3.0 ? '#3b82f6' : cgpaVal >= 2.0 ? '#f59e0b' : '#ef4444'

  return (
    <>
      <div className="mb-6" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">المعدل الدراسي</h2>
        <p className="text-[12.5px] text-text-light">GPA / CGPA</p>
      </div>

      {/* CGPA card */}
      <div className="bg-white border border-primary/12 rounded-[18px] p-6 mb-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
        <div className="flex items-center gap-2 mb-5 pb-3 border-b border-primary/10" dir="rtl">
          <h3 className="text-[15px] font-extrabold text-text-dark">المعدل التراكمي</h3>
          <span className="text-[11px] text-text-light">Cumulative GPA</span>
        </div>
        <div className="flex items-center gap-6 flex-wrap" dir="rtl">
          <div className="text-[64px] font-black leading-none" style={{ color: cgpaColor }}>
            {cgpaVal !== null ? Number(cgpaVal).toFixed(2) : '—'}
          </div>
          <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2 text-[13px]" dir="rtl">
              <span className="text-text-light">من</span>
              <span className="font-bold text-text-dark">4.0</span>
            </div>
            <div className="flex items-center gap-2 text-[13px]" dir="rtl">
              <span className="text-text-light">الساعات المحتسبة:</span>
              <span className="font-bold text-text-dark">{cgpa?.total_included_credit_hours ?? 0}</span>
            </div>
            <div className="flex items-center gap-2 text-[13px]" dir="rtl">
              <span className="text-text-light">المقررات:</span>
              <span className="font-bold text-text-dark">{cgpa?.included_courses_count ?? 0}</span>
            </div>
          </div>
          {/* Visual bar */}
          <div className="flex-1 min-w-[160px]">
            <div className="h-3 bg-gray-100 rounded-full overflow-hidden">
              <div
                className="h-full rounded-full transition-all duration-700"
                style={{ width: `${cgpaVal !== null ? (cgpaVal / 4.0) * 100 : 0}%`, background: cgpaColor }}
              />
            </div>
            <div className="flex justify-between text-[10px] text-text-light mt-1" dir="ltr">
              <span>0.0</span><span>1.0</span><span>2.0</span><span>3.0</span><span>4.0</span>
            </div>
          </div>
        </div>
      </div>

      {/* Term GPA calculator */}
      <div className="bg-white border border-primary/12 rounded-[18px] p-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
        <div className="flex items-center gap-2 mb-5 pb-3 border-b border-primary/10" dir="rtl">
          <h3 className="text-[15px] font-extrabold text-text-dark">معدل الفصل الدراسي</h3>
          <span className="text-[11px] text-text-light">Term GPA</span>
        </div>
        <div className="flex items-end gap-3 flex-wrap" dir="rtl">
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">العام الدراسي</label>
            <select
              className="px-3 py-2 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] min-w-[170px]"
              value={yearId}
              onChange={e => { setYearId(e.target.value); setTermGPA(null) }}
              dir="rtl"
            >
              <option value="">اختر العام</option>
              {academicYears.map(y => <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>)}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">الفصل الدراسي</label>
            <select
              className="px-3 py-2 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] min-w-[170px]"
              value={semId}
              onChange={e => { setSemId(e.target.value); setTermGPA(null) }}
              dir="rtl"
            >
              <option value="">اختر الفصل</option>
              {semesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}
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
        {gpaError && <p className="mt-3 text-[12.5px] text-red-600" dir="rtl">⚠ {gpaError}</p>}
        {termGPA && (
          <div className="mt-5 flex items-center gap-5 bg-blue-50 border border-blue-500/20 rounded-[14px] px-6 py-4" dir="rtl">
            <div className="text-[48px] font-black text-blue-600 leading-none">{Number(termGPA.gpa).toFixed(2)}</div>
            <div>
              <p className="text-[13px] font-extrabold text-text-dark">معدل الفصل</p>
              <p className="text-[12px] text-text-light mt-0.5">{termGPA.total_credit_hours} ساعة معتمدة</p>
            </div>
          </div>
        )}
      </div>
    </>
  )
}
