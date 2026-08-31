import { useState, useEffect, useRef, useMemo } from 'react'
import {
  FaSearch, FaSpinner, FaUserGraduate, FaCheckCircle, FaTimesCircle,
  FaPlus, FaMinus, FaBookOpen, FaExchangeAlt,
} from 'react-icons/fa'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import CourseRequirementBadges, { pickRequirementClassification } from '../../../components/academic/CourseRequirementBadges'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

async function get(url) {
  const r = await fetch(url, { headers: authHeaders() })
  return r.json()
}

// Backend `students/search` matches each field (first_name, last_name, ...)
// independently against the whole query, so a full "first last" name never
// matches either field alone. Search using the most selective word, then
// filter client-side across the full name so multi-word queries still work.
function matchesAllWords(student, words) {
  const haystack = [
    student.first_name, student.last_name, student.student_number,
    student.email, student.phone_number,
  ].filter(Boolean).join(' ').toLowerCase()
  return words.every(w => haystack.includes(w.toLowerCase()))
}

async function searchStudents(query) {
  const words = query.trim().split(/\s+/).filter(Boolean)
  if (words.length <= 1) {
    const json = await get(`${API}/students/search?q=${encodeURIComponent(query.trim())}&per_page=10`)
    return json
  }
  const primary = [...words].sort((a, b) => b.length - a.length)[0]
  const json = await get(`${API}/students/search?q=${encodeURIComponent(primary)}&per_page=100`)
  if (!json.success) return json
  const filtered = (json.data?.data ?? []).filter(s => matchesAllWords(s, words))
  return { ...json, data: { ...json.data, data: filtered.slice(0, 10) } }
}

const REASON_LABELS = {
  already_registered:    { ar: 'مسجل مسبقاً',         color: 'bg-blue-100 text-blue-700'    },
  missing_prerequisites:  { ar: 'متطلبات سابقة ناقصة',  color: 'bg-red-100 text-red-700'      },
  course_already_passed:  { ar: 'تم اجتياز المقرر سابقاً', color: 'bg-blue-100 text-blue-700' },
  credit_limit_exceeded:  { ar: 'تجاوز الحد الأقصى',    color: 'bg-yellow-100 text-yellow-700'},
}

