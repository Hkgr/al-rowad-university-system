import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FaCheck, FaDatabase, FaLockOpen, FaSyncAlt, FaUndoAlt, FaUpload, FaUserPlus } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import {
  materializationStatusLabel,
  materializationReasonLabel,
  operationalStatusLabel,
  periodStatusLabel,
  reconciliationIssueLabel,
  reconciliationStatusLabel,
  resultStatusLabel,
  supplementaryErrorMessage,
  workflowStatusLabel,
} from '../../supplementary-exams/supplementaryStatus'

const offeringOf = (row) => row?.offering ?? row?.supplementary_exam_offering ?? row ?? {}
const offeringIdOf = (row) => offeringOf(row).supplementary_exam_offering_id ?? offeringOf(row).id
const periodOf = (row) => offeringOf(row).period ?? row?.period ?? row?.supplementary_exam_period ?? {}
const periodIdOf = (period) => period?.supplementary_exam_period_id ?? period?.id
const candidateRows = (row) => row?.roster ?? row?.candidates ?? row?.registrations ?? []

function extractQueue(response) {
  const data = response?.data
  if (Array.isArray(data)) return { rows: data, periods: response?.periods ?? [] }
  return {
    rows: data?.rows ?? data?.offerings ?? data?.data ?? [],
    periods: data?.periods ?? data?.supplementary_exam_periods ?? [],
  }
}

function mergePeriods(explicitPeriods, rows) {
  const unique = new Map()
  ;[...(explicitPeriods ?? []), ...rows.map(periodOf)].forEach((period) => {
    const id = periodIdOf(period)
    if (id !== null && id !== undefined) unique.set(String(id), period)
  })
  return [...unique.values()].sort((left, right) => Number(periodIdOf(right) ?? 0) - Number(periodIdOf(left) ?? 0))
}

function assignedGrader(row) {
  return row?.current_grader_assignment
    ?? row?.grader_assignment
    ?? row?.grader
    ?? row?.assigned_grader
    ?? offeringOf(row)?.grader
    ?? offeringOf(row)?.assigned_grader
    ?? offeringOf(row)?.faculty_member
}

function personName(person) {
  const source = person?.faculty_member ?? person?.employee ?? person?.user ?? person
  const joined = [source?.first_name, source?.middle_name, source?.last_name].filter(Boolean).join(' ')
  return (source?.full_name ?? source?.name ?? joined) || 'غير مسند'
}

function graderId(grader) {
  return grader?.faculty_member_id ?? grader?.faculty_member?.faculty_member_id ?? grader?.id
}

function programName(row) {
  const program = offeringOf(row)?.academic_program ?? row?.academic_program ?? row?.program
  return program?.program_name ?? program?.name ?? 'برنامج غير محدد'
}

function rowCounts(row) {
  const roster = candidateRows(row)
  const counts = row?.counts ?? row?.summary?.counts ?? {}
  const materialization = row?.materialization ?? {}
  const graded = roster.filter((candidate) => (
    candidate.supplementary_theoretical_mark !== null
    && candidate.supplementary_theoretical_mark !== undefined
    && candidate.supplementary_theoretical_mark !== ''
  )).length
  const published = roster.filter((candidate) => candidate.result_status || candidate.preview?.result_status_code).length
  const materialized = roster.filter((candidate) => candidate.official_record_materialized).length

  return {
    registered: counts.registered ?? counts.roster ?? counts.candidates ?? roster.length,
    graded: counts.graded ?? counts.marks ?? graded,
    published: counts.published ?? (row?.workflow_status === 'published' ? (published || roster.length) : published),
    materialized: counts.materialized ?? materialization.materialized_count ?? materialized,
  }
}

