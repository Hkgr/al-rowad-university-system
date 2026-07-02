import { useState, useEffect, useMemo } from 'react'
import { FaSpinner, FaCheckCircle, FaTimesCircle, FaPlus, FaMinus, FaBookOpen, FaClock } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function getUser() {
  return JSON.parse(localStorage.getItem('user') || '{}')
}

const REASON_LABELS = {
  already_registered:   { ar: 'مسجل مسبقاً',        color: 'bg-blue-100 text-blue-700'   },
  missing_prerequisites:{ ar: 'متطلبات سابقة ناقصة', color: 'bg-red-100 text-red-700'    },
  no_available_seats:   { ar: 'لا توجد مقاعد',        color: 'bg-orange-100 text-orange-700'},
  credit_limit_exceeded:{ ar: 'تجاوز الحد الأقصى',   color: 'bg-yellow-100 text-yellow-700'},
}

// ── Hours progress bar ────────────────────────────────────────────────────────
function HoursBar({ registered, max, remaining }) {
  const pct = max > 0 ? Math.min((registered / max) * 100, 100) : 0
  const color = pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-500' : 'bg-primary'
  return (
    <div className="bg-white border border-primary/12 rounded-[14px] p-4 mb-5 shadow-[0_2px_8px_rgba(26,46,16,0.05)]">
      <div className="flex items-center justify-between mb-2" dir="rtl">
        <span className="text-[12.5px] font-bold text-text-dark">الساعات المعتمدة المسجلة</span>
        <span className="text-[13px] font-extrabold text-text-dark">
          <span className="text-primary">{registered}</span> / {max} ساعة
        </span>
      </div>
      <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden">
        <div className={`h-full rounded-full transition-all duration-500 ${color}`} style={{ width: `${pct}%` }} />
      </div>
      <p className="text-[11px] text-text-light mt-1.5 text-left" dir="rtl">
        متبقي: <strong className="text-text-dark">{remaining} ساعة</strong>
      </p>
    </div>
  )
}

// ── Registered courses panel ──────────────────────────────────────────────────
function RegisteredPanel({ registrations, onDrop, dropping }) {
  if (registrations.length === 0) return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <PanelHeader title="المواد المسجلة" count={0} />
      <div className="flex flex-col items-center py-12 gap-2">
        <FaBookOpen className="text-[36px] text-primary/15" />
        <p className="text-[12px] text-text-light" dir="rtl">لم تسجل أي مادة بعد</p>
      </div>
    </div>
  )

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <PanelHeader title="المواد المسجلة" count={registrations.length} />
      <div className="divide-y divide-primary/6">
        {registrations.map(r => (
          <div key={r.registration_id} className="flex items-center justify-between gap-3 px-5 py-3.5" dir="rtl">
            <div className="flex-1 min-w-0">
              <div className="font-semibold text-[13px] text-text-dark truncate">{r.course_name}</div>
              <div className="flex items-center gap-2 mt-0.5">
                <span className="text-[11px] text-text-light font-mono">{r.course_code}</span>
                <span className="text-[10.5px] text-primary font-bold">{r.credit_hours} ساعات</span>
                <span className="inline-block px-1.5 py-0.5 bg-green-100 text-green-700 text-[10px] font-bold rounded-full">
                  {r.registration_status?.status_name || 'مسجل'}
                </span>
              </div>
            </div>
            <button
              onClick={() => onDrop(r.registration_id, r.course_name)}
              disabled={!!dropping[r.registration_id]}
              className="flex items-center gap-1.5 px-3 py-1.5 border border-red-300 text-red-600 rounded-[8px] text-[11.5px] font-bold hover:bg-red-50 disabled:opacity-40 transition-colors flex-shrink-0"
            >
              {dropping[r.registration_id]
                ? <FaSpinner className="animate-spin text-[10px]" />
                : <FaMinus className="text-[10px]" />}
              حذف
            </button>
          </div>
        ))}
      </div>
    </div>
  )
}

// ── Available courses panel ───────────────────────────────────────────────────
function AvailablePanel({ courses, onRegister, registering }) {
  const eligible   = courses.filter(c => c.eligibility_status === 'eligible')
  const ineligible = courses.filter(c => c.eligibility_status !== 'eligible')

  if (courses.length === 0) return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <PanelHeader title="المواد المتاحة للتسجيل" count={0} />
      <div className="flex flex-col items-center py-12 gap-2">
        <FaBookOpen className="text-[36px] text-primary/15" />
        <p className="text-[12px] text-text-light" dir="rtl">لا توجد مواد متاحة للتسجيل في هذا الفصل</p>
      </div>
    </div>
  )

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <PanelHeader title="المواد المتاحة للتسجيل" count={courses.length} />
      <div className="divide-y divide-primary/6 max-h-[600px] overflow-y-auto">
        {/* Eligible first */}
        {eligible.map(c => (
          <CourseRow key={c.course_offering_id} course={c} onRegister={onRegister} registering={registering} />
        ))}
        {/* Separator */}
        {eligible.length > 0 && ineligible.length > 0 && (
          <div className="px-5 py-2 bg-gray-50 text-[11px] text-text-light font-bold" dir="rtl">
            — مواد غير مؤهلة للتسجيل حالياً —
          </div>
        )}
        {ineligible.map(c => (
          <CourseRow key={c.course_offering_id} course={c} onRegister={onRegister} registering={registering} />
        ))}
      </div>
    </div>
  )
}

