import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import DeanConfirmDialog from '../components/DeanConfirmDialog'
import OfficialTimetable from '../../registration-requests/OfficialTimetable'

const LABELS = {
  draft: 'مسودة', submitted: 'بانتظار المراجعة', returned: 'أعيد للتعديل',
  approved: 'معتمد', expired: 'منتهي', superseded: 'مستبدل لتغير التسجيل الرسمي',
}

export default function DeanRegistrationModificationDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [request, setRequest] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState(null)
  const [notes, setNotes] = useState('')

  async function load() {
    setLoading(true)
    setError('')
    try {
      const response = await apiRequest(`/v1/academic-advising/registration-modifications/${id}`)
      setRequest(response?.data ?? null)
    } catch (requestError) {
      setError(requestError?.message || 'تعذر تحميل طلب تعديل التسجيل.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  async function decide(action) {
    setBusy(true)
    setError('')
    try {
      const response = await apiRequest(`/v1/academic-advising/registration-modifications/${id}/${action}`, {
        method: 'POST',
        body: JSON.stringify(action === 'return' ? { advisor_notes: notes } : {}),
      })
      setRequest(response?.data ?? null)
      setDialog(null)
      setNotes('')
    } catch (requestError) {
      const failures = requestError?.itemFailures ?? []
      setError([requestError?.message, ...failures.map(item => item.reason)].filter(Boolean).join(' — ') || 'تعذر تنفيذ القرار.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <div className="flex justify-center py-20 text-primary"><FaSpinner className="animate-spin text-[30px]" /></div>
  if (!request) return <p className="text-red-700">{error || 'الطلب غير موجود.'}</p>

  const items = request.items ?? []
  const baseline = items.filter(item => item.operation !== 'add')
  const changes = items.filter(item => item.operation === 'remove' || item.operation === 'add')
  const projected = items.filter(item => item.operation !== 'remove')
  const hours = request.hours ?? {}
  const canReview = request.status === 'submitted' && request.advisor_decision_open === true

  const list = (title, rows) => (
    <section className="rounded-[15px] border border-primary/12 bg-white p-5">
      <h2 className="text-[14px] font-black text-text-dark">{title}</h2>
      <div className="mt-3 space-y-2">
        {rows.length === 0 ? <p className="text-[12px] text-text-light">لا توجد مقررات.</p> : rows.map(item => (
          <div key={`${title}-${item.student_registration_modification_item_id}`} className="rounded-[10px] border border-primary/10 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-[13px] font-bold">{item.course?.course_code} — {item.course?.course_name}</p>
              <span className={`text-[11px] font-bold ${item.operation === 'remove' ? 'text-red-700' : item.operation === 'add' ? 'text-primary' : 'text-blue-700'}`}>
                {item.operation === 'remove' ? 'إزالة' : item.operation === 'add' ? 'إضافة' : 'استمرار'}
              </span>
            </div>
            <OfficialTimetable schedule={item.official_timetable} compact />
          </div>
        ))}
      </div>
    </section>
  )

  return (
    <div className="space-y-5" dir="rtl">
      <button type="button" className="text-[13px] font-bold text-primary" onClick={() => navigate('/dean/registration-requests')}>← العودة إلى الطلبات</button>
      <header className="rounded-[18px] border border-primary/12 bg-white p-6">
        <h1 className="text-[21px] font-black">مراجعة تعديل التسجيل المعتمد</h1>
        <p className="mt-2 text-[13px] text-text-light">{request.student?.full_name} — {request.student?.student_number}</p>
        <p className="mt-1 text-[12px] font-bold text-primary">{request.academic_year} / {request.semester} — {LABELS[request.status] ?? request.status}</p>
      </header>
      {error ? <p className="rounded-[10px] border border-red-200 bg-red-50 p-3 text-[12px] text-red-700">{error}</p> : null}
      <section className="grid grid-cols-6 gap-2 max-[900px]:grid-cols-3 max-[550px]:grid-cols-2">
        {[
          ['المعدل الرسمي الحالي', hours.official_cgpa ?? '—'], ['قبل التعديل', hours.registered_hours_before ?? 0],
          ['ساعات محذوفة', hours.removed_hours ?? 0], ['ساعات مضافة', hours.added_hours ?? 0],
          ['بعد الاعتماد', hours.projected_hours ?? 0], ['الحد الأقصى', hours.max_allowed_hours ?? 0],
        ].map(([label, value]) => <div key={label} className="rounded-[12px] border border-primary/10 bg-white p-3"><p className="text-[10.5px] text-text-light">{label}</p><p className="mt-1 text-[18px] font-black">{value}</p></div>)}
      </section>
      {hours.below_recommended_minimum ? <p className="rounded-[10px] bg-amber-50 p-3 text-[12px] font-semibold text-amber-900">الساعات المتوقعة أقل من 12 ساعة؛ هذا تنبيه إرشادي فقط.</p> : null}
      <div className="grid grid-cols-3 gap-4 max-[1100px]:grid-cols-1">
        {list('التسجيل الرسمي الحالي / baseline', baseline)}
        {list('التغييرات المطلوبة', changes)}
        {list('التسجيل المتوقع بعد الاعتماد', projected)}
      </div>
      {request.student_notes ? <section className="rounded-[12px] border border-primary/12 bg-white p-4 text-[13px]">ملاحظات الطالب: {request.student_notes}</section> : null}
      {canReview ? <div className="flex gap-2"><button type="button" onClick={() => setDialog('approve')} className="rounded-[10px] bg-primary px-5 py-2.5 text-[13px] font-black text-white">اعتماد</button><button type="button" onClick={() => setDialog('return')} className="rounded-[10px] border border-orange-300 px-5 py-2.5 text-[13px] font-black text-orange-800">إعادة للتعديل</button></div> : null}
      {dialog === 'approve' ? <DeanConfirmDialog title="اعتماد تعديل التسجيل" confirmLabel="اعتماد وتثبيت التعديل" busy={busy} onConfirm={() => decide('approve')} onCancel={() => setDialog(null)}><p>سيتم تطبيق جميع الإزالات والإضافات ذريًا.</p></DeanConfirmDialog> : null}
      {dialog === 'return' ? <DeanConfirmDialog title="إعادة التعديل" confirmLabel="إعادة للطالب" busy={busy} onConfirm={() => decide('return')} onCancel={() => setDialog(null)}><textarea value={notes} onChange={event => setNotes(event.target.value)} minLength={8} maxLength={2000} className="min-h-[110px] w-full rounded-[10px] border border-primary/20 p-3" placeholder="سبب الإعادة" /></DeanConfirmDialog> : null}
    </div>
  )
}
