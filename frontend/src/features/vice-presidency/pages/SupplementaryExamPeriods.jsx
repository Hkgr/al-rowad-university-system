import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaTimes } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { getIdentity } from '../../auth/auth'
import {
  canAnnouncePeriod,
  canDecideSupplementaryExamPeriod,
  canViewSupplementaryExamPeriod,
  defaultSupplementaryPeriodName,
  formatPeriodDate,
  periodForIdentity,
  statusLabelAr,
} from '../utils/supplementaryExamPeriods'

const CONFIRMATION_TEXT = 'سيؤدي اعتماد القرار إلى إنشاء دورة امتحانية تكميلية مرتبطة بهذا الفصل، وستصبح مرئية للجهات الأكاديمية المخولة.'

function catalogFrom(response) {
  return response?.data ?? {}
}

export default function SupplementaryExamPeriodsPage() {
  const navigate = useNavigate()
  const identity = getIdentity()
  const canView = canViewSupplementaryExamPeriod(identity)
  const canDecide = canDecideSupplementaryExamPeriod(identity)
  const [years, setYears] = useState([])
  const [semesters, setSemesters] = useState([])
  const [periods, setPeriods] = useState([])
  const [academicYearId, setAcademicYearId] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [dialogSemester, setDialogSemester] = useState(null)
  const [detailPeriod, setDetailPeriod] = useState(null)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({
    period_name: '',
    start_date: '',
    end_date: '',
    decision_note: '',
  })

  const selectedYear = years.find(year => String(year.academic_year_id) === String(academicYearId)) ?? null

  useEffect(() => {
    if (!canView) {
      setLoading(false)
      setError('هذه الصفحة متاحة لنائب رئيس الجامعة للشؤون العلمية مع صلاحية العرض المعينة.')
      return undefined
    }

    let active = true

    async function load() {
      setLoading(true)
      setError('')
      try {
        const params = new URLSearchParams()
        if (academicYearId) params.set('academic_year_id', String(academicYearId))
        const query = params.toString()
        const response = await apiRequest(`/v1/vice-presidency/scientific/supplementary-exam-periods${query ? `?${query}` : ''}`)
        if (!active) return
        const catalog = catalogFrom(response)
        const nextYears = Array.isArray(catalog.academic_years) ? catalog.academic_years : []
        const nextSemesters = Array.isArray(catalog.semesters) ? catalog.semesters : []
        const nextPeriods = Array.isArray(catalog.periods) ? catalog.periods : []
        setYears(nextYears)
        setSemesters(nextSemesters)
        setPeriods(nextPeriods)
        if (!academicYearId && nextYears.length > 0) {
          const current = nextYears.find(year => year.is_current) ?? nextYears[0]
          setAcademicYearId(String(current.academic_year_id))
        }
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setYears([])
        setSemesters([])
        setPeriods([])
        setError(requestError.status === 403
          ? 'ليس لديك صلاحية عرض الدورات الامتحانية التكميلية.'
          : (requestError.message || 'تعذّر تحميل الدورات التكميلية.'))
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [academicYearId, canView, navigate])

  const yearPeriods = useMemo(
    () => periods.filter(period => Number(period?.academic_year?.academic_year_id ?? period?.academic_year?.id) === Number(academicYearId)),
    [academicYearId, periods]
  )

  function openAnnounceDialog(semester) {
    if (!canDecide || !canAnnouncePeriod(periodForIdentity(yearPeriods, academicYearId, semester.semester_id))) return
    setNotice('')
    setDialogSemester(semester)
    setForm({
      period_name: defaultSupplementaryPeriodName(semester.semester_name || semester.name, selectedYear?.year_name || selectedYear?.name),
      start_date: '',
      end_date: '',
      decision_note: '',
    })
  }

  async function submitAnnounce() {
    if (!dialogSemester || !canDecide) return
    setSaving(true)
    setError('')
    try {
      await apiRequest('/v1/vice-presidency/scientific/supplementary-exam-periods', {
        method: 'POST',
        body: JSON.stringify({
          academic_year_id: Number(academicYearId),
          semester_id: dialogSemester.semester_id,
          period_name: form.period_name,
          start_date: form.start_date,
          end_date: form.end_date,
          decision_note: form.decision_note || undefined,
        }),
      })
      setDialogSemester(null)
      setNotice('تم اعتماد فتح الدورة الامتحانية التكميلية.')
      const params = new URLSearchParams({ academic_year_id: String(academicYearId) })
      const response = await apiRequest(`/v1/vice-presidency/scientific/supplementary-exam-periods?${params.toString()}`)
      const catalog = catalogFrom(response)
      setPeriods(Array.isArray(catalog.periods) ? catalog.periods : [])
    } catch (requestError) {
      setError(requestError.message || 'تعذّر اعتماد فتح الدورة التكميلية.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex flex-col gap-5 py-8 px-2" dir="rtl">
      <div>
        <p className="text-[18px] font-black text-text-dark">الامتحانات التكميلية</p>
        <p className="text-[13px] text-text-light mt-1">دورة امتحانية اختيارية مرتبطة بسنة أكاديمية وفصل قائم. غياب الدورة يعني أنها لم تُفتح.</p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-[16px] px-4 py-3 text-[13px]">{error}</div>
      )}
      {notice && (
        <div className="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-[16px] px-4 py-3 text-[13px]">{notice}</div>
      )}

      <div className="bg-white border border-primary/15 rounded-[16px] p-5 shadow-sm">
        <label className="block text-[12px] font-bold text-text-light mb-2">السنة الأكاديمية</label>
        <select
          className="w-full max-w-md border border-primary/20 rounded-[10px] px-3 py-2 text-[13px] text-text-dark"
          value={academicYearId}
          onChange={event => setAcademicYearId(event.target.value)}
          disabled={loading || years.length === 0}
        >
          {years.length === 0 && <option value="">لا توجد سنوات أكاديمية</option>}
          {years.map(year => (
            <option key={year.academic_year_id} value={year.academic_year_id}>
              {year.year_name || year.name}
            </option>
          ))}
        </select>
      </div>

      {loading ? (
        <div className="bg-white border border-black/5 rounded-[16px] p-5 text-[13px] text-text-light">جاري التحميل…</div>
      ) : semesters.map(semester => {
        const period = periodForIdentity(yearPeriods, academicYearId, semester.semester_id)
        const inactive = canAnnouncePeriod(period)
        return (
          <div key={semester.semester_id} className="bg-white border border-primary/15 rounded-[16px] p-5 shadow-sm">
            <p className="text-[15px] font-black text-text-dark">{semester.semester_name || semester.name}</p>
            <div className="mt-3 h-px bg-primary/10" />
            <p className="text-[13px] text-text-light mt-3">الدورة التكميلية:</p>
            {inactive ? (
              <>
                <p className="text-[14px] font-bold text-text-dark mt-1">غير مفعلة</p>
                {canDecide && (
                  <button
                    type="button"
                    className="mt-4 px-4 py-2 rounded-[10px] bg-primary text-white text-[13px] font-bold hover:bg-primary-dark"
                    onClick={() => openAnnounceDialog(semester)}
                  >
                    فتح دورة تكميلية
                  </button>
                )}
              </>
            ) : (
              <>
                <p className="text-[14px] font-bold text-text-dark mt-1">{statusLabelAr(period.status)}</p>
                <p className="text-[13px] text-text-dark mt-2">{period.period_name}</p>
                <p className="text-[12px] text-text-light mt-1">من {formatPeriodDate(period.start_date)}</p>
                <p className="text-[12px] text-text-light">إلى {formatPeriodDate(period.end_date)}</p>
                <button
                  type="button"
                  className="mt-4 px-4 py-2 rounded-[10px] border border-primary/20 text-primary text-[13px] font-bold hover:bg-primary/5"
                  onClick={() => setDetailPeriod(period)}
                >
                  عرض التفاصيل
                </button>
              </>
            )}
          </div>
        )
      })}

      {dialogSemester && (
        <div
          className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45 p-0 sm:p-4"
          dir="rtl"
          role="dialog"
          aria-modal="true"
          aria-labelledby="supp-period-dialog-title"
          onClick={event => {
            if (event.target === event.currentTarget && !saving) setDialogSemester(null)
          }}
        >
          <div className="w-full sm:max-w-[520px] max-h-[96vh] overflow-y-auto bg-white rounded-t-[18px] sm:rounded-[18px] shadow-2xl">
            <div className="flex items-center justify-between border-b border-primary/10 px-5 py-4 sticky top-0 bg-white z-10">
              <h3 id="supp-period-dialog-title" className="text-[16px] font-black text-text-dark">فتح دورة تكميلية</h3>
              <button type="button" className="p-2 text-text-light hover:text-text-dark" onClick={() => !saving && setDialogSemester(null)} aria-label="إغلاق">
                <FaTimes aria-hidden="true" />
              </button>
            </div>
            <div className="px-5 py-4 space-y-4">
              <label className="block">
                <span className="block text-[12px] font-bold text-text-light mb-1">اسم الدورة</span>
                <input
                  className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px]"
                  value={form.period_name}
                  onChange={event => setForm(current => ({ ...current, period_name: event.target.value }))}
                />
              </label>
              <label className="block">
                <span className="block text-[12px] font-bold text-text-light mb-1">تاريخ البداية</span>
                <input
                  type="date"
                  className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px]"
                  value={form.start_date}
                  onChange={event => setForm(current => ({ ...current, start_date: event.target.value }))}
                />
              </label>
              <label className="block">
                <span className="block text-[12px] font-bold text-text-light mb-1">تاريخ النهاية</span>
                <input
                  type="date"
                  className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px]"
                  value={form.end_date}
                  onChange={event => setForm(current => ({ ...current, end_date: event.target.value }))}
                />
              </label>
              <label className="block">
                <span className="block text-[12px] font-bold text-text-light mb-1">ملاحظة القرار</span>
                <textarea
                  className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px] min-h-[80px]"
                  value={form.decision_note}
                  onChange={event => setForm(current => ({ ...current, decision_note: event.target.value }))}
                />
              </label>
              <p className="text-[13px] font-semibold rounded-[12px] px-3.5 py-3 bg-amber-50 text-amber-800 border border-amber-200">
                {CONFIRMATION_TEXT}
              </p>
            </div>
            <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-primary/10">
              <button
                type="button"
                className="px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] font-bold text-text-gray"
                onClick={() => setDialogSemester(null)}
                disabled={saving}
              >
                إلغاء
              </button>
              <button
                type="button"
                className="px-4 py-2 rounded-[10px] text-[13px] font-bold bg-primary text-white disabled:opacity-50"
                onClick={submitAnnounce}
                disabled={saving || !form.period_name || !form.start_date || !form.end_date}
              >
                اعتماد فتح الدورة
              </button>
            </div>
          </div>
        </div>
      )}

      {detailPeriod && (
        <div
          className="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4"
          dir="rtl"
          role="dialog"
          aria-modal="true"
          onClick={event => {
            if (event.target === event.currentTarget) setDetailPeriod(null)
          }}
        >
          <div className="w-full max-w-[480px] bg-white rounded-[18px] shadow-2xl p-5">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-[16px] font-black text-text-dark">تفاصيل الدورة التكميلية</h3>
              <button type="button" className="p-2 text-text-light" onClick={() => setDetailPeriod(null)} aria-label="إغلاق">
                <FaTimes />
              </button>
            </div>
            <dl className="space-y-2 text-[13px]">
              <div className="flex justify-between gap-4"><dt className="text-text-light">الاسم</dt><dd>{detailPeriod.period_name}</dd></div>
              <div className="flex justify-between gap-4"><dt className="text-text-light">الحالة</dt><dd>{statusLabelAr(detailPeriod.status)}</dd></div>
              <div className="flex justify-between gap-4"><dt className="text-text-light">من</dt><dd>{formatPeriodDate(detailPeriod.start_date)}</dd></div>
              <div className="flex justify-between gap-4"><dt className="text-text-light">إلى</dt><dd>{formatPeriodDate(detailPeriod.end_date)}</dd></div>
              <div className="flex justify-between gap-4"><dt className="text-text-light">تاريخ الاعتماد</dt><dd>{formatPeriodDate(detailPeriod.opened_at)}</dd></div>
              <div className="flex justify-between gap-4"><dt className="text-text-light">ملاحظة القرار</dt><dd>{detailPeriod.decision_note || '—'}</dd></div>
            </dl>
          </div>
        </div>
      )}
    </div>
  )
}
