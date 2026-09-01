import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'

export default function DeanRegistrationReplacementDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [request, setRequest] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')
  const [notes, setNotes] = useState('')
  async function load() { setLoading(true); try { const response = await apiRequest(`/v1/academic-advising/registration-replacements/${id}`); setRequest(response?.data ?? null) } catch (e) { setError(e?.message || 'تعذر تحميل طلب الاستبدال.') } finally { setLoading(false) } }
  useEffect(() => { load() }, [id])
  async function act(action) { setBusy(action); setError(''); try { await apiRequest(`/v1/academic-advising/registration-replacements/${id}/${action}`, { method: 'POST', body: JSON.stringify(action === 'return' ? { advisor_notes: notes } : {}) }); await load() } catch (e) { setError(e?.message || 'تعذر تنفيذ القرار.') } finally { setBusy('') } }
  if (loading) return <p dir="rtl">جاري التحميل…</p>
  return <div className="space-y-5" dir="rtl">
    <button type="button" className="font-bold text-primary" onClick={() => navigate('/dean/registration-requests')}>← العودة إلى الطلبات</button>
    <section className="rounded-[18px] border border-primary/12 bg-white p-6"><h1 className="text-[21px] font-black">طلب استبدال المقررات الملغاة</h1><p className="mt-2 text-[13px]">{request?.student?.student_number} — {request?.student?.full_name}</p><p className="text-[12px] font-bold text-primary">الحالة: {request?.status}</p></section>
    {error ? <p className="rounded-[10px] bg-red-50 p-3 text-red-700">{error}</p> : null}
    <section className="space-y-2 rounded-[16px] border border-primary/12 bg-white p-5">{(request?.items ?? []).map(item => <div key={item.student_registration_replacement_item_id} className="rounded-[10px] border border-primary/10 p-3 text-[13px]"><b>{item.source_course?.course_code} — {item.source_course?.course_name}</b><span className="mx-2">←</span>{item.target_course?.course_code} — {item.target_course?.course_name}</div>)}</section>
    {request?.status === 'submitted' ? <section className="rounded-[16px] border border-primary/12 bg-white p-5"><textarea value={notes} onChange={event => setNotes(event.target.value)} placeholder="سبب الإعادة للتعديل" className="min-h-[90px] w-full rounded-[10px] border border-primary/20 p-3"/><div className="mt-3 flex gap-2"><button disabled={busy !== ''} onClick={() => act('approve')} className="rounded-[9px] bg-primary px-4 py-2 font-bold text-white">اعتماد</button><button disabled={busy !== '' || notes.trim().length < 8} onClick={() => act('return')} className="rounded-[9px] border border-orange-300 px-4 py-2 font-bold text-orange-800">إعادة للتعديل</button></div></section> : null}
  </div>
}
