import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaLockOpen, FaPlus, FaSpinner, FaTimes } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import DeanConfirmDialog from '../components/DeanConfirmDialog'
import { firstApiErrorMessage, offeringStatusLabel, displayValue } from '../utils/teacherDisplay'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'
import {
  instructorCoverageComplete,
  instructorCoverageSummary,
  instructorRoleTeacherName,
} from '../utils/courseOfferingDisplay'
import {
  requestStatusLabel,
  reviewStatusLabel,
} from '../../vice-presidency/utils/exceptionalOpeningLabels'
import {
  ADVISORY_NOTICE,
  CLEAR_DRAFT_WARNING,
  CLOSURE_REQUEST_SUBMITTED,
  CLOSURE_REQUEST_WARNING,
  advisorySemesterLabel,
  applyAdvisoryPlan,
  applyBulkPrepareOutcome,
  canSubmitCurrentWorkflowRequest,
  coursesForAcademicLevel,
  currentWorkflowRequest,
  matchesCourseSearch,
  openOfferingIds,
  pagedResourceRows,
  recommendedSemesterMatches,
  rowsByAcademicLevel,
  savePreview,
  uniqueProgramCourseIds,
} from '../utils/deanOfferingPlanner'

async function fetchCurrentClosureRequests(levels) {
  const ids = openOfferingIds(levels)
  if (ids.length === 0) return {}
  const entries = await Promise.all(ids.map(async (id) => {
    try {
      const params = new URLSearchParams({
        course_offering_id: String(id),
        per_page: '5',
      })
      const response = await apiRequest(`/v1/dean/course-offering-closures?${params.toString()}`)
      return [id, currentWorkflowRequest(pagedResourceRows(response))]
    } catch {
      return [id, null]
    }
  }))
  const map = {}
  entries.forEach(([id, row]) => {
    if (row) map[id] = row
  })
  return map
}

function InfoLine({ label, value }) {
  return (
    <div className="min-w-0">
      <p className="text-[11px] text-text-light font-semibold mb-0.5">{label}</p>
      <p className="text-[13.5px] text-text-dark font-semibold break-words">{displayValue(value)}</p>
    </div>
  )
}

function registrationState(offering) {
  if (!offering) {
    return {
      key: 'draft',
      label: 'غير محفوظ',
      className: 'bg-amber-500/10 text-amber-800 border-amber-500/20',
    }
  }
  if (offering.status === 'open') {
    return {
      key: 'open',
      label: 'مفتوح',
      className: 'bg-green-500/10 text-green-700 border-green-500/20',
    }
  }
  if (offering.status === 'closed') {
    if (offering.instructor_coverage && !instructorCoverageComplete(offering.instructor_coverage)) {
      return {
        key: 'pending_coverage',
        label: 'بانتظار التكليف',
        className: 'bg-amber-500/10 text-amber-800 border-amber-500/20',
      }
    }
    return {
      key: 'closed',
      label: 'مغلق',
      className: 'bg-slate-500/10 text-slate-600 border-slate-500/25',
    }
  }
  return {
    key: 'other',
    label: offeringStatusLabel(offering.status),
    className: 'bg-red-500/10 text-red-700 border-red-500/20',
  }
}

