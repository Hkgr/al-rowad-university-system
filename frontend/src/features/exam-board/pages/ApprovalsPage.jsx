import { useState } from 'react'
import { FaSpinner, FaCheckDouble, FaCheck } from 'react-icons/fa'
import StudentPicker from '../components/StudentPicker'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

export default function ApprovalsPage() {
  const [selected,   setSelected]   = useState(null)
  const [transcript, setTranscript] = useState(null)
  const [loading,    setLoading]    = useState(false)
  const [error,      setError]      = useState('')
  const [approving,  setApproving]  = useState({})
  const [approved,   setApproved]   = useState({})

  function handleSelect(student) {
    setSelected(student)
    setTranscript(null)
    setError('')
    setApproved({})
    setLoading(true)
    fetch(`${API}/students/${student.student_id}/transcript`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setTranscript(json.data)
        else setError(json.message || 'فشل تحميل البيانات')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }

  async function handleApprove(registrationId) {
    setApproving(p => ({ ...p, [registrationId]: true }))
    try {
      const res  = await fetch(`${API}/grade-approvals`, {
        method:  'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          student_course_registration_id: registrationId,
          approval_role: 'exam_board',
          approval_notes: '',
        }),
      })
      const json = await res.json()
      if (json.success) setApproved(p => ({ ...p, [registrationId]: true }))
    } finally {
      setApproving(p => ({ ...p, [registrationId]: false }))
    }
  }

  const terms = transcript?.terms ?? []

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">اعتماد الدرجات</h2>
        <p className="text-[12.5px] text-text-light">Grade Approvals</p>
      </div>

      <StudentPicker onSelect={handleSelect} selected={selected} />

      {loading && (
        <div className="flex justify-center py-16 text-primary">
          <FaSpinner className="animate-spin text-[28px]" />
        </div>
      )}

      {error && <p className="text-center text-red-600 text-[13px] py-8" dir="rtl">⚠ {error}</p>}

      {transcript && !loading && (
        <>
          <div className="flex items-center gap-3 mb-4" dir="rtl">
            <span className="text-[15px] font-extrabold text-text-dark">{selected.first_name} {selected.last_name}</span>
            <span className="text-[12px] text-text-light font-mono">{selected.student_number}</span>
          </div>

          {terms.length === 0 ? (
            <div className="flex flex-col items-center py-16 gap-2">
              <FaCheckDouble className="text-[42px] text-primary/15" />
              <p className="text-[13px] text-text-light" dir="rtl">لا توجد درجات للاعتماد</p>
            </div>
          ) : (
            <div className="space-y-4">
              {terms.map((term, ti) => (
                <div key={ti} className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
                  <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
                    <span className="text-[14px] font-extrabold text-primary-dark">{term.academic_year?.year_name}</span>
                    <span className="text-primary/30">•</span>
                    <span className="text-[13px] font-semibold text-text-dark">{term.semester?.semester_name}</span>
                  </div>
                  <div className="divide-y divide-primary/6">
                    {term.courses.map((c, ci) => {
                      const regId       = c.student_course_registration_id
                      const isApproved  = approved[regId]
                      const isApproving = approving[regId]
                      const hasMark     = c.final_mark !== null && c.final_mark !== undefined

                      return (
                        <div key={ci} className="flex items-center justify-between gap-4 px-5 py-3.5" dir="rtl">
                          <div className="flex-1 min-w-0">
                            <div className="font-semibold text-[13.5px] text-text-dark">{c.course_name}</div>
                            <div className="text-[11px] text-text-light font-mono">{c.course_code}</div>
                          </div>
                          <div className="flex items-center gap-3 flex-shrink-0">
                            <div className="text-center">
                              <div className="text-[18px] font-black text-text-dark">{c.final_mark ?? '—'}</div>
                              <div className="text-[10px] text-text-light">المجموع</div>
                            </div>
                            <div className="text-center min-w-[36px]">
                              <div className={`text-[16px] font-black ${c.letter_grade?.startsWith('A') ? 'text-green-600' : c.letter_grade?.startsWith('B') ? 'text-blue-600' : c.letter_grade?.startsWith('F') ? 'text-red-600' : 'text-amber-600'}`}>
                                {c.letter_grade || '—'}
                              </div>
                              <div className="text-[10px] text-text-light">التقدير</div>
                            </div>
                            {hasMark && (
                              <button
                                onClick={() => handleApprove(regId)}
                                disabled={isApproved || isApproving}
                                className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-[8px] text-[12px] font-bold transition-all ${
                                  isApproved
                                    ? 'bg-green-500/10 text-green-700 border border-green-500/25 cursor-default'
                                    : 'bg-primary text-white hover:bg-primary-dark disabled:opacity-50'
                                }`}
                              >
                                {isApproving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaCheck className="text-[11px]" />}
                                {isApproved ? 'تم الاعتماد' : 'اعتماد'}
                              </button>
                            )}
                          </div>
                        </div>
                      )
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </>
  )
}
