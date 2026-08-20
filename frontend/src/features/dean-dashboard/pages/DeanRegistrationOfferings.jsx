import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaSpinner, FaTimes } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import DeanConfirmDialog from '../components/DeanConfirmDialog'
import { firstApiErrorMessage, displayValue } from '../utils/teacherDisplay'

function uniqueProgramCourseIds(ids) {
  const seen = new Set()
  const result = []
  ;(ids ?? []).forEach(raw => {
    const id = Number(raw)
    if (!Number.isFinite(id) || id < 1 || seen.has(id)) return
    seen.add(id)
    result.push(id)
  })
  return result
}

function flattenCatalogCourses(levels) {
  return (levels ?? []).flatMap(level => (level.courses ?? []).map(row => ({
    ...row,
    academic_level_id: row.academic_level_id ?? level.academic_level_id,
    academic_level_name: row.academic_level_name ?? level.level_name,
  })))
}

function recommendedSemesterMatches(row, selectedSemesterId) {
  const recommendedId = row?.advisory_plan?.recommended_semester_id
  if (recommendedId == null || recommendedId === '') return false
  return Number(recommendedId) === Number(selectedSemesterId)
}

function advisoryPlanDraftIds(levels, selectedSemesterId) {
  return uniqueProgramCourseIds(
    flattenCatalogCourses(levels)
      .filter(row => recommendedSemesterMatches(row, selectedSemesterId))
      .map(row => row.program_course_id),
  )
}

function fillAdvisoryPlanDraft(currentIds, levels, selectedSemesterId) {
  return uniqueProgramCourseIds([
    ...(currentIds ?? []),
    ...advisoryPlanDraftIds(levels, selectedSemesterId),
  ])
}

function advisorySemesterLabel(row) {
  const recommendedId = row?.advisory_plan?.recommended_semester_id
  if (recommendedId == null || recommendedId === '') return 'الفصل الإرشادي غير محدد'
  const name = row?.advisory_plan?.recommended_semester_name
  return name ? `إرشاديًا: ${name}` : 'إرشاديًا: فصل آخر'
}

function plannerRowsForLevel(level, draftIds) {
  const draftSet = new Set((draftIds ?? []).map(Number))
  return (level?.courses ?? []).filter(row => (
    Boolean(row.offering) || draftSet.has(Number(row.program_course_id))
  ))
}

function savePreview(catalogCourses, draftIds) {
  const byId = new Map(catalogCourses.map(row => [Number(row.program_course_id), row]))
  const selected = uniqueProgramCourseIds(draftIds)
    .map(id => byId.get(id))
    .filter(Boolean)
  return {
    total: selected.length,
    existing: selected.filter(row => Boolean(row.offering)).length,
    creating: selected.filter(row => !row.offering).length,
    programCourseIds: selected.map(row => Number(row.program_course_id)),
  }
}

function InfoLine({ label, value }) {
  return (
    <div className="min-w-0">
      <p className="text-[11px] text-text-light font-semibold mb-0.5">{label}</p>
      <p className="text-[13.5px] text-text-dark font-semibold break-words">{displayValue(value)}</p>
    </div>
  )
}

function PlannerRow({ row, onRemove, onManage }) {
  const persisted = Boolean(row.offering)
  return (
    <div className="flex items-center gap-3 py-2.5 border-b border-primary/8 last:border-b-0">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <span className="font-mono text-[13px] font-black text-primary-dark">{displayValue(row.course?.course_code)}</span>
          <span className="text-[13.5px] font-bold text-text-dark">{displayValue(row.course?.course_name)}</span>
          <span className="text-[12px] text-text-light">{displayValue(row.course?.credit_hours)} ساعات</span>
        </div>
        <p className="text-[11.5px] text-text-light mt-0.5">{advisorySemesterLabel(row)}</p>
      </div>
      <span className={`shrink-0 text-[10.5px] font-bold px-2 py-0.5 rounded-full border ${
        persisted
          ? 'bg-slate-500/10 text-slate-600 border-slate-500/20'
          : 'bg-amber-500/10 text-amber-800 border-amber-500/20'
      }`}
      >
        {persisted ? 'محفوظ مسبقًا' : 'غير محفوظ'}
      </span>
      {persisted ? (
        <button
          type="button"
          className="shrink-0 text-[12px] font-bold text-primary-dark hover:underline"
          onClick={() => onManage(row.offering.course_offering_id)}
        >
          إدارة الطرح
        </button>
      ) : (
        <button
          type="button"
          className="shrink-0 text-[12px] font-semibold text-text-light hover:text-text-dark"
          onClick={() => onRemove(row.program_course_id)}
          aria-label="إزالة من التجهيز"
          title="إزالة من التجهيز"
        >
          × إزالة من التجهيز
        </button>
      )}
    </div>
  )
}

