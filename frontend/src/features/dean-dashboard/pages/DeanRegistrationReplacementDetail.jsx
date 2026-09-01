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
    {request?.hours ? <section className="grid gap-3 rounded-[16px] border border-primary/12 bg-white p-5 sm:grid-cols-2 lg:grid-cols-5">{[
      ['الساعات الحالية', request.hours.registered_hours], ['ساعات الاستبدال', request.hours.replacement_hours], ['الساعات المتوقعة', request.hours.projected_hours], ['الحد الأعلى', request.hours.max_allowed_hours], ['المعدل الرسمي الحالي', request.hours.official_cgpa ?? '—'],
    ].map(([label, value]) => <div key={label} className="rounded-[10px] bg-primary/[0.05] p-3"><span className="block text-[11px] text-text-light">{label}</span><b>{value ?? '—'}</b></div>)}{request.hours.below_recommended_minimum ? <p className="sm:col-span-2 lg:col-span-5 text-[12px] font-bold text-amber-700">الساعات المتوقعة أقل من الحد الإرشادي 12 ساعة.</p> : null}</section> : null}
    <section className="space-y-2 rounded-[16px] border border-primary/12 bg-white p-5">{(request?.items ?? []).map(item => <div key={item.student_registration_replacement_item_id} className="rounded-[10px] border border-primary/10 p-3 text-[13px]"><b>{item.source_course?.course_code} — {item.source_course?.course_name}</b><span className="mx-2">←</span>{item.target_course?.course_code} — {item.target_course?.course_name}<p className="mt-2 text-[11px] text-text-light">الجدول الرسمي: {(item.official_timetable?.slots ?? []).map(slot => `${slot.iso_weekday} ${slot.starts_at}-${slot.ends_at}`).join('، ') || 'غير مكتمل'}</p>{(item.eligibility_failures ?? []).map((failure, index) => <p key={`${failure.reason}-${index}`} className="text-[11px] text-red-700">{failure.reason}</p>)}</div>)}</section>
    {(request?.failures ?? []).length > 0 ? <section className="rounded-[16px] bg-red-50 p-4 text-[12px] text-red-700"><h2 className="font-black">موانع الاعتماد الحالية</h2>{request.failures.map((failure, index) => <p key={`${failure.reason}-${index}`}>{failure.reason}</p>)}</section> : null}
    {(request?.events ?? []).length > 0 ? <section className="rounded-[16px] border border-primary/12 bg-white p-5"><h2 className="font-black">سجل الطلب</h2>{request.events.map((event, index) => <p key={`${event.event_type}-${index}`} className="mt-1 text-[11px] text-text-light">{event.event_type} — {event.created_at ?? '—'}</p>)}</section> : null}
    {request?.status === 'submitted' ? <section className="rounded-[16px] border border-primary/12 bg-white p-5"><textarea value={notes} onChange={event => setNotes(event.target.value)} placeholder="سبب الإعادة للتعديل" className="min-h-[90px] w-full rounded-[10px] border border-primary/20 p-3"/><div className="mt-3 flex gap-2"><button disabled={busy !== ''} onClick={() => act('approve')} className="rounded-[9px] bg-primary px-4 py-2 font-bold text-white">اعتماد</button><button disabled={busy !== '' || notes.trim().length < 8} onClick={() => act('return')} className="rounded-[9px] border border-orange-300 px-4 py-2 font-bold text-orange-800">إعادة للتعديل</button></div></section> : null}
  </div>
}
