import { useState, useEffect } from 'react'
import { FaSpinner, FaCheckDouble, FaCheck } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function calcLetter(t, p) {
  const f = (t || 0) + (p || 0)
  if ((t || 0) < 15 || (p || 0) < 10 || f < 50) return { letter: 'F', color: 'text-red-600' }
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

function GradeRow({ row }) {
  const t = row.theoretical_mark
  const p = row.practical_mark
  const f = t != null && p != null ? t + p : null
  const { letter, color } = f !== null ? calcLetter(t, p) : { letter: '—', color: 'text-text-light' }

  return (
    <tr className="border-t border-primary/6 hover:bg-primary/[0.02]">
      <td className="px-4 py-3" dir="rtl">
        <div className="font-semibold text-[13px] text-text-dark">{row.student?.first_name} {row.student?.last_name}</div>
        <div className="text-[11px] text-text-light font-mono">{row.student?.student_number}</div>
      </td>
      <td className="px-3 py-3 text-center text-text-dark">{t ?? '—'}</td>
      <td className="px-3 py-3 text-center text-text-dark">{p ?? '—'}</td>
      <td className="px-3 py-3 text-center font-bold text-text-dark">{f ?? '—'}</td>
      <td className={`px-3 py-3 text-center text-[15px] font-black ${color}`}>{letter}</td>
    </tr>
  )
}

export default function ApprovalsPage() {
  const user = JSON.parse(localStorage.getItem('user') || '{}')

  const [years,             setYears]             = useState([])
  const [semesters,         setSemesters]         = useState([])
  const [filteredSemesters, setFilteredSemesters] = useState([])
  const [yearId,            setYearId]            = useState('')
  const [semId,             setSemId]             = useState('')
  const [offerings,         setOfferings]         = useState([])
  const [offeringId,        setOfferingId]        = useState('')
  const [gradeSheet,        setGradeSheet]        = useState(null)
  const [loadingInit,       setLoadingInit]       = useState(true)
  const [loadingFilter,     setLoadingFilter]     = useState(false)
  const [loadingOff,        setLoadingOff]        = useState(false)
  const [loadingGs,         setLoadingGs]         = useState(false)
  const [approving,         setApproving]         = useState(false)
  const [approved,          setApproved]          = useState(false)
  const [approveErr,        setApproveErr]        = useState('')

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
    setYearId(yId); setSemId(''); setOfferings([]); setOfferingId(''); setGradeSheet(null); setApproved(false); setApproveErr('')
    if (!yId) { setFilteredSemesters(allSems); return }
    setLoadingFilter(true)
    Promise.all(
      allSems.map(s =>
        fetch(`${API}/course-offerings/by-semester?academic_year_id=${yId}&semester_id=${s.semester_id}&per_page=1`, { headers: authHeaders() })
          .then(r => r.json())
          .then(json => ({ sem: s, has: json.success && (json.data?.data?.length ?? 0) > 0 }))
          .catch(() => ({ sem: s, has: false }))
      )
    ).then(results => {
      const valid = results.filter(r => r.has).map(r => r.sem)
      const list  = valid.length > 0 ? valid : allSems
      setFilteredSemesters(list)
      if (valid.length === 1) {
        const sId = String(valid[0].semester_id)
        setSemId(sId)
        loadOfferings(yId, sId)
      }
    }).finally(() => setLoadingFilter(false))
  }

  function loadOfferings(yId, sId) {
    if (!yId || !sId) return
    setOfferings([]); setOfferingId(''); setGradeSheet(null); setApproved(false); setApproveErr('')
    setLoadingOff(true)
    fetch(`${API}/course-offerings/by-semester?academic_year_id=${yId}&semester_id=${sId}&per_page=100`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => setOfferings(json.success ? (json.data?.data ?? json.data ?? []) : []))
      .finally(() => setLoadingOff(false))
  }

  function loadGradeSheet(offId) {
    if (!offId) return
    setGradeSheet(null); setApproved(false); setApproveErr(''); setLoadingGs(true)
    fetch(`${API}/course-offerings/${offId}/grade-sheet`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => setGradeSheet(json.success ? (json.data?.data ?? json.data ?? []) : []))
      .finally(() => setLoadingGs(false))
  }

  async function handleApprove() {
    if (!offeringId) return
    setApproving(true); setApproveErr('')
    try {
      const res  = await fetch(`${API}/grade-approvals`, {
        method:  'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          course_offering_id:   parseInt(offeringId),
          approval_status_id:   2,
          submitted_by_user_id: user.user_id,
        }),
      })
      const json = await res.json()
      if (json.success) setApproved(true)
      else setApproveErr(json.message || 'فشل الاعتماد')
    } catch { setApproveErr('تعذّر الاتصال بالخادم') }
    finally { setApproving(false) }
  }

  if (loadingInit) return <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">اعتماد الدرجات</h2>
        <p className="text-[12.5px] text-text-light">Grade Approvals</p>
      </div>

      {/* Filters */}
      <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-3 max-[700px]:grid-cols-1 gap-4" dir="rtl">
          {/* Year */}
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
          {/* Semester */}
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">
              الفصل الدراسي
              {loadingFilter && <FaSpinner className="inline mr-2 animate-spin text-[11px] text-primary" />}
            </label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
              value={semId}
              onChange={e => { setSemId(e.target.value); loadOfferings(yearId, e.target.value) }}
              disabled={!yearId || loadingFilter}
              dir="rtl"
            >
              <option value="">اختر الفصل</option>
              {filteredSemesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}
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

      {loadingGs && <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>}

      {gradeSheet && !loadingGs && (
        gradeSheet.length === 0
          ? <p className="text-center text-[13px] text-text-light py-10" dir="rtl">لا يوجد طلاب مسجلون في هذه المادة</p>
          : (
            <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
              {/* Table header with approve button */}
              <div className="flex items-center justify-between px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
                <span className="text-[13px] font-extrabold text-text-dark">{gradeSheet.length} طالب</span>
                {approved ? (
                  <span className="inline-flex items-center gap-2 px-4 py-1.5 bg-green-500/10 text-green-700 border border-green-500/25 rounded-[8px] text-[13px] font-bold">
                    <FaCheckDouble className="text-[12px]" /> تم اعتماد المادة
                  </span>
                ) : (
                  <button
                    onClick={handleApprove}
                    disabled={approving}
                    className="flex items-center gap-2 px-4 py-1.5 bg-primary text-white rounded-[8px] text-[13px] font-bold disabled:opacity-50 hover:enabled:bg-primary-dark transition-colors"
                  >
                    {approving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaCheck className="text-[11px]" />}
                    اعتماد المادة
                  </button>
                )}
              </div>

              {approveErr && (
                <p className="px-5 py-2 text-[12px] text-red-600 bg-red-50 border-b border-red-100" dir="rtl">⚠ {approveErr}</p>
              )}

              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-[13px]">
                  <thead>
                    <tr className="bg-[#fafaf8]">
                      <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">الطالب</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">نظري / 60</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">عملي / 40</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">المجموع</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
                    </tr>
                  </thead>
                  <tbody>
                    {gradeSheet.map(row => (
                      <GradeRow key={row.student_course_registration_id} row={row} />
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )
      )}
    </>
  )
}
