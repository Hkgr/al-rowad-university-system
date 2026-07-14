import { useState, useEffect } from 'react'
import { FaSpinner, FaCheck, FaSave, FaBook, FaChevronDown, FaUsers } from 'react-icons/fa'
import useMyOfferings from '../hooks/useMyOfferings'
import { API, authHeaders } from '../lib/professorApi'

// ── shared helpers (copied from exam-board/pages/GradeEntryPage.jsx) ────────

function calcLetter(t, p) {
  const f = (t || 0) + (p || 0)
  if (t < 15 || p < 10 || f < 50) return { letter: 'F', color: 'text-red-600' }
  if (f >= 98) return { letter: 'A+', color: 'text-green-600' }
  if (f >= 95) return { letter: 'A',  color: 'text-green-600' }
  if (f >= 90) return { letter: 'A-', color: 'text-green-600' }
  if (f >= 85) return { letter: 'B+', color: 'text-blue-600'  }
  if (f >= 80) return { letter: 'B',  color: 'text-blue-600'  }
  if (f >= 75) return { letter: 'B-', color: 'text-blue-600'  }
  if (f >= 70) return { letter: 'C+', color: 'text-amber-600' }
  if (f >= 65) return { letter: 'C',  color: 'text-amber-600' }
  if (f >= 60) return { letter: 'C-', color: 'text-amber-600' }
  if (f >= 55) return { letter: 'D+', color: 'text-orange-500'}
  return { letter: f >= 50 ? 'D' : 'F', color: f >= 50 ? 'text-orange-500' : 'text-red-600' }
}

async function saveGrade(registrationId, theory, prac) {
  const body    = JSON.stringify({ theoretical_mark: theory, practical_mark: prac })
  const headers = { ...authHeaders(), 'Content-Type': 'application/json' }
  let res  = await fetch(`${API}/registrations/${registrationId}/grades`, { method: 'POST', headers, body })
  let json = await res.json()
  if (!json.success && json.message?.toLowerCase().includes('already exist')) {
    res  = await fetch(`${API}/registrations/${registrationId}/grades`, { method: 'PUT', headers, body })
    json = await res.json()
  }
  return json
}

function BulkRow({ row }) {
  const [theory,  setTheory]  = useState(row.theoretical_mark ?? '')
  const [prac,    setPrac]    = useState(row.practical_mark   ?? '')
  const [saving,  setSaving]  = useState(false)
  const [saved,   setSaved]   = useState(false)
  const [err,     setErr]     = useState('')

  const t   = parseFloat(theory) || 0
  const p   = parseFloat(prac)   || 0
  const fin = theory !== '' && prac !== '' ? t + p : null
  const { letter, color } = fin !== null ? calcLetter(t, p) : { letter: '—', color: 'text-text-light' }
  const tWarn = theory !== '' && t < 15
  const pWarn = prac   !== '' && p < 10

  async function handleSave() {
    if (theory === '' || prac === '') return
    setSaving(true); setErr('')
    try {
      const json = await saveGrade(row.student_course_registration_id, t, p)
      if (json.success) setSaved(true)
      else setErr(json.message || 'فشل')
    } catch { setErr('خطأ') }
    finally { setSaving(false) }
  }

  return (
    <tr className={`border-t border-primary/6 transition-colors ${saved ? 'bg-green-500/[0.03]' : 'hover:bg-primary/[0.02]'}`}>
      <td className="px-4 py-3" dir="rtl">
        <div className="font-semibold text-[13px] text-text-dark">{row.full_name}</div>
        <div className="text-[11px] text-text-light font-mono">{row.student_number}</div>
      </td>
      <td className="px-3 py-3">
        <input
          type="number" min="0" max="60" step="0.5"
          value={theory}
          onChange={e => { setTheory(e.target.value); setSaved(false) }}
          className={`w-[80px] px-2.5 py-1.5 border rounded-[8px] text-[13px] text-center outline-none focus:shadow-[0_0_0_2px_rgba(86,153,51,0.15)] ${tWarn ? 'border-red-400 bg-red-50' : 'border-primary/20 focus:border-primary'}`}
          dir="ltr"
        />
        {tWarn && <div className="text-[10px] text-red-500 text-center mt-0.5">min 15</div>}
      </td>
      <td className="px-3 py-3">
        <input
          type="number" min="0" max="40" step="0.5"
          value={prac}
          onChange={e => { setPrac(e.target.value); setSaved(false) }}
          className={`w-[80px] px-2.5 py-1.5 border rounded-[8px] text-[13px] text-center outline-none focus:shadow-[0_0_0_2px_rgba(86,153,51,0.15)] ${pWarn ? 'border-red-400 bg-red-50' : 'border-primary/20 focus:border-primary'}`}
          dir="ltr"
        />
        {pWarn && <div className="text-[10px] text-red-500 text-center mt-0.5">min 10</div>}
      </td>
      <td className="px-3 py-3 text-center font-bold text-text-dark">{fin ?? '—'}</td>
      <td className={`px-3 py-3 text-center text-[15px] font-black ${color}`}>{letter}</td>
      <td className="px-3 py-3 text-center">
        {saved
          ? <span className="inline-flex items-center gap-1 text-[11.5px] text-green-700 font-bold"><FaCheck className="text-[10px]" /> تم</span>
          : (
            <button
              onClick={handleSave}
              disabled={theory === '' || prac === '' || saving}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white rounded-[7px] text-[12px] font-bold disabled:opacity-40 hover:enabled:bg-primary-dark transition-colors"
            >
              {saving ? <FaSpinner className="animate-spin text-[10px]" /> : <FaSave className="text-[10px]" />}
              حفظ
            </button>
          )
        }
        {err && <div className="text-[10px] text-red-500 mt-0.5">{err}</div>}
      </td>
    </tr>
  )
}

