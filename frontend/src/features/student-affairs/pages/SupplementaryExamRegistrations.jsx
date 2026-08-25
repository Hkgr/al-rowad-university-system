import { useCallback, useEffect, useRef, useState } from 'react'
import { apiRequest } from '../../../services/apiClient'
import {
  eligibilityReasonLabel,
  periodStatusLabel,
  supplementaryErrorMessage,
} from '../../supplementary-exams/supplementaryStatus'
import { SupplementaryConfirmDialog, SupplementaryEmptyState, SupplementaryMetricCard, SupplementaryNotice, SupplementaryPeriodHeader, SupplementaryWorkflowSteps } from '../../supplementary-exams/SupplementaryUi'

export default function SupplementaryExamRegistrations() {
  const [periods, setPeriods] = useState([])
  const [period, setPeriod] = useState('')
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [query, setQuery] = useState('')
  const [students, setStudents] = useState([])
  const [student, setStudent] = useState(null)
  const [eligible, setEligible] = useState([])
  const [periodsLoading, setPeriodsLoading] = useState(true)
  const [listLoading, setListLoading] = useState(false)
  const [searchLoading, setSearchLoading] = useState(false)
  const [eligibilityLoading, setEligibilityLoading] = useState(false)
  const [eligibilityLoadedSuccessfully, setEligibilityLoadedSuccessfully] = useState(false)
  const [busyAction, setBusyAction] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState(null)
  const [rosterSearch, setRosterSearch] = useState('')
  const [debouncedRosterSearch, setDebouncedRosterSearch] = useState('')
  const [rosterPage, setRosterPage] = useState(1)

  const mountedRef = useRef(true)
  const periodRef = useRef('')
  const metaRef = useRef(null)
  const periodIdentityRef = useRef(0)
  const periodsRequestSequenceRef = useRef(0)
  const listRequestSequenceRef = useRef(0)
  const searchRequestSequenceRef = useRef(0)
  const eligibilityRequestSequenceRef = useRef(0)
  const studentResultsPeriodRef = useRef('')
  const mutationBusyRef = useRef(false)

  const clearCandidateState = useCallback(() => {
    searchRequestSequenceRef.current += 1
    eligibilityRequestSequenceRef.current += 1
    studentResultsPeriodRef.current = ''
    setQuery('')
    setStudents([])
    setStudent(null)
    setEligible([])
    setSearchLoading(false)
    setEligibilityLoading(false)
    setEligibilityLoadedSuccessfully(false)
  }, [])

  const load = useCallback(async (selectedPeriodId) => {
    const requestedPeriod = String(selectedPeriodId ?? '')
    if (!requestedPeriod || periodRef.current !== requestedPeriod) return

    const identity = periodIdentityRef.current
    const sequence = listRequestSequenceRef.current + 1
    listRequestSequenceRef.current = sequence
    setListLoading(true)
    setRows([])
    metaRef.current = null
    setMeta(null)
    setError('')

    try {
      const params = new URLSearchParams({ page: String(rosterPage), per_page: '25' })
      if (debouncedRosterSearch.trim()) params.set('search', debouncedRosterSearch.trim())
      const payload = await apiRequest(`/v1/registration-office/supplementary-exam-periods/${requestedPeriod}/registrations?${params.toString()}`)
      if (
        !mountedRef.current
        || sequence !== listRequestSequenceRef.current
        || identity !== periodIdentityRef.current
        || periodRef.current !== requestedPeriod
      ) return

      const nextMeta = { ...payload, supplementary_exam_period_id: requestedPeriod }
      const nextRows = Array.isArray(payload?.data) ? payload.data : []
      metaRef.current = nextMeta
      setMeta(nextMeta)
      setRows(nextRows.map((row) => ({ ...row, _supplementaryPeriodId: requestedPeriod })))
    } catch (requestError) {
      if (
        mountedRef.current
        && sequence === listRequestSequenceRef.current
        && identity === periodIdentityRef.current
        && periodRef.current === requestedPeriod
      ) {
        setError(supplementaryErrorMessage(requestError, 'تعذر تحميل قائمة التسجيل لهذه الدورة.'))
      }
    } finally {
      if (
        mountedRef.current
        && sequence === listRequestSequenceRef.current
        && identity === periodIdentityRef.current
        && periodRef.current === requestedPeriod
      ) setListLoading(false)
    }
  }, [debouncedRosterSearch, rosterPage])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setRosterPage(1)
      setDebouncedRosterSearch(rosterSearch)
    }, 350)
    return () => window.clearTimeout(timeout)
  }, [rosterSearch])

  useEffect(()=>{void (async () => {
    mountedRef.current = true
    mutationBusyRef.current = false
    const sequence = periodsRequestSequenceRef.current + 1
    periodsRequestSequenceRef.current = sequence
    setPeriodsLoading(true)
    setError('')
    setRosterSearch('')
    setDebouncedRosterSearch('')
    setRosterPage(1)
    try {
      const payload = await apiRequest('/v1/supplementary-exam-registration-periods')
      if (!mountedRef.current || sequence !== periodsRequestSequenceRef.current) return
      setPeriods(Array.isArray(payload?.data) ? payload.data : [])
    } catch (requestError) {
      if (mountedRef.current && sequence === periodsRequestSequenceRef.current) {
        setError(supplementaryErrorMessage(requestError, 'تعذر تحميل دورات الامتحانات التكميلية.'))
      }
    } finally {
      if (mountedRef.current && sequence === periodsRequestSequenceRef.current) setPeriodsLoading(false)
    }
  })()

    return () => {
      mountedRef.current = false
      periodRef.current = ''
      periodIdentityRef.current += 1
      periodsRequestSequenceRef.current += 1
      listRequestSequenceRef.current += 1
      searchRequestSequenceRef.current += 1
      eligibilityRequestSequenceRef.current += 1
      mutationBusyRef.current = true
    }
  }, [])

  useEffect(()=>{void load(period)},[load,period])

  const handlePeriodChange = (event) => {
    if (mutationBusyRef.current) return

    const nextPeriod = event.target.value
    periodRef.current = nextPeriod
    metaRef.current = null
    periodIdentityRef.current += 1
    listRequestSequenceRef.current += 1
    searchRequestSequenceRef.current += 1
    eligibilityRequestSequenceRef.current += 1
    studentResultsPeriodRef.current = ''

    setPeriod(nextPeriod)
    setRows([])
    setMeta(null)
    setQuery('')
    setStudents([])
    setStudent(null)
    setEligible([])
    setListLoading(Boolean(nextPeriod))
    setSearchLoading(false)
    setEligibilityLoading(false)
    setEligibilityLoadedSuccessfully(false)
    setRosterSearch('')
    setDebouncedRosterSearch('')
    setRosterPage(1)
    setMessage('')
    setError('')
  }

  const getPeriodContext = (requiredStatus) => {
    const selectedPeriodId = periodRef.current
    const selectedMeta = metaRef.current
    if (
      !selectedPeriodId
      || String(selectedMeta?.supplementary_exam_period_id ?? '') !== selectedPeriodId
      || selectedMeta?.period_status !== requiredStatus
    ) {
      setError('تغيّرت الدورة أو حالتها. أعد تحميل القائمة قبل تنفيذ الإجراء.')
      return null
    }

    return { identity: periodIdentityRef.current, periodId: selectedPeriodId }
  }

  const contextIsCurrent = ({ identity, periodId }) => (
    mountedRef.current
    && identity === periodIdentityRef.current
    && periodId === periodRef.current
  )

  const search = async () => {
    const context = getPeriodContext('registration_open')
    if (!context || searchLoading || mutationBusyRef.current) return

    const sequence = searchRequestSequenceRef.current + 1
    searchRequestSequenceRef.current = sequence
    eligibilityRequestSequenceRef.current += 1
    studentResultsPeriodRef.current = ''
    setSearchLoading(true)
    setStudents([])
    setStudent(null)
    setEligible([])
    setEligibilityLoading(false)
    setEligibilityLoadedSuccessfully(false)
    setMessage('')
    setError('')

    try {
      const params=new URLSearchParams({search:query,per_page:'10'})
      const payload = await apiRequest(`/v1/students?${params.toString()}`)
      if (
        !contextIsCurrent(context)
        || sequence !== searchRequestSequenceRef.current
        || metaRef.current?.period_status !== 'registration_open'
      ) return

      studentResultsPeriodRef.current = context.periodId
      setStudents(payload?.data?.data??[])
    } catch (requestError) {
      if (contextIsCurrent(context) && sequence === searchRequestSequenceRef.current) {
        setError(supplementaryErrorMessage(requestError, 'تعذر البحث عن الطلاب.'))
      }
    } finally {
      if (contextIsCurrent(context) && sequence === searchRequestSequenceRef.current) {
        setSearchLoading(false)
      }
    }
  }

  const selectStudent = async (selectedStudent) => {
    const context = getPeriodContext('registration_open')
    if (
      !context
      || mutationBusyRef.current
      || studentResultsPeriodRef.current !== context.periodId
    ) return

    const sequence = eligibilityRequestSequenceRef.current + 1
    eligibilityRequestSequenceRef.current = sequence
    searchRequestSequenceRef.current += 1
    studentResultsPeriodRef.current = ''
    setStudent(selectedStudent)
    setStudents([])
    setEligible([])
    setSearchLoading(false)
    setEligibilityLoading(true)
    setEligibilityLoadedSuccessfully(false)
    setMessage('')
    setError('')

    try {
      const params=new URLSearchParams({supplementary_exam_period_id:String(context.periodId),student_id:String(selectedStudent.student_id),eligible:'1'})
      const payload = await apiRequest(`/v1/supplementary-exam-eligibility?${params.toString()}`)
      if (
        !contextIsCurrent(context)
        || sequence !== eligibilityRequestSequenceRef.current
        || metaRef.current?.period_status !== 'registration_open'
      ) return

      const candidates = Array.isArray(payload?.data) ? payload.data : []
      setEligible(candidates.map((item) => ({ ...item, _supplementaryPeriodId: context.periodId })))
      setEligibilityLoadedSuccessfully(true)
    } catch (requestError) {
      if (contextIsCurrent(context) && sequence === eligibilityRequestSequenceRef.current) {
        setError(supplementaryErrorMessage(requestError, 'تعذر تحميل أهلية الطالب لهذه الدورة.'))
      }
    } finally {
      if (contextIsCurrent(context) && sequence === eligibilityRequestSequenceRef.current) {
        setEligibilityLoading(false)
      }
    }
  }

  const transition = async (action) => {
    if (!['open', 'close'].includes(action) || mutationBusyRef.current) return
    const requiredStatus = action === 'open' ? 'announced' : 'registration_open'
    const context = getPeriodContext(requiredStatus)
    if (!context) return
    mutationBusyRef.current = true
    setBusyAction(action)
    setMessage('')
    setError('')
    try {
      await apiRequest(`/v1/registration-office/supplementary-exam-periods/${context.periodId}/${action}-registration`,{method:'POST'})
      if (!contextIsCurrent(context)) return
      clearCandidateState()
      setMessage(action === 'open' ? 'تم فتح التسجيل' : 'تم تثبيت القائمة النهائية')
      await load(context.periodId)
    } catch (requestError) {
      if (contextIsCurrent(context)) {
        setError(supplementaryErrorMessage(requestError, action === 'open' ? 'تعذر فتح التسجيل.' : 'تعذر إغلاق التسجيل وتثبيت القائمة.'))
      }
    } finally {
      mutationBusyRef.current = false
      if (contextIsCurrent(context)) setBusyAction('')
    }
  }

  const register = async (item) => {
    if (mutationBusyRef.current) return
    const context = getPeriodContext('registration_open')
    if (!context) return
    if (String(item?._supplementaryPeriodId ?? '') !== context.periodId) {
      setError('نتيجة الأهلية المعروضة تخص دورة أخرى. أعد اختيار الطالب.')
      return
    }

    mutationBusyRef.current = true
    setBusyAction('register')
    setMessage('')
    setError('')
    try {
      await apiRequest('/v1/registration-office/supplementary-exam-registrations',{method:'POST',body:JSON.stringify({supplementary_exam_offering_id:item.supplementary_exam_offering_id??item.supplementary_offering_id,student_course_registration_id:item.original_registration_id})})
      if (!contextIsCurrent(context)) return
      clearCandidateState()
      setMessage('تم تسجيل الطالب في الامتحان التكميلي.')
      await load(context.periodId)
    } catch (requestError) {
      if (contextIsCurrent(context)) {
        setError(supplementaryErrorMessage(requestError, 'تعذر تسجيل الطالب في الامتحان التكميلي.'))
      }
    } finally {
      mutationBusyRef.current = false
      if (contextIsCurrent(context)) setBusyAction('')
    }
  }

  const cancel = async (row, reason) => {
    if (mutationBusyRef.current) return
    let context = getPeriodContext('registration_open')
    if (!context) return
    if (String(row?._supplementaryPeriodId ?? '') !== context.periodId) {
      setError('التسجيل المعروض يخص دورة أخرى. أعد تحميل القائمة.')
      return
    }

    if (!reason?.trim()) return

    context = getPeriodContext('registration_open')
    if (
      !context
      || mutationBusyRef.current
      || String(row?._supplementaryPeriodId ?? '') !== context.periodId
    ) return

    mutationBusyRef.current = true
    setBusyAction('cancel')
    setMessage('')
    setError('')
    try {
      await apiRequest(`/v1/registration-office/supplementary-exam-registrations/${row.supplementary_exam_registration_id}/cancel`,{method:'POST',body:JSON.stringify({reason:reason.trim()})})
      if (!contextIsCurrent(context)) return
      setMessage('تم إلغاء تسجيل الطالب.')
      await load(context.periodId)
    } catch (requestError) {
      if (contextIsCurrent(context)) {
        setError(supplementaryErrorMessage(requestError, 'تعذر إلغاء تسجيل الطالب.'))
      }
    } finally {
      mutationBusyRef.current = false
      if (contextIsCurrent(context)) setBusyAction('')
    }
  }

  const periodContextMatches = Boolean(
    period
    && String(meta?.supplementary_exam_period_id ?? '') === String(period),
  )
  const canOpenRegistration = periodContextMatches
    && meta?.period_status === 'announced'
    && meta?.capabilities?.can_manage_window === true
  const canManageRegistration = periodContextMatches
    && meta?.period_status === 'registration_open'
    && meta?.capabilities?.can_manage_registrations === true
  const canCloseRegistration = periodContextMatches
    && meta?.period_status === 'registration_open'
    && meta?.capabilities?.can_manage_window === true
  const mutationBusy = Boolean(busyAction)
  const selectedPeriod = periods.find((item) => String(item.supplementary_exam_period_id) === String(period)) ?? null
  const periodPresentation = selectedPeriod === null ? null : { ...selectedPeriod, status: meta?.period_status ?? selectedPeriod.status }
  const workflowCodes = ['announced', 'registration_open', 'registration_closed', 'grading_open']
  const workflowIndex = workflowCodes.indexOf(meta?.period_status)
  const workflowSteps = workflowCodes.map((code, index) => ({
    code,
    state: workflowIndex < 0 ? 'pending' : index < workflowIndex ? 'complete' : index === workflowIndex ? 'current' : 'pending',
  }))

  return (
    <main
      aria-busy={periodsLoading || listLoading || searchLoading || eligibilityLoading || mutationBusy}
      className="space-y-5 p-4 sm:p-6"
      dir="rtl"
    >
      <SupplementaryPeriodHeader period={periodPresentation} title="إدارة تسجيل الامتحانات التكميلية">
        <p className="mt-3 text-sm text-text-gray">لا يمكن تجاوز الأهلية الأكاديمية أو اختيار مصدر غير معتمد.</p>
        <div className="mt-4 flex flex-wrap items-end gap-2">
          <label className="grid gap-1 text-sm font-bold text-text-dark">الدورة
            <select className="min-w-64 rounded-[14px] border border-primary/20 bg-white p-2 font-normal" disabled={periodsLoading || mutationBusy} onChange={handlePeriodChange} value={period}>
              <option value="">{periodsLoading ? 'جارٍ تحميل الدورات...' : 'اختر الدورة'}</option>
              {periods.map((p) => <option key={p.supplementary_exam_period_id} value={p.supplementary_exam_period_id}>{p.period_name} — {periodStatusLabel(p.status)}</option>)}
            </select>
          </label>
          <button className="rounded-[14px] bg-primary px-4 py-2 font-bold text-white disabled:opacity-40" disabled={!canOpenRegistration || listLoading || mutationBusy} onClick={() => void transition('open')} type="button">
            {busyAction === 'open' ? 'جارٍ فتح التسجيل...' : 'فتح التسجيل'}
          </button>
          <button className="rounded-[14px] border border-red-200 bg-white px-4 py-2 font-bold text-red-700 disabled:opacity-40" disabled={!canCloseRegistration || listLoading || mutationBusy} onClick={() => setDialog({ type: 'close' })} type="button">
            {busyAction === 'close' ? 'جارٍ تثبيت القائمة...' : 'إغلاق التسجيل وتثبيت القائمة'}
          </button>
        </div>
      </SupplementaryPeriodHeader>

      {periodContextMatches && <SupplementaryWorkflowSteps steps={workflowSteps} />}

      <section className="grid gap-3 sm:grid-cols-3">
        <SupplementaryMetricCard label="الطلاب المسجلون" value={meta?.summary?.registered_students ?? 0} />
        <SupplementaryMetricCard label="العروض ذات التسجيلات" value={meta?.summary?.offerings_with_registrations ?? 0} />
        <SupplementaryMetricCard label="حالة القائمة" value={meta ? (meta.list_status === 'fixed' ? 'نهائية' : 'أولية') : '—'} />
      </section>

      {error && <SupplementaryNotice tone="error">{error}</SupplementaryNotice>}
      {message && <SupplementaryNotice>{message}</SupplementaryNotice>}
      {listLoading && <SupplementaryNotice>جارٍ تحميل قائمة التسجيل...</SupplementaryNotice>}

      <section className="rounded-[18px] border border-primary/15 bg-white p-5 shadow-sm">
        <h2 className="font-black text-text-dark">تسجيل طالب مؤهل</h2>
        <p className="mt-1 text-sm text-text-gray">تُعرض فقط الأهلية التي أعادتها الخدمة الخلفية للدورة المحددة.</p>
        <div className="my-4 flex flex-wrap gap-2">
          <input
            className="min-w-64 rounded-[14px] border border-primary/20 p-2 disabled:opacity-50"
            disabled={!canManageRegistration || searchLoading || eligibilityLoading || mutationBusy}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="رقم أو اسم الطالب"
            value={query}
          />
          <button
            className="rounded-[14px] border border-primary/20 bg-white px-4 py-2 font-bold text-primary-dark disabled:opacity-40"
            disabled={!canManageRegistration || searchLoading || eligibilityLoading || mutationBusy}
            onClick={() => void search()}
            type="button"
          >
            {searchLoading ? 'جارٍ البحث...' : 'بحث'}
          </button>
        </div>
        {students.map((candidate) => (
          <button
            className="block w-full rounded-[14px] border border-transparent p-3 text-right text-text-dark hover:border-primary/10 hover:bg-primary/5 disabled:opacity-40"
            disabled={!canManageRegistration || eligibilityLoading || mutationBusy}
            key={candidate.student_id}
            onClick={() => void selectStudent(candidate)}
            type="button"
          >
            {candidate.student_number} — {candidate.full_name ?? candidate.name}
          </button>
        ))}
        {student && <p className="my-2 font-bold">الطالب: {student.student_number}</p>}
        {eligibilityLoading && <p className="py-3 text-gray-500">جارٍ تحميل أهلية الطالب...</p>}
        {!eligibilityLoading && eligibilityLoadedSuccessfully && student && eligible.length === 0 && (
          <SupplementaryEmptyState title="لا توجد مقررات مؤهلة" description="لا توجد مقررات تكميلية مؤهلة لهذا الطالب في الدورة المختارة." />
        )}
        {eligible.map((item) => (
          <div className="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-[14px] border border-primary/10 bg-primary/5 p-3" key={`${item.supplementary_exam_offering_id}-${item.original_registration_id}`}>
            <span>{item.course_name ?? `المقرر ${item.supplementary_exam_offering_id}`} — {eligibilityReasonLabel(item.eligibility_reason)}</span>
            <button
              className="rounded-[14px] bg-primary px-4 py-2 font-bold text-white disabled:opacity-40"
              disabled={!canManageRegistration || mutationBusy}
              onClick={() => void register(item)}
              type="button"
            >
              {busyAction === 'register' ? 'جارٍ التسجيل...' : 'تسجيل'}
            </button>
          </div>
        ))}
      </section>

      <section className="rounded-[18px] border border-primary/15 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h2 className="font-black text-text-dark">قائمة التسجيل الحالية</h2><p className="mt-1 text-sm text-text-gray">بحث مرقّم في الطالب أو المقرر أو البرنامج.</p></div>
        <input
          className="min-w-64 rounded-[14px] border border-primary/20 px-3 py-2"
          onChange={(event) => setRosterSearch(event.target.value)}
          placeholder="بحث في الطالب أو المقرر أو البرنامج"
          type="search"
          value={rosterSearch}
        />
      </div>
      <div className="mt-4 overflow-auto rounded-[14px] border border-primary/10 bg-white">
        <table className="w-full">
          <thead className="bg-primary/5 text-text-dark"><tr><th className="p-3">الطالب</th><th className="p-3">المادة</th><th className="p-3">البرنامج</th><th className="p-3">سبب الأهلية</th><th className="p-3">الإجراء</th></tr></thead>
          <tbody>
            {rows.map((row) => (
              <tr className="border-t border-primary/10" key={row.supplementary_exam_registration_id}>
                <td className="p-3">{row.student?.student_number}</td>
                <td className="p-3">{row.offering?.course?.course_name}</td>
                <td className="p-3">{row.offering?.academic_program?.program_name}</td>
                <td className="p-3">{eligibilityReasonLabel(row.eligibility_reason)}</td>
                <td className="p-3">
                  <button
                    className="text-red-700 disabled:opacity-40"
                    disabled={!canManageRegistration || mutationBusy}
                    onClick={() => setDialog({ type: 'cancel', row })}
                    type="button"
                  >
                    {busyAction === 'cancel' ? 'جارٍ الإلغاء...' : 'إلغاء بسبب'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!listLoading && meta && rows.length === 0 && (
          <SupplementaryEmptyState title="لا توجد تسجيلات" description="لا توجد تسجيلات مطابقة في هذه الدورة أو ضمن عبارة البحث الحالية." />
        )}
      </div>
      {meta?.meta?.last_page > 1 && (
        <nav aria-label="صفحات قائمة التسجيل" className="mt-3 flex items-center justify-center gap-3">
          <button className="rounded-[14px] border border-primary/20 px-3 py-2 text-primary-dark disabled:opacity-40" disabled={listLoading || rosterPage <= 1} onClick={() => setRosterPage((page) => page - 1)} type="button">السابق</button>
          <span>صفحة {meta.meta.current_page} من {meta.meta.last_page}</span>
          <button className="rounded-[14px] border border-primary/20 px-3 py-2 text-primary-dark disabled:opacity-40" disabled={listLoading || rosterPage >= meta.meta.last_page} onClick={() => setRosterPage((page) => page + 1)} type="button">التالي</button>
        </nav>
      )}
      </section>
      {dialog && (
        <SupplementaryConfirmDialog
          busy={mutationBusy}
          confirmLabel={dialog.type === 'cancel' ? 'إلغاء التسجيل' : 'تثبيت القائمة النهائية'}
          danger={dialog.type === 'cancel'}
          description={dialog.type === 'close'
            ? 'سيتم إغلاق التسجيل وتثبيت القائمة النهائية، ولن يمكن تعديل التسجيلات بعد ذلك.'
            : 'سيبقى سبب الإلغاء محفوظاً في سجل العملية.'}
          onCancel={() => setDialog(null)}
          onConfirm={(reason) => {
            const current = dialog
            setDialog(null)
            if (current.type === 'close') void transition('close')
            else void cancel(current.row, reason)
          }}
          reasonLabel={dialog.type === 'cancel' ? 'سبب الإلغاء' : undefined}
          reasonRequired={dialog.type === 'cancel'}
          title={dialog.type === 'cancel' ? 'إلغاء تسجيل الطالب' : 'تثبيت قائمة الامتحان التكميلية'}
        />
      )}
    </main>
  )
}
