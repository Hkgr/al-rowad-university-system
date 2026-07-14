import { useState, useEffect, useCallback } from 'react'
import { useLocation } from 'react-router-dom'
import {
  FaSpinner, FaBook, FaChevronDown, FaPlus, FaCalendarCheck,
  FaExclamationTriangle, FaCheck, FaSave,
} from 'react-icons/fa'
import useMyOfferings from '../hooks/useMyOfferings'
import { API, authHeaders } from '../lib/professorApi'

const STATUS_OPTIONS = [
  { code: 'present', ar: 'حاضر',       color: 'bg-green-100 text-green-700 border-green-300' },
  { code: 'absent',  ar: 'غائب',       color: 'bg-red-100 text-red-700 border-red-300'       },
  { code: 'excused', ar: 'غياب بعذر', color: 'bg-amber-100 text-amber-700 border-amber-300' },
  { code: 'late',    ar: 'متأخر',      color: 'bg-blue-100 text-blue-700 border-blue-300'     },
]

// ── New session form ─────────────────────────────────────────────────────

function NewSessionForm({ offeringId, onCreated }) {
  const [date,    setDate]    = useState('')
  const [type,    setType]    = useState('theoretical')
  const [topic,   setTopic]   = useState('')
  const [saving,  setSaving]  = useState(false)
  const [err,     setErr]     = useState('')

  async function handleCreate() {
    if (!date) return
    setSaving(true); setErr('')
    try {
      const res = await fetch(`${API}/course-offerings/${offeringId}/attendance-sessions`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_date: date, session_type: type, topic: topic || undefined }),
      })
      const json = await res.json()
      if (json.success) {
        onCreated(json.data)
        setDate(''); setTopic('')
      } else {
        setErr(json.message || 'فشل إنشاء الجلسة')
      }
    } catch {
      setErr('تعذّر الاتصال بالخادم')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <div className="grid grid-cols-3 max-[700px]:grid-cols-1 gap-4 items-end" dir="rtl">
        <div className="flex flex-col gap-1.5">
          <label className="text-[12px] font-bold text-text-dark">التاريخ</label>
          <input
            type="date"
            value={date}
            onChange={e => setDate(e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[12px] font-bold text-text-dark">النوع</label>
          <select
            value={type}
            onChange={e => setType(e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
            dir="rtl"
          >
            <option value="theoretical">نظري</option>
            <option value="practical">عملي</option>
          </select>
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[12px] font-bold text-text-dark">الموضوع (اختياري)</label>
          <input
            type="text"
            value={topic}
            onChange={e => setTopic(e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
            dir="rtl"
          />
        </div>
      </div>
      <div className="flex items-center gap-3 mt-4" dir="rtl">
        <button
          onClick={handleCreate}
          disabled={!date || saving}
          className="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40 hover:enabled:bg-primary-dark transition-colors"
        >
          {saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaPlus className="text-[11px]" />}
          إضافة جلسة
        </button>
        {err && <span className="text-[12px] text-red-500">⚠ {err}</span>}
      </div>
    </div>
  )
}

// ── Attendance recording panel for one session ──────────────────────────────

function RecordingPanel({ session }) {
  const [students, setStudents] = useState(null)
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState('')
  const [edits,    setEdits]    = useState({})
  const [saving,   setSaving]   = useState(false)
  const [saved,    setSaved]    = useState(false)
  const [saveErr,  setSaveErr]  = useState('')

  useEffect(() => {
    setStudents(null); setError(''); setLoading(true); setEdits({}); setSaved(false); setSaveErr('')
    fetch(`${API}/attendance-sessions/${session.attendance_session_id}/students`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setStudents(json.data?.students ?? [])
        else setError(json.message || 'فشل تحميل قائمة الطلاب')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }, [session.attendance_session_id])

  function setStatus(regId, code) {
    setEdits(e => ({ ...e, [regId]: code }))
    setSaved(false)
  }

  async function handleSaveAll() {
    if (!students?.length) return
    const records = students
      .map(s => ({ student_course_registration_id: s.student_course_registration_id, status_code: edits[s.student_course_registration_id] ?? s.attendance_status?.status_code }))
      .filter(r => r.status_code)
    if (!records.length) return
    setSaving(true); setSaveErr('')
    try {
      const res = await fetch(`${API}/attendance-sessions/${session.attendance_session_id}/record`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ records }),
      })
      const json = await res.json()
      if (json.success) setSaved(true)
      else setSaveErr(json.message || 'فشل الحفظ')
    } catch {
      setSaveErr('تعذّر الاتصال بالخادم')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="flex justify-center py-10 text-primary"><FaSpinner className="animate-spin text-[22px]" /></div>
  if (error)   return <p className="text-center text-[13px] text-red-600 py-8" dir="rtl">⚠ {error}</p>
  if (!students?.length) return <p className="text-center text-[13px] text-text-light py-8" dir="rtl">لا يوجد طلاب مسجلون في هذه المادة</p>

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)] mb-6">
      <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10 flex items-center justify-between flex-wrap gap-2" dir="rtl">
        <span className="text-[13px] font-extrabold text-text-dark">تسجيل الحضور — {session.session_date}</span>
        <button
          onClick={handleSaveAll}
          disabled={saving}
          className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[9px] text-[12.5px] font-bold disabled:opacity-40 hover:enabled:bg-primary-dark transition-colors"
        >
          {saving ? <FaSpinner className="animate-spin text-[11px]" /> : saved ? <FaCheck className="text-[11px]" /> : <FaSave className="text-[11px]" />}
          {saved ? 'تم الحفظ' : 'حفظ الحضور'}
        </button>
      </div>

      {saveErr && <div className="px-5 py-2 text-[12px] text-red-500" dir="rtl">⚠ {saveErr}</div>}

      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr className="bg-[#fafaf8]">
              <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">الطالب</th>
              <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
            </tr>
          </thead>
          <tbody>
            {students.map(s => {
              const current = edits[s.student_course_registration_id] ?? s.attendance_status?.status_code ?? null
              return (
                <tr key={s.student_course_registration_id} className="border-t border-primary/6">
                  <td className="px-4 py-3" dir="rtl">
                    <div className="font-semibold text-[13px] text-text-dark">{s.full_name}</div>
                    <div className="text-[11px] text-text-light font-mono">{s.student_number}</div>
                  </td>
                  <td className="px-3 py-3">
                    <div className="flex items-center justify-center gap-1.5 flex-wrap">
                      {STATUS_OPTIONS.map(opt => (
                        <button
                          key={opt.code}
                          onClick={() => setStatus(s.student_course_registration_id, opt.code)}
                          className={`px-2.5 py-1 rounded-full text-[11px] font-bold border transition-colors ${current === opt.code ? opt.color : 'bg-white text-text-light border-gray-200 hover:border-gray-300'}`}
                        >
                          {opt.ar}
                        </button>
                      ))}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Sessions panel (list + create + select-to-record) ───────────────────────

function SessionsPanel({ offeringId }) {
  const [sessions,    setSessions]    = useState([])
  const [loading,     setLoading]     = useState(true)
  const [error,       setError]       = useState('')
  const [selectedId,  setSelectedId]  = useState(null)

  const loadSessions = useCallback(() => {
    setLoading(true); setError('')
    fetch(`${API}/course-offerings/${offeringId}/attendance-sessions`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setSessions(json.data?.sessions ?? [])
        else setError(json.message || 'فشل تحميل الجلسات')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }, [offeringId])

  useEffect(() => { setSelectedId(null); loadSessions() }, [offeringId, loadSessions])

  function handleCreated(session) {
    setSessions(s => [session, ...s])
    setSelectedId(session.attendance_session_id)
  }

  const selected = sessions.find(s => s.attendance_session_id === selectedId)

  return (
    <>
      <NewSessionForm offeringId={offeringId} onCreated={handleCreated} />

      {loading && <div className="flex justify-center py-8 text-primary"><FaSpinner className="animate-spin text-[20px]" /></div>}
      {!loading && error && <p className="text-center text-[13px] text-red-600 py-6" dir="rtl">⚠ {error}</p>}

      {!loading && !error && sessions.length === 0 && (
        <p className="text-center text-[13px] text-text-light py-8" dir="rtl">لا توجد جلسات حضور بعد لهذه المادة</p>
      )}

      {!loading && sessions.length > 0 && (
        <div className="flex gap-2.5 flex-wrap mb-5">
          {sessions.map(s => {
            const active = s.attendance_session_id === selectedId
            return (
              <button
                key={s.attendance_session_id}
                onClick={() => setSelectedId(active ? null : s.attendance_session_id)}
                className={`px-3.5 py-2 rounded-[10px] text-[12px] font-bold border transition-colors ${active ? 'bg-primary text-white border-primary' : 'bg-white text-text-dark border-primary/20 hover:border-primary/40'}`}
                dir="rtl"
              >
                {s.session_date} · {s.session_type === 'practical' ? 'عملي' : 'نظري'} · {s.recorded_count}/{s.total_students}
              </button>
            )
          })}
        </div>
      )}

      {selected && <RecordingPanel session={selected} />}
    </>
  )
}

// ── Deprivation panel ─────────────────────────────────────────────────────

function DeprivationPanel({ offeringId }) {
  const [data,     setData]     = useState(null)
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState('')
  const [applying, setApplying] = useState(false)
  const [result,   setResult]   = useState(null)

  const load = useCallback(() => {
    setLoading(true); setError('')
    fetch(`${API}/course-offerings/${offeringId}/deprived-students`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setData(json.data)
        else setError(json.message || 'فشل تحميل قائمة الحرمان')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }, [offeringId])

  useEffect(() => { setResult(null); load() }, [offeringId, load])

  async function handleApply() {
    if (!window.confirm('هل أنت متأكد من تطبيق الحرمان على الطلاب المتجاوزين لنسبة الغياب المسموحة؟')) return
    setApplying(true)
    try {
      const res = await fetch(`${API}/course-offerings/${offeringId}/apply-deprivation`, {
        method: 'POST', headers: authHeaders(),
      })
      const json = await res.json()
      if (json.success) { setResult(json.data); load() }
      else setError(json.message || 'فشل تطبيق الحرمان')
    } catch {
      setError('تعذّر الاتصال بالخادم')
    } finally {
      setApplying(false)
    }
  }

  if (loading) return <div className="flex justify-center py-10 text-primary"><FaSpinner className="animate-spin text-[22px]" /></div>
  if (error)   return <p className="text-center text-[13px] text-red-600 py-8" dir="rtl">⚠ {error}</p>

  const students = data?.students ?? []
  const pending  = students.filter(s => !s.is_already_deprived)

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <div className="px-5 py-3 bg-amber-500/[0.06] border-b border-amber-500/15 flex items-center justify-between flex-wrap gap-2" dir="rtl">
        <span className="text-[13px] font-extrabold text-text-dark flex items-center gap-2">
          <FaExclamationTriangle className="text-amber-500 text-[13px]" />
          مرشحون للحرمان (تجاوز {data?.deprivation_threshold ?? 15}%)
        </span>
        {pending.length > 0 && (
          <button
            onClick={handleApply}
            disabled={applying}
            className="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-[9px] text-[12.5px] font-bold disabled:opacity-40 hover:enabled:bg-red-700 transition-colors"
          >
            {applying ? <FaSpinner className="animate-spin text-[11px]" /> : <FaExclamationTriangle className="text-[11px]" />}
            تطبيق الحرمان
          </button>
        )}
      </div>

      {result && (
        <div className="px-5 py-2.5 bg-green-500/[0.05] border-b border-green-500/15 text-[12.5px] text-green-700 font-bold" dir="rtl">
          تم تطبيق الحرمان على {result.applied_count} طالب ({result.skipped_count} تم تجاوزهم)
        </div>
      )}

      {students.length === 0 ? (
        <p className="text-center text-[13px] text-text-light py-8" dir="rtl">لا يوجد طلاب متجاوزون لنسبة الغياب المسموحة حالياً</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-[13px]">
            <thead>
              <tr className="bg-[#fafaf8]">
                <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">الطالب</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الجلسات</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الغياب</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">النسبة</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {students.map(s => (
                <tr key={s.student_course_registration_id} className="border-t border-primary/6">
                  <td className="px-4 py-3" dir="rtl">
                    <div className="font-semibold text-[13px] text-text-dark">{s.full_name}</div>
                    <div className="text-[11px] text-text-light font-mono">{s.student_number}</div>
                  </td>
                  <td className="px-3 py-3 text-center">{s.total_sessions}</td>
                  <td className="px-3 py-3 text-center text-red-500 font-bold">{s.absent_count}</td>
                  <td className="px-3 py-3 text-center font-bold">{s.absence_percentage}%</td>
                  <td className="px-3 py-3 text-center">
                    {s.is_already_deprived
                      ? <span className="px-2.5 py-1 bg-red-500/10 border border-red-500/25 text-red-600 text-[11px] font-bold rounded-full">محروم</span>
                      : <span className="px-2.5 py-1 bg-amber-500/10 border border-amber-500/25 text-amber-700 text-[11px] font-bold rounded-full">مرشح</span>
                    }
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────

export default function AttendanceDeprivationPage() {
  const { facultyMember, offerings, loading, error } = useMyOfferings()
  const location = useLocation()
  const [selectedId, setSelectedId] = useState(location.state?.offeringId ?? null)

  useEffect(() => {
    if (location.state?.offeringId) setSelectedId(location.state.offeringId)
  }, [location.state])

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الحضور والحرمان</h2>
        <p className="text-[12.5px] text-text-light">Attendance & Deprivation</p>
      </div>

      {loading && <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>}

      {!loading && error && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {error}</div>
      )}

      {!loading && !error && !facultyMember && (
        <p className="text-center text-[13px] text-text-light py-16" dir="rtl">لم يتم العثور على سجل عضو هيئة تدريس مرتبط بحسابك</p>
      )}

      {!loading && facultyMember && offerings.length === 0 && (
        <p className="text-center text-[13px] text-text-light py-16" dir="rtl">لا توجد مواد مسندة إليك حالياً</p>
      )}

      {!loading && facultyMember && offerings.length > 0 && (
        <div className="grid grid-cols-3 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1 gap-4 mb-6">
          {offerings.map(o => {
            const active = selectedId === o.course_offering_id
            return (
              <button
                key={o.course_offering_id}
                onClick={() => setSelectedId(active ? null : o.course_offering_id)}
                className={`text-right bg-white border rounded-[16px] px-5 py-4 flex items-center gap-3 shadow-[0_2px_10px_rgba(26,46,16,0.05)] transition-all duration-200 ${active ? 'border-primary shadow-[0_4px_16px_rgba(86,153,51,0.15)]' : 'border-primary/12 hover:-translate-y-[2px]'}`}
                dir="rtl"
              >
                <div className="w-11 h-11 rounded-[11px] bg-primary/10 flex items-center justify-center text-[18px] text-primary flex-shrink-0">
                  <FaBook />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="font-bold text-[13.5px] text-text-dark truncate">{o.course?.course_name || o.course_name || `مادة #${o.course_offering_id}`}</div>
                  <div className="text-[11px] text-text-light font-mono mt-0.5">
                    {o.course?.course_code}{o.section_number ? ` — شعبة ${o.section_number}` : ''}
                  </div>
                  <div className="text-[10.5px] text-text-light mt-0.5 truncate">
                    {o.academic_year?.year_name} {o.semester?.semester_name ? `— ${o.semester.semester_name}` : ''}
                  </div>
                </div>
                <FaChevronDown className={`text-[12px] text-primary/50 flex-shrink-0 transition-transform duration-200 ${active ? 'rotate-180' : ''}`} />
              </button>
            )
          })}
        </div>
      )}

      {selectedId && (
        <>
          <div className="flex items-center gap-2 mb-3 text-[13px] font-bold text-text-dark" dir="rtl">
            <FaCalendarCheck className="text-primary text-[13px]" /> جلسات الحضور
          </div>
          <SessionsPanel offeringId={selectedId} />
          <div className="flex items-center gap-2 mb-3 mt-2 text-[13px] font-bold text-text-dark" dir="rtl">
            <FaExclamationTriangle className="text-amber-500 text-[13px]" /> الحرمان
          </div>
          <DeprivationPanel offeringId={selectedId} />
        </>
      )}
    </>
  )
}
