import { useEffect, useState } from 'react'
import { FaCheck, FaSpinner, FaTimes } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json', 'Content-Type': 'application/json' }
}

export default function VicePresidentOfferingsPage() {
  const [proposals, setProposals] = useState([])
  const [loading, setLoading] = useState(true)
  const [busyId, setBusyId] = useState(null)
  const [error, setError] = useState('')

  function load() {
    setLoading(true)
    fetch(`${API}/offering-proposals?status=submitted`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => setProposals(json.success ? (json.data ?? []) : []))
      .catch(() => setError('تعذّر الاتصال'))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [])

  async function decide(id, action) {
    setBusyId(id)
    setError('')
    try {
      const response = await fetch(`${API}/offering-proposals/${id}/${action}`, { method: 'POST', headers: authHeaders(), body: '{}' })
      const json = await response.json()
      if (!json.success) setError(json.message || 'تعذّر حفظ القرار')
      else setProposals(prev => prev.filter(proposal => proposal.offering_proposal_id !== id))
    } catch {
      setError('تعذّر الاتصال')
    } finally {
      setBusyId(null)
    }
  }

  if (loading) return <div className="text-primary py-10 flex justify-center"><FaSpinner className="animate-spin" /></div>

  return (
    <div dir="rtl">
      <h2 className="text-[20px] font-black text-text-dark mb-1">اعتماد طرح الفصل</h2>
      <p className="text-[12.5px] text-text-light mb-5">الموافقة تفتح المواد للتسجيل، بما فيها الإجبارية المرسلة بلا مدرس.</p>
      {error && <p className="text-red-600 text-[13px] mb-3">{error}</p>}
      {proposals.length === 0 && <p className="text-text-light">لا توجد طروحات بانتظار الموافقة.</p>}
      <div className="flex flex-col gap-4">
        {proposals.map(proposal => (
          <article key={proposal.offering_proposal_id} className="bg-white border border-primary/12 rounded-[14px] p-4">
            <div className="font-black mb-3">
              {proposal.department?.department_name} · {proposal.academic_year?.year_name} · {proposal.semester?.semester_name}
            </div>
            <ul className="flex flex-col gap-2 mb-4">
              {proposal.items.map(item => (
                <li key={item.offering_proposal_item_id} className="text-[13px] flex flex-wrap justify-between gap-2 border-b border-primary/8 pb-2">
                  <span>{item.course_name} · {item.course_type === 'mandatory' ? 'إجباري' : 'اختياري'} · {item.program_name}</span>
                  {item.instructor_exception
                    ? <span className="font-bold text-amber-800">{item.exception_reason}</span>
                    : <span className="text-text-light">بمدرس</span>}
                </li>
              ))}
            </ul>
            <div className="flex gap-2">
              <button type="button" disabled={busyId === proposal.offering_proposal_id} onClick={() => decide(proposal.offering_proposal_id, 'approve')} className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[9px] font-bold disabled:opacity-40">
                <FaCheck /> موافقة
              </button>
              <button type="button" disabled={busyId === proposal.offering_proposal_id} onClick={() => decide(proposal.offering_proposal_id, 'reject')} className="inline-flex items-center gap-2 px-4 py-2 bg-red-700 text-white rounded-[9px] font-bold disabled:opacity-40">
                <FaTimes /> رفض
              </button>
            </div>
          </article>
        ))}
      </div>
    </div>
  )
}