function reconciliationDetails(payload) {
  const reconciliation = payload?.reconciliation ?? payload ?? {}
  const status = String(reconciliation.overall_status ?? reconciliation.state ?? reconciliation.status ?? reconciliation.result ?? '').toUpperCase()
  const counts = reconciliation.counts ?? reconciliation.summary?.counts ?? reconciliation.metrics ?? {}
  const nestedIssues = (reconciliation.offerings ?? reconciliation.rows ?? [])
    .flatMap((offering) => (offering.issues ?? []).map((issue) => ({ ...issue, offering })))
  const directIssues = reconciliation.issues ?? []
  return { reconciliation, status, counts, issues: directIssues.length > 0 ? directIssues : nestedIssues }
}

const reconciliationCountLabels = {
  periods: 'الدورات',
  offerings: 'العروض',
  registrations: 'التسجيلات',
  candidates: 'الطلاب',
  roster: 'القائمة المثبتة',
  grades: 'العلامات',
  graded: 'العلامات المدخلة',
  submissions: 'الدفعات',
  published: 'النتائج المنشورة',
  materialized: 'النتائج المُرحّلة',
  warnings: 'التحذيرات',
  conflicts: 'التعارضات',
  issues: 'الملاحظات',
}

export default function SupplementaryGradesPage() {
  const [rows, setRows] = useState([])
  const [periods, setPeriods] = useState([])
  const [periodId, setPeriodId] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busyAction, setBusyAction] = useState('')
  const [reconciliation, setReconciliation] = useState(null)
  const [reconciliationLoading, setReconciliationLoading] = useState(false)
  const [reconciliationError, setReconciliationError] = useState('')
  const reconciliationRequestSequenceRef = useRef(0)
  const periodIdRef = useRef('')
  const [graderOptions, setGraderOptions] = useState({})
  const [graderSelections, setGraderSelections] = useState({})
  const [graderSearches, setGraderSearches] = useState({})
  const [graderLoading, setGraderLoading] = useState({})

  const loadQueue = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const queueResponse = await apiRequest('/v1/exams/supplementary-grades')
      const payload = extractQueue(queueResponse)
      const nextRows = Array.isArray(payload.rows) ? payload.rows : []
      const nextPeriods = mergePeriods(payload.periods, nextRows)
      setRows(nextRows)
      setPeriods(nextPeriods)
      setPeriodId((current) => {
        const next = current || String(periodIdOf(nextPeriods[0]) ?? '')
        periodIdRef.current = next
        return next
      })
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر تحميل طابور العلامات التكميلية.'))
    } finally {
      setLoading(false)
    }
  }, [])

  const loadReconciliation = useCallback(async (selectedPeriodId) => {
    const sequence = reconciliationRequestSequenceRef.current + 1
    reconciliationRequestSequenceRef.current = sequence
    if (!selectedPeriodId) {
      setReconciliation(null)
      setReconciliationError('')
      setReconciliationLoading(false)
      return
    }
    setReconciliationLoading(true)
    setReconciliation(null)
    setReconciliationError('')
    try {
      const response = await apiRequest(
        `/v1/exams/supplementary-periods/${selectedPeriodId}/reconciliation`,
        { method: 'GET' },
      )
      const nextReconciliation = response?.data ?? response ?? null
      const responsePeriodId = periodIdOf(nextReconciliation?.period ?? nextReconciliation?.reconciliation?.period ?? nextReconciliation)
      if (sequence !== reconciliationRequestSequenceRef.current) return
      if (responsePeriodId === null || responsePeriodId === undefined
        || String(responsePeriodId) !== String(selectedPeriodId)) {
        setReconciliationError('استجابة تقرير المطابقة لا تخص الدورة المحددة. حدّث البيانات وأعد المحاولة.')
        return
      }
      setReconciliation(nextReconciliation)
    } catch (requestError) {
      if (sequence === reconciliationRequestSequenceRef.current) {
        setReconciliation(null)
        setReconciliationError(supplementaryErrorMessage(requestError, 'تعذر تحميل تقرير المطابقة لهذه الدورة.'))
      }
    } finally {
      if (sequence === reconciliationRequestSequenceRef.current) setReconciliationLoading(false)
    }
  }, [])

  useEffect(() => { void loadQueue() }, [loadQueue])
  useEffect(() => { void loadReconciliation(periodId) }, [loadReconciliation, periodId])
  useEffect(() => () => { reconciliationRequestSequenceRef.current += 1 }, [])

  const refreshAll = async () => {
    await Promise.all([loadQueue(), loadReconciliation(periodIdRef.current)])
  }

  const visibleRows = useMemo(() => (
    periodId
      ? rows.filter((row) => String(periodIdOf(periodOf(row)) ?? '') === String(periodId))
      : rows
  ), [periodId, rows])
  const selectedPeriod = periods.find((period) => String(periodIdOf(period)) === String(periodId))
  const reconciliationView = reconciliationDetails(reconciliation)
  const reconciliationPeriodId = periodIdOf(reconciliationView.reconciliation?.period ?? reconciliationView.reconciliation)
  const reconciliationMatchesPeriod = Boolean(periodId && reconciliationPeriodId !== null
    && reconciliationPeriodId !== undefined && String(reconciliationPeriodId) === String(periodId))
  const knownReconciliationStatus = ['PASS', 'WARNING', 'CONFLICT'].includes(reconciliationView.status)
  const reconciliationOfferings = reconciliationMatchesPeriod
    && Array.isArray(reconciliationView.reconciliation?.offerings)
    ? reconciliationView.reconciliation.offerings
    : []

  const review = async (row, action) => {
    const submissionId = row?.submission?.supplementary_exam_grade_submission_id ?? row?.submission?.id
    if (!submissionId) {
      setError('لا توجد دفعة علامات قابلة للمراجعة لهذا العرض.')
      return
    }

    let body
    if (action === 'return') {
      const reason = window.prompt('سبب الإرجاع (إلزامي)')?.trim()
      if (!reason) return
      if (!window.confirm('سيعود العرض إلى المصحح مع السبب المدخل. هل تريد المتابعة؟')) return
      body = { reason }
    } else if (action === 'approve') {
      if (!window.confirm('سيتم اعتماد هذه الدفعة كما هي ولن تعود للمصحح إلا بإرجاع صريح. هل تريد المتابعة؟')) return
    } else if (action === 'publish') {
      if (!window.confirm('سيتم نشر النتائج المعتمدة للطلاب، لكنها لن تصبح في السجل الرسمي قبل الترحيل. هل تريد المتابعة؟')) return
    }

    const key = `${action}:${offeringIdOf(row)}`
    setBusyAction(key)
    setError('')
    setNotice('')
    try {
      await apiRequest(`/v1/exams/supplementary-grades/${submissionId}/${action}`, {
        method: 'POST',
        body: body ? JSON.stringify(body) : undefined,
      })
      const messages = {
        return: 'تمت إعادة الدفعة إلى المصحح مع حفظ السبب في سجل التدقيق.',
        approve: 'تم اعتماد دفعة العلامات وحفظ الإجراء في سجل التدقيق.',
        publish: 'تم نشر النتائج المعتمدة. يلزم الترحيل لاحقاً لتحديث السجل الرسمي.',
      }
      setNotice(messages[action])
      await refreshAll()
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر تنفيذ إجراء المراجعة.'))
    } finally {
      setBusyAction('')
    }
  }

  const materialize = async (row) => {
    if (!window.confirm('سيتم ترحيل النتائج المنشورة إلى السجل الأكاديمي الرسمي مع إبقاء العلامات العملية الأصلية دون تغيير. هذا الإجراء ليس عكساً للعلامة. هل تريد المتابعة؟')) return
    const offeringId = offeringIdOf(row)
    setBusyAction(`materialize:${offeringId}`)
    setError('')
    setNotice('')
    try {
      const response = await apiRequest(`/v1/exams/supplementary-offerings/${offeringId}/materialize`, { method: 'POST' })
      const result = response?.data ?? {}
      setNotice(result.status === 'already_materialized'
        ? 'النتائج مُرحّلة مسبقاً ولم يجرِ أي تعديل مكرر.'
        : `تم ترحيل ${result.materialized_count ?? 0} نتيجة إلى السجل الرسمي.`)
      await refreshAll()
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر ترحيل النتائج إلى السجل الرسمي.'))
    } finally {
      setBusyAction('')
    }
  }

  const openGrading = async () => {
    if (!periodId || !window.confirm('سيتم تثبيت قائمة الطلاب وفتح إدخال العلامات للمصححين في هذه الدورة. هل تريد المتابعة؟')) return
    setBusyAction(`open-grading:${periodId}`)
    setError('')
    setNotice('')
    try {
      await apiRequest(`/v1/exams/supplementary-periods/${periodId}/open-grading`, { method: 'POST' })
      setNotice('تم تثبيت القائمة وفتح إدخال العلامات التكميلية للمصححين.')
      await refreshAll()
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر فتح إدخال العلامات لهذه الدورة.'))
    } finally {
      setBusyAction('')
    }
  }

  const loadGraders = async (row) => {
    const offeringId = offeringIdOf(row)
    const search = String(graderSearches[offeringId] ?? '').trim()
    const params = new URLSearchParams()
    if (search) params.set('search', search)
    const query = params.toString()
    setGraderLoading((current) => ({ ...current, [offeringId]: true }))
    setError('')
    try {
      const response = await apiRequest(`/v1/exams/supplementary-offerings/${offeringId}/graders${query ? `?${query}` : ''}`, { method: 'GET' })
      const data = response?.data
      const options = Array.isArray(data) ? data : data?.graders ?? data?.faculty_members ?? data?.data ?? []
      setGraderOptions((current) => ({ ...current, [offeringId]: options }))
      const currentGraderId = graderId(assignedGrader(row))
      setGraderSelections((current) => {
        const existing = current[offeringId]
        const selectedId = options.some((grader) => String(graderId(grader)) === String(existing))
          ? existing
          : options.some((grader) => String(graderId(grader)) === String(currentGraderId)) ? String(currentGraderId) : ''
        return { ...current, [offeringId]: selectedId }
      })
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر تحميل قائمة المصححين المتاحين.'))
    } finally {
      setGraderLoading((current) => ({ ...current, [offeringId]: false }))
    }
  }

  const assignGrader = async (row) => {
    const offeringId = offeringIdOf(row)
    const facultyMemberId = graderSelections[offeringId]
    if (!facultyMemberId) {
      setError('اختر المصحح قبل حفظ الإسناد.')
      return
    }
    const option = (graderOptions[offeringId] ?? []).find((grader) => String(graderId(grader)) === String(facultyMemberId))
    if (!window.confirm(`سيتم إسناد التصحيح إلى ${personName(option)}. هل تريد المتابعة؟`)) return

    setBusyAction(`grader:${offeringId}`)
    setError('')
    setNotice('')
    try {
      await apiRequest(`/v1/exams/supplementary-offerings/${offeringId}/grader`, {
        method: 'POST',
        body: JSON.stringify({ faculty_member_id: Number(facultyMemberId) }),
      })
      setNotice('تم حفظ إسناد المصحح لهذا العرض.')
      await loadQueue()
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر حفظ إسناد المصحح.'))
    } finally {
      setBusyAction('')
    }
  }

  const canOpenGrading = Boolean(reconciliationMatchesPeriod && (
    reconciliationView.reconciliation?.action_flags?.can_open_grading
    ?? reconciliationView.reconciliation?.actions?.can_open_grading
    ?? selectedPeriod?.action_flags?.can_open_grading
    ?? selectedPeriod?.actions?.can_open_grading
    ?? false
  ))

  return (
    <main className="p-6" dir="rtl">
      <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-black">تشغيل علامات الامتحانات التكميلية</h1>
          <p className="text-sm text-gray-500">الإسناد والمراجعة والنشر والترحيل الرسمي، مع تقرير مطابقة للقراءة فقط.</p>
        </div>
        <button className="inline-flex items-center gap-2 rounded border px-3 py-2" disabled={loading || Boolean(busyAction)} onClick={() => { setNotice(''); void refreshAll() }} type="button">
          <FaSyncAlt aria-hidden="true" /> {loading ? 'جارٍ التحديث...' : 'تحديث البيانات'}
        </button>
      </header>

      <section className="mb-4 rounded-md border bg-white p-4">
        <div className="flex flex-wrap items-end gap-3">
          <label className="grid gap-1 font-bold">
            الدورة
            <select className="min-w-72 rounded border p-2 font-normal" onChange={(event) => {
              const nextPeriodId = event.target.value
              reconciliationRequestSequenceRef.current += 1
              setReconciliation(null)
              setReconciliationError('')
              periodIdRef.current = nextPeriodId
              setPeriodId(nextPeriodId)
            }} disabled={loading || Boolean(busyAction)} value={periodId}>
              <option value="">كل الدورات</option>
              {periods.map((period) => (
                <option key={periodIdOf(period)} value={periodIdOf(period)}>
                  {period.period_name ?? 'دورة غير مسماة'} — {periodStatusLabel(period.status)}
                </option>
              ))}
            </select>
          </label>
          {canOpenGrading && (
            <button className="inline-flex items-center gap-2 rounded bg-blue-700 px-3 py-2 text-white disabled:opacity-50" disabled={Boolean(busyAction)} onClick={() => void openGrading()} type="button">
              <FaLockOpen aria-hidden="true" /> {busyAction.startsWith('open-grading:') ? 'جارٍ الفتح...' : 'تثبيت القائمة وفتح العلامات'}
            </button>
          )}
        </div>
      </section>

      {error && <p className="my-3 border-r-4 border-red-600 bg-red-50 px-3 py-2" role="alert">{error}</p>}
      {notice && <p className="my-3 border-r-4 border-green-700 bg-green-50 px-3 py-2" role="status">{notice}</p>}

      {periodId && (
        <section className="mb-5 rounded-md border bg-slate-50 p-4" aria-busy={reconciliationLoading}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="font-black">تقرير المطابقة التشغيلي</h2>
              <p className="text-sm text-gray-600">للقراءة فقط؛ يعرض الفروقات ولا ينفذ أي تعديل أو معالجة للبيانات.</p>
            </div>
            {!reconciliationLoading && reconciliationMatchesPeriod && (
              <span className={`rounded px-3 py-2 font-black ${reconciliationView.status === 'CONFLICT' ? 'bg-red-100 text-red-800' : reconciliationView.status === 'WARNING' ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800'}`}>
                {knownReconciliationStatus ? reconciliationStatusLabel(reconciliationView.status) : 'لم يُنفذ الفحص بعد'}
              </span>
            )}
          </div>
          {reconciliationLoading && <p className="py-4 text-gray-500">جارٍ فحص المطابقة...</p>}
          {reconciliationError && <p className="mt-3 rounded bg-red-50 p-3 text-red-800" role="alert">{reconciliationError}</p>}
          {!reconciliationLoading && !reconciliationError && reconciliationMatchesPeriod && (
            <>
              <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                {Object.entries(reconciliationView.counts).map(([key, value]) => (
                  <div className="rounded border bg-white p-3" key={key}>
                    <div className="text-xs text-gray-500">{reconciliationCountLabels[key] ?? 'قياس إضافي'}</div>
                    <div className="text-xl font-black">{Number.isFinite(Number(value)) ? Number(value) : '—'}</div>
                  </div>
                ))}
              </div>
              {reconciliationView.issues.length === 0 ? (
                <p className="mt-3 rounded bg-green-50 p-3">لم يُبلغ الفحص عن ملاحظات.</p>
              ) : (
                <ul className="mt-3 space-y-2">
                  {reconciliationView.issues.map((issue, index) => (
                    <li className="rounded border bg-white p-3" key={issue.id ?? `${issue.code ?? issue.type ?? 'issue'}-${index}`}>
                      <b>{issue.message ?? issue.message_ar ?? reconciliationIssueLabel(issue)}</b>
                      {(issue.offering_id ?? issue.supplementary_exam_offering_id ?? issue.identifiers?.supplementary_exam_offering_id ?? offeringIdOf(issue.offering)) && (
                        <span className="mr-2 text-sm text-gray-500">رقم العرض: {issue.offering_id ?? issue.supplementary_exam_offering_id ?? issue.identifiers?.supplementary_exam_offering_id ?? offeringIdOf(issue.offering)}</span>
                      )}
                      {(issue.registration_id ?? issue.supplementary_exam_registration_id ?? issue.identifiers?.supplementary_exam_registration_id) && (
                        <span className="mr-2 text-sm text-gray-500">رقم التسجيل: {issue.registration_id ?? issue.supplementary_exam_registration_id ?? issue.identifiers?.supplementary_exam_registration_id}</span>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </section>
      )}

      {loading && <p className="rounded border bg-white p-8 text-center text-gray-500">جارٍ تحميل طابور العلامات التكميلية...</p>}
      {!loading && !error && visibleRows.length === 0 && (
        <p className="rounded border bg-white p-8 text-center text-gray-500">لا توجد عروض تكميلية في طابور التشغيل للدورة المحددة.</p>
      )}

      {!loading && visibleRows.length === 0 && reconciliationOfferings.length > 0 && (
        <section className="mt-4 space-y-3" aria-label="ملخص العروض التكميلية للدورة">
          <h2 className="font-black">حالة كل عرض في الدورة المحددة</h2>
          {reconciliationOfferings.map((report) => {
            const counts = rowCounts(report)
            const offeringId = offeringIdOf(report)

            return (
              <article className="rounded-md border bg-white p-4" key={offeringId}>
                <header className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 className="font-black">
                      {report.course?.course_code ?? 'دون رمز'} — {report.course?.course_name ?? `المقرر ${report.course_id ?? 'غير المحدد'}`}
                    </h3>
                    <p className="mt-1 text-sm text-gray-600">
                      البرنامج: {report.academic_program?.program_name ?? `البرنامج ${report.academic_program_id ?? 'غير المحدد'}`}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2 text-sm">
                    <span className="rounded bg-blue-50 px-2 py-1 font-bold">{operationalStatusLabel(report)}</span>
                    <span className="rounded border px-2 py-1">{reconciliationStatusLabel(report.state)}</span>
                  </div>
                </header>
                <div className="mt-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                  <div className="rounded bg-gray-50 p-2"><small>المسجلون</small><strong className="block text-lg">{counts.registered}</strong></div>
                  <div className="rounded bg-gray-50 p-2"><small>أُدخلت علاماتهم</small><strong className="block text-lg">{counts.graded}</strong></div>
                  <div className="rounded bg-gray-50 p-2"><small>المنشورة</small><strong className="block text-lg">{counts.published}</strong></div>
                  <div className="rounded bg-gray-50 p-2"><small>المُرحّلة رسمياً</small><strong className="block text-lg">{counts.materialized}</strong></div>
                </div>
                {(report.issues ?? []).length > 0 && (
                  <p className="mt-3 rounded bg-amber-50 p-3">
                    لهذا العرض {(report.issues ?? []).length} ملاحظة في تقرير المطابقة أعلاه.
                  </p>
                )}
              </article>
            )
          })}
        </section>
      )}

      <div className="space-y-4">
        {!loading && visibleRows.map((row) => {
          const offering = offeringOf(row)
          const offeringId = offeringIdOf(row)
          const period = periodOf(row)
          const roster = candidateRows(row)
          const counts = rowCounts(row)
          const materialization = row.materialization ?? { state: 'not_ready' }
          const currentGrader = assignedGrader(row)
          const canAssignGrader = Boolean(row.action_flags?.can_assign_grader
            ?? row.actions?.can_assign_grader
            ?? false)
          const rowBusy = busyAction.endsWith(`:${offeringId}`)
          const options = graderOptions[offeringId]

          return (
            <article className="rounded-md border bg-white p-4" key={offeringId}>
              <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 className="font-black">{offering.course?.course_code ?? 'دون رمز'} — {offering.course?.course_name ?? 'مقرر غير مسمى'}</h2>
                  <div className="mt-1 grid gap-1 text-sm text-gray-600 sm:grid-cols-2">
                    <span>الدورة: {period.period_name ?? 'غير محددة'} ({periodStatusLabel(period.status)})</span>
                    <span>البرنامج: {programName(row)}</span>
                    <span>المصحح: {personName(currentGrader)}</span>
                    <span>الإصدار: {row.submission?.submission_version ?? 'لم يُنشأ'}</span>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2 text-sm">
                  <span className="rounded bg-blue-50 px-2 py-1 font-bold">{operationalStatusLabel(row)}</span>
                  <span className="rounded border px-2 py-1">{workflowStatusLabel(row.workflow_status)}</span>
                  <span className="rounded border px-2 py-1 font-bold">{materializationStatusLabel(materialization.state)}</span>
                </div>
              </header>

              <div className="mt-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                <div className="rounded bg-gray-50 p-2"><small>المسجلون</small><strong className="block text-lg">{counts.registered}</strong></div>
                <div className="rounded bg-gray-50 p-2"><small>أُدخلت علاماتهم</small><strong className="block text-lg">{counts.graded}</strong></div>
                <div className="rounded bg-gray-50 p-2"><small>المنشورة</small><strong className="block text-lg">{counts.published}</strong></div>
                <div className="rounded bg-gray-50 p-2"><small>المُرحّلة رسمياً</small><strong className="block text-lg">{counts.materialized}</strong></div>
              </div>

              {materialization.reason && (
                <p className="mt-3 rounded bg-amber-50 p-3">سبب عدم الجاهزية: {materializationReasonLabel(materialization.reason)}</p>
              )}

              {(row.submission?.review_reason ?? row.review_reason ?? row.return_reason) && (
                <p className="mt-3 rounded bg-amber-50 p-3">سبب الإرجاع: {row.submission?.review_reason ?? row.review_reason ?? row.return_reason}</p>
              )}

              {canAssignGrader && (
                <div className="mt-3 flex flex-wrap items-end gap-2 rounded border bg-gray-50 p-3">
                  <label className="grid gap-1 text-sm font-bold">
                    البحث عن مصحح
                    <input
                      className="min-w-64 rounded border bg-white p-2 font-normal"
                      disabled={graderLoading[offeringId] || rowBusy}
                      maxLength={100}
                      onChange={(event) => setGraderSearches((current) => ({ ...current, [offeringId]: event.target.value }))}
                      placeholder="الاسم أو الرقم الوظيفي"
                      value={graderSearches[offeringId] ?? ''}
                    />
                  </label>
                  <button className="inline-flex items-center gap-2 rounded border bg-white px-3 py-2" disabled={graderLoading[offeringId] || rowBusy} onClick={() => void loadGraders(row)} type="button">
                    <FaUserPlus aria-hidden="true" /> {graderLoading[offeringId] ? 'جارٍ البحث...' : 'بحث وعرض المصححين'}
                  </button>
                  {options !== undefined && (
                    <>
                      <label className="grid gap-1 text-sm font-bold">
                        المصحح المتاح
                        <select className="min-w-64 rounded border bg-white p-2 font-normal" onChange={(event) => setGraderSelections((current) => ({ ...current, [offeringId]: event.target.value }))} value={graderSelections[offeringId] ?? ''}>
                          <option value="">اختر المصحح</option>
                          {options.map((grader) => <option key={graderId(grader)} value={graderId(grader)}>{personName(grader)}</option>)}
                        </select>
                      </label>
                      {options.length === 0 && <span className="text-sm text-gray-500">لا يوجد مصححون متاحون ضمن نطاقك.</span>}
                      <button className="rounded bg-gray-800 px-3 py-2 text-white disabled:opacity-50" disabled={!graderSelections[offeringId] || Boolean(busyAction)} onClick={() => void assignGrader(row)} type="button">
                        {busyAction === `grader:${offeringId}` ? 'جارٍ الحفظ...' : 'حفظ الإسناد'}
                      </button>
                    </>
                  )}
                </div>
              )}

              {roster.length === 0 ? (
                <p className="mt-3 rounded bg-gray-50 p-4 text-gray-500">لا يوجد طلاب في القائمة المثبتة لهذا العرض.</p>
              ) : (
                <div className="mt-3 overflow-x-auto">
                  <table className="w-full min-w-[720px] text-right">
                    <thead><tr><th className="p-2">الطالب</th><th className="p-2">العملي المحفوظ</th><th className="p-2">النظري التكميلي</th><th className="p-2">المجموع المتوقع</th><th className="p-2">النتيجة</th><th className="p-2">السجل الرسمي</th></tr></thead>
                    <tbody>
                      {roster.map((candidate) => (
                        <tr className="border-t" key={candidate.supplementary_exam_registration_id ?? candidate.id}>
                          <td className="p-2">{candidate.student?.student_number ?? 'طالب غير معروف'}</td>
                          <td className="p-2">{candidate.preserved_practical_mark ?? '—'}</td>
                          <td className="p-2">{candidate.supplementary_theoretical_mark ?? '—'}</td>
                          <td className="p-2">{candidate.preview?.final_mark ?? candidate.final_mark ?? '—'}</td>
                          <td className="p-2">{resultStatusLabel(candidate.preview?.result_status_code ?? candidate.official_result_status)}</td>
                          <td className="p-2">{candidate.official_record_materialized ? 'مُرحّل' : 'غير مُرحّل'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <div className="mt-3 flex flex-wrap gap-2">
                {(row.action_flags?.can_return || row.action_flags?.can_approve) && (
                  <>
                    {row.action_flags?.can_return && <button className="inline-flex items-center gap-2 rounded border px-3 py-2 disabled:opacity-50" disabled={Boolean(busyAction)} onClick={() => void review(row, 'return')} type="button"><FaUndoAlt aria-hidden="true" /> إرجاع مع سبب</button>}
                    {row.action_flags?.can_approve && <button className="inline-flex items-center gap-2 rounded bg-primary px-3 py-2 text-white disabled:opacity-50" disabled={Boolean(busyAction)} onClick={() => void review(row, 'approve')} type="button"><FaCheck aria-hidden="true" /> اعتماد</button>}
                  </>
                )}
                {row.action_flags?.can_publish && (
                  <button className="inline-flex items-center gap-2 rounded bg-green-700 px-3 py-2 text-white disabled:opacity-50" disabled={Boolean(busyAction)} onClick={() => void review(row, 'publish')} type="button"><FaUpload aria-hidden="true" /> نشر</button>
                )}
                {materialization.can_materialize && (
                  <button className="inline-flex items-center gap-2 rounded bg-blue-700 px-3 py-2 text-white disabled:opacity-50" disabled={Boolean(busyAction)} onClick={() => void materialize(row)} type="button"><FaDatabase aria-hidden="true" /> {busyAction === `materialize:${offeringId}` ? 'جارٍ الترحيل...' : 'ترحيل إلى السجل الرسمي'}</button>
                )}
              </div>
            </article>
          )
        })}
      </div>
    </main>
  )
}
