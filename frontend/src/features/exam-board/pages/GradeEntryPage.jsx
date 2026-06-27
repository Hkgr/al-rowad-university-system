import { useState, useEffect } from 'react'
import { FaSpinner, FaCheck, FaSave, FaUsers, FaUserEdit } from 'react-icons/fa'
import StudentPicker from '../components/StudentPicker'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

// ── shared helpers ───────────────────────────────────────────────────────────

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

// ── Grade row used in the bulk table ─────────────────────────────────────────

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
      {/* Student */}
      <td className="px-4 py-3" dir="rtl">
        <div className="font-semibold text-[13px] text-text-dark">{row.student?.first_name} {row.student?.last_name}</div>
        <div className="text-[11px] text-text-light font-mono">{row.student?.student_number}</div>
      </td>
      {/* Theoretical */}
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
      {/* Practical */}
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
      {/* Final preview */}
      <td className="px-3 py-3 text-center font-bold text-text-dark">{fin ?? '—'}</td>
      {/* Letter */}
      <td className={`px-3 py-3 text-center text-[15px] font-black ${color}`}>{letter}</td>
      {/* Save */}
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

// ── MODE 1: Bulk (course → students table) ───────────────────────────────────

function BulkMode() {
  const [years,       setYears]       = useState([])
  const [semesters,   setSemesters]   = useState([])
  const [yearId,      setYearId]      = useState('')
  const [semId,       setSemId]       = useState('')
  const [offerings,   setOfferings]   = useState([])
  const [offeringId,  setOfferingId]  = useState('')
  const [gradeSheet,  setGradeSheet]  = useState(null)
  const [loadingInit, setLoadingInit] = useState(true)
  const [loadingOff,  setLoadingOff]  = useState(false)
  const [loadingGs,   setLoadingGs]   = useState(false)

  useEffect(() => {
    Promise.all([
      fetch(`${API}/academic-years?per_page=50`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/semesters?per_page=20`,      { headers: authHeaders() }).then(r => r.json()),
    ]).then(([y, s]) => {
      setYears(y.success     ? (y.data?.data ?? y.data ?? []) : [])
      setSemesters(s.success ? (s.data?.data ?? s.data ?? []) : [])
    }).finally(() => setLoadingInit(false))
  }, [])

  function loadOfferings(yId, sId) {
    if (!yId || !sId) return
    setOfferings([]); setOfferingId(''); setGradeSheet(null)
    setLoadingOff(true)
    fetch(`${API}/course-offerings/by-semester?academic_year_id=${yId}&semester_id=${sId}&per_page=100`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => setOfferings(json.success ? (json.data?.data ?? json.data ?? []) : []))
      .finally(() => setLoadingOff(false))
  }

  function loadGradeSheet(offId) {
    if (!offId) return
    setGradeSheet(null); setLoadingGs(true)
    fetch(`${API}/course-offerings/${offId}/grade-sheet`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => setGradeSheet(json.success ? (json.data?.data ?? json.data ?? []) : []))
      .finally(() => setLoadingGs(false))
  }

  if (loadingInit) return <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>

  return (
    <>
      {/* Filters */}
      <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-3 max-[700px]:grid-cols-1 gap-4" dir="rtl">
          {/* Year */}
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">السنة الدراسية</label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
              value={yearId}
              onChange={e => { setYearId(e.target.value); loadOfferings(e.target.value, semId) }}
              dir="rtl"
            >
              <option value="">اختر السنة</option>
              {years.map(y => <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>)}
            </select>
          </div>
          {/* Semester */}
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">الفصل الدراسي</label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
              value={semId}
              onChange={e => { setSemId(e.target.value); loadOfferings(yearId, e.target.value) }}
              dir="rtl"
            >
              <option value="">اختر الفصل</option>
              {semesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}
            </select>
          </div>
          {/* Course offering */}
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">
              المادة
              {loadingOff && <FaSpinner className="inline mr-2 animate-spin text-[11px] text-primary" />}
            </label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
              value={offeringId}
              onChange={e => { setOfferingId(e.target.value); loadGradeSheet(e.target.value) }}
              disabled={offerings.length === 0}
              dir="rtl"
            >
              <option value="">اختر المادة</option>
              {offerings.map(o => (
                <option key={o.course_offering_id} value={o.course_offering_id}>
                  {o.course?.course_name || o.course_name || `Offering #${o.course_offering_id}`}
                  {o.section_number ? ` — شعبة ${o.section_number}` : ''}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {/* Grade sheet table */}
      {loadingGs && <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>}

      {gradeSheet && !loadingGs && (
        <>
          {/* Rules reminder */}
          <div className="flex gap-4 flex-wrap mb-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-[12px] text-[12px] text-amber-800" dir="rtl">
            <span>✦ نظري: الحد الأدنى <strong>15 / 60</strong></span>
            <span>✦ عملي: الحد الأدنى <strong>10 / 40</strong></span>
            <span>✦ المجموع: الحد الأدنى <strong>50 / 100</strong></span>
          </div>

          {gradeSheet.length === 0
            ? <p className="text-center text-[13px] text-text-light py-10" dir="rtl">لا يوجد طلاب مسجلون في هذه المادة</p>
            : (
              <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
                <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10 flex items-center gap-2" dir="rtl">
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
            )
          }
        </>
      )}
    </>
  )
}

// ── MODE 2: Individual (student → course → grade) ────────────────────────────

function IndividualMode() {
  const [selected, setSelected] = useState(null)
  const [regs,     setRegs]     = useState([])
  const [regId,    setRegId]    = useState('')
  const [current,  setCurrent]  = useState(null)
  const [theory,   setTheory]   = useState('')
  const [prac,     setPrac]     = useState('')
  const [saving,   setSaving]   = useState(false)
  const [saved,    setSaved]    = useState(false)
  const [err,      setErr]      = useState('')
  const [loadRegs, setLoadRegs] = useState(false)
  const [loadGrade,setLoadGrade]= useState(false)

  function handleSelect(student) {
    setSelected(student); setRegs([]); setRegId(''); setCurrent(null)
    setTheory(''); setPrac(''); setSaved(false); setErr('')
    setLoadRegs(true)
    fetch(`${API}/student-course-registrations?student_id=${student.student_id}&per_page=100`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => setRegs(json.success ? (json.data?.data ?? json.data ?? []) : []))
      .finally(() => setLoadRegs(false))
  }

  function handlePickReg(id) {
    setRegId(id); setCurrent(null); setTheory(''); setPrac(''); setSaved(false); setErr('')
    if (!id) return
    setLoadGrade(true)
    fetch(`${API}/registrations/${id}/grades`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) {
          setCurrent(json.data)
          setTheory(json.data.theoretical_mark ?? '')
          setPrac(json.data.practical_mark ?? '')
        }
      })
      .finally(() => setLoadGrade(false))
  }

  const t   = parseFloat(theory) || 0
  const p   = parseFloat(prac)   || 0
  const fin = theory !== '' && prac !== '' ? t + p : null
  const { letter, color } = fin !== null ? calcLetter(t, p) : { letter: '—', color: 'text-text-light' }

  async function handleSave() {
    if (!regId || theory === '' || prac === '') return
    setSaving(true); setErr(''); setSaved(false)
    try {
      const json = await saveGrade(regId, t, p)
      if (json.success) setSaved(true)
      else setErr(json.message || 'فشل الحفظ')
    } catch { setErr('تعذّر الاتصال') }
    finally { setSaving(false) }
  }

  return (
    <>
      <StudentPicker onSelect={handleSelect} selected={selected} />

      {loadRegs && <div className="flex justify-center py-8 text-primary"><FaSpinner className="animate-spin text-[22px]" /></div>}

      {regs.length > 0 && (
        <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
          <label className="text-[12px] font-bold text-text-dark block mb-2" dir="rtl">اختر المادة</label>
          <select
            className="w-full px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
            value={regId}
            onChange={e => handlePickReg(e.target.value)}
            dir="rtl"
          >
            <option value="">— اختر مادة —</option>
            {regs.map(r => (
              <option key={r.student_course_registration_id} value={r.student_course_registration_id}>
                {r.course?.course_name || `Registration #${r.student_course_registration_id}`}
                {r.course?.course_code ? ` (${r.course.course_code})` : ''}
              </option>
            ))}
          </select>
        </div>
      )}

      {loadGrade && <div className="flex justify-center py-8 text-primary"><FaSpinner className="animate-spin text-[22px]" /></div>}

      {regId && !loadGrade && (
        <div className="bg-white border border-primary/12 rounded-[18px] p-6 shadow-[0_2px_12px_rgba(26,46,16,0.06)]">
          {current && (
            <div className="flex items-center gap-2 mb-5 pb-3 border-b border-primary/10" dir="rtl">
              <span className="text-[13px] text-text-light">الدرجة الحالية:</span>
              <span className="font-bold text-text-dark">{current.final_mark ?? '—'} / 100</span>
              <span className={`text-[15px] font-black mr-2 ${current.letter_grade?.startsWith('A') ? 'text-green-600' : current.letter_grade?.startsWith('B') ? 'text-blue-600' : 'text-red-600'}`}>
                {current.letter_grade || '—'}
              </span>
            </div>
          )}

          <div className="flex items-end gap-4 flex-wrap" dir="rtl">
            <div className="flex flex-col gap-1.5">
              <label className="text-[12px] font-bold text-text-dark">نظري (0 – 60)</label>
              <input
                type="number" min="0" max="60" step="0.5"
                value={theory}
                onChange={e => { setTheory(e.target.value); setSaved(false) }}
                className={`w-[130px] px-3 py-2.5 border rounded-[10px] text-[14px] text-center outline-none focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] ${theory !== '' && t < 15 ? 'border-red-400 bg-red-50' : 'border-primary/20 focus:border-primary'}`}
                dir="ltr"
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <label className="text-[12px] font-bold text-text-dark">عملي (0 – 40)</label>
              <input
                type="number" min="0" max="40" step="0.5"
                value={prac}
                onChange={e => { setPrac(e.target.value); setSaved(false) }}
                className={`w-[130px] px-3 py-2.5 border rounded-[10px] text-[14px] text-center outline-none focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)] ${prac !== '' && p < 10 ? 'border-red-400 bg-red-50' : 'border-primary/20 focus:border-primary'}`}
                dir="ltr"
              />
            </div>

            {/* Preview */}
            <div className="flex items-center gap-4 px-5 py-3 bg-gray-50 border border-gray-200 rounded-[12px]" dir="rtl">
              <div className="text-center">
                <div className="text-[28px] font-black text-text-dark leading-none">{fin ?? '—'}</div>
                <div className="text-[10px] text-text-light mt-1">المجموع</div>
              </div>
              <div className="w-px h-10 bg-gray-200" />
              <div className="text-center">
                <div className={`text-[28px] font-black leading-none ${color}`}>{letter}</div>
                <div className="text-[10px] text-text-light mt-1">التقدير</div>
              </div>
            </div>

            <button
              onClick={handleSave}
              disabled={theory === '' || prac === '' || saving}
              className="flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13.5px] font-bold disabled:opacity-40 disabled:cursor-not-allowed hover:enabled:bg-primary-dark transition-colors"
            >
              {saving ? <FaSpinner className="animate-spin" /> : saved ? <FaCheck /> : <FaSave />}
              {saved ? 'تم الحفظ' : 'حفظ'}
            </button>
          </div>
          {err && <p className="mt-3 text-[12.5px] text-red-600" dir="rtl">⚠ {err}</p>}
        </div>
      )}
    </>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function GradeEntryPage() {
  const [mode, setMode] = useState('bulk')

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">إدخال الدرجات</h2>
        <p className="text-[12.5px] text-text-light">Grade Entry</p>
      </div>

      {/* Mode toggle */}
      <div className="flex gap-2 mb-6 p-1.5 bg-gray-100 rounded-[12px] w-fit" dir="rtl">
        <button
          onClick={() => setMode('bulk')}
          className={`flex items-center gap-2 px-4 py-2 rounded-[9px] text-[13px] font-bold transition-all ${mode === 'bulk' ? 'bg-white text-primary shadow-sm' : 'text-text-gray hover:text-text-dark'}`}
        >
          <FaUsers className="text-[12px]" />
          إدخال جماعي
        </button>
        <button
          onClick={() => setMode('individual')}
          className={`flex items-center gap-2 px-4 py-2 rounded-[9px] text-[13px] font-bold transition-all ${mode === 'individual' ? 'bg-white text-primary shadow-sm' : 'text-text-gray hover:text-text-dark'}`}
        >
          <FaUserEdit className="text-[12px]" />
          تعديل طالب
        </button>
      </div>

      {mode === 'bulk'       ? <BulkMode />       : <IndividualMode />}
    </>
  )
}
