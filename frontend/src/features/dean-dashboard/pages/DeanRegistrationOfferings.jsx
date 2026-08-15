import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaLock, FaLockOpen, FaSpinner } from 'react-icons/fa'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import DeanConfirmDialog from '../components/DeanConfirmDialog'
import { firstApiErrorMessage, offeringStatusLabel, displayValue } from '../utils/teacherDisplay'

const DEFAULT_CAPACITY = 40

function InfoLine({ label, value }) {
  return (
    <div className="min-w-0">
      <p className="text-[11px] text-text-light font-semibold mb-0.5">{label}</p>
      <p className="text-[13.5px] text-text-dark font-semibold break-words">{displayValue(value)}</p>
    </div>
  )
}

function SummaryCard({ label, value }) {
  return (
    <div className="bg-white border border-primary/12 rounded-[14px] px-4 py-3 shadow-[0_2px_12px_rgba(26,46,16,0.05)] min-h-[76px]">
      <p className="text-[11.5px] text-text-light font-semibold mb-1">{label}</p>
      <p className="text-[20px] font-black text-text-dark tabular-nums">{value}</p>
    </div>
  )
}

function courseTypeLabel(type) {
  if (type === 'mandatory') return 'إجباري'
  if (type === 'elective') return 'اختياري'
  return displayValue(type)
}

