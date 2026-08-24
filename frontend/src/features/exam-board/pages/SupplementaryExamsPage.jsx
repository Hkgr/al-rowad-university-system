import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaBookOpen, FaCheckCircle, FaClock, FaRedo, FaSearch, FaUsers } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { getIdentity } from '../../auth/auth'
import { eligibilityReasonLabel, periodOperationalMessage, periodStatusLabel, workflowStatusLabel } from '../../supplementary-exams/supplementaryStatus'
import {
  canOpenSupplementaryGrades,
  OVERVIEW_STAGE_LABELS,
  overviewEmptyMessage,
  overviewQuery,
  responseMatchesPeriod,
} from '../lib/supplementaryOverview'

const metricDefinitions = [
  ['offerings_count', 'العروض', FaBookOpen],
  ['registered_students_count', 'الطلاب المسجلون', FaUsers],
  ['grader_assigned_offerings_count', 'عروض لها مصحح', FaCheckCircle],
  ['graded_students_count', 'علامات مدخلة', FaCheckCircle],
  ['published_offerings_count', 'عروض منشورة', FaClock],
  ['materialized_students_count', 'طلاب مُرحّلون', FaCheckCircle],
]

const displayDate = (value) => value
  ? new Intl.DateTimeFormat('ar-SY', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(value))
  : '—'

function errorMessage(error) {
  if (error?.status === 403) return 'لا تملك صلاحية عرض عمليات الامتحانات التكميلية ضمن هذا المسار.'
  return 'تعذّر تحميل بيانات عمليات الامتحانات التكميلية من الخادم.'
}

