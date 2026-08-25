import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { apiRequest } from '../../../services/apiClient'
import {
  periodStatusLabel,
  resultStatusLabel,
  supplementaryErrorMessage,
  workflowStatusLabel,
} from '../../supplementary-exams/supplementaryStatus'
import { SupplementaryConfirmDialog } from '../../supplementary-exams/SupplementaryUi'

const offeringKey = (offering) => offering?.supplementary_exam_offering_id
const normalizedMark = (value) => (value === null || value === undefined ? '' : String(value).trim())

function marksFromRoster(roster = []) {
  return Object.fromEntries(roster.map((candidate) => [
    candidate.supplementary_exam_registration_id,
    candidate.supplementary_theoretical_mark ?? '',
  ]))
}

function gradingLimits(sheet) {
  const limits = sheet?.grading_limits ?? sheet?.limits ?? sheet?.grading_policy ?? {}
  return {
    min: limits.theoretical_min ?? limits.min ?? 0,
    max: limits.theoretical_max ?? limits.theoretical_max_mark ?? limits.max,
    step: limits.theoretical_step ?? limits.step ?? 0.01,
  }
}

function SupplementaryExamOccurrenceIndicator({ occurrence }) {
  const presentation = occurrence?.status === 'open'
    ? {
      text: 'فترة الامتحانات التكميلية جارية',
      className: 'border-green-700 bg-green-50 text-green-900',
    }
    : occurrence?.status === 'closed'
      ? {
        text: 'خارج فترة الامتحانات التكميلية',
        className: 'border-gray-500 bg-gray-50 text-gray-800',
      }
      : {
        text: 'حالة فترة الامتحانات التكميلية غير متاحة',
        className: 'border-amber-600 bg-amber-50 text-amber-900',
      }

  return (
    <p className={`mb-4 rounded border-r-4 px-3 py-2 text-sm ${presentation.className}`} role="status">
      {presentation.text}
    </p>
  )
}