// ── Student search ─────────────────────────────────────────────────────────────
function StudentSearch({ onSelect }) {
  const [query, setQuery]     = useState('')
  const [results, setResults] = useState([])
  const [searching, setSearching] = useState(false)
  const [err, setErr]         = useState('')
  const debounceRef = useRef(null)

  useEffect(() => {
    clearTimeout(debounceRef.current)
    const q = query.trim()
    debounceRef.current = setTimeout(async () => {
      if (q.length < 2) { setResults([]); setErr(''); return }
      setSearching(true)
      setErr('')
      try {
        const json = await searchStudents(q)
        if (json.success) setResults(json.data?.data ?? [])
        else setErr(json.message || 'فشل البحث')
      } catch {
        setErr('تعذّر الاتصال بالخادم')
      } finally {
        setSearching(false)
      }
    }, 350)
    return () => clearTimeout(debounceRef.current)
  }, [query])

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <div className="flex items-center gap-2 mb-4" dir="rtl">
        <FaUserGraduate className="text-primary text-[16px]" />
        <span className="text-[14.5px] font-extrabold text-text-dark">اختر الطالب</span>
        <span className="text-[11px] text-text-light">ابحث بالاسم أو رقم القيد</span>
      </div>
      <div className="relative mb-3">
        <FaSearch className="absolute right-3 top-1/2 -translate-y-1/2 text-text-light text-[13px] pointer-events-none" />
        <input
          type="text"
          placeholder="اسم الطالب أو رقم القيد…"
          className="w-full pr-9 pl-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)]"
          value={query}
          onChange={e => setQuery(e.target.value)}
          dir="rtl"
        />
        {searching && <FaSpinner className="absolute left-3 top-1/2 -translate-y-1/2 text-primary animate-spin text-[13px]" />}
      </div>

      {err && <p className="text-[12.5px] text-red-600 mb-2" dir="rtl">⚠ {err}</p>}

      {query.trim().length >= 2 && !searching && (
        <div className="max-h-[280px] overflow-y-auto rounded-[10px] border border-primary/10 divide-y divide-primary/6">
          {results.length === 0 ? (
            <p className="text-center text-[13px] text-text-light py-5" dir="rtl">لا توجد نتائج</p>
          ) : results.map(s => (
            <button
              key={s.student_id}
              onClick={() => onSelect(s)}
              className="w-full flex items-center gap-3 px-4 py-3 text-right transition-colors hover:bg-primary/[0.04]"
              dir="rtl"
            >
              <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-[13px] flex-shrink-0">
                {s.first_name?.[0]}
              </div>
              <div>
                <div className="text-[13.5px] font-bold text-text-dark">{s.first_name} {s.last_name}</div>
                <div className="text-[11px] text-text-light font-mono">{s.student_number}</div>
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  )
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

function PanelHeader({ title, count }) {
  return (
    <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
      <span className="text-[13px] font-extrabold text-text-dark">{title}</span>
      <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{count}</span>
    </div>
  )
}

// ── Registered courses panel ──────────────────────────────────────────────────
function RegisteredPanel({ registrations, onDrop, dropping, canManage }) {
  if (registrations.length === 0) return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <PanelHeader title="المواد المسجلة" count={0} />
      <div className="flex flex-col items-center py-12 gap-2">
        <FaBookOpen className="text-[36px] text-primary/15" />
        <p className="text-[12px] text-text-light" dir="rtl">لم يسجّل الطالب أي مادة بعد</p>
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
              <div className="mt-1">
                <CourseRequirementBadges classification={pickRequirementClassification(r)} compact />
              </div>
              <div className="flex items-center gap-2 mt-0.5">
                <span className="text-[11px] text-text-light font-mono">{r.course_code}</span>
                <span className="text-[10.5px] text-primary font-bold">{r.credit_hours} ساعات</span>
                <span className="inline-block px-1.5 py-0.5 bg-green-100 text-green-700 text-[10px] font-bold rounded-full">
                  {r.registration_status?.status_name || 'مسجل'}
                </span>
              </div>
            </div>
            {canManage && <button
              onClick={() => onDrop(r.registration_id, r.course_name)}
              disabled={!!dropping[r.registration_id]}
              className="flex items-center gap-1.5 px-3 py-1.5 border border-red-300 text-red-600 rounded-[8px] text-[11.5px] font-bold hover:bg-red-50 disabled:opacity-40 transition-colors flex-shrink-0"
            >
              {dropping[r.registration_id]
                ? <FaSpinner className="animate-spin text-[10px]" />
                : <FaMinus className="text-[10px]" />}
              حذف
            </button>}
          </div>
        ))}
      </div>
    </div>
  )
}

// Courses are grouped by advisory academic level (program_courses) for display only.
// A course from any advisory year can be registered if genuine eligibility rules pass.
function AvailablePanel({ courses, levels, programCourseMap, currentLevelId, onRegister, registering, canManage }) {
  const groups = useMemo(() => {
    const byLevel = new Map()
    const shared = []
    courses.forEach(c => {
      const courseId = c.course?.course_id
      const pc = programCourseMap.get(courseId)
      if (pc) {
        if (!byLevel.has(pc.academic_level_id)) byLevel.set(pc.academic_level_id, [])
        byLevel.get(pc.academic_level_id).push({
          ...c,
          requirement_classification: pc.requirement_classification || c.requirement_classification,
        })
      } else {
        shared.push({
          ...c,
          requirement_classification: c.requirement_classification || { status: 'not_linked_to_program' },
        })
      }
    })
    return { byLevel, shared }
  }, [courses, programCourseMap])

  if (courses.length === 0) return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <PanelHeader title="المواد المتاحة للتسجيل" count={0} />
      <div className="flex flex-col items-center py-12 gap-2">
        <FaBookOpen className="text-[36px] text-primary/15" />
        <p className="text-[12px] text-text-light" dir="rtl">لا توجد مواد متاحة للتسجيل في هذا الفصل</p>
      </div>
    </div>
  )

  const orderedLevels = levels.filter(l => groups.byLevel.has(l.academic_level_id))

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <PanelHeader title="المواد المتاحة للتسجيل" count={courses.length} />
      <p className="px-5 py-2 text-[11.5px] leading-6 text-text-light border-b border-primary/8" dir="rtl">
        التجميع حسب السنة الإرشادية للخطة فقط. لا يمنع تسجيل مقرر من سنة إرشادية مختلفة إذا استوفيت المتطلبات.
      </p>
      <div className="max-h-[600px] overflow-y-auto">
        {orderedLevels.map(level => (
          <div key={level.academic_level_id}>
            <div className="flex items-center gap-2 px-5 py-2 bg-gray-50 border-b border-t border-primary/6 first:border-t-0" dir="rtl">
              <span className="text-[11.5px] font-extrabold text-text-dark">الخطة الإرشادية — {level.level_name}</span>
              {String(level.academic_level_id) === String(currentLevelId) && (
                <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-primary/10 text-primary-dark">السنة الإرشادية الحالية للطالب</span>
              )}
            </div>
            <div className="divide-y divide-primary/6">
              {groups.byLevel.get(level.academic_level_id).map(c => (
              <CourseRow key={c.course_offering_id} course={c} onRegister={onRegister} registering={registering} canManage={canManage} />
              ))}
            </div>
          </div>
        ))}
        {groups.shared.length > 0 && (
          <div>
            <div className="flex items-center gap-2 px-5 py-2 bg-gray-50 border-b border-t border-primary/6 first:border-t-0" dir="rtl">
              <span className="text-[11.5px] font-extrabold text-text-dark">مواد مشتركة</span>
            </div>
            <div className="divide-y divide-primary/6">
              {groups.shared.map(c => (
            <CourseRow key={c.course_offering_id} course={c} onRegister={onRegister} registering={registering} canManage={canManage} />
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

function CourseRow({ course, onRegister, registering, canManage }) {
  const eligible = course.eligibility_status === 'eligible'
  const reasons  = course.eligibility_reasons ?? []

  return (
    <div className={`flex items-start justify-between gap-3 px-5 py-3.5 transition-colors ${eligible ? 'hover:bg-primary/[0.02]' : 'opacity-70'}`} dir="rtl">
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className="font-semibold text-[13px] text-text-dark">{course.course_name}</span>
          {eligible
            ? <FaCheckCircle className="text-green-500 text-[12px] flex-shrink-0" />
            : <FaTimesCircle className="text-red-400 text-[12px] flex-shrink-0" />}
        </div>
        <div className="flex items-center gap-2 mt-0.5 flex-wrap">
          <span className="text-[11px] text-text-light font-mono">{course.course_code}</span>
          <span className="text-[10.5px] text-primary font-bold">{course.credit_hours} ساعات</span>
        </div>
        <div className="flex items-center gap-2 mt-1 flex-wrap">
          <CourseRequirementBadges classification={pickRequirementClassification(course)} compact />
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
      {canManage && <button
        onClick={() => onRegister(course.course_offering_id, course.course_name)}
        disabled={!eligible || !!registering[course.course_offering_id]}
        className="flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white rounded-[8px] text-[11.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-30 disabled:cursor-not-allowed transition-colors flex-shrink-0 mt-0.5"
      >
        {registering[course.course_offering_id]
          ? <FaSpinner className="animate-spin text-[10px]" />
          : <FaPlus className="text-[10px]" />}
        تسجيل
      </button>}
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function CourseRegistrationPage() {
  const canManage = hasPermission(PERMISSIONS.registrationManage)
  const [selectedStudent, setSelectedStudent] = useState(null)

  const [years,     setYears]     = useState([])
  const [semesters, setSemesters] = useState([])
  const [levels,    setLevels]    = useState([])
  const [programCourses, setProgramCourses] = useState([])
  const [offerings, setOfferings] = useState([])
  const [yearId,    setYearId]    = useState('')
  const [semId,     setSemId]     = useState('')

  const [available,   setAvailable]   = useState([])
  const [summary,     setSummary]     = useState(null)
  const [loadingData, setLoadingData] = useState(false)
  const [registering, setRegistering] = useState({})
  const [dropping,    setDropping]    = useState({})
  const [err,   setErr]   = useState('')
  const [toast, setToast] = useState('')

  useEffect(() => {
    Promise.all([
      get(`${API}/academic-years?per_page=50`),
      get(`${API}/semesters?per_page=20`),
      get(`${API}/academic-levels?per_page=20`),
      get(`${API}/program-courses?per_page=200`),
      get(`${API}/course-offerings?per_page=200`),
    ]).then(([y, s, lvl, pc, off]) => {
      setYears(y.success ? (y.data?.data ?? []) : [])
      setSemesters(s.success ? (s.data?.data ?? []) : [])
      setLevels(lvl.success ? (lvl.data?.data ?? []).filter(l => l.is_active).sort((a, b) => a.level_order - b.level_order) : [])
      setProgramCourses(pc.success ? (pc.data?.data ?? []) : [])
      setOfferings(off.success ? (off.data?.data ?? []) : [])
    })
  }, [])

  const programCourseMap = useMemo(() => {
    const map = new Map()
    if (!selectedStudent) return map
    programCourses
      .filter(pc => String(pc.academic_program_id) === String(selectedStudent.academic_program_id))
      .forEach(pc => map.set(pc.course_id, pc))
    return map
  }, [programCourses, selectedStudent])

  // Every distinct (year, semester) that currently has open offerings — more
  // than one term can be open at once, so staff must be able to pick which
  // one to register the student into instead of guessing at "the" term.
  const openTerms = useMemo(() => {
    const seen = new Map()
    offerings.filter(o => o.status === 'open').forEach(o => {
      const key = `${o.academic_year_id}-${o.semester_id}`
      if (seen.has(key)) return
      const yearName = years.find(y => String(y.academic_year_id) === String(o.academic_year_id))?.year_name ?? '—'
      const semName  = semesters.find(s => String(s.semester_id) === String(o.semester_id))?.semester_name ?? '—'
      seen.set(key, { academic_year_id: o.academic_year_id, semester_id: o.semester_id, label: `${yearName} — ${semName}` })
    })
    return [...seen.values()]
  }, [offerings, years, semesters])

  function loadData(studentId, yId, sId) {
    if (!studentId || !yId || !sId) return
    setAvailable([]); setSummary(null); setErr(''); setLoadingData(true)
    Promise.all([
      get(`${API}/students/${studentId}/available-courses?academic_year_id=${yId}&semester_id=${sId}`),
      get(`${API}/students/${studentId}/registration-summary?academic_year_id=${yId}&semester_id=${sId}`),
    ]).then(([av, sm]) => {
      if (av.success) setAvailable(Array.isArray(av.data) ? av.data : [])
      else setErr(av.message || 'فشل تحميل المواد المتاحة')
      if (sm.success) setSummary(sm.data)
    }).catch(() => setErr('تعذّر الاتصال بالخادم'))
      .finally(() => setLoadingData(false))
  }

  function showToast(msg) {
    setToast(msg)
    setTimeout(() => setToast(''), 3000)
  }

  function handleSelectStudent(student) {
    setSelectedStudent(student)
    setAvailable([]); setSummary(null); setErr('')
    if (openTerms.length > 0) {
      const t = openTerms[0]
      setYearId(String(t.academic_year_id)); setSemId(String(t.semester_id))
      loadData(student.student_id, t.academic_year_id, t.semester_id)
    } else {
      setYearId(''); setSemId('')
    }
  }

  function handleChangeStudent() {
    setSelectedStudent(null)
    setYearId(''); setSemId(''); setAvailable([]); setSummary(null); setErr('')
  }

  function handleChangeTerm(key) {
    const t = openTerms.find(t => `${t.academic_year_id}-${t.semester_id}` === key)
    if (!t || !selectedStudent) return
    setYearId(String(t.academic_year_id)); setSemId(String(t.semester_id))
    loadData(selectedStudent.student_id, t.academic_year_id, t.semester_id)
  }

  async function handleRegister(offeringId, name) {
    if (!canManage) { setErr('ليس لديك صلاحية إدارة تسجيل المواد'); return }
    setRegistering(p => ({ ...p, [offeringId]: true })); setErr('')
    try {
      const res  = await fetch(`${API}/registrations/register-student`, {
        method:  'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body:    JSON.stringify({ student_id: selectedStudent.student_id, course_offering_id: offeringId }),
      })
      const json = await res.json()
      if (json.success) {
        showToast(`تم تسجيل "${name}" للطالب بنجاح`)
        loadData(selectedStudent.student_id, yearId, semId)
      } else {
        setErr(json.message || 'فشل التسجيل')
      }
    } catch {
      setErr('تعذّر الاتصال بالخادم')
    } finally {
      setRegistering(p => ({ ...p, [offeringId]: false }))
    }
  }

  async function handleDrop(registrationId, name) {
    if (!canManage) { setErr('ليس لديك صلاحية إدارة تسجيل المواد'); return }
    if (!window.confirm(`هل تريد حذف تسجيل مادة "${name}"؟`)) return
    setDropping(p => ({ ...p, [registrationId]: true })); setErr('')
    try {
      const res  = await fetch(`${API}/registrations/${registrationId}/drop`, {
        method:  'POST',
        headers: authHeaders(),
      })
      const json = await res.json()
      if (json.success) {
        showToast(`تم حذف "${name}" من قائمة التسجيل`)
        loadData(selectedStudent.student_id, yearId, semId)
      } else {
        setErr(json.message || 'فشل الحذف')
      }
    } catch {
      setErr('تعذّر الاتصال بالخادم')
    } finally {
      setDropping(p => ({ ...p, [registrationId]: false }))
    }
  }

  const registrations = summary?.registrations ?? []

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">تسجيل المواد</h2>
        <p className="text-[12.5px] text-text-light">Course Registration</p>
      </div>

      {!selectedStudent ? (
        <StudentSearch onSelect={handleSelectStudent} />
      ) : (
        <>
          {/* Selected student bar */}
          <div className="flex items-center justify-between gap-3 bg-white border border-primary/12 rounded-[14px] px-5 py-3.5 mb-5 shadow-[0_2px_8px_rgba(26,46,16,0.05)] flex-wrap" dir="rtl">
            <div className="flex items-center gap-3">
              <div className="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-[14px] flex-shrink-0">
                {selectedStudent.first_name?.[0]}
              </div>
              <div>
                <div className="text-[14px] font-bold text-text-dark">{selectedStudent.first_name} {selectedStudent.last_name}</div>
                <div className="text-[11px] text-text-light font-mono">{selectedStudent.student_number}</div>
              </div>
            </div>
            <button
              onClick={handleChangeStudent}
              className="flex items-center gap-2 px-3.5 py-2 border border-primary/25 rounded-[9px] text-[12.5px] font-bold text-primary-dark hover:bg-primary/7 transition-colors"
            >
              <FaExchangeAlt className="text-[11px]" />
              تغيير الطالب
            </button>
          </div>

          {/* Term (year + semester) — inferred from what's already open in "فتح المواد الدراسية" */}
          {openTerms.length === 0 ? (
            <div className="bg-amber-50 border border-amber-200 rounded-[14px] px-5 py-4 mb-5" dir="rtl">
              <p className="text-[12.5px] text-amber-800 font-bold">لم يتم فتح أي مواد دراسية بعد</p>
              <p className="text-[11.5px] text-amber-700 mt-1">يرجى فتح المواد أولاً من صفحة "فتح المواد الدراسية" قبل تسجيل الطلاب.</p>
            </div>
          ) : openTerms.length === 1 ? (
            <div className="flex items-center gap-2 bg-white border border-primary/12 rounded-[14px] px-5 py-3 mb-5 shadow-[0_2px_8px_rgba(26,46,16,0.05)]" dir="rtl">
              <span className="text-[12px] font-bold text-text-light">الفصل الدراسي الحالي:</span>
              <span className="text-[13px] font-extrabold text-primary-dark">{openTerms[0].label}</span>
            </div>
          ) : (
            <div className="flex items-center gap-2 bg-white border border-primary/12 rounded-[14px] px-5 py-3 mb-5 shadow-[0_2px_8px_rgba(26,46,16,0.05)]" dir="rtl">
              <span className="text-[12px] font-bold text-text-light">الفصل الدراسي:</span>
              <select
                value={`${yearId}-${semId}`}
                onChange={e => handleChangeTerm(e.target.value)}
                className="px-3 py-1.5 border border-primary/20 rounded-[9px] text-[13px] font-bold text-primary-dark outline-none focus:border-primary"
                dir="rtl"
              >
                {openTerms.map(t => (
                  <option key={`${t.academic_year_id}-${t.semester_id}`} value={`${t.academic_year_id}-${t.semester_id}`}>
                    {t.label}
                  </option>
                ))}
              </select>
              <span className="text-[11px] text-text-light">— أكثر من فصل مفتوح حالياً</span>
            </div>
          )}

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
              <HoursBar
                registered={summary.total_registered_hours}
                max={summary.max_allowed_hours}
                remaining={summary.remaining_hours}
              />

              <div className="grid grid-cols-2 max-[860px]:grid-cols-1 gap-5">
                <RegisteredPanel
                  registrations={registrations}
                  onDrop={handleDrop}
                  dropping={dropping}
                  canManage={canManage}
                />
                <AvailablePanel
                  courses={available}
                  levels={levels}
                  programCourseMap={programCourseMap}
                  currentLevelId={selectedStudent.current_academic_level_id}
                  onRegister={handleRegister}
                  registering={registering}
                  canManage={canManage}
                />
              </div>
            </>
          )}

        </>
      )}
    </>
  )
}