function CourseRow({ course, onRegister, registering }) {
  const eligible = course.eligibility_status === 'eligible'
  const reasons  = course.eligibility_reasons ?? []
  const seats    = course.available_seats ?? 0
  const capacity = course.capacity ?? 0

  return (
    <div className={`flex items-start justify-between gap-3 px-5 py-3.5 transition-colors ${eligible ? 'hover:bg-primary/[0.02]' : 'opacity-70'}`} dir="rtl">
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className="font-semibold text-[13px] text-text-dark">{course.course_name}</span>
          {eligible
            ? <FaCheckCircle className="text-green-500 text-[12px] flex-shrink-0" />
            : <FaTimesCircle className="text-red-400 text-[12px] flex-shrink-0" />
          }
        </div>
        <div className="flex items-center gap-2 mt-0.5 flex-wrap">
          <span className="text-[11px] text-text-light font-mono">{course.course_code}</span>
          <span className="text-[10.5px] text-primary font-bold">{course.credit_hours} ساعات</span>
          {course.faculty_member && (
            <span className="text-[10.5px] text-text-light">
              د. {course.faculty_member.first_name} {course.faculty_member.last_name}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2 mt-1 flex-wrap">
          {/* Seat count */}
          <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${seats > 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600'}`}>
            {seats}/{capacity} مقعد
          </span>
          {/* Schedule placeholder */}
          <span className="inline-flex items-center gap-1 text-[10px] text-text-light bg-gray-100 px-1.5 py-0.5 rounded-full">
            <FaClock className="text-[9px]" /> الجدول غير محدد بعد
          </span>
          {/* Ineligibility reasons */}
          {reasons.map(r => {
            const info = REASON_LABELS[r] ?? { ar: r, color: 'bg-gray-100 text-text-light' }
            return (
              <span key={r} className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${info.color}`}>
                {info.ar}
              </span>
            )
          })}
        </div>
      </div>
      <button
        onClick={() => onRegister(course.course_offering_id, course.course_name)}
        disabled={!eligible || !!registering[course.course_offering_id]}
        className="flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white rounded-[8px] text-[11.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-30 disabled:cursor-not-allowed transition-colors flex-shrink-0 mt-0.5"
      >
        {registering[course.course_offering_id]
          ? <FaSpinner className="animate-spin text-[10px]" />
          : <FaPlus className="text-[10px]" />}
        تسجيل
      </button>
    </div>
  )
}

function PanelHeader({ title, count }) {
  return (
    <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
      <span className="text-[13px] font-extrabold text-text-dark">{title}</span>
      <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{count}</span>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function StudentRegistration() {
  const user       = getUser()
  const studentId  = user.student_id

  const [years,             setYears]             = useState([])
  const [semesters,         setSemesters]         = useState([])
  const [filteredSemesters, setFilteredSemesters] = useState([])
  const [yearId,            setYearId]            = useState('')
  const [semId,             setSemId]             = useState('')
  const [available,         setAvailable]         = useState([])
  const [summary,           setSummary]           = useState(null)
  const [loadingInit,       setLoadingInit]       = useState(true)
  const [loadingFilter,     setLoadingFilter]     = useState(false)
  const [loadingData,       setLoadingData]       = useState(false)
  const [registering,       setRegistering]       = useState({})
  const [dropping,          setDropping]          = useState({})
  const [err,               setErr]               = useState('')
  const [toast,             setToast]             = useState('')

  useEffect(() => {
    Promise.all([
      fetch(`${API}/academic-years?per_page=50`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/semesters?per_page=20`,      { headers: authHeaders() }).then(r => r.json()),
    ]).then(([y, s]) => {
      setYears(y.success     ? (y.data?.data ?? y.data ?? []) : [])
      const allSems = s.success ? (s.data?.data ?? s.data ?? []) : []
      setSemesters(allSems)
      setFilteredSemesters(allSems)
    }).finally(() => setLoadingInit(false))
  }, [])

  function handleYearChange(yId, allSems) {
    setYearId(yId); setSemId(''); setAvailable([]); setSummary(null); setErr('')
    if (!yId) { setFilteredSemesters(allSems); return }
    setLoadingFilter(true)
    Promise.all(
      allSems.map(s =>
        fetch(`${API}/students/${studentId}/available-courses?academic_year_id=${yId}&semester_id=${s.semester_id}`, { headers: authHeaders() })
          .then(r => r.json())
          .then(json => ({ sem: s, has: json.success && Array.isArray(json.data) && json.data.length > 0 }))
          .catch(() => ({ sem: s, has: false }))
      )
    ).then(results => {
      const valid = results.filter(r => r.has).map(r => r.sem)
      const list  = valid.length > 0 ? valid : allSems
      setFilteredSemesters(list)
      if (valid.length === 1) {
        const sId = String(valid[0].semester_id)
        setSemId(sId)
        loadData(yId, sId)
      }
    }).finally(() => setLoadingFilter(false))
  }

  function loadData(yId, sId) {
    if (!yId || !sId) return
    setAvailable([]); setSummary(null); setErr(''); setLoadingData(true)
    Promise.all([
      fetch(`${API}/students/${studentId}/available-courses?academic_year_id=${yId}&semester_id=${sId}`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/students/${studentId}/registration-summary?academic_year_id=${yId}&semester_id=${sId}`, { headers: authHeaders() }).then(r => r.json()),
    ]).then(([av, sm]) => {
      if (av.success) setAvailable(Array.isArray(av.data) ? av.data : [])
      if (sm.success) setSummary(sm.data)
      if (!av.success) setErr(av.message || 'فشل تحميل المواد المتاحة')
    }).catch(() => setErr('تعذّر الاتصال بالخادم'))
    .finally(() => setLoadingData(false))
  }

  function showToast(msg) {
    setToast(msg)
    setTimeout(() => setToast(''), 3000)
  }

  async function handleRegister(offeringId, name) {
    setRegistering(p => ({ ...p, [offeringId]: true })); setErr('')
    try {
      const res  = await fetch(`${API}/registrations/register-student`, {
        method:  'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body:    JSON.stringify({ student_id: studentId, course_offering_id: offeringId }),
      })
      const json = await res.json()
      if (json.success) { showToast(`تم تسجيل "${name}" بنجاح`); loadData(yearId, semId) }
      else setErr(json.message || 'فشل التسجيل')
    } catch { setErr('تعذّر الاتصال بالخادم') }
    finally { setRegistering(p => ({ ...p, [offeringId]: false })) }
  }

  async function handleDrop(registrationId, name) {
    if (!window.confirm(`هل تريد حذف تسجيل مادة "${name}"؟`)) return
    setDropping(p => ({ ...p, [registrationId]: true })); setErr('')
    try {
      const res  = await fetch(`${API}/registrations/${registrationId}/drop`, {
        method:  'POST',
        headers: authHeaders(),
      })
      const json = await res.json()
      if (json.success) { showToast(`تم حذف "${name}" من قائمة التسجيل`); loadData(yearId, semId) }
      else setErr(json.message || 'فشل الحذف')
    } catch { setErr('تعذّر الاتصال بالخادم') }
    finally { setDropping(p => ({ ...p, [registrationId]: false })) }
  }

  const registrations = summary?.registrations ?? []

  if (loadingInit) return (
    <div className="flex justify-center py-20 text-primary"><FaSpinner className="animate-spin text-[32px]" /></div>
  )

  return (
    <>
      {/* Header */}
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">تسجيل المواد</h2>
        <p className="text-[12.5px] text-text-light">Course Registration</p>
      </div>

      {/* Year + Semester selector */}
      <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-4" dir="rtl">
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">السنة الدراسية</label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
              value={yearId}
              onChange={e => handleYearChange(e.target.value, semesters)}
              dir="rtl"
            >
              <option value="">اختر السنة</option>
              {years.map(y => <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>)}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">
              الفصل الدراسي
              {loadingFilter && <FaSpinner className="inline mr-2 animate-spin text-[11px] text-primary" />}
            </label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
              value={semId}
              onChange={e => { setSemId(e.target.value); loadData(yearId, e.target.value) }}
              disabled={!yearId || loadingFilter}
              dir="rtl"
            >
              <option value="">اختر الفصل</option>
              {filteredSemesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}
            </select>
          </div>
        </div>
      </div>

      {/* Toast */}
      {toast && (
        <div className="mb-4 px-4 py-2.5 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px] flex items-center gap-2" dir="rtl">
          <FaCheckCircle className="text-green-500 flex-shrink-0" /> {toast}
        </div>
      )}
      {err && (
        <p className="mb-4 px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]" dir="rtl">⚠ {err}</p>
      )}

      {loadingData && (
        <div className="flex justify-center py-16 text-primary"><FaSpinner className="animate-spin text-[28px]" /></div>
      )}

      {summary && !loadingData && (
        <>
          {/* Hours bar */}
          <HoursBar
            registered={summary.total_registered_hours}
            max={summary.max_allowed_hours}
            remaining={summary.remaining_hours}
          />

          {/* Schedule notice */}
          <div className="mb-5 px-4 py-2.5 bg-amber-50 border border-amber-200 rounded-[10px] flex items-center gap-2 text-[12px] text-amber-800" dir="rtl">
            <FaClock className="flex-shrink-0" />
            الجدول الزمني للمواد غير متاح حالياً — سيتم إضافة أوقات المحاضرات عند توفر البيانات من الإدارة.
          </div>

          {/* Two panels */}
          <div className="grid grid-cols-2 max-[860px]:grid-cols-1 gap-5">
            <RegisteredPanel
              registrations={registrations}
              onDrop={handleDrop}
              dropping={dropping}
            />
            <AvailablePanel
              courses={available}
              onRegister={handleRegister}
              registering={registering}
            />
          </div>
        </>
      )}
    </>
  )
}
