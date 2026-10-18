import { useCallback, useEffect, useRef, useState } from 'react'
import { apiRequest } from '../../services/apiClient'
import {
  eligibilityReasonLabel,
  isFixedRosterStatus,
  periodStatusLabel,
  supplementaryErrorMessage,
} from './supplementaryStatus'

export default function ReadOnlyRegistrationList({ title = 'قائمة التسجيل التكميلي' }) {
  const [periods, setPeriods] = useState([])
  const [periodId, setPeriodId] = useState('')
  const [result, setResult] = useState(null)
  const [periodsLoading, setPeriodsLoading] = useState(true)
  const [periodsLoadedSuccessfully, setPeriodsLoadedSuccessfully] = useState(false)
  const [listLoading, setListLoading] = useState(false)
  const [error, setError] = useState('')
  const requestSequenceRef = useRef(0)

  useEffect(()=>{void (async () => {
    setPeriodsLoading(true)
    setPeriodsLoadedSuccessfully(false)
    try {
      const payload = await apiRequest('/v1/supplementary-exam-registration-periods')
      const data = payload?.data
      setPeriods(Array.isArray(data) ? data : data?.periods ?? [])
      setPeriodsLoadedSuccessfully(true)
    } catch (requestError) {
      setError(supplementaryErrorMessage(requestError, 'تعذر تحميل دورات الامتحانات التكميلية.'))
    } finally {
      setPeriodsLoading(false)
    }
  })()}, [])

  const load = useCallback(async () => {
    if (!periodId) return
    const sequence = requestSequenceRef.current + 1
    requestSequenceRef.current = sequence
    setListLoading(true)
    setResult(null)
    setError('')
    try {
      const payload = await apiRequest(`/v1/supplementary-exam-periods/${periodId}/registrations`)
      if (sequence !== requestSequenceRef.current) return
      setResult(payload?.data && !Array.isArray(payload.data) ? payload.data : payload)
    } catch (requestError) {
      if (sequence === requestSequenceRef.current) {
        setError(supplementaryErrorMessage(requestError, 'تعذر تحميل قائمة التسجيل لهذه الدورة.'))
      }
    } finally {
      if (sequence === requestSequenceRef.current) setListLoading(false)
    }
  }, [periodId])

  const registrations = result?.data ?? result?.registrations ?? []
  const fixed = result?.list_status === 'fixed' || isFixedRosterStatus(result?.period_status)

  return (
    <section className="my-4 rounded-xl border bg-white p-5" dir="rtl">
      <h2 className="text-lg font-bold">{title}</h2>
      <p className="mt-1 text-sm text-gray-500">هذه الشاشة للقراءة فقط ولا تعدّل التسجيلات أو القائمة المثبتة.</p>

      <div className="my-3 flex flex-wrap gap-2">
        <select
          className="min-w-64 rounded border p-2"
          disabled={periodsLoading || listLoading}
          onChange={(event) => {
            requestSequenceRef.current += 1
            setPeriodId(event.target.value)
            setResult(null)
            setError('')
          }}
          value={periodId}
        >
          <option value="">{periodsLoading ? 'جارٍ تحميل الدورات...' : 'اختر الدورة'}</option>
          {periods.map((period) => (
            <option key={period.supplementary_exam_period_id ?? period.id} value={period.supplementary_exam_period_id ?? period.id}>
              {period.period_name ?? 'دورة غير مسماة'} — {periodStatusLabel(period.status)}
            </option>
          ))}
        </select>
        <button className="rounded border px-4 py-2 disabled:opacity-40" disabled={!periodId || listLoading} onClick={() => void load()} type="button">
          {listLoading ? 'جارٍ التحميل...' : 'عرض'}
        </button>
      </div>

      {error && <p className="my-3 rounded bg-red-50 p-3 text-red-800" role="alert">{error}</p>}
      {!periodsLoading && periodsLoadedSuccessfully && periods.length === 0 && <p className="py-5 text-gray-500">لا توجد دورات تكميلية متاحة للعرض.</p>}
      {listLoading && <p className="py-5 text-gray-500">جارٍ تحميل قائمة التسجيل...</p>}

      {!listLoading && result && (
        <>
          <div className="flex flex-wrap gap-2 font-bold">
            <span>{fixed ? 'القائمة النهائية المثبتة' : 'قائمة أولية قابلة للتغير لاحقاً'}</span>
            <span>— {periodStatusLabel(result.period_status)}</span>
            <span>— {registrations.length} طالباً</span>
          </div>
          {registrations.length === 0 ? (
            <p className="py-5 text-gray-500">لا توجد تسجيلات في هذه الدورة.</p>
          ) : (
            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[620px] text-right">
                <thead><tr><th className="p-2">الطالب</th><th className="p-2">المادة</th><th className="p-2">البرنامج</th><th className="p-2">سبب الأهلية</th></tr></thead>
                <tbody>
                  {registrations.map((registration) => (
                    <tr className="border-t" key={registration.supplementary_exam_registration_id}>
                      <td className="p-2">{registration.student?.student_number ?? 'طالب غير معروف'}</td>
                      <td className="p-2">{registration.offering?.course?.course_name ?? 'مقرر غير معروف'}</td>
                      <td className="p-2">{registration.offering?.academic_program?.program_name ?? 'برنامج غير معروف'}</td>
                      <td className="p-2">{eligibilityReasonLabel(registration.eligibility_reason)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  )
}
