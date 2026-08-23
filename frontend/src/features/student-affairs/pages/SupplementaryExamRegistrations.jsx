import { useCallback, useEffect, useRef, useState } from 'react'
import { apiRequest } from '../../../services/apiClient'
import {
  eligibilityReasonLabel,
  periodStatusLabel,
  supplementaryErrorMessage,
} from '../../supplementary-exams/supplementaryStatus'

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
      const payload = await apiRequest(`/v1/registration-office/supplementary-exam-periods/${requestedPeriod}/registrations`)
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
  }, [])

  useEffect(()=>{void (async () => {
    mountedRef.current = true
    mutationBusyRef.current = false
    const sequence = periodsRequestSequenceRef.current + 1
    periodsRequestSequenceRef.current = sequence
    setPeriodsLoading(true)
    setError('')
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
    if (action === 'close' && !window.confirm('سيتم إغلاق التسجيل وتثبيت القائمة النهائية. لن يمكن تعديل التسجيلات بعد ذلك. هل تريد المتابعة؟')) return

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

  const cancel = async (row) => {
    if (mutationBusyRef.current) return
    let context = getPeriodContext('registration_open')
    if (!context) return
    if (String(row?._supplementaryPeriodId ?? '') !== context.periodId) {
      setError('التسجيل المعروض يخص دورة أخرى. أعد تحميل القائمة.')
      return
    }

    const reason = window.prompt('سبب الإلغاء مطلوب')
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
  const canOpenRegistration = periodContextMatches && meta?.period_status === 'announced'
  const canManageRegistration = periodContextMatches && meta?.period_status === 'registration_open'
  const mutationBusy = Boolean(busyAction)

  return (
    <main
      aria-busy={periodsLoading || listLoading || searchLoading || eligibilityLoading || mutationBusy}
      className="p-6"
      dir="rtl"
    >
      <h1 className="text-2xl font-bold">التسجيل في الامتحانات التكميلية</h1>
      <p className="my-2 text-gray-600">لا يمكن تجاوز الأهلية الأكاديمية أو اختيار مصدر غير معتمد.</p>

      <div className="my-4 flex flex-wrap gap-2">
        <select
          className="rounded border p-2"
          disabled={periodsLoading || mutationBusy}
          onChange={handlePeriodChange}
          value={period}
        >
          <option value="">{periodsLoading ? 'جارٍ تحميل الدورات...' : 'اختر الدورة'}</option>
          {periods.map((p) => (
            <option key={p.supplementary_exam_period_id} value={p.supplementary_exam_period_id}>
              {p.period_name} — {periodStatusLabel(p.status)}
            </option>
          ))}
        </select>
        <button
          className="rounded bg-primary p-2 text-white disabled:opacity-40"
          disabled={!canOpenRegistration || listLoading || mutationBusy}
          onClick={() => void transition('open')}
          type="button"
        >
          {busyAction === 'open' ? 'جارٍ فتح التسجيل...' : 'فتح التسجيل'}
        </button>
        <button
          className="rounded bg-red-700 p-2 text-white disabled:opacity-40"
          disabled={!canManageRegistration || listLoading || mutationBusy}
          onClick={() => void transition('close')}
          type="button"
        >
          {busyAction === 'close' ? 'جارٍ تثبيت القائمة...' : 'إغلاق التسجيل وتثبيت القائمة'}
        </button>
      </div>

      {error && <p className="my-3 rounded bg-red-50 p-3 text-red-800" role="alert">{error}</p>}
      {message && <p className="my-3 rounded bg-green-50 p-3 text-green-800" role="status">{message}</p>}
      {listLoading && <p className="my-3 text-gray-500">جارٍ تحميل قائمة التسجيل...</p>}
      <p className="font-bold">
        حالة النافذة: {meta ? periodStatusLabel(meta.period_status) : '—'} — {meta ? (meta.list_status === 'fixed' ? 'القائمة النهائية' : 'قائمة أولية') : '—'}
      </p>

      <section className="my-5 rounded border p-4">
        <h2 className="font-bold">تسجيل طالب مؤهل</h2>
        <div className="my-2 flex gap-2">
          <input
            className="rounded border p-2 disabled:opacity-50"
            disabled={!canManageRegistration || searchLoading || eligibilityLoading || mutationBusy}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="رقم أو اسم الطالب"
            value={query}
          />
          <button
            disabled={!canManageRegistration || searchLoading || eligibilityLoading || mutationBusy}
            onClick={() => void search()}
            type="button"
          >
            {searchLoading ? 'جارٍ البحث...' : 'بحث'}
          </button>
        </div>
        {students.map((candidate) => (
          <button
            className="block border-b p-2 disabled:opacity-40"
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
          <p className="py-3 text-gray-500">لا توجد مقررات تكميلية مؤهلة لهذا الطالب في الدورة المختارة.</p>
        )}
        {eligible.map((item) => (
          <div className="flex justify-between border-t p-2" key={`${item.supplementary_exam_offering_id}-${item.original_registration_id}`}>
            <span>{item.course_name ?? `المقرر ${item.supplementary_exam_offering_id}`} — {eligibilityReasonLabel(item.eligibility_reason)}</span>
            <button
              className="text-primary disabled:opacity-40"
              disabled={!canManageRegistration || mutationBusy}
              onClick={() => void register(item)}
              type="button"
            >
              {busyAction === 'register' ? 'جارٍ التسجيل...' : 'تسجيل'}
            </button>
          </div>
        ))}
      </section>

      <h2 className="my-3 font-bold">عدد المسجلين: {meta ? rows.length : '—'}</h2>
      <div className="overflow-auto rounded border bg-white">
        <table className="w-full">
          <thead><tr><th>الطالب</th><th>المادة</th><th>البرنامج</th><th>سبب الأهلية</th><th>الإجراء</th></tr></thead>
          <tbody>
            {rows.map((row) => (
              <tr className="border-t" key={row.supplementary_exam_registration_id}>
                <td>{row.student?.student_number}</td>
                <td>{row.offering?.course?.course_name}</td>
                <td>{row.offering?.academic_program?.program_name}</td>
                <td>{eligibilityReasonLabel(row.eligibility_reason)}</td>
                <td>
                  <button
                    className="text-red-700 disabled:opacity-40"
                    disabled={!canManageRegistration || mutationBusy}
                    onClick={() => void cancel(row)}
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
          <p className="p-4 text-gray-500">لا توجد تسجيلات في هذه الدورة.</p>
        )}
      </div>
    </main>
  )
}