function registrationState(offering) {
  if (!offering) {
    return {
      key: 'missing',
      label: 'غير متاحة للتسجيل بعد',
      className: 'bg-amber-500/10 text-amber-800 border-amber-500/20',
    }
  }
  if (offering.status === 'open') {
    return {
      key: 'open',
      label: 'متاحة للتسجيل',
      className: 'bg-green-500/10 text-green-700 border-green-500/20',
    }
  }
  if (offering.status === 'closed') {
    return {
      key: 'closed',
      label: 'مغلقة للتسجيل',
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
  canManage,
  busy,
  capacity,
  onCapacityChange,
  onOpen,
  onReopen,
  onClose,
}) {
  const course = row.course
  const offering = row.offering
  const state = registrationState(offering)

  return (
    <article className="border border-primary/12 rounded-[14px] bg-white px-4 py-3.5 flex flex-col min-h-[210px] shadow-[0_1px_8px_rgba(26,46,16,0.04)]">
      <div className="min-w-0">
        <p className="font-mono text-[13px] font-black text-primary-dark">{displayValue(course?.course_code)}</p>
        <h4 className="text-[14px] font-extrabold text-text-dark mt-0.5 break-words">{displayValue(course?.course_name)}</h4>
      </div>

      <div className="flex items-center flex-wrap gap-1.5 mt-2.5">
        <span className={`inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-full ${
          row.course_type === 'mandatory' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'
        }`}
        >
          {courseTypeLabel(row.course_type)}
        </span>
        <span className={`inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-full border ${state.className}`}>
          {state.label}
        </span>
      </div>

      <div className="flex items-center flex-wrap gap-x-3 gap-y-1 mt-2.5 text-[11.5px] text-text-light">
        <span><b className="text-text-dark">{displayValue(course?.credit_hours)}</b> ساعة</span>
        <span>نظري <b className="text-text-dark">{displayValue(course?.theoretical_hours)}</b></span>
        <span>عملي <b className="text-text-dark">{displayValue(course?.practical_hours)}</b></span>
      </div>

      {offering && (
        <p className="text-[12px] text-text-gray mt-2">
          {Number(offering.registered_students_count) || 0} طالب مسجل
          {' · '}
          {Number(offering.available_seats) || 0} مقعد متاح
          {' · '}
          السعة {displayValue(offering.capacity)}
        </p>
      )}

      <div className="mt-auto pt-3">
        {!canManage ? null : state.key === 'missing' ? (
          <div className="flex items-center gap-2">
            <input
              id={`capacity-${row.program_course_id}`}
              type="number"
              min="1"
              value={capacity}
              onChange={event => onCapacityChange(row.program_course_id, event.target.value)}
              className="w-[72px] px-2 py-2 border border-primary/20 rounded-[10px] text-[13px] text-center outline-none focus:border-primary"
              disabled={busy}
              aria-label="سعة المادة"
              title="السعة"
            />
            <button
              type="button"
              className="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-primary text-white rounded-[10px] text-[12.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
              onClick={() => onOpen(row)}
              disabled={busy || !capacity || Number(capacity) < 1}
              aria-label="إتاحة للتسجيل"
              title="إتاحة للتسجيل"
            >
              {busy ? <FaSpinner className="animate-spin text-[11px]" aria-hidden="true" /> : <FaLockOpen className="text-[11px]" aria-hidden="true" />}
              إتاحة للتسجيل
            </button>
          </div>
        ) : state.key === 'closed' ? (
          <button
            type="button"
            className="w-full flex items-center justify-center gap-1.5 px-3 py-2 bg-primary text-white rounded-[10px] text-[12.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
            onClick={() => onReopen(row)}
            disabled={busy}
            aria-label="إعادة فتح التسجيل"
            title="إعادة فتح التسجيل"
          >
            {busy ? <FaSpinner className="animate-spin text-[11px]" aria-hidden="true" /> : <FaLockOpen className="text-[11px]" aria-hidden="true" />}
            إعادة فتح التسجيل
          </button>
        ) : state.key === 'open' ? (
          <button
            type="button"
            className="w-full flex items-center justify-center gap-1.5 px-3 py-2 border border-red-300 text-red-700 bg-red-50 rounded-[10px] text-[12.5px] font-bold hover:enabled:bg-red-100 disabled:opacity-40"
            onClick={() => onClose(row)}
            disabled={busy}
            aria-label="إغلاق التسجيل"
            title="إغلاق التسجيل"
          >
            {busy ? <FaSpinner className="animate-spin text-[11px]" aria-hidden="true" /> : <FaLock className="text-[11px]" aria-hidden="true" />}
            إغلاق التسجيل
          </button>
        ) : (
          <p className="text-[12px] text-text-light font-semibold">
            لا يمكن تعديل إتاحة التسجيل لهذه الحالة.
          </p>
        )}
      </div>
    </article>
  )
}

export default function DeanRegistrationOfferings() {
  const navigate = useNavigate()
  const canManageLocal = hasPermission(PERMISSIONS.courseOfferingsManage)
    || hasPermission(PERMISSIONS.coursesManage)

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
  const [search, setSearch] = useState('')
  const [levels, setLevels] = useState([])
  const [context, setContext] = useState({ academic_year: null, semester: null })
  const [bootLoading, setBootLoading] = useState(true)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busyIds, setBusyIds] = useState({})
  const [capacities, setCapacities] = useState({})
  const [confirm, setConfirm] = useState(null)
  const savingRef = useRef(false)

  const goToLogin = useCallback(() => navigate('/login', { replace: true }), [navigate])

  const handleRequestError = useCallback((requestError, fallback) => {
    if (requestError.status === 401) {
      goToLogin()
      return fallback
    }
    if (requestError.status === 403) {
      return requestError.message || 'ليس لديك صلاحية لإدارة إتاحة هذه المادة.'
    }
    if (requestError.status === 404) {
      return 'تعذّر الوصول إلى المادة المطلوبة.'
    }
    if (requestError.status === 409) {
      return 'تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.'
    }
    if (requestError.status === 422) {
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

  const filteredLevels = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return levels
    return levels
      .map(level => ({
        ...level,
        courses: (level.courses ?? []).filter(row => {
          const code = String(row.course?.course_code ?? '').toLowerCase()
          const name = String(row.course?.course_name ?? '').toLowerCase()
          return code.includes(query) || name.includes(query)
        }),
      }))
      .filter(level => level.courses.length > 0)
  }, [levels, search])

  const visibleSummary = useMemo(() => {
    const courses = filteredLevels.flatMap(level => level.courses ?? [])
    return {
      total_courses: courses.length,
      open_count: courses.filter(row => row.offering?.status === 'open').length,
      closed_count: courses.filter(row => row.offering?.status === 'closed').length,
      missing_count: courses.filter(row => !row.offering).length,
    }
  }, [filteredLevels])

  function patchOffering(programCourseId, offering) {
    setLevels(current => current.map(level => ({
      ...level,
      courses: (level.courses ?? []).map(row => (
        row.program_course_id === programCourseId ? { ...row, offering } : row
      )),
    })))
  }

  function capacityFor(programCourseId) {
    const value = capacities[programCourseId]
    return value === undefined ? String(DEFAULT_CAPACITY) : value
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
      setNotice(response?.message || successFallback)
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
          إدارة المواد المتاحة لتسجيل الطلاب ضمن برامج الكلية
        </p>
      </div>

      <div className="bg-primary/[0.05] border border-primary/15 rounded-[14px] px-4 py-3 mb-5 text-[13px] text-text-dark">
        فتح المادة يجعلها متاحة للتسجيل للطلاب المؤهلين ضمن البرنامج المحدد.
      </div>

      {college?.college_name && (
        <p className="text-[13px] font-semibold text-primary-dark mb-4">
          {college.college_name}
        </p>
      )}

      <div className="bg-white border border-primary/12 rounded-[16px] p-4 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
          <label className="flex flex-col gap-1.5 min-w-0">
            <span className="text-[12px] font-bold text-text-dark">السنة الدراسية</span>
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
            <span className="text-[12px] font-bold text-text-dark">الفصل الدراسي</span>
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
            <span className="text-[12px] font-bold text-text-dark">البرنامج / التخصص</span>
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

      {yearId && semesterId && programId && (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            <SummaryCard label="إجمالي مواد الخطة" value={visibleSummary?.total_courses ?? 0} />
            <SummaryCard label="متاحة للتسجيل" value={visibleSummary?.open_count ?? 0} />
            <SummaryCard label="مغلقة" value={visibleSummary?.closed_count ?? 0} />
            <SummaryCard label="غير مضافة للتسجيل" value={visibleSummary?.missing_count ?? 0} />
          </div>

          <FilterBar
            search={{
              value: search,
              onChange: setSearch,
              placeholder: 'ابحث برمز المادة أو اسمها...',
            }}
            hasActiveFilters={Boolean(search)}
            onClear={() => setSearch('')}
            disabled={loading}
          />
        </>
      )}

      {notice && (
        <div className="mb-4 bg-green-500/8 border border-green-500/25 rounded-[12px] px-[18px] py-3 text-[13.5px] text-green-700 font-semibold">
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
          اختر السنة الدراسية والفصل والبرنامج لعرض مواد الخطة.
        </p>
      ) : loading ? (
        <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary-light">
          <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
          <span className="text-[13.5px] font-medium">جاري تحميل مواد الخطة…</span>
        </div>
      ) : filteredLevels.length === 0 ? (
        <p className="text-[13.5px] text-text-light bg-white border border-primary/12 rounded-[14px] px-4 py-8 text-center">
          {search ? 'لا توجد مواد مطابقة لبحثك في خطة هذا البرنامج.' : 'لا توجد مواد في خطة هذا البرنامج.'}
        </p>
      ) : (
        <div className="space-y-4">
          {filteredLevels.map(level => (
            <section key={level.academic_level_id ?? level.level_name} className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
              <div className="flex items-center justify-between px-4 py-3 bg-primary/[0.05] border-b border-primary/10">
                <h3 className="text-[14px] font-extrabold text-text-dark">{level.level_name}</h3>
                <span className="text-[11px] font-bold text-text-light bg-white px-2 py-0.5 rounded-full">
                  {(level.courses ?? []).length} مادة
                </span>
              </div>
              <div className="p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                {(level.courses ?? []).map(row => (
                  <CourseCard
                    key={row.program_course_id}
                    row={row}
                    canManage={canManageLocal}
                    busy={Boolean(busyIds[`pc-${row.program_course_id}`] || (row.offering && busyIds[`off-${row.offering.course_offering_id}`]))}
                    capacity={capacityFor(row.program_course_id)}
                    onCapacityChange={(id, value) => setCapacities(current => ({ ...current, [id]: value }))}
                    onOpen={item => setConfirm({
                      type: 'open',
                      key: `pc-${item.program_course_id}`,
                      row: item,
                    })}
                    onReopen={item => setConfirm({
                      type: 'reopen',
                      key: `off-${item.offering.course_offering_id}`,
                      row: item,
                    })}
                    onClose={item => setConfirm({
                      type: 'close',
                      key: `off-${item.offering.course_offering_id}`,
                      row: item,
                    })}
                  />
                ))}
              </div>
            </section>
          ))}
        </div>
      )}

      {confirm && (
        <DeanConfirmDialog
          title={
            confirm.type === 'open'
              ? 'تأكيد إتاحة المادة للتسجيل'
              : confirm.type === 'reopen'
                ? 'تأكيد إعادة فتح التسجيل'
                : 'تأكيد إغلاق التسجيل'
          }
          warning={
            confirm.type === 'open'
              ? 'بعد التأكيد ستصبح المادة متاحة لتسجيل الطلاب المؤهلين ضمن هذا البرنامج.'
              : confirm.type === 'reopen'
                ? 'سيتم السماح للطلاب المؤهلين بالتسجيل في المادة مجددًا.'
                : 'سيؤدي الإغلاق إلى منع تسجيل طلاب جدد في هذه المادة. لن يتم حذف تسجيلات الطلاب الحالية.'
          }
          confirmLabel={
            confirm.type === 'open'
              ? 'تأكيد الإتاحة'
              : confirm.type === 'reopen'
                ? 'تأكيد إعادة الفتح'
                : 'تأكيد الإغلاق'
          }
          confirmTone={confirm.type === 'close' ? 'danger' : 'primary'}
          busy={confirmBusy}
          onCancel={() => { if (!confirmBusy) setConfirm(null) }}
          onConfirm={() => {
            const row = confirm.row
            if (confirm.type === 'open') {
              const capacity = Number(capacityFor(row.program_course_id))
              runMutation(
                confirm.key,
                () => apiRequest('/v1/dean/registration-offerings/open', {
                  method: 'POST',
                  body: JSON.stringify({
                    program_course_id: row.program_course_id,
                    academic_year_id: Number(yearId),
                    semester_id: Number(semesterId),
                    capacity,
                  }),
                }),
                row.program_course_id,
                'تمت إتاحة المادة للتسجيل بنجاح.',
              )
              return
            }
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
            runMutation(
              confirm.key,
              () => apiRequest(`/v1/dean/registration-offerings/${row.offering.course_offering_id}/close`, {
                method: 'POST',
              }),
              row.program_course_id,
              'تم إغلاق التسجيل للمادة بنجاح.',
            )
          }}
        >
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <InfoLine label="المادة" value={[confirm.row.course?.course_code, confirm.row.course?.course_name].filter(Boolean).join(' — ')} />
            <InfoLine label="البرنامج" value={selectedProgram?.program_name} />
            <InfoLine label="السنة الدراسية" value={context.academic_year?.year_name || options.academic_years.find(year => String(year.academic_year_id) === String(yearId))?.year_name} />
            <InfoLine label="الفصل" value={context.semester?.semester_name || options.semesters.find(semester => String(semester.semester_id) === String(semesterId))?.semester_name} />
            {confirm.type === 'open' && (
              <InfoLine label="السعة" value={capacityFor(confirm.row.program_course_id)} />
            )}
            {confirm.row.offering && (
              <>
                <InfoLine label="الطلاب المسجلون" value={confirm.row.offering.registered_students_count ?? 0} />
                <InfoLine label="المقاعد المتاحة" value={confirm.row.offering.available_seats ?? 0} />
              </>
            )}
          </div>
        </DeanConfirmDialog>
      )}
    </div>
  )
}