// ── Roster panel for one selected offering ──────────────────────────────────

function RosterPanel({ offeringId }) {
  const [gradeSheet, setGradeSheet] = useState(null)
  const [loading,    setLoading]    = useState(true)
  const [error,      setError]      = useState('')

  useEffect(() => {
    setGradeSheet(null); setError(''); setLoading(true)
    fetch(`${API}/course-offerings/${offeringId}/grade-sheet`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setGradeSheet(json.data?.students ?? [])
        else setError(json.message || 'فشل تحميل كشف الدرجات')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }, [offeringId])

  if (loading) return <div className="flex justify-center py-10 text-primary"><FaSpinner className="animate-spin text-[22px]" /></div>
  if (error)   return <p className="text-center text-[13px] text-red-600 py-8" dir="rtl">⚠ {error}</p>
  if (!gradeSheet?.length) return <p className="text-center text-[13px] text-text-light py-8" dir="rtl">لا يوجد طلاب مسجلون في هذه المادة</p>

  return (
    <>
      <div className="flex gap-4 flex-wrap mb-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-[12px] text-[12px] text-amber-800" dir="rtl">
        <span>✦ نظري: الحد الأدنى <strong>15 / 60</strong></span>
        <span>✦ عملي: الحد الأدنى <strong>10 / 40</strong></span>
        <span>✦ المجموع: الحد الأدنى <strong>50 / 100</strong></span>
      </div>
      <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10 flex items-center gap-2" dir="rtl">
          <FaUsers className="text-[12px] text-primary" />
          <span className="text-[13px] font-extrabold text-text-dark">{gradeSheet.length} طالب</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-[13px]">
            <thead>
              <tr className="bg-[#fafaf8]">
                <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">الطالب</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">نظري / 60</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">عملي / 40</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">المجموع</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
                <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">حفظ</th>
              </tr>
            </thead>
            <tbody>
              {gradeSheet.map(row => (
                <BulkRow key={row.student_course_registration_id} row={row} />
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────

export default function MySubjectsGradeEntry() {
  const { facultyMember, semester, offerings, loading, error } = useMyOfferings()
  const [selectedId, setSelectedId] = useState(null)

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">إدخال الدرجات</h2>
        <p className="text-[12.5px] text-text-light">Grade Entry {semester ? `— ${semester.semester_name}` : ''}</p>
      </div>

      {loading && <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>}

      {!loading && error && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {error}</div>
      )}

      {!loading && !error && !facultyMember && (
        <p className="text-center text-[13px] text-text-light py-16" dir="rtl">لم يتم العثور على سجل عضو هيئة تدريس مرتبط بحسابك</p>
      )}

      {!loading && facultyMember && offerings.length === 0 && (
        <p className="text-center text-[13px] text-text-light py-16" dir="rtl">لا توجد مواد مسندة إليك هذا الفصل</p>
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
                </div>
                <FaChevronDown className={`text-[12px] text-primary/50 flex-shrink-0 transition-transform duration-200 ${active ? 'rotate-180' : ''}`} />
              </button>
            )
          })}
        </div>
      )}

      {selectedId && <RosterPanel offeringId={selectedId} />}
    </>
  )
}