function statusTone(status) {
  if (status === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-800'
  if (status === 'current') return 'border-primary/30 bg-primary/10 text-primary-dark'
  if (status === 'future') return 'border-gray-200 bg-gray-50 text-gray-500'
  return 'border-amber-200 bg-amber-50 text-amber-800'
}

export default function SupplementaryExamsPage() {
  const [payload, setPayload] = useState(null)
  const [periodId, setPeriodId] = useState('')
  const [offeringId, setOfferingId] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const requestSequenceRef = useRef(0)
  const successfulPeriodRef = useRef('')

  const load = useCallback(async ({ requestedPeriod = periodId, requestedOffering = offeringId, requestedSearch = search, requestedPage = page } = {}) => {
    const sequence = requestSequenceRef.current + 1
    requestSequenceRef.current = sequence
    setLoading(true)
    setError('')
    try {
      const response = await apiRequest(overviewQuery({
        periodId: requestedPeriod,
        offeringId: requestedOffering,
        search: requestedSearch,
        page: requestedPage,
      }))
      const next = response?.data ?? null
      if (sequence !== requestSequenceRef.current) return
      if (!responseMatchesPeriod(next, requestedPeriod)) throw new Error('supplementary_overview_stale_period')
      const selected = String(next?.selected_period?.supplementary_exam_period_id ?? '')
      setPayload(next)
      setPeriodId(selected)
      successfulPeriodRef.current = selected
    } catch (requestError) {
      if (sequence !== requestSequenceRef.current) return
      setPeriodId(successfulPeriodRef.current)
      setError(errorMessage(requestError))
    } finally {
      if (sequence === requestSequenceRef.current) setLoading(false)
    }
  }, [offeringId, page, periodId, search])

  useEffect(() => { void load({ requestedPeriod: '', requestedOffering: '', requestedSearch: '', requestedPage: 1 }) }, []) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const timer = window.setTimeout(() => {
      const normalized = searchInput.trim()
      if (normalized === search) return
      setSearch(normalized)
      setPage(1)
      void load({ requestedSearch: normalized, requestedPage: 1 })
    }, 350)
    return () => window.clearTimeout(timer)
  }, [searchInput]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedPeriod = payload?.selected_period
  const registrations = payload?.registrations?.data ?? []
  const meta = payload?.registrations?.meta ?? { current_page: 1, last_page: 1, total: 0 }
  const canAccessGrades = useMemo(() => canOpenSupplementaryGrades(payload, getIdentity()), [payload])

  const changePeriod = (nextPeriodId) => {
    setPeriodId(nextPeriodId)
    setOfferingId('')
    setSearchInput('')
    setSearch('')
    setPage(1)
    void load({ requestedPeriod: nextPeriodId, requestedOffering: '', requestedSearch: '', requestedPage: 1 })
  }

  const changeOffering = (nextOfferingId) => {
    setOfferingId(nextOfferingId)
    setPage(1)
    void load({ requestedOffering: nextOfferingId, requestedPage: 1 })
  }

  const changePage = (nextPage) => {
    setPage(nextPage)
    void load({ requestedPage: nextPage })
  }

  return (
    <section className="space-y-5" dir="rtl">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-black text-text-dark">نظرة عمليات الامتحانات التكميلية</h1>
          <p className="mt-1 text-sm text-text-gray">مراقبة معلوماتية للعرض والتسجيل والتصحيح والنشر والترحيل.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <select className="min-w-64 rounded-xl border border-gray-200 bg-white px-3 py-2" disabled={loading && !payload} onChange={(event) => changePeriod(event.target.value)} value={periodId}>
            {(payload?.periods ?? []).map((period) => <option key={period.supplementary_exam_period_id} value={period.supplementary_exam_period_id}>{period.period_name} — {periodStatusLabel(period.status)}</option>)}
          </select>
          <button className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-bold disabled:opacity-50" disabled={loading} onClick={() => void load()} type="button"><FaRedo className={loading ? 'animate-spin' : ''} /></button>
        </div>
      </div>

      {error && <div className="flex items-center justify-between rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert"><span>{error}</span><button className="font-bold underline" onClick={() => void load()} type="button">إعادة المحاولة</button></div>}
      {loading && payload && <div className="rounded-lg bg-primary/5 px-4 py-2 text-sm text-primary-dark">جارٍ تحديث البيانات مع إبقاء آخر لقطة موثوقة ظاهرة…</div>}
      {loading && !payload && <div className="rounded-xl border bg-white p-10 text-center text-gray-500">جارٍ تحميل النظرة التشغيلية…</div>}
      {!loading && !error && payload && !selectedPeriod && <div className="rounded-xl border bg-white p-10 text-center text-gray-500">{overviewEmptyMessage(payload)}</div>}

      {selectedPeriod && <>
        <div className="overflow-hidden rounded-2xl border border-primary/20 bg-gradient-to-l from-primary/10 to-white p-6 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-primary-dark">{periodStatusLabel(selectedPeriod.status)}</span>
              <h2 className="mt-3 text-xl font-black">{selectedPeriod.period_name}</h2>
              <p className="mt-1 text-sm text-gray-600">{selectedPeriod.academic_year?.year_name ?? 'سنة غير محددة'} · {selectedPeriod.semester?.semester_name ?? 'فصل غير محدد'} · {displayDate(selectedPeriod.start_date)} — {displayDate(selectedPeriod.end_date)}</p>
              <p className="mt-3 text-sm font-medium text-primary-dark">{periodOperationalMessage(selectedPeriod.status)}</p>
              <p className="mt-1 text-xs text-gray-500">نافذة التقويم الأكاديمي: {selectedPeriod.supplementary_exam_occurrence?.status === 'open' ? 'جارية الآن' : selectedPeriod.supplementary_exam_occurrence?.status === 'closed' ? 'خارج الفترة حالياً' : 'غير متاحة'}</p>
            </div>
            {canAccessGrades && <Link className="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white no-underline" to="/exam-board/supplementary-grades">إدارة الدرجات التكميلية</Link>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-7">
          {(payload.stage?.steps ?? []).map((step) => <div className={`rounded-xl border p-3 text-center text-xs font-bold ${statusTone(step.state)}`} key={step.code}>{OVERVIEW_STAGE_LABELS[step.code] ?? step.code}</div>)}
        </div>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
          {metricDefinitions.map(([key, label, Icon]) => <div className="rounded-xl border bg-white p-4 shadow-sm" key={key}><Icon className="mb-2 text-primary"/><div className="text-2xl font-black">{payload.summary?.[key] ?? 0}</div><div className="text-xs text-gray-500">{label}</div></div>)}
        </div>

        <div>
          <h3 className="mb-3 text-lg font-black">العروض الامتحانية</h3>
          {(payload.offerings ?? []).length === 0 ? <div className="rounded-xl border bg-white p-6 text-gray-500">{overviewEmptyMessage(payload)}</div> : <div className="grid gap-3 lg:grid-cols-2">
            {payload.offerings.map((offering) => <article className="rounded-xl border bg-white p-4 shadow-sm" key={offering.supplementary_exam_offering_id}>
              <div className="flex items-start justify-between gap-3"><div><h4 className="font-black">{offering.course?.course_name ?? 'مقرر غير معروف'}</h4><p className="text-xs text-gray-500">{offering.course?.course_code ?? '—'} · {offering.academic_program?.program_name ?? 'برنامج غير محدد'}</p></div><span className="rounded-full bg-gray-100 px-2 py-1 text-xs">{offering.status === 'open' ? 'مفتوح' : 'مغلق'}</span></div>
              <div className="mt-3 grid grid-cols-4 gap-2 text-center text-xs"><span>مسجلون<br/><b>{offering.counts?.registered ?? 0}</b></span><span>مصححون<br/><b>{offering.counts?.graded ?? 0}</b></span><span>منشورون<br/><b>{offering.counts?.published ?? 0}</b></span><span>مُرحّلون<br/><b>{offering.counts?.materialized ?? 0}</b></span></div>
              <div className="mt-3 border-t pt-3 text-xs text-gray-600"><p>المصحح: {offering.current_grader?.full_name || 'غير مسند'}</p><p>سير العلامات: {workflowStatusLabel(offering.workflow_status)}</p><p>المصدر: {offering.sources?.length ? offering.sources.map((source) => `${source.academic_year ?? ''} ${source.semester ?? ''}`).join('، ') : 'لا يوجد مصدر ظاهر'}</p></div>
            </article>)}
          </div>}
        </div>

        <div className="rounded-xl border bg-white p-4 shadow-sm">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h3 className="text-lg font-black">قائمة الطلاب الحالية</h3><div className="flex flex-wrap gap-2">
            <label className="relative"><FaSearch className="absolute right-3 top-3 text-gray-400"/><input className="rounded-xl border py-2 pl-3 pr-9" onChange={(event) => setSearchInput(event.target.value)} placeholder="بحث عن طالب أو مقرر أو برنامج" value={searchInput}/></label>
            <select className="rounded-xl border px-3 py-2" onChange={(event) => changeOffering(event.target.value)} value={offeringId}><option value="">كل العروض</option>{(payload.offerings ?? []).map((offering) => <option key={offering.supplementary_exam_offering_id} value={offering.supplementary_exam_offering_id}>{offering.course?.course_name ?? offering.supplementary_exam_offering_id}</option>)}</select>
          </div></div>
          {registrations.length === 0 ? <p className="py-8 text-center text-gray-500">{overviewEmptyMessage(payload)}</p> : <div className="overflow-x-auto"><table className="w-full min-w-[900px] text-right text-sm"><thead className="bg-gray-50"><tr><th className="p-3">الطالب</th><th className="p-3">المقرر</th><th className="p-3">البرنامج</th><th className="p-3">الأهلية</th><th className="p-3">القناة</th><th className="p-3">التاريخ</th><th className="p-3">الحالة</th></tr></thead><tbody>{registrations.map((row) => <tr className="border-t" key={row.supplementary_exam_registration_id}><td className="p-3"><b>{row.student?.full_name || 'طالب غير معروف'}</b><br/><span className="text-xs text-gray-500">{row.student?.student_number ?? '—'}</span></td><td className="p-3">{row.course?.course_name ?? '—'}<br/><span className="text-xs text-gray-500">{row.course?.course_code ?? ''}</span></td><td className="p-3">{row.academic_program?.program_name ?? '—'}</td><td className="p-3">{eligibilityReasonLabel(row.eligibility?.reason)}</td><td className="p-3">{row.registration_channel ?? '—'}</td><td className="p-3">{displayDate(row.registered_at)}</td><td className="p-3">مسجل</td></tr>)}</tbody></table></div>}
          <div className="mt-4 flex items-center justify-between text-sm"><span>{meta.total ?? 0} سجل</span><div className="flex gap-2"><button className="rounded border px-3 py-1 disabled:opacity-40" disabled={loading || meta.current_page <= 1} onClick={() => changePage(meta.current_page - 1)} type="button">السابق</button><span className="px-2 py-1">{meta.current_page} / {meta.last_page}</span><button className="rounded border px-3 py-1 disabled:opacity-40" disabled={loading || meta.current_page >= meta.last_page} onClick={() => changePage(meta.current_page + 1)} type="button">التالي</button></div></div>
        </div>
      </>}
    </section>
  )
}