function CourseCard({
  row,
  selectedSemesterId,
  canManage,
  canRequestException,
  canRequestClosure,
  busy,
  prepareError,
  closureRequest,
  onRemoveDraft,
  onReopen,
  onManageOffering,
  onManageInstructors,
  onRequestException,
  onResubmitException,
  onRequestClosure,
  onResubmitClosure,
}) {
  const course = row.course
  const offering = row.offering
  const state = registrationState(offering)
  const coverage = offering?.instructor_coverage
  const coverageLabel = instructorCoverageSummary(coverage)
  const theoryName = instructorRoleTeacherName(coverage, 'theoretical')
  const practicalName = instructorRoleTeacherName(coverage, 'practical')
  const requiredRoles = coverage?.required_roles ?? []
  const exceptionRequest = offering?.exceptional_opening_request
  const exceptionStatus = exceptionRequest?.status
  const showExceptionRequest = canRequestException && state.key === 'pending_coverage'
    && (!exceptionRequest || exceptionStatus === 'returned' || exceptionStatus === 'superseded')
  const showExceptionStatus = Boolean(exceptionRequest)
    && state.key === 'pending_coverage'
    && exceptionStatus !== 'superseded'
  const closureStatus = closureRequest?.status
  const showClosureRequest = canRequestClosure && state.key === 'open'
    && canSubmitCurrentWorkflowRequest(closureRequest)
  const showClosureStatus = Boolean(closureRequest)
    && state.key === 'open'
    && closureStatus !== 'superseded'
  const advisory = advisorySemesterLabel(row, selectedSemesterId)
  const advisoryMatch = recommendedSemesterMatches(row, selectedSemesterId)
  const advisoryClass = advisoryMatch
    ? 'bg-primary/10 text-primary-dark border-primary/20'
    : advisory === 'الفصل الإرشادي غير محدد'
      ? 'bg-slate-500/10 text-slate-600 border-slate-500/20'
      : 'bg-amber-500/10 text-amber-800 border-amber-500/20'

  return (
    <article className="border border-primary/12 rounded-[14px] bg-white px-4 py-3.5 flex flex-col shadow-[0_1px_8px_rgba(26,46,16,0.04)]">
      <div className="min-w-0">
        <p className="font-mono text-[13px] font-black text-primary-dark">{displayValue(course?.course_code)}</p>
        <h4 className="text-[14px] font-extrabold text-text-dark mt-0.5 break-words">{displayValue(course?.course_name)}</h4>
      </div>

      <div className="flex items-center flex-wrap gap-1.5 mt-2.5">
        <CourseRequirementBadges classification={row.requirement_classification} compact />
        <span className={`inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-full border ${state.className}`}>
          {state.label}
        </span>
        <span className={`inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-full border ${advisoryClass}`}>
          {advisory}
        </span>
      </div>

      <div className="flex items-center flex-wrap gap-x-3 gap-y-1 mt-2.5 text-[11.5px] text-text-light">
        <span><b className="text-text-dark">{displayValue(course?.credit_hours)}</b> ساعة</span>
      </div>

      {offering && coverage && (
        <p className={`mt-2 text-[12px] font-bold ${instructorCoverageComplete(coverage) ? 'text-green-700' : 'text-amber-800'}`}>
          اكتمال المدرسين: {coverageLabel}
          {requiredRoles.includes('theoretical') ? ` · نظري: ${theoryName || '—'}` : ''}
          {requiredRoles.includes('practical') ? ` · عملي: ${practicalName || '—'}` : ''}
        </p>
      )}

      {showExceptionStatus && (
        <div className="mt-2 rounded-[10px] border border-primary/15 bg-primary/[0.04] px-3 py-2 text-[12px] text-text-dark space-y-1">
          <p className="font-bold">طلب الفتح الاستثنائي: {requestStatusLabel(exceptionStatus)}</p>
          <p>علمي: {reviewStatusLabel(exceptionRequest.scientific_review?.status)}</p>
          <p>إداري: {reviewStatusLabel(exceptionRequest.administrative_review?.status)}</p>
        </div>
      )}

      {showClosureStatus && (
        <div className="mt-2 rounded-[10px] border border-primary/15 bg-primary/[0.04] px-3 py-2 text-[12px] text-text-dark space-y-1">
          <p className="font-bold">طلب إغلاق التسجيل: {requestStatusLabel(closureStatus)}</p>
          <p>علمي: {reviewStatusLabel(closureRequest.scientific_review?.status)}</p>
          <p>إداري: {reviewStatusLabel(closureRequest.administrative_review?.status)}</p>
        </div>
      )}

      {!offering && prepareError ? (
        <p className="mt-2 text-[12px] font-semibold text-amber-800">{prepareError}</p>
      ) : null}

      <div className="mt-3 flex flex-wrap gap-2">
        {!offering ? (
          <button
            type="button"
            className="px-3 py-2 border border-primary/20 rounded-[10px] text-[12.5px] font-bold text-text-gray hover:bg-primary/5"
            onClick={() => onRemoveDraft(row.program_course_id)}
          >
            إزالة
          </button>
        ) : (
          <>
            <button
              type="button"
              className="px-3 py-2 bg-primary/15 text-primary-dark rounded-[10px] text-[12.5px] font-bold hover:bg-primary/22"
              onClick={() => onManageOffering(offering.course_offering_id)}
            >
              إدارة الطرح
            </button>
            {canManage && state.key === 'closed' ? (
              <button
                type="button"
                className="flex items-center justify-center gap-1.5 px-3 py-2 bg-primary text-white rounded-[10px] text-[12.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
                onClick={() => onReopen(row)}
                disabled={busy}
              >
                {busy ? <FaSpinner className="animate-spin text-[11px]" aria-hidden="true" /> : <FaLockOpen className="text-[11px]" aria-hidden="true" />}
                فتح المادة
              </button>
            ) : null}
            {canManage && state.key === 'pending_coverage' ? (
              <button
                type="button"
                className="px-3 py-2 bg-primary/15 text-primary-dark rounded-[10px] text-[12.5px] font-bold hover:bg-primary/22"
                onClick={() => onManageInstructors(offering.course_offering_id)}
              >
                إدارة تكليف المدرسين
              </button>
            ) : null}
            {showExceptionRequest ? (
              <button
                type="button"
                className="px-3 py-2 border border-primary/30 text-primary-dark rounded-[10px] text-[12.5px] font-bold hover:bg-primary/8 disabled:opacity-40"
                onClick={() => (exceptionStatus === 'returned' ? onResubmitException(row) : onRequestException(row))}
                disabled={busy}
              >
                {exceptionStatus === 'returned' ? 'إعادة إرسال طلب الفتح الاستثنائي' : 'طلب فتح استثنائي'}
              </button>
            ) : null}
            {showClosureRequest ? (
              <button
                type="button"
                className="px-3 py-2 border border-primary/30 text-primary-dark rounded-[10px] text-[12.5px] font-bold hover:bg-primary/8 disabled:opacity-40"
                onClick={() => (closureStatus === 'returned' ? onResubmitClosure(row) : onRequestClosure(row))}
                disabled={busy}
              >
                {closureStatus === 'returned' ? 'إعادة إرسال طلب الإغلاق' : 'طلب إغلاق التسجيل'}
              </button>
            ) : null}
          </>
        )}
      </div>
    </article>
  )
}