function AddCourseDialog({
  levelName,
  courses,
  query,
  onQueryChange,
  onAdd,
  onClose,
}) {
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (!needle) return courses
    return courses.filter(row => {
      const code = String(row.course?.course_code ?? '').toLowerCase()
      const name = String(row.course?.course_name ?? '').toLowerCase()
      return code.includes(needle) || name.includes(needle)
    })
  }, [courses, query])

  return (
    <div
      className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45 p-0 sm:p-4"
      dir="rtl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="add-course-title"
      onClick={event => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div className="w-full sm:max-w-[520px] max-h-[96vh] overflow-y-auto bg-white rounded-t-[18px] sm:rounded-[18px] shadow-2xl">
        <div className="flex items-center justify-between border-b border-primary/10 px-5 py-4 sticky top-0 bg-white z-10">
          <h3 id="add-course-title" className="text-[16px] font-black text-text-dark">
            إضافة مادة — {levelName}
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
            type="search"
            value={query}
            onChange={event => onQueryChange(event.target.value)}
            placeholder="ابحث باسم المادة أو رمزها"
            className="w-full px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
          />
          {filtered.length === 0 ? (
            <p className="text-[13px] text-text-light py-6 text-center">لا توجد مواد مطابقة.</p>
          ) : (
            <ul className="divide-y divide-primary/8">
              {filtered.map(row => {
                const persisted = Boolean(row.offering)
                const added = Boolean(row.alreadyInDraft)
                const blocked = persisted || added
                return (
                  <li key={row.program_course_id}>
                    <button
                      type="button"
                      className="w-full text-right py-3 flex items-start gap-3 disabled:opacity-55"
                      disabled={blocked}
                      onClick={() => onAdd(row.program_course_id)}
                    >
                      <span className="mt-1 h-3.5 w-3.5 rounded-full border border-primary/40 shrink-0" aria-hidden="true" />
                      <span className="min-w-0">
                        <span className="block font-mono text-[13px] font-black text-primary-dark">
                          {displayValue(row.course?.course_code)} — {displayValue(row.course?.course_name)}
                        </span>
                        <span className="block text-[12px] text-text-light mt-0.5">{advisorySemesterLabel(row)}</span>
                        {persisted ? (
                          <span className="block text-[11.5px] font-bold text-slate-600 mt-0.5">موجودة مسبقًا</span>
                        ) : added ? (
                          <span className="block text-[11.5px] font-bold text-text-light mt-0.5">مضافة</span>
                        ) : null}
                      </span>
                    </button>
                  </li>
                )
              })}
            </ul>
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
  const [context, setContext] = useState({ academic_year: null, semester: null })
  const [draftIds, setDraftIds] = useState([])
  const [bootLoading, setBootLoading] = useState(true)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [confirm, setConfirm] = useState(null)
  const [picker, setPicker] = useState(null)
  const [pickerQuery, setPickerQuery] = useState('')
  const savingRef = useRef(false)

  const goToLogin = useCallback(() => navigate('/login', { replace: true }), [navigate])

  const handleRequestError = useCallback((requestError, fallback) => {
    if (requestError.status === 401) {
      goToLogin()
      return fallback
    }
    if (requestError.status === 403) {
      return requestError.message || 'ليس لديك صلاحية لتجهيز هذا الفصل.'
    }
    if (requestError.status === 404) {
      return 'تعذّر الوصول إلى البرنامج المطلوب.'
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
            : 'تعذّر تحميل بيانات تجهيز الفصل.',
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

  useEffect(() => {
    setDraftIds([])
    setPicker(null)
    setPickerQuery('')
    setConfirm(null)
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

  const catalogCourses = useMemo(() => flattenCatalogCourses(levels), [levels])
  const draftSet = useMemo(() => new Set(draftIds.map(Number)), [draftIds])
  const preview = useMemo(() => savePreview(catalogCourses, draftIds), [catalogCourses, draftIds])

  const yearName = context.academic_year?.year_name
    || options.academic_years.find(year => String(year.academic_year_id) === String(yearId))?.year_name
  const semesterName = context.semester?.semester_name
    || options.semesters.find(semester => String(semester.semester_id) === String(semesterId))?.semester_name

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
  }

  function applyAdvisoryPlan() {
    setDraftIds(current => fillAdvisoryPlanDraft(current, levels, semesterId))
    setNotice('تمت إضافة الخطة الإرشادية إلى التجهيز.')
    setError('')
  }

  function removeFromDraft(programCourseId) {
    setDraftIds(current => current.filter(id => Number(id) !== Number(programCourseId)))
  }

  function addCourseToDraft(programCourseId) {
    setDraftIds(current => uniqueProgramCourseIds([...current, programCourseId]))
    setPicker(null)
    setPickerQuery('')
  }

  async function saveDraft() {
    if (savingRef.current || preview.programCourseIds.length < 1) return
    savingRef.current = true
    setSaving(true)
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
      const created = result.created_count ?? 0
      const existing = result.existing_count ?? 0
      setNotice(`تم حفظ تجهيز الفصل بنجاح. تم إنشاء ${created} طروحات، و${existing} كانت موجودة مسبقًا.`)
      setConfirm(null)
      const failedIds = (result.items ?? [])
        .filter(item => item.result === 'failed')
        .map(item => item.program_course_id)
      setDraftIds(uniqueProgramCourseIds(failedIds))
      await reloadCatalog()
    } catch (requestError) {
      setError(handleRequestError(requestError, 'تعذّر حفظ تجهيز الفصل.'))
    } finally {
      savingRef.current = false
      setSaving(false)
    }
  }

  const pickerCourses = useMemo(() => {
    if (!picker) return []
    return (picker.courses ?? []).map(row => ({
      ...row,
      alreadyInDraft: draftSet.has(Number(row.program_course_id)),
    }))
  }, [draftSet, picker])

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
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">تجهيز الفصل</h2>
        <p className="text-[12.5px] text-text-light">
          اختر السنة والفصل والبرنامج، ثم أضف الخطة الإرشادية أو المواد يدويًا واحفظ التجهيز.
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

      {yearId && semesterId && programId && canManageLocal && (
        <div className="flex flex-wrap items-center gap-2 mb-5">
          <button
            type="button"
            className="px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] font-bold text-text-dark hover:bg-primary/5 disabled:opacity-40"
            disabled={loading || saving || draftIds.length === 0}
            onClick={() => setConfirm({ type: 'clear-draft' })}
          >
            تفريغ
          </button>
          <button
            type="button"
            className="px-4 py-2 bg-primary/15 text-primary-dark rounded-[10px] text-[13px] font-bold hover:enabled:bg-primary/22 disabled:opacity-40"
            disabled={loading || saving}
            onClick={applyAdvisoryPlan}
          >
            إضافة الخطة الإرشادية
          </button>
          <button
            type="button"
            className="px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
            disabled={loading || saving || preview.programCourseIds.length < 1}
            onClick={() => setConfirm({ type: 'save-draft' })}
          >
            {saving ? 'جاري الحفظ' : 'حفظ التجهيز'}
          </button>
        </div>
      )}

      {yearId && semesterId && programId && (
        <p className="text-[13px] text-text-light mb-4">
          ابدأ بإضافة الخطة الإرشادية أو أضف المواد يدويًا.
        </p>
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
          اختر السنة الأكاديمية والفصل الفعلي والبرنامج لبدء تجهيز الفصل.
        </p>
      ) : loading ? (
        <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary-light">
          <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
          <span className="text-[13.5px] font-medium">جاري تحميل الخطة…</span>
        </div>
      ) : (
        <div className="space-y-8">
          {levels.map(level => {
            const rows = plannerRowsForLevel(level, draftIds)
            return (
              <section key={level.academic_level_id ?? level.level_name}>
                <h3 className="text-[15px] font-extrabold text-text-dark pb-2 mb-1 border-b border-primary/15">
                  {level.level_name}
                </h3>
                {rows.length === 0 ? (
                  <p className="text-[13px] text-text-light py-4">لا توجد مواد في التجهيز</p>
                ) : (
                  <div>
                    {rows.map(row => (
                      <PlannerRow
                        key={row.program_course_id}
                        row={row}
                        onRemove={removeFromDraft}
                        onManage={id => navigate(`/dean/courses/${id}`)}
                      />
                    ))}
                  </div>
                )}
                {canManageLocal && (
                  <button
                    type="button"
                    className="mt-3 inline-flex items-center gap-1.5 text-[13px] font-bold text-primary-dark hover:underline disabled:opacity-40"
                    disabled={saving}
                    onClick={() => {
                      setPickerQuery('')
                      setPicker({
                        levelName: level.level_name,
                        academicLevelId: level.academic_level_id,
                        courses: level.courses ?? [],
                      })
                    }}
                  >
                    + إضافة مادة
                  </button>
                )}
              </section>
            )
          })}
        </div>
      )}

      {picker && (
        <AddCourseDialog
          levelName={picker.levelName}
          courses={pickerCourses}
          query={pickerQuery}
          onQueryChange={setPickerQuery}
          onAdd={addCourseToDraft}
          onClose={() => { setPicker(null); setPickerQuery('') }}
        />
      )}

      {confirm && (
        <DeanConfirmDialog
          title={confirm.type === 'clear-draft' ? 'تفريغ التجهيز غير المحفوظ' : 'حفظ التجهيز'}
          warning={
            confirm.type === 'clear-draft'
              ? 'سيتم تفريغ التجهيز غير المحفوظ فقط. لن يتم حذف أي طروحات أو بيانات أكاديمية محفوظة.'
              : 'سيتم إنشاء الطروحات المفقودة فقط وبحالة مغلقة. لن يُفتح التسجيل ولن يُعيَّن مدرسون.'
          }
          confirmLabel={confirm.type === 'clear-draft' ? 'تأكيد التفريغ' : 'تأكيد الحفظ'}
          busy={confirm.type === 'save-draft' ? saving : false}
          disabled={confirm.type === 'save-draft' && preview.programCourseIds.length < 1}
          onCancel={() => { if (!saving) setConfirm(null) }}
          onConfirm={() => {
            if (confirm.type === 'clear-draft') {
              setDraftIds([])
              setConfirm(null)
              setNotice('تم تفريغ التجهيز غير المحفوظ.')
              return
            }
            saveDraft()
          }}
        >
          {confirm.type === 'save-draft' ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <InfoLine label="البرنامج" value={selectedProgram?.program_name} />
              <InfoLine label="السنة الأكاديمية" value={yearName} />
              <InfoLine label="الفصل الفعلي" value={semesterName} />
              <InfoLine label="إجمالي المواد في التجهيز" value={preview.total} />
              <InfoLine label="موجودة مسبقًا" value={preview.existing} />
              <InfoLine label="سيتم إنشاء" value={preview.creating} />
            </div>
          ) : (
            <p className="text-[13px] text-text-dark leading-7">
              الطروحات المحفوظة مسبقًا ستبقى ظاهرة في التجهيز ولن تُحذف.
            </p>
          )}
        </DeanConfirmDialog>
      )}
    </div>
  )
}