export default function ProfessorSupplementaryExams() {
  const [offerings, setOfferings] = useState([])
  const [selectedOffering, setSelectedOffering] = useState(null)
  const [sheet, setSheet] = useState(null)
  const [occurrence, setOccurrence] = useState(null)
  const [marks, setMarks] = useState({})
  const [savedMarks, setSavedMarks] = useState({})
  const [dirty, setDirty] = useState(false)
  const [offeringsLoading, setOfferingsLoading] = useState(true)
  const [offeringsLoadedSuccessfully, setOfferingsLoadedSuccessfully] = useState(false)
  const [sheetLoading, setSheetLoading] = useState(false)
  const [busyAction, setBusyAction] = useState(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [dialog, setDialog] = useState(null)
  const requestSequenceRef = useRef(0)

  const replaceSheet = useCallback((nextSheet) => {
    const nextMarks = marksFromRoster(nextSheet?.roster)
    setSheet(nextSheet)
    setMarks(nextMarks)
    setSavedMarks(nextMarks)
    setDirty(false)
  }, [])

  const loadOfferings = useCallback(async () => {
    setOfferingsLoading(true)
    setOfferingsLoadedSuccessfully(false)
    setError('')
    try {
      const response = await apiRequest('/v1/professor/supplementary-exams')
      const data = response?.data
      setOfferings(Array.isArray(data) ? data : [])
      setOfferingsLoadedSuccessfully(true)
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر تحميل المقررات التكميلية المكلف بها.'))
    } finally {
      setOfferingsLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadOfferings()
    return () => {
      requestSequenceRef.current += 1
    }
  }, [loadOfferings])

  const loadSheet = async (offering) => {
    const sequence = requestSequenceRef.current + 1
    requestSequenceRef.current = sequence
    setSelectedOffering(offering)
    setSheet(null)
    setOccurrence(null)
    setMarks({})
    setSavedMarks({})
    setDirty(false)
    setSheetLoading(true)
    setError('')
    setNotice('')

    try {
      const response = await apiRequest(
        `/v1/professor/supplementary-exams/${offeringKey(offering)}/grades`,
      )
      if (sequence !== requestSequenceRef.current) return
      const nextSheet = response?.data ?? null
      setOccurrence(nextSheet?.supplementary_exam_occurrence ?? null)
      replaceSheet(nextSheet)
    } catch (requestError) {
      if (sequence !== requestSequenceRef.current) return
      setError(supplementaryErrorMessage(requestError, 'تعذر تحميل ورقة العلامات لهذا المقرر.'))
    } finally {
      if (sequence === requestSequenceRef.current) setSheetLoading(false)
    }
  }

  const requestSheet = (offering) => {
    if (dirty) {
      setDialog({ type: 'discard', offering })
      return
    }
    void loadSheet(offering)
  }

  const periodStatus = sheet?.period_status ?? sheet?.offering?.period?.status
  const serverCanEdit = sheet?.action_flags?.can_edit === true
  const editable = Boolean(serverCanEdit && periodStatus === 'grading_open')
  const limits = gradingLimits(sheet)
  const roster = sheet?.roster ?? []
  const submitAction = sheet?.workflow_status === 'returned' ? 'resubmit' : 'submit'
  const serverCanSubmit = submitAction === 'resubmit'
    ? sheet?.action_flags?.can_resubmit === true
    : sheet?.action_flags?.can_submit === true

  const changedMarks = useMemo(() => Object.entries(marks)
    .filter(([registrationId, value]) => (
      normalizedMark(value) !== normalizedMark(savedMarks[registrationId])
      && normalizedMark(value) !== ''
    ))
    .map(([registrationId, theoreticalMark]) => ({
      supplementary_exam_registration_id: Number(registrationId),
      theoretical_mark: Number(normalizedMark(theoreticalMark)),
    })), [marks, savedMarks])

  const hasClearedSavedMark = Object.entries(marks).some(([registrationId, value]) => (
    normalizedMark(value) === '' && normalizedMark(savedMarks[registrationId]) !== ''
  ))
  const hasIncompleteMarks = roster.some((candidate) => (
    normalizedMark(marks[candidate.supplementary_exam_registration_id]) === ''
  ))

  const updateMark = (registrationId, value) => {
    const nextMarks = { ...marks, [registrationId]: value }
    setMarks(nextMarks)
    setDirty(Object.keys(nextMarks).some((id) => (
      normalizedMark(nextMarks[id]) !== normalizedMark(savedMarks[id])
    )))
    setNotice('')
  }

  const save = async () => {
    if (!editable || !dirty || busyAction) return
    if (hasClearedSavedMark) {
      setError('لا يمكن مسح علامة محفوظة. أدخل القيمة الصحيحة أو أعد تحميل الورقة لإلغاء التعديل المحلي.')
      return
    }
    if (changedMarks.length === 0) {
      setError('لا توجد قيم رقمية جديدة للحفظ. الحقول الفارغة لا تُرسل ولا تتحول إلى صفر.')
      return
    }
    if (changedMarks.some(({ theoretical_mark: mark }) => (
      !Number.isFinite(mark)
      || mark < Number(limits.min)
      || (limits.max !== null && limits.max !== undefined && mark > Number(limits.max))
    ))) {
      setError(`يجب أن تكون كل علامة رقماً بين ${limits.min} و${limits.max ?? 'الحد الأعلى المعتمد'}.`)
      return
    }

    setBusyAction('save')
    setError('')
    setNotice('')
    try {
      const response = await apiRequest(
        `/v1/professor/supplementary-exams/${offeringKey(selectedOffering)}/grades`,
        { method: 'PUT', body: JSON.stringify({ marks: changedMarks }) },
      )
      replaceSheet(response?.data ?? sheet)
      setNotice('تم حفظ مسودة العلامات النظرية التكميلية.')
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر حفظ مسودة العلامات.'))
    } finally {
      setBusyAction(null)
    }
  }

  const submit = () => {
    if (!editable || busyAction) return
    if (dirty) {
      setError('توجد تعديلات غير محفوظة. احفظ المسودة قبل إرسال الدفعة للمراجعة.')
      return
    }
    if (hasIncompleteMarks) {
      setError('أدخل واحفظ علامة جميع الطلاب قبل إرسال الدفعة للمراجعة.')
      return
    }
    if (!serverCanSubmit) {
      setError('حالة الدفعة الحالية لا تسمح بإرسالها. حدّث ورقة العلامات ثم راجع حالتها.')
      return
    }

    setDialog({ type: submitAction })
  }

  const performSubmit = async (action) => {
    setDialog(null)

    setBusyAction(action)
    setError('')
    setNotice('')
    try {
      const response = await apiRequest(
        `/v1/professor/supplementary-exams/${offeringKey(selectedOffering)}/${action}`,
        { method: 'POST' },
      )
      replaceSheet({ ...sheet, ...(response?.data ?? {}) })
      setNotice('تم إرسال الدفعة كاملة إلى لجنة الامتحانات للمراجعة.')
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر إرسال دفعة العلامات للمراجعة.'))
    } finally {
      setBusyAction(null)
    }
  }

  return (
    <main className="p-6" dir="rtl">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-black">الامتحانات التكميلية</h1>
          <p className="text-sm text-gray-500">
            إدخال العلامة النظرية التكميلية فقط؛ تبقى العلامة العملية الأصلية للقراءة، ولا يتغير السجل الرسمي في هذه المرحلة.
          </p>
        </div>
        <button className="rounded border px-3 py-2" disabled={offeringsLoading} onClick={loadOfferings} type="button">
          {offeringsLoading ? 'جارٍ التحديث...' : 'تحديث المقررات'}
        </button>
      </div>

      {error && <p className="my-3 border-r-4 border-red-600 bg-red-50 px-3 py-2" role="alert">{error}</p>}
      {notice && <p className="my-3 border-r-4 border-green-700 bg-green-50 px-3 py-2" role="status">{notice}</p>}

      <div className="grid gap-4 md:grid-cols-[280px_1fr]">
        <aside className="rounded-xl border bg-white p-3" aria-busy={offeringsLoading}>
          {offeringsLoading && <p className="p-3 text-gray-500">جارٍ تحميل المقررات المكلف بها...</p>}
          {!offeringsLoading && offeringsLoadedSuccessfully && offerings.length === 0 && (
            <p className="p-3 text-gray-500">لا توجد مقررات تكميلية مسندة إليك حالياً.</p>
          )}
          {offerings.map((offering) => (
            <button
              className={`block w-full border-b p-3 text-right ${offeringKey(selectedOffering) === offeringKey(offering) ? 'bg-blue-50 font-bold' : ''}`}
              disabled={Boolean(busyAction)}
              key={offeringKey(offering)}
              onClick={() => requestSheet(offering)}
              type="button"
            >
              {offering.course?.course_code ?? 'دون رمز'} — {offering.course?.course_name ?? 'مقرر غير مسمى'}
            </button>
          ))}
        </aside>

        <section className="rounded-xl border bg-white p-4" aria-busy={sheetLoading}>
          {sheetLoading && <p className="py-8 text-center text-gray-500">جارٍ تحميل ورقة العلامات...</p>}
          {!sheetLoading && !sheet && (
            <p className="py-8 text-center text-gray-500">
              {selectedOffering ? 'تعذر عرض ورقة العلامات. أعد اختيار المقرر أو حدّث الصفحة.' : 'اختر مقرراً مكلفاً لك.'}
            </p>
          )}
          {!sheetLoading && sheet && (
            <>
              <header className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <b>{sheet.offering?.course?.course_name ?? selectedOffering?.course?.course_name ?? 'المقرر التكميلي'}</b>
                <div className="flex flex-wrap gap-2 text-sm">
                  <span className="rounded border px-2 py-1">الدورة: {periodStatusLabel(periodStatus)}</span>
                  <span className="rounded border px-2 py-1">الدفعة: {workflowStatusLabel(sheet.workflow_status)}</span>
                  <span className="rounded border px-2 py-1">الإصدار {sheet.submission?.submission_version ?? 1}</span>
                  {dirty && <span className="rounded bg-amber-100 px-2 py-1 font-bold">تعديلات غير محفوظة</span>}
                </div>
              </header>

              <SupplementaryExamOccurrenceIndicator occurrence={occurrence} />

              {!editable && (
                <p className="mb-3 rounded bg-gray-100 p-3 text-sm">
                  ورقة العلامات للقراءة فقط. يفتح التحرير فقط عندما تكون الدورة في «إدخال العلامات مفتوح» وتسمح الخدمة بالتعديل.
                </p>
              )}
              {sheet.submission?.review_reason && (
                <p className="mb-3 rounded bg-amber-50 p-3">ملاحظات الإرجاع: {sheet.submission.review_reason}</p>
              )}

              {roster.length === 0 ? (
                <p className="py-8 text-center text-gray-500">لا يوجد طلاب في القائمة المثبتة لهذا المقرر.</p>
              ) : (
                <div className="overflow-auto">
                  <table className="w-full min-w-[720px] text-right">
                    <thead>
                      <tr>
                        <th className="p-2">الطالب</th>
                        <th className="p-2">العملي الأصلي (للقراءة)</th>
                        <th className="p-2">النظري التكميلي</th>
                        <th className="p-2">المجموع المتوقع</th>
                        <th className="p-2">النتيجة المتوقعة</th>
                      </tr>
                    </thead>
                    <tbody>
                      {roster.map((candidate) => {
                        const registrationId = candidate.supplementary_exam_registration_id
                        return (
                          <tr className="border-t" key={registrationId}>
                            <td className="p-2">{candidate.student?.student_number ?? candidate.student?.full_name ?? 'طالب غير معروف'}</td>
                            <td className="p-2 text-center">{candidate.preserved_practical_mark ?? '—'}</td>
                            <td className="p-2">
                              <input
                                aria-label={`العلامة النظرية التكميلية للطالب ${candidate.student?.student_number ?? ''}`}
                                className="w-28 rounded border p-2 disabled:bg-gray-100"
                                disabled={!editable || Boolean(busyAction)}
                                max={limits.max}
                                min={limits.min}
                                onChange={(event) => updateMark(registrationId, event.target.value)}
                                step={limits.step}
                                type="number"
                                value={marks[registrationId] ?? ''}
                              />
                            </td>
                            <td className="p-2">{candidate.preview?.final_mark ?? '—'}</td>
                            <td className="p-2">{resultStatusLabel(candidate.preview?.result_status_code ?? candidate.result_status)}</td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}

              <div className="mt-4 flex flex-wrap gap-2">
                <button
                  className="rounded bg-gray-700 px-4 py-2 text-white disabled:opacity-40"
                  disabled={!editable || !dirty || Boolean(busyAction)}
                  onClick={() => void save()}
                  type="button"
                >
                  {busyAction === 'save' ? 'جارٍ الحفظ...' : 'حفظ المسودة'}
                </button>
                <button
                  className="rounded bg-primary px-4 py-2 text-white disabled:opacity-40"
                  disabled={!editable || !serverCanSubmit || dirty || hasIncompleteMarks || Boolean(busyAction) || roster.length === 0}
                  onClick={() => void submit()}
                  type="button"
                >
                  {busyAction === 'submit' || busyAction === 'resubmit'
                    ? 'جارٍ الإرسال...'
                    : sheet.workflow_status === 'returned' ? 'إعادة الإرسال' : 'إرسال الدفعة'}
                </button>
              </div>
            </>
          )}
        </section>
      </div>
      {dialog && (
        <SupplementaryConfirmDialog
          busy={Boolean(busyAction)}
          confirmLabel={dialog.type === 'discard' ? 'تجاهل التعديلات' : 'تأكيد الإرسال'}
          danger={dialog.type === 'discard'}
          description={dialog.type === 'discard'
            ? 'توجد تعديلات غير محفوظة في المقرر الحالي. سيؤدي المتابعة إلى تجاهلها وإعادة تحميل ورقة العلامات.'
            : dialog.type === 'resubmit'
              ? 'سيُعاد إرسال الدفعة المصححة كاملة إلى لجنة الامتحانات.'
              : 'سيُغلق تحرير هذه الدفعة بعد إرسالها إلى لجنة الامتحانات.'}
          onCancel={() => setDialog(null)}
          onConfirm={() => dialog.type === 'discard'
            ? (setDialog(null), void loadSheet(dialog.offering))
            : void performSubmit(dialog.type)}
          title={dialog.type === 'discard' ? 'تجاهل التعديلات غير المحفوظة' : 'إرسال دفعة العلامات'}
        />
      )}
    </main>
  )
}
