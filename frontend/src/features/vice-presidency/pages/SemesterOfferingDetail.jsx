import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'
import { PERMISSIONS, ROLES, getIdentity, hasActualUniversityScope, hasAssignedPermission, hasRole } from '../../auth/auth'
import { courseTypeLabel, coverageLabel, semesterOfferingStatusLabel } from '../utils/semesterOfferingLabels'

export default function SemesterOfferingDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const identity = getIdentity()
  const [row, setRow] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [returnOpen, setReturnOpen] = useState(false)
  const [reason, setReason] = useState('')

  async function load() {
    setLoading(true); setError('')
    try {
      const response = await apiRequest(`/v1/vice-presidency/scientific/semester-offerings/${id}`)
      setRow(response.data)
    } catch (requestError) {
      if (requestError.status === 401) return navigate('/login', { replace: true })
      setError(requestError.message || 'تعذّر تحميل تفاصيل الطرح.')
    } finally { setLoading(false) }
  }

  useEffect(() => { load() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  async function mutate(path, body) {
    setBusy(true); setError('')
    try {
      const response = await apiRequest(path, { method: 'POST', body: JSON.stringify(body || {}) })
      setRow(response.data); setReturnOpen(false); setReason('')
    } catch (requestError) { setError(requestError.message || 'تعذّر تنفيذ القرار.') }
    finally { setBusy(false) }
  }

  if (loading) return <p className="p-8 text-center" dir="rtl">جاري التحميل...</p>
  if (!row) return <div className="p-8" dir="rtl"><p className="text-red-600">{error || 'الطرح غير متاح.'}</p></div>
  const offering = row.course_offering
  const canReview = hasRole(ROLES.vicePresidentScientific, identity)
    && hasAssignedPermission(PERMISSIONS.semesterOfferingGovernanceReviewScientific, identity)
    && hasActualUniversityScope(identity)
  const pending = canReview && row.status === 'submitted' && !row.materialized_at

  return <div className="space-y-5 px-2 py-6" dir="rtl">
    <Link to="/vp/scientific/semester-offerings" className="font-bold text-primary">← العودة إلى القائمة</Link>
    <section className="rounded-[18px] border border-primary/15 bg-white p-6">
      <h1 className="text-[22px] font-black">{offering?.course?.course_code} — {offering?.course?.course_name}</h1>
      <div className="mt-5 grid gap-3 text-[13px] md:grid-cols-2">
        <p><b>البرنامج:</b> {offering?.academic_program?.program_name || '—'}</p>
        <p><b>الكلية:</b> {offering?.college?.college_name || '—'}</p>
        <p><b>الفصل الفعلي:</b> {offering?.academic_year?.year_name} / {offering?.semester?.semester_name}</p>
        <p><b>نوع المقرر:</b> {courseTypeLabel(row.course_type)}</p>
        <p><b>حالة الحوكمة:</b> {semesterOfferingStatusLabel(row.status)}</p>
        <p><b>إصدار الإرسال:</b> {row.submission_version}</p>
        <p><b>الحد الأدنى:</b> {row.minimum_enrollment ?? 'غير مطلوب'}</p>
        <p><b>التغطية:</b> {coverageLabel(offering?.instructor_coverage)}</p>
      </div>
      {error && <p className="mt-4 text-red-600">⚠ {error}</p>}
      {pending && <div className="mt-6 flex gap-3">
        <button disabled={busy} onClick={() => mutate(`/v1/vice-presidency/scientific/semester-offerings/${id}/approve`)} className="rounded-lg bg-primary px-5 py-2 font-bold text-white disabled:opacity-50">اعتماد</button>
        <button disabled={busy} onClick={() => setReturnOpen(true)} className="rounded-lg border border-orange-300 px-5 py-2 font-bold text-orange-700 disabled:opacity-50">إعادة للتعديل</button>
      </div>}
    </section>
    {returnOpen && <div className="fixed inset-0 z-50 grid place-items-center bg-black/30 p-4">
      <form onSubmit={event => { event.preventDefault(); mutate(`/v1/vice-presidency/scientific/semester-offerings/${id}/return`, { reason }) }} className="w-full max-w-lg rounded-[18px] bg-white p-6 shadow-xl">
        <h2 className="text-lg font-black">إعادة الطرح للتعديل</h2>
        <label className="mt-4 block text-[13px] font-bold">سبب الإعادة<textarea autoFocus required minLength={1} maxLength={1000} rows={5} value={reason} onChange={event => setReason(event.target.value)} className="mt-2 w-full rounded-lg border p-3" /></label>
        <div className="mt-4 flex justify-end gap-2"><button type="button" onClick={() => setReturnOpen(false)} className="rounded-lg border px-4 py-2">إلغاء</button><button disabled={busy || !reason.trim()} className="rounded-lg bg-primary px-4 py-2 font-bold text-white disabled:opacity-50">تأكيد الإعادة</button></div>
      </form>
    </div>}
  </div>
}
