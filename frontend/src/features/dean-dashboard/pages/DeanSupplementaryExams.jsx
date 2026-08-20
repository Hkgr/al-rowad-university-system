import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaCheckCircle, FaLock, FaPlus, FaRedo, FaUnlock } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { getIdentity } from '../../auth/auth'
import DeanConfirmDialog from '../components/DeanConfirmDialog'
import {
  canManageSupplementaryExamOfferings,
  canViewSupplementaryExamOfferings,
  isSummerPeriod,
  offeringErrorMessage,
  semesterOrderLabel,
  sourceSemestersLabel,
} from '../utils/supplementaryExamOfferings'

const EMPTY_COPY = 'لا توجد مواد مستوفية لشروط الطرح التكميلي لهذا البرنامج ضمن نطاق الدورة المحددة.'

function payloadFrom(response) {
  return response?.data ?? {}
}

export default function DeanSupplementaryExams() {
  const navigate = useNavigate()
  const identity = getIdentity()
  const canView = canViewSupplementaryExamOfferings(identity)
  const canManage = canManageSupplementaryExamOfferings(identity)

  const [years, setYears] = useState([])
  const [periods, setPeriods] = useState([])
  const [departments, setDepartments] = useState([])
  const [programs, setPrograms] = useState([])
  const [yearId, setYearId] = useState('')
  const [periodId, setPeriodId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [programId, setProgramId] = useState('')
  const [catalog, setCatalog] = useState(null)
  const [loading, setLoading] = useState(true)
  const [catalogLoading, setCatalogLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [dialog, setDialog] = useState(null)
  const [saving, setSaving] = useState(false)

  const selectedPeriod = useMemo(
    () => (catalog?.period ?? periods.find(period => String(period.supplementary_exam_period_id) === String(periodId)) ?? null),
    [catalog, periods, periodId],
  )
  const summer = isSummerPeriod(selectedPeriod)
  const filteredPeriods = useMemo(
    () => periods.filter(period => !yearId || String(period.academic_year?.academic_year_id ?? period.academic_year?.id) === String(yearId)),
    [periods, yearId],
  )
  const filteredPrograms = useMemo(
    () => programs.filter(program => !departmentId || String(program.department_id) === String(departmentId)),
    [programs, departmentId],
  )
  const courses = Array.isArray(catalog?.available_courses) ? catalog.available_courses : []
  const manageable = Boolean(catalog?.manageable) && canManage

  useEffect(() => {
    if (!canView) {
      setLoading(false)
      setError('هذه الصفحة متاحة لعميد الكلية مع صلاحية العرض المعينة.')
      return undefined
    }

    let active = true
    async function loadContext() {
      setLoading(true)
      setError('')
      try {
        const response = await apiRequest('/v1/dean/supplementary-exam-offerings/context')
        if (!active) return
        const data = payloadFrom(response)
        const nextYears = Array.isArray(data.academic_years) ? data.academic_years : []
        const nextPeriods = Array.isArray(data.periods) ? data.periods : []
        const nextDepartments = Array.isArray(data.departments) ? data.departments : []
        const nextPrograms = Array.isArray(data.programs) ? data.programs : []
        setYears(nextYears)
        setPeriods(nextPeriods)
        setDepartments(nextDepartments)
        setPrograms(nextPrograms)
        if (!yearId && nextYears.length > 0) {
          const current = nextYears.find(year => year.is_current) ?? nextYears[0]
          setYearId(String(current.academic_year_id))
        }
        if (!departmentId && nextDepartments.length === 1) {
          setDepartmentId(String(nextDepartments[0].department_id))
        }
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError(offeringErrorMessage(requestError))
      } finally {
        if (active) setLoading(false)
      }
    }

    loadContext()
    return () => { active = false }
  }, [canView, navigate])

  useEffect(() => {
    if (!canView || !periodId || !programId) {
      setCatalog(null)
      return undefined
    }

    let active = true
    async function loadCatalog() {
      setCatalogLoading(true)
      setError('')
      try {
        const params = new URLSearchParams({
          supplementary_exam_period_id: String(periodId),
          academic_program_id: String(programId),
        })
        const response = await apiRequest(`/v1/dean/supplementary-exam-offerings/catalog?${params.toString()}`)
        if (!active) return
        setCatalog(payloadFrom(response))
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setCatalog(null)
        setError(offeringErrorMessage(requestError))
      } finally {
        if (active) setCatalogLoading(false)
      }
    }

    loadCatalog()
    return () => { active = false }
  }, [canView, periodId, programId, navigate])

  async function confirmAction() {
    if (!dialog) return
    setSaving(true)
    setNotice('')
    setError('')
    try {
      if (dialog.type === 'open') {
        await apiRequest('/v1/dean/supplementary-exam-offerings', {
          method: 'POST',
          body: JSON.stringify({
            supplementary_exam_period_id: Number(periodId),
            academic_program_id: Number(programId),
            course_id: dialog.course.course_id,
          }),
        })
        setNotice('تم طرح المادة في الدورة التكميلية.')
      } else if (dialog.type === 'close') {
        await apiRequest(`/v1/dean/supplementary-exam-offerings/${dialog.offeringId}/close`, { method: 'POST' })
        setNotice('تم إغلاق الطرح التكميلي.')
      } else if (dialog.type === 'reopen') {
        await apiRequest(`/v1/dean/supplementary-exam-offerings/${dialog.offeringId}/reopen`, { method: 'POST' })
        setNotice('تمت إعادة فتح الطرح التكميلي.')
      }
      setDialog(null)
      const params = new URLSearchParams({
        supplementary_exam_period_id: String(periodId),
        academic_program_id: String(programId),
      })
      const response = await apiRequest(`/v1/dean/supplementary-exam-offerings/catalog?${params.toString()}`)
      setCatalog(payloadFrom(response))
    } catch (requestError) {
      setError(offeringErrorMessage(requestError))
    } finally {
      setSaving(false)
    }
  }

  const periodTitle = selectedPeriod
    ? `${selectedPeriod.name || selectedPeriod.period_name || 'الدورة التكميلية'} — ${semesterOrderLabel(selectedPeriod.semester_order)}`
    : 'الامتحانات التكميلية'
  const yearName = selectedPeriod?.academic_year?.year_name || years.find(year => String(year.academic_year_id) === String(yearId))?.year_name || ''

  return (
    <div className="space-y-5" dir="rtl">
      <header className="bg-white border border-primary/12 rounded-[18px] px-5 py-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
        <h1 className="text-[20px] font-black text-text-dark mb-1">الامتحانات التكميلية</h1>
        <p className="text-[13px] text-text-light font-semibold">طرح المواد التي قُدّمت فعليًا في الفصل الأصلي لهذه الدورة التكميلية.</p>
      </header>

      <section className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-4 max-[1100px]:grid-cols-2 max-[560px]:grid-cols-1 gap-3">
          <label className="block">
            <span className="block text-[12px] font-bold text-text-gray mb-1">السنة الأكاديمية</span>
            <select
              className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px] font-semibold"
              value={yearId}
              onChange={event => {
                setYearId(event.target.value)
                setPeriodId('')
                setCatalog(null)
              }}
            >
              <option value="">اختر السنة</option>
              {years.map(year => (
                <option key={year.academic_year_id} value={year.academic_year_id}>{year.year_name}</option>
              ))}
            </select>
          </label>
          <label className="block">
            <span className="block text-[12px] font-bold text-text-gray mb-1">الدورة التكميلية</span>
            <select
              className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px] font-semibold"
              value={periodId}
              onChange={event => {
                setPeriodId(event.target.value)
                setCatalog(null)
              }}
            >
              <option value="">اختر الدورة</option>
              {filteredPeriods.map(period => (
                <option key={period.supplementary_exam_period_id} value={period.supplementary_exam_period_id}>
                  {period.name || period.period_name} — {semesterOrderLabel(period.semester_order)}
                </option>
              ))}
            </select>
          </label>
          <label className="block">
            <span className="block text-[12px] font-bold text-text-gray mb-1">القسم</span>
            <select
              className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px] font-semibold"
              value={departmentId}
              onChange={event => {
                setDepartmentId(event.target.value)
                setProgramId('')
                setCatalog(null)
              }}
            >
              <option value="">اختر القسم</option>
              {departments.map(department => (
                <option key={department.department_id} value={department.department_id}>{department.department_name}</option>
              ))}
            </select>
          </label>
          <label className="block">
            <span className="block text-[12px] font-bold text-text-gray mb-1">البرنامج</span>
            <select
              className="w-full border border-primary/20 rounded-[10px] px-3 py-2 text-[13px] font-semibold"
              value={programId}
              onChange={event => {
                setProgramId(event.target.value)
                setCatalog(null)
              }}
            >
              <option value="">اختر البرنامج</option>
              {filteredPrograms.map(program => (
                <option key={program.academic_program_id} value={program.academic_program_id}>{program.program_name}</option>
              ))}
            </select>
          </label>
        </div>
      </section>

      {error ? (
        <p className="bg-red-50 border border-red-200 text-red-700 rounded-[14px] px-4 py-3 text-[13px] font-semibold">{error}</p>
      ) : null}
      {notice ? (
        <p className="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-[14px] px-4 py-3 text-[13px] font-semibold">{notice}</p>
      ) : null}

      {periodId && programId ? (
        <section className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)] space-y-4">
          <div>
            <h2 className="text-[16px] font-black text-text-dark">{periodTitle}</h2>
            <p className="text-[13px] text-text-light font-semibold">{yearName}</p>
            {summer ? (
              <>
                <p className="text-[13px] font-bold text-text-dark mt-2">الدورة التكميلية للفصل الثالث / الصيفي</p>
                <p className="text-[12.5px] font-semibold text-text-gray">المواد المتاحة من السنة الأكاديمية كاملة</p>
                <p className="text-[12.5px] font-semibold text-amber-800 bg-amber-50 border border-amber-200 rounded-[12px] px-3 py-2 mt-2">
                  في هذه الدورة يحق للطالب لاحقًا التسجيل في ثلاث مواد كحد أقصى.
                </p>
              </>
            ) : (
              <p className="text-[12.5px] font-semibold text-text-gray mt-2">المواد المتاحة من الفصل الأصلي</p>
            )}
          </div>

          {loading || catalogLoading ? (
            <p className="text-[13px] text-text-light font-semibold">جاري التحميل…</p>
          ) : courses.length === 0 ? (
            <p className="text-[13.5px] font-semibold text-text-gray bg-primary/5 rounded-[12px] px-4 py-4">{EMPTY_COPY}</p>
          ) : (
            <ul className="space-y-3">
              {courses.map(course => {
                const offering = course.supplementary_offering
                const sourcesLabel = sourceSemestersLabel(course.source_offerings)
                return (
                  <li
                    key={course.course_id}
                    className="flex items-center justify-between gap-3 border border-primary/12 rounded-[14px] px-4 py-3"
                  >
                    <div className="min-w-0">
                      <p className="text-[14px] font-black text-text-dark truncate">
                        {course.course_name}
                        {course.course_code ? <span className="text-text-light font-semibold"> · {course.course_code}</span> : null}
                      </p>
                      <p className="text-[12px] font-semibold text-text-gray">
                        المصدر: {sourcesLabel || '—'}
                      </p>
                      {offering?.status === 'open' ? (
                        <p className="text-[12px] font-bold text-primary mt-1 flex items-center gap-1">
                          <FaCheckCircle aria-hidden="true" /> مطروحة في التكميلي
                        </p>
                      ) : null}
                      {offering?.status === 'closed' ? (
                        <p className="text-[12px] font-bold text-text-light mt-1">مغلقة</p>
                      ) : null}
                    </div>
                    {manageable && !offering ? (
                      <button
                        type="button"
                        className="shrink-0 flex items-center gap-2 bg-primary hover:bg-primary-dark text-white rounded-[10px] px-3 py-2 text-[12.5px] font-bold"
                        onClick={() => setDialog({ type: 'open', course })}
                      >
                        <FaPlus aria-hidden="true" /> طرح في التكميلي
                      </button>
                    ) : null}
                    {manageable && offering?.status === 'open' ? (
                      <button
                        type="button"
                        className="shrink-0 flex items-center gap-2 border border-red-200 text-red-700 hover:bg-red-50 rounded-[10px] px-3 py-2 text-[12.5px] font-bold"
                        onClick={() => setDialog({ type: 'close', course, offeringId: offering.id ?? offering.supplementary_exam_offering_id })}
                      >
                        <FaLock aria-hidden="true" /> إغلاق
                      </button>
                    ) : null}
                    {manageable && offering?.status === 'closed' ? (
                      <button
                        type="button"
                        className="shrink-0 flex items-center gap-2 border border-primary/20 text-primary hover:bg-primary/5 rounded-[10px] px-3 py-2 text-[12.5px] font-bold"
                        onClick={() => setDialog({ type: 'reopen', course, offeringId: offering.id ?? offering.supplementary_exam_offering_id })}
                      >
                        <FaRedo aria-hidden="true" /> إعادة فتح
                      </button>
                    ) : null}
                    {!manageable && offering?.status === 'open' ? (
                      <span className="text-[12px] font-bold text-primary flex items-center gap-1"><FaUnlock aria-hidden="true" /> مطروحة</span>
                    ) : null}
                  </li>
                )
              })}
            </ul>
          )}
        </section>
      ) : (
        <p className="text-[13px] font-semibold text-text-light">اختر السنة والدورة والقسم والبرنامج لعرض المواد المتاحة.</p>
      )}

      {dialog ? (
        <DeanConfirmDialog
          title={
            dialog.type === 'open' ? 'طرح المادة في التكميلي'
              : dialog.type === 'close' ? 'إغلاق الطرح التكميلي'
                : 'إعادة فتح الطرح التكميلي'
          }
          confirmLabel={
            dialog.type === 'open' ? 'طرح في التكميلي'
              : dialog.type === 'close' ? 'إغلاق'
                : 'إعادة فتح'
          }
          confirmTone={dialog.type === 'close' ? 'danger' : 'primary'}
          busy={saving}
          onConfirm={confirmAction}
          onCancel={() => { if (!saving) setDialog(null) }}
        >
          <p className="text-[13.5px] font-semibold text-text-dark">
            {dialog.course?.course_name}
          </p>
          <p className="text-[12.5px] text-text-gray font-semibold">
            {dialog.type === 'open'
              ? 'سيتم طرح هذه المادة للامتحان النظري في الدورة التكميلية المحددة. هذا ليس طرحًا تدريسيًا.'
              : dialog.type === 'close'
                ? 'سيُغلق الطرح التكميلي. يبقى السجل والمصادر للرجوع إليهما لاحقًا.'
                : 'ستُعاد فتح المادة في الدورة التكميلية بعد التحقق من مصادرها الأصلية.'}
          </p>
        </DeanConfirmDialog>
      ) : null}
    </div>
  )
}