function AddCourseDialog({
  level,
  courses,
  draftIds,
  selectedSemesterId,
  onAdd,
  onClose,
}) {
  const [query, setQuery] = useState('')
  const draftSet = new Set((draftIds ?? []).map(Number))
  const visible = (courses ?? []).filter(row => matchesCourseSearch(row, query))

  return (
    <div
      className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45 p-0 sm:p-4"
      dir="rtl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="dean-add-course-title"
      onClick={event => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div className="w-full sm:max-w-[560px] max-h-[96vh] overflow-y-auto bg-white rounded-t-[18px] sm:rounded-[18px] shadow-2xl">
        <div className="flex items-center justify-between border-b border-primary/10 px-5 py-4 sticky top-0 bg-white z-10">
          <h3 id="dean-add-course-title" className="text-[16px] font-black text-text-dark">
            إضافة مادة — {level?.level_name}
          </h3>
          <button
            type="button"
            className="p-2 text-text-light hover:text-text-dark"
            onClick={onClose}
            aria-label="إغلاق"
            title="إغلاق"
          >
            <FaTimes aria-hidden="true" />
          </button>
        </div>

        <div className="px-5 py-4 space-y-3">
          <input
            className="w-full py-[13px] px-4 border-[1.5px] border-primary/20 rounded-[13px] bg-white text-[14px] font-medium text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)]"
            type="search"
            placeholder="ابحث باسم المادة أو رمزها"
            value={query}
            onChange={event => setQuery(event.target.value)}
          />

          {visible.length === 0 ? (
            <p className="text-[13px] text-text-light py-6 text-center">لا توجد مواد مطابقة في هذه السنة.</p>
          ) : (
            <div className="space-y-2">
              {visible.map(row => {
                const persisted = Boolean(row.offering)
                const added = draftSet.has(Number(row.program_course_id))
                const blocked = persisted || added
                return (
                  <div
                    key={row.program_course_id}
                    className="border border-primary/12 rounded-[14px] bg-white px-3.5 py-3 flex items-center justify-between gap-3"
                  >
                    <div className="min-w-0">
                      <p className="font-mono text-[12.5px] font-black text-primary-dark">
                        {displayValue(row.course?.course_code)}
                      </p>
                      <p className="text-[13.5px] font-extrabold text-text-dark break-words">
                        {displayValue(row.course?.course_name)}
                      </p>
                      <p className="text-[11.5px] text-text-light mt-1">
                        {displayValue(row.course?.credit_hours)} ساعات
                        {' · '}
                        {advisorySemesterLabel(row, selectedSemesterId)}
                      </p>
                    </div>
                    <button
                      type="button"
                      className="shrink-0 px-3 py-2 bg-primary text-white rounded-[10px] text-[12.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
                      disabled={blocked}
                      onClick={() => onAdd(row)}
                    >
                      {blocked ? (persisted ? 'محفوظة' : 'مضافة') : 'إضافة'}
                    </button>
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export default function DeanRegistrationOfferings() {
  const navigate = useNavigate()
  const canManageLocal = hasPermission(PERMISSIONS.courseOfferingsManage)
    || hasPermission(PERMISSIONS.coursesManage)
  const canRequestException = hasPermission(PERMISSIONS.exceptionalOpenRequest)
  const canRequestClosure = hasPermission(PERMISSIONS.closureRequest)
  const [exceptionReason, setExceptionReason] = useState('')
  const [closureReason, setClosureReason] = useState('')

  const [options, setOptions] = useState({
    academic_years: [],
    semesters: [],
    departments: [],
    academic_programs: [],
  })
  const [college, setCollege] = useState(null)
  const [yearId, setYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [programId, setProgramId] = useState('')
  const [levels, setLevels] = useState([])
  const [draftIds, setDraftIds] = useState([])
  const [prepareErrors, setPrepareErrors] = useState({})
  const [closureByOffering, setClosureByOffering] = useState({})
  const [context, setContext] = useState({ academic_year: null, semester: null })
  const [bootLoading, setBootLoading] = useState(true)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [noticeTone, setNoticeTone] = useState('success')
  const [busyIds, setBusyIds] = useState({})
  const [confirm, setConfirm] = useState(null)
  const [addLevel, setAddLevel] = useState(null)
  const savingRef = useRef(false)

  const goToLogin = useCallback(() => navigate('/login', { replace: true }), [navigate])

  const handleRequestError = useCallback((requestError, fallback) => {
    if (requestError.status === 401) {
      goToLogin()
      return fallback
    }
    if (requestError.status === 403) {
      if (requestError.errorCode === 'program_outside_user_scope') {
        return requestError.message || 'ليس لديك صلاحية على هذا البرنامج.'
      }
      return requestError.message || 'ليس لديك صلاحية لإدارة إتاحة هذه المادة.'
    }
    if (requestError.status === 404) {
      return 'تعذّر الوصول إلى المادة المطلوبة.'
    }
    if (requestError.status === 409) {
      if (requestError.errorCode === 'offering_instructor_coverage_incomplete') {
        return requestError.message || 'لا يمكن فتح المادة قبل استكمال تكليف المدرسين المعتمدين.'
      }
      if (requestError.errorCode === 'exceptional_opening_not_required') {
        return requestError.message || 'تكليف المدرسين مكتمل. استخدم الفتح الاعتيادي.'
      }
      if (requestError.errorCode === 'exceptional_opening_duplicate_current') {
        return requestError.message || 'يوجد طلب فتح استثنائي حالي لنفس الطرح.'
      }
      if (requestError.errorCode === 'normal_opening_available') {
        return requestError.message || 'أصبح الفتح الاعتيادي متاحًا. استخدم فتح المادة الاعتيادي.'
      }
      if (requestError.errorCode === 'course_offering_closure_request_already_current') {
        return requestError.message || 'يوجد طلب إغلاق حالي لنفس الطرح.'
      }
      if (requestError.errorCode === 'course_offering_closure_workflow_required') {
        return requestError.message || 'لا يمكن إغلاق طرح مفتوح مباشرة. يجب إرسال طلب عبر مسار موافقة النائب العلمي والنائب الإداري.'
      }
      if (requestError.errorCode === 'course_offering_closure_reason_required') {
        return requestError.message || 'سبب طلب إغلاق طرح المادة مطلوب.'
      }
      return requestError.message || 'تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.'
    }
    if (requestError.status === 422) {
      if (requestError.errorCode === 'offering_teaching_components_undefined') {
        return requestError.message || 'لا يمكن فتح المادة لأن مكونات التدريس للمقرر غير محددة.'
      }
      return firstApiErrorMessage(requestError, fallback)
    }
    return fallback
  }, [goToLogin])

  useEffect(() => {
    let active = true

    async function boot() {
      setBootLoading(true)
      setError('')
      try {
        const response = await apiRequest('/v1/dean/registration-offerings')
        if (!active) return
        const data = response?.data ?? {}
        setOptions({
          academic_years: data.filter_options?.academic_years ?? [],
          semesters: data.filter_options?.semesters ?? [],
          departments: data.filter_options?.departments ?? [],
          academic_programs: data.filter_options?.academic_programs ?? [],
        })
        setCollege(data.college ?? null)
        const currentYear = (data.filter_options?.academic_years ?? []).find(year => year.is_current)
        if (currentYear) setYearId(String(currentYear.academic_year_id))
      } catch (requestError) {
        if (!active) return
        setError(handleRequestError(
          requestError,
          requestError.status === 403
            ? 'ليس لديك صلاحية لعرض مواد الكلية.'
            : 'تعذّر تحميل بيانات إتاحة المواد.',
        ))
      } finally {
        if (active) setBootLoading(false)
      }
    }

    boot()
    return () => { active = false }
  }, [handleRequestError])

  useEffect(() => {
    if (!yearId || !semesterId || !programId) return undefined

    let active = true

    async function loadCurriculum() {
      setLoading(true)
      setError('')
      try {
        const params = new URLSearchParams({
          academic_year_id: yearId,
          semester_id: semesterId,
          academic_program_id: programId,
        })
        const response = await apiRequest(`/v1/dean/registration-offerings?${params.toString()}`)
        if (!active) return
        const data = response?.data ?? {}
        setLevels(data.levels ?? [])
        setCollege(data.college ?? null)
        setContext({
          academic_year: data.academic_year ?? null,
          semester: data.semester ?? null,
        })
        setClosureByOffering(await fetchCurrentClosureRequests(data.levels ?? []))
      } catch (requestError) {
        if (!active) return
        setLevels([])
        setError(handleRequestError(requestError, 'تعذّر تحميل خطة البرنامج.'))
      } finally {
        if (active) setLoading(false)
      }
    }

    loadCurriculum()
    return () => { active = false }
  }, [handleRequestError, programId, semesterId, yearId])

  useEffect(() => {
    setDraftIds([])
    setPrepareErrors({})
    setClosureByOffering({})
    setAddLevel(null)
    setNotice('')
  }, [programId, semesterId, yearId])

  const programs = useMemo(() => {
    const all = options.academic_programs ?? []
    if (!departmentId) return all
    return all.filter(program => String(program.department_id) === String(departmentId))
  }, [departmentId, options.academic_programs])

  const selectedProgram = useMemo(
    () => programs.find(program => String(program.academic_program_id) === String(programId))
      || (options.academic_programs ?? []).find(program => String(program.academic_program_id) === String(programId)),
    [options.academic_programs, programId, programs],
  )

  const levelCards = useMemo(
    () => rowsByAcademicLevel(levels, draftIds),
    [draftIds, levels],
  )

  const preview = useMemo(
    () => savePreview(levels, draftIds),
    [draftIds, levels],
  )

  const yearName = context.academic_year?.year_name
    || options.academic_years.find(year => String(year.academic_year_id) === String(yearId))?.year_name
  const semesterName = context.semester?.semester_name
    || options.semesters.find(semester => String(semester.semester_id) === String(semesterId))?.semester_name

  function patchOffering(programCourseId, offering) {
    setLevels(current => current.map(level => ({
      ...level,
      courses: (level.courses ?? []).map(row => (
        row.program_course_id === programCourseId ? { ...row, offering } : row
      )),
    })))
  }

  function showNotice(message, tone = 'success') {
    setNoticeTone(tone)
    setNotice(message)
  }

  function applyAdvisoryPlanClick() {
    const result = applyAdvisoryPlan(draftIds, levels, semesterId)
    if (result.kind === 'missing-metadata') {
      showNotice(result.notice || ADVISORY_NOTICE.missingMetadata, 'warning')
      return
    }
    if (result.kind === 'zero-match') {
      showNotice(result.notice || ADVISORY_NOTICE.zeroMatch, 'warning')
      return
    }
    setDraftIds(result.draftIds)
    showNotice(result.notice, 'success')
  }

  function addCourseToDraft(row) {
    setDraftIds(current => uniqueProgramCourseIds([...(current ?? []), row.program_course_id]))
    setAddLevel(null)
  }

  function courseCard(row) {
    return (
      <CourseCard
        key={row.program_course_id}
        row={row}
        selectedSemesterId={semesterId}
        canManage={canManageLocal}
        canRequestException={canRequestException}
        canRequestClosure={canRequestClosure}
        busy={Boolean(busyIds[`pc-${row.program_course_id}`] || (row.offering && busyIds[`off-${row.offering.course_offering_id}`]) || busyIds.bulk || (row.offering && busyIds[`cl-${row.offering.course_offering_id}`]))}
        prepareError={prepareErrors[row.program_course_id]}
        closureRequest={row.offering ? closureByOffering[row.offering.course_offering_id] : null}
        onRemoveDraft={id => setDraftIds(current => current.filter(item => Number(item) !== Number(id)))}
        onReopen={item => setConfirm({
          type: 'reopen',
          key: `off-${item.offering.course_offering_id}`,
          row: item,
        })}
        onManageOffering={id => navigate(`/dean/courses/${id}`)}
        onManageInstructors={id => navigate(`/dean/courses/${id}`)}
        onRequestException={item => {
          setExceptionReason('')
          setConfirm({
            type: 'exception',
            key: `ex-${item.offering.course_offering_id}`,
            row: item,
          })
        }}
        onResubmitException={item => {
          setExceptionReason(item.offering?.exceptional_opening_request?.reason || '')
          setConfirm({
            type: 'exception-resubmit',
            key: `ex-${item.offering.course_offering_id}`,
            row: item,
          })
        }}
        onRequestClosure={item => {
          setClosureReason('')
          setConfirm({
            type: 'closure',
            key: `cl-${item.offering.course_offering_id}`,
            row: item,
          })
        }}
        onResubmitClosure={item => {
          const current = closureByOffering[item.offering.course_offering_id]
          setClosureReason(current?.reason || '')
          setConfirm({
            type: 'closure-resubmit',
            key: `cl-${item.offering.course_offering_id}`,
            row: item,
          })
        }}
      />
    )
  }

  async function runMutation(key, request, programCourseId, successFallback) {
    if (savingRef.current) return
    savingRef.current = true
    setBusyIds(current => ({ ...current, [key]: true }))
    setError('')
    try {
      const response = await request()
      const offering = response?.data?.offering ?? null
      const rowId = response?.data?.program_course_id ?? programCourseId
      if (offering) patchOffering(rowId, offering)
      showNotice(response?.message || successFallback)
      setConfirm(null)
    } catch (requestError) {
      setError(handleRequestError(requestError, 'تعذّر تحديث إتاحة المادة للتسجيل.'))
    } finally {
      savingRef.current = false
      setBusyIds(current => {
        const next = { ...current }
        delete next[key]
        return next
      })
    }
  }

  async function runException(key, request, programCourseId, successFallback) {
    if (savingRef.current) return
    savingRef.current = true
    setBusyIds(current => ({ ...current, [key]: true }))
    setError('')
    try {
      const response = await request()
      const exceptionRequest = response?.data ?? null
      setLevels(current => current.map(level => ({
        ...level,
        courses: (level.courses ?? []).map(row => (
          row.program_course_id === programCourseId && row.offering
            ? { ...row, offering: { ...row.offering, exceptional_opening_request: exceptionRequest } }
            : row
        )),
      })))
      showNotice(response?.message || successFallback)
      setConfirm(null)
      setExceptionReason('')
    } catch (requestError) {
      setError(handleRequestError(requestError, 'تعذّر إرسال طلب الفتح الاستثنائي.'))
    } finally {
      savingRef.current = false
      setBusyIds(current => {
        const next = { ...current }
        delete next[key]
        return next
      })
    }
  }

  async function runClosure(key, request, offeringId, successFallback) {
    if (savingRef.current) return
    savingRef.current = true
    setBusyIds(current => ({ ...current, [key]: true }))
    setError('')
    try {
      const response = await request()
      const closureRequest = response?.data ?? null
      if (closureRequest) {
        setClosureByOffering(current => ({
          ...current,
          [offeringId]: closureRequest,
        }))
      }
      showNotice(successFallback || CLOSURE_REQUEST_SUBMITTED)
      setConfirm(null)
      setClosureReason('')
    } catch (requestError) {
      setError(handleRequestError(requestError, 'تعذّر إرسال طلب إغلاق التسجيل.'))
    } finally {
      savingRef.current = false
      setBusyIds(current => {
        const next = { ...current }
        delete next[key]
        return next
      })
    }
  }

  async function reloadCatalog() {
    if (!yearId || !semesterId || !programId) return
    const params = new URLSearchParams({
      academic_year_id: yearId,
      semester_id: semesterId,
      academic_program_id: programId,
    })
    const response = await apiRequest(`/v1/dean/registration-offerings?${params.toString()}`)
    const data = response?.data ?? {}
    setLevels(data.levels ?? [])
    setCollege(data.college ?? null)
    setContext({
      academic_year: data.academic_year ?? null,
      semester: data.semester ?? null,
    })
    setClosureByOffering(await fetchCurrentClosureRequests(data.levels ?? []))
  }

  async function savePreparation() {
    if (savingRef.current) return
    savingRef.current = true
    setBusyIds(current => ({ ...current, bulk: true }))
    setError('')
    try {
      const response = await apiRequest('/v1/dean/registration-offerings/bulk-prepare', {
        method: 'POST',
        body: JSON.stringify({
          academic_program_id: Number(programId),
          academic_year_id: Number(yearId),
          semester_id: Number(semesterId),
          mode: 'selected',
          program_course_ids: preview.programCourseIds,
        }),
      })
      const result = response?.data ?? {}
      const outcome = applyBulkPrepareOutcome(result)
      setDraftIds(outcome.draftIds)
      setPrepareErrors(outcome.prepareErrors)
      showNotice(outcome.notice, outcome.tone)
      await reloadCatalog()
    } catch (requestError) {
      setError(handleRequestError(requestError, 'تعذّر تجهيز طروحات المواد.'))
    } finally {
      savingRef.current = false
      setBusyIds(current => {
        const next = { ...current }
        delete next.bulk
        return next
      })
    }
  }

  const confirmBusy = confirm
    ? Boolean(busyIds[confirm.key])
    : false

  if (bootLoading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-primary-light" dir="rtl">
        <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[14px] font-medium">جاري التحميل…</span>
      </div>
    )
  }

  return (
    <div dir="rtl">
      <div className="mb-5">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">فتح المواد للتسجيل</h2>
        <p className="text-[12.5px] text-text-light">
          جهّز مواد الفصل ثم تابع إدارة كل طرح بعد الحفظ.
        </p>
      </div>

      {college?.college_name && (
        <p className="text-[13px] font-semibold text-primary-dark mb-4">
          {college.college_name}
        </p>
      )}

      <div className="bg-white border border-primary/12 rounded-[16px] p-4 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
          <label className="flex flex-col gap-1.5 min-w-0">
            <span className="text-[12px] font-bold text-text-dark">السنة الأكاديمية</span>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
              value={yearId}
              onChange={event => setYearId(event.target.value)}
            >
              <option value="">اختر السنة</option>
              {(options.academic_years ?? []).map(year => (
                <option key={year.academic_year_id} value={year.academic_year_id}>{year.year_name}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1.5 min-w-0">
            <span className="text-[12px] font-bold text-text-dark">الفصل الفعلي</span>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary disabled:opacity-50"
              value={semesterId}
              onChange={event => setSemesterId(event.target.value)}
              disabled={!yearId}
            >
              <option value="">اختر الفصل</option>
              {(options.semesters ?? []).map(semester => (
                <option key={semester.semester_id} value={semester.semester_id}>{semester.semester_name}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1.5 min-w-0">
            <span className="text-[12px] font-bold text-text-dark">القسم</span>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
              value={departmentId}
              onChange={event => {
                setDepartmentId(event.target.value)
                setProgramId('')
              }}
            >
              <option value="">كل الأقسام</option>
              {(options.departments ?? []).map(department => (
                <option key={department.department_id} value={department.department_id}>{department.department_name}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1.5 min-w-0">
            <span className="text-[12px] font-bold text-text-dark">البرنامج</span>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary disabled:opacity-50"
              value={programId}
              onChange={event => setProgramId(event.target.value)}
              disabled={!yearId || !semesterId}
            >
              <option value="">اختر البرنامج</option>
              {programs.map(program => (
                <option key={program.academic_program_id} value={program.academic_program_id}>{program.program_name}</option>
              ))}
            </select>
          </label>
        </div>
      </div>

      {notice && (
        <div className={`mb-4 rounded-[12px] px-[18px] py-3 text-[13.5px] font-semibold whitespace-pre-line ${
          noticeTone === 'warning'
            ? 'bg-amber-50 text-amber-800 border border-amber-200'
            : 'bg-green-500/8 border border-green-500/25 text-green-700'
        }`}
        >
          {notice}
        </div>
      )}

      {error && (
        <div className="mb-4 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 text-[13.5px] text-red-600">
          ⚠ {error}
        </div>
      )}

      {!yearId || !semesterId || !programId ? (
        <p className="text-[13.5px] text-text-light bg-white border border-primary/12 rounded-[14px] px-4 py-8 text-center">
          اختر السنة الأكاديمية والفصل الفعلي والقسم والبرنامج لبدء تجهيز مواد الفصل.
        </p>
      ) : loading ? (
        <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary-light">
          <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
          <span className="text-[13.5px] font-medium">جاري تحميل مواد الخطة…</span>
        </div>
      ) : (
        <>
          <section className="bg-white border border-primary/12 rounded-[16px] p-4 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <h3 className="text-[14px] font-extrabold text-text-dark mb-1">تجهيز مواد الفصل</h3>
            <p className="text-[12.5px] text-text-light mb-4">
              أضف مواد الخطة الإرشادية أو أضف مواد أخرى يدويًا.
            </p>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                className="px-3 py-2 bg-primary text-white rounded-[10px] text-[12.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
                disabled={!canManageLocal || Boolean(busyIds.bulk)}
                onClick={applyAdvisoryPlanClick}
              >
                إضافة الخطة الإرشادية
              </button>
              <button
                type="button"
                className="px-3 py-2 border border-primary/20 rounded-[10px] text-[12.5px] font-bold text-text-gray hover:bg-primary/5 disabled:opacity-40"
                disabled={draftIds.length === 0 || Boolean(busyIds.bulk)}
                onClick={() => setConfirm({ type: 'clear-draft', key: 'clear-draft' })}
              >
                تفريغ التجهيز
              </button>
            </div>
          </section>

          {levels.length === 0 ? (
            <p className="text-[13.5px] text-text-light bg-white border border-primary/12 rounded-[14px] px-4 py-8 text-center">
              لا توجد مواد في خطة هذا البرنامج.
            </p>
          ) : (
            <div className="space-y-4">
              {levelCards.map(level => (
                <section
                  key={level.academic_level_id ?? level.level_name}
                  className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]"
                >
                  <div className="flex items-center justify-between px-4 py-3 bg-primary/[0.05] border-b border-primary/10">
                    <h3 className="text-[14px] font-extrabold text-text-dark">
                      {level.level_name}
                    </h3>
                    <span className="text-[11px] font-bold text-text-light bg-white px-2 py-0.5 rounded-full">
                      {level.rows.length} مواد
                      {level.curriculumCount > 0 ? ` · ${level.curriculumCount} مادة في الخطة` : ''}
                    </span>
                  </div>
                  <div className="p-4 space-y-3">
                    {level.rows.length === 0 ? (
                      <p className="text-[13px] text-text-light">
                        لم تتم إضافة مواد إلى تجهيز هذه السنة بعد.
                      </p>
                    ) : (
                      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        {level.rows.map(row => courseCard(row))}
                      </div>
                    )}
                    <button
                      type="button"
                      className="inline-flex items-center gap-1.5 px-3 py-2 border border-primary/20 rounded-[10px] text-[12.5px] font-bold text-primary-dark hover:bg-primary/5"
                      onClick={() => setAddLevel(level)}
                    >
                      <FaPlus className="text-[10px]" aria-hidden="true" />
                      + إضافة مادة
                    </button>
                  </div>
                </section>
              ))}
            </div>
          )}

          <section className="bg-white border border-primary/12 rounded-[16px] p-4 mt-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div className="text-[13px] text-text-dark space-y-1">
                <p><b>{preview.total}</b> مادة في التجهيز</p>
                <p><b>{preview.existing}</b> محفوظة مسبقًا</p>
                <p><b>{preview.creating}</b> سيتم إنشاؤها</p>
              </div>
              <button
                type="button"
                className="px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
                disabled={!canManageLocal || preview.total < 1 || Boolean(busyIds.bulk)}
                onClick={savePreparation}
              >
                {busyIds.bulk ? 'جاري الحفظ' : 'حفظ التجهيز'}
              </button>
            </div>
          </section>
        </>
      )}

      {addLevel && (
        <AddCourseDialog
          level={addLevel}
          courses={coursesForAcademicLevel(levels, addLevel.academic_level_id)}
          draftIds={draftIds}
          selectedSemesterId={semesterId}
          onAdd={addCourseToDraft}
          onClose={() => setAddLevel(null)}
        />
      )}

      {confirm && (
        <DeanConfirmDialog
          title={
            confirm.type === 'clear-draft'
              ? 'تفريغ التجهيز'
              : confirm.type === 'reopen'
                ? 'تأكيد فتح المادة'
                : confirm.type === 'exception' || confirm.type === 'exception-resubmit'
                  ? 'طلب فتح استثنائي'
                  : 'طلب إغلاق التسجيل'
          }
          warning={
            confirm.type === 'clear-draft'
              ? CLEAR_DRAFT_WARNING
              : confirm.type === 'reopen'
                ? 'سيتم فتح المادة للتسجيل بعد التحقق من اكتمال تكليف المدرسين المعتمدين.'
                : confirm.type === 'exception' || confirm.type === 'exception-resubmit'
                  ? 'لن تُفتح المادة من هذه الشاشة. يبقى الطرح مغلقًا إلى أن يوافق النائب العلمي والنائب الإداري معًا.'
                  : CLOSURE_REQUEST_WARNING
          }
          confirmLabel={
            confirm.type === 'clear-draft'
              ? 'تفريغ غير المحفوظ'
              : confirm.type === 'reopen'
                ? 'تأكيد الفتح'
                : confirm.type === 'exception' || confirm.type === 'closure'
                  ? 'إرسال الطلب'
                  : confirm.type === 'exception-resubmit' || confirm.type === 'closure-resubmit'
                    ? 'إعادة الإرسال'
                    : 'إرسال الطلب'
          }
          confirmTone="primary"
          busy={confirmBusy}
          disabled={
            ((confirm.type === 'exception' || confirm.type === 'exception-resubmit') && !exceptionReason.trim())
            || ((confirm.type === 'closure' || confirm.type === 'closure-resubmit') && !closureReason.trim())
          }
          onCancel={() => { if (!confirmBusy) { setConfirm(null); setExceptionReason(''); setClosureReason('') } }}
          onConfirm={() => {
            if (confirm.type === 'clear-draft') {
              setDraftIds([])
              setPrepareErrors({})
              setConfirm(null)
              return
            }
            const row = confirm.row
            if (confirm.type === 'reopen') {
              runMutation(
                confirm.key,
                () => apiRequest(`/v1/dean/registration-offerings/${row.offering.course_offering_id}/open`, {
                  method: 'POST',
                }),
                row.program_course_id,
                'تمت إعادة فتح التسجيل للمادة بنجاح.',
              )
              return
            }
            if (confirm.type === 'exception') {
              runException(
                confirm.key,
                () => apiRequest('/v1/dean/course-offering-exceptions', {
                  method: 'POST',
                  body: JSON.stringify({
                    course_offering_id: row.offering.course_offering_id,
                    reason: exceptionReason.trim(),
                  }),
                }),
                row.program_course_id,
                'تم إرسال طلب الفتح الاستثنائي. يبقى الطرح مغلقًا.',
              )
              return
            }
            if (confirm.type === 'exception-resubmit') {
              const requestId = row.offering.exceptional_opening_request?.course_offering_exception_request_id
              runException(
                confirm.key,
                () => apiRequest(`/v1/dean/course-offering-exceptions/${requestId}/resubmit`, {
                  method: 'POST',
                  body: JSON.stringify({
                    reason: exceptionReason.trim(),
                  }),
                }),
                row.program_course_id,
                'تم إعادة إرسال طلب الفتح الاستثنائي.',
              )
              return
            }
            if (confirm.type === 'closure') {
              runClosure(
                confirm.key,
                () => apiRequest('/v1/dean/course-offering-closures', {
                  method: 'POST',
                  body: JSON.stringify({
                    course_offering_id: row.offering.course_offering_id,
                    reason: closureReason.trim(),
                  }),
                }),
                row.offering.course_offering_id,
                CLOSURE_REQUEST_SUBMITTED,
              )
              return
            }
            if (confirm.type === 'closure-resubmit') {
              const requestId = closureByOffering[row.offering.course_offering_id]?.course_offering_closure_request_id
              runClosure(
                confirm.key,
                () => apiRequest(`/v1/dean/course-offering-closures/${requestId}/resubmit`, {
                  method: 'POST',
                  body: JSON.stringify({
                    reason: closureReason.trim(),
                  }),
                }),
                row.offering.course_offering_id,
                CLOSURE_REQUEST_SUBMITTED,
              )
            }
          }}
        >
          {confirm.row ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <InfoLine label="المادة" value={[confirm.row.course?.course_code, confirm.row.course?.course_name].filter(Boolean).join(' — ')} />
              <InfoLine label="البرنامج" value={selectedProgram?.program_name} />
              <InfoLine label="السنة الدراسية" value={yearName} />
              <InfoLine label="الفصل الفعلي للطرح" value={semesterName} />
              {(confirm.type === 'exception' || confirm.type === 'exception-resubmit') && (
                <label className="sm:col-span-2 flex flex-col gap-1.5">
                  <span className="text-[11px] text-text-light font-semibold">سبب الفتح الاستثنائي</span>
                  <textarea
                    className="w-full min-h-[96px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[13px]"
                    value={exceptionReason}
                    onChange={event => setExceptionReason(event.target.value)}
                    required
                  />
                </label>
              )}
              {(confirm.type === 'closure' || confirm.type === 'closure-resubmit') && (
                <label className="sm:col-span-2 flex flex-col gap-1.5">
                  <span className="text-[11px] text-text-light font-semibold">سبب إغلاق التسجيل</span>
                  <textarea
                    className="w-full min-h-[96px] py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[13px]"
                    value={closureReason}
                    onChange={event => setClosureReason(event.target.value)}
                    required
                  />
                </label>
              )}
            </div>
          ) : null}
        </DeanConfirmDialog>
      )}
    </div>
  )
}
