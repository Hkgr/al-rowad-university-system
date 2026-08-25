import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'
import { eligibilityBlockerLabel, eligibilityReasonLabel, resultStatusLabel, supplementaryErrorMessage, supplementaryRegistrationAttemptKey, workflowStatusLabel } from '../../supplementary-exams/supplementaryStatus'
import { SupplementaryConfirmDialog, SupplementaryEmptyState, SupplementaryMetricCard, SupplementaryNotice, SupplementaryPeriodHeader, SupplementaryStatusBadge } from '../../supplementary-exams/SupplementaryUi'

const offeringId = (row) => Number(row?.supplementary_offering?.supplementary_exam_offering_id ?? row?.supplementary_exam_offering_id)

export default function StudentSupplementaryExams() {
  const [eligibilityRows, setEligibilityRows] = useState([])
  const [registrations, setRegistrations] = useState([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [dialog, setDialog] = useState(null)
  const requestSequenceRef = useRef(0)

  const load = useCallback(async () => {
    const sequence = requestSequenceRef.current + 1
    requestSequenceRef.current = sequence
    setLoading(true)
    setError('')
    try {
      const [eligibility, registrationPayload] = await Promise.all([
        apiRequest('/v1/student/supplementary-exams/eligibility'),
        apiRequest('/v1/student/supplementary-exams/registrations'),
      ])
      if (sequence !== requestSequenceRef.current) return
      setEligibilityRows(Array.isArray(eligibility?.data) ? eligibility.data : [])
      setRegistrations(Array.isArray(registrationPayload?.data) ? registrationPayload.data : [])
    } catch (requestError) {
      if (sequence !== requestSequenceRef.current) return
      setError(supplementaryErrorMessage(requestError, 'تعذر تحميل بيانات الامتحانات التكميلية.'))
    } finally {
      if (sequence === requestSequenceRef.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
    return () => { requestSequenceRef.current += 1 }
  }, [load])

  const registrationsByAttempt = useMemo(() => new Map(registrations.map((row) => [
    supplementaryRegistrationAttemptKey(row.supplementary_exam_offering_id, row.student_course_registration_id),
    row,
  ])), [registrations])
  const currentPeriod = eligibilityRows[0]?.period ?? registrations[0]?.offering?.period ?? null
  const registeredCount = registrations.filter((row) => row.status === 'registered').length
  const materializedCount = registrations.filter((row) => row.official_record_updated === true).length

  async function confirmAction(reason = '') {
    if (!dialog || busy) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      if (dialog.type === 'declare') {
        await apiRequest('/v1/student/supplementary-exams/deferrals', { method: 'POST', body: JSON.stringify({ supplementary_exam_offering_id: offeringId(dialog.row), student_course_registration_id: dialog.row.eligibility.original_registration_id }) })
        setNotice('تم تسجيل قرار تأجيل الجزء النظري إلى الدورة التكميلية.')
      } else if (dialog.type === 'cancel-deferral') {
        await apiRequest(`/v1/student/supplementary-exams/deferrals/${dialog.row.eligibility.active_deferral_id}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) })
        setNotice('تم إلغاء قرار التأجيل.')
      } else if (dialog.type === 'register') {
        await apiRequest('/v1/student/supplementary-exams/registrations', { method: 'POST', body: JSON.stringify({ supplementary_exam_offering_id: offeringId(dialog.row), student_course_registration_id: dialog.row.eligibility.original_registration_id }) })
        setNotice('تم تسجيلك في الامتحان التكميلي.')
      } else if (dialog.type === 'cancel-registration') {
        await apiRequest(`/v1/student/supplementary-exams/registrations/${dialog.registration.supplementary_exam_registration_id}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) })
        setNotice('تم إلغاء التسجيل في الامتحان التكميلي.')
      }
      setDialog(null)
      await load()
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر تنفيذ الإجراء.'))
    } finally {
      setBusy(false)
    }
  }

  return <main className="space-y-5 p-4 sm:p-6" dir="rtl">
    <SupplementaryPeriodHeader title="الامتحانات التكميلية" period={currentPeriod}>
      <p className="mt-4 text-sm font-semibold text-text-dark">الامتحان التكميلي نظري فقط. علامة العملي المعتمدة محفوظة من المحاولة النظامية ولن يعاد تقديمها.</p>
    </SupplementaryPeriodHeader>
    <section className="grid gap-3 sm:grid-cols-3"><SupplementaryMetricCard label="المقررات المتاحة" value={eligibilityRows.length} /><SupplementaryMetricCard label="التسجيلات الحالية" value={registeredCount} /><SupplementaryMetricCard label="نتائج رُحّلت رسمياً" value={materializedCount} /></section>
    {notice && <SupplementaryNotice>{notice}</SupplementaryNotice>}
    {error && <SupplementaryNotice tone="error">{error}<button type="button" onClick={load} className="mr-3 underline">إعادة المحاولة</button></SupplementaryNotice>}
    {loading && <SupplementaryNotice>جاري تحميل حالة الامتحانات التكميلية…</SupplementaryNotice>}
    {!loading && eligibilityRows.length === 0 && registrations.length === 0
      ? <SupplementaryEmptyState title="لا توجد دورات تكميلية متاحة" description="ستظهر هنا المقررات فور إعلان دورة مرتبطة بمحاولاتك الأكاديمية." />
      : <section className="grid gap-4 lg:grid-cols-2">{eligibilityRows.map((row) => {
        const evaluation = row.eligibility ?? {}
        const blocker = evaluation.blockers?.[0]
        const registration = registrationsByAttempt.get(supplementaryRegistrationAttemptKey(
          offeringId(row),
          evaluation.original_registration_id,
        ))
        const meta = row.registration_meta ?? {}
        const periodStatus = row.period?.status
        const deferred = evaluation.eligibility_reason === 'voluntarily_deferred_theoretical'
        const canDeclare = periodStatus === 'announced' && !evaluation.active_deferral_id && blocker === 'regular_result_not_official' && !evaluation.blockers?.some((code) => ['student_deprived', 'practical_failed', 'practical_mark_missing', 'practical_result_not_approved', 'theoretical_already_graded', 'theoretical_part_locked'].includes(code))
        const limitReached = meta.course_limit !== null && meta.remaining_slots === 0
        return <article key={`${offeringId(row)}-${evaluation.original_registration_id}`} className="rounded-[18px] border border-primary/15 bg-white p-5 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-bold text-primary">{row.course?.course_code ?? '—'}</p><h2 className="mt-1 text-lg font-black text-text-dark">{row.course?.course_name ?? 'مقرر غير مسمى'}</h2><p className="mt-1 text-sm text-text-gray">{row.period?.period_name} — {row.original_registration?.course_offering?.semester?.semester_name ?? 'فصل غير محدد'}</p></div><SupplementaryStatusBadge status={periodStatus} /></div>
          <dl className="mt-4 grid grid-cols-2 gap-3 text-sm"><div className="rounded-xl bg-slate-50 p-3"><dt className="text-text-light">سبب الأهلية</dt><dd className="mt-1 font-bold text-text-dark">{evaluation.eligible ? eligibilityReasonLabel(evaluation.eligibility_reason) : eligibilityBlockerLabel(blocker)}</dd></div><div className="rounded-xl bg-slate-50 p-3"><dt className="text-text-light">العملي المحفوظ</dt><dd className="mt-1 font-bold text-text-dark">{evaluation.practical_required ? evaluation.practical_mark ?? 'بانتظار الاعتماد' : 'غير مطلوب'}</dd></div></dl>
          {evaluation.practical_required && <p className="mt-3 text-xs text-text-gray">الحد الأدنى للعملي: {evaluation.practical_minimum}. علامة العملي للقراءة فقط ولا تتغير في التكميلي.</p>}
          {meta.course_limit !== null && <p className="mt-3 text-xs text-text-gray">المسجل: {meta.current_registered_count} من {meta.course_limit} — المتبقي: {meta.remaining_slots}</p>}
          <div className="mt-4 flex flex-wrap gap-2 border-t border-primary/10 pt-4">
            {deferred && <span className="rounded-lg bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">مؤجل النظري إلى التكميلي</span>}
            {deferred && periodStatus === 'announced' && <button disabled={busy} onClick={() => setDialog({ type: 'cancel-deferral', row })} className="rounded-lg border border-red-200 px-3 py-2 text-sm font-bold text-red-700">إلغاء التأجيل</button>}
            {!deferred && canDeclare && <button disabled={busy} onClick={() => setDialog({ type: 'declare', row })} className="rounded-lg bg-primary px-3 py-2 text-sm font-bold text-white">تأجيل النظري إلى التكميلي</button>}
            {registration?.status === 'registered' ? <><span className="rounded-lg bg-primary/10 px-3 py-2 text-sm font-bold text-primary-dark">{meta.registration_window_closed ? 'مثبت ضمن القائمة النهائية' : 'مسجل في التكميلي'}</span>{registration.can_cancel && <button disabled={busy} onClick={() => setDialog({ type: 'cancel-registration', registration })} className="rounded-lg border border-red-200 px-3 py-2 text-sm font-bold text-red-700">إلغاء التسجيل</button>}</> : <button disabled={busy || !meta.registration_window_open || !evaluation.eligible || limitReached} onClick={() => setDialog({ type: 'register', row })} className="rounded-lg bg-primary px-3 py-2 text-sm font-bold text-white disabled:opacity-40">التسجيل في التكميلي</button>}
          </div>
        </article>
      })}</section>}
    {registrations.map((registration) => <section key={`result-${registration.supplementary_exam_registration_id}`} className="rounded-[18px] border border-primary/15 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap justify-between gap-3"><h2 className="font-black text-text-dark">{registration.offering?.course?.course_name ?? 'نتيجة امتحان تكميلي'}</h2><SupplementaryStatusBadge kind="workflow" status={registration.workflow_status} /></div>
      {registration.published_supplementary_result && <SupplementaryNotice tone="warning">نتيجة تكميلية منشورة: النظري {registration.published_supplementary_result.theoretical_mark}، المجموع {registration.published_supplementary_result.final_mark}، الحالة {resultStatusLabel(registration.published_supplementary_result.result_status_code)}. لم يُحدّث السجل الأكاديمي الرسمي بعد.</SupplementaryNotice>}
      {registration.official_result && <div className="mt-3"><SupplementaryNotice>تم تحديث نتيجتك الأكاديمية الرسمية: النظري {registration.official_result.theoretical_mark}، العملي {registration.official_result.practical_mark ?? 'غير مطلوب'}، المجموع {registration.official_result.final_mark}، الحالة {resultStatusLabel(registration.official_result.result_status_code)}.</SupplementaryNotice><Link to="/student/transcript" className="mt-3 inline-flex font-bold text-primary underline">عرض كشف الدرجات</Link></div>}
      {!registration.published_supplementary_result && !registration.official_result && <p className="mt-3 text-sm text-text-gray">الحالة الحالية: {workflowStatusLabel(registration.workflow_status)}. لا تُعرض أي علامة قبل النشر الرسمي.</p>}
    </section>)}
    {dialog && <SupplementaryConfirmDialog title={dialog.type === 'declare' ? 'تأكيد تأجيل النظري' : dialog.type === 'register' ? 'تأكيد التسجيل' : 'تأكيد الإلغاء'} description={dialog.type === 'declare' ? 'لن تدخل علامة نظري نظامية لهذا المقرر. علامة العملي المعتمدة ستبقى محفوظة، ويلزم التسجيل في الدورة لاحقاً.' : dialog.type === 'cancel-registration' ? 'إلغاء التسجيل في التكميلي لا يلغي قرار تأجيل النظري النظامي.' : 'راجع الإجراء قبل التأكيد.'} reasonLabel={dialog.type.includes('cancel') ? 'سبب الإلغاء (اختياري)' : undefined} confirmLabel="تأكيد" danger={dialog.type.includes('cancel')} busy={busy} onCancel={() => setDialog(null)} onConfirm={confirmAction} />}
  </main>
}
