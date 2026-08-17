import { useEffect, useMemo, useRef, useState } from 'react'
import { FaCopy, FaSpinner, FaTimes } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import {
  academicRankLabel,
  activeComponentFacultyId,
  displayValue,
  facultySlotName,
  firstApiErrorMessage,
  initialComponentFacultyId,
  offeringStatusLabel,
  offeringTitle,
  proposedFacultyId,
  reviewStatusLabel,
  teacherChoiceLabel,
  workflowStatusLabel,
} from '../utils/teacherDisplay'

const OFFERING_PAGE_SIZE = 8
const TEACHER_PAGE_SIZE = 100

function paginatedRows(response) {
  return response?.data?.data ?? []
}

function offeringPayload(response) {
  return response?.data ?? null
}

function currentFacultyId(component) {
  return activeComponentFacultyId(component)
}

function sameId(left, right) {
  if (left == null && right == null) return true
  if (left == null || right == null) return false
  return Number(left) === Number(right)
}

function WorkflowStatus({ title, component }) {
  const workflow = component?.workflow
  if (!component?.available) return null

  return (
    <div className="bg-slate-50 border border-slate-200 rounded-[12px] px-3 py-3 space-y-1.5">
      <p className="text-[12.5px] font-bold text-text-dark">{title}</p>
      <p className="text-[12px] text-text-dark">
        المدرس المعتمد: <span className="font-bold">{facultySlotName(component.faculty_member)}</span>
      </p>
      <p className="text-[12px] text-text-dark">
        المدرس المقترح: <span className="font-bold">{facultySlotName(workflow?.proposed_faculty_member)}</span>
      </p>
      <p className="text-[12px] text-text-dark">
        موافقة النائب العلمي: <span className="font-bold">{reviewStatusLabel(workflow?.scientific_review?.status)}</span>
      </p>
      {workflow?.scientific_review?.status === 'returned' && workflow.scientific_review.reason && (
        <p className="text-[12px] text-amber-800">علمي — {workflow.scientific_review.reason}</p>
      )}
      <p className="text-[12px] text-text-dark">
        موافقة النائب الإداري: <span className="font-bold">{reviewStatusLabel(workflow?.administrative_review?.status)}</span>
      </p>
      {workflow?.administrative_review?.status === 'returned' && workflow.administrative_review.reason && (
        <p className="text-[12px] text-amber-800">إداري — {workflow.administrative_review.reason}</p>
      )}
      <p className="text-[12px] text-text-dark">
        الحالة: <span className="font-bold">{workflow ? workflowStatusLabel(workflow.status) : '—'}</span>
      </p>
    </div>
  )
}

function InfoLine({ label, value }) {
  return (
    <div className="min-w-0">
      <p className="text-[11px] text-text-light font-semibold mb-0.5">{label}</p>
      <p className="text-[13px] text-text-dark font-semibold break-words">{displayValue(value)}</p>
    </div>
  )
}

function OfferingSummary({ offering }) {
  const course = offering?.course
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-primary/[0.03] border border-primary/10 rounded-[12px] p-3">
      <InfoLine label="المادة" value={offeringTitle(offering)} />
      <InfoLine label="حالة الطرح" value={offeringStatusLabel(offering?.status)} />
      <InfoLine label="العام الدراسي" value={offering?.academic_year?.year_name} />
      <InfoLine label="الفصل" value={offering?.semester?.semester_name} />
      <InfoLine label="البرنامج" value={offering?.academic_program?.program_name} />
      <InfoLine label="القسم" value={offering?.department?.department_name} />
      <InfoLine label="ساعات نظري" value={course?.theoretical_hours} />
      <InfoLine label="ساعات عملي" value={course?.practical_hours} />
    </div>
  )
}

function TeacherSelect({
  id,
  label,
  available,
  unavailableText,
  value,
  onChange,
  options,
  disabled,
}) {
  if (!available) {
    return (
      <div>
        <p className="text-[12.5px] font-bold text-text-dark mb-1.5">{label}</p>
        <p className="text-[12.5px] text-text-light bg-slate-50 border border-slate-200 rounded-[10px] px-3 py-2">
          {unavailableText}
        </p>
      </div>
    )
  }

  return (
    <label className="block" htmlFor={id}>
      <span className="block text-[12.5px] font-bold text-text-dark mb-1.5">{label}</span>
      <select
        id={id}
        className="w-full py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none focus:border-primary"
        value={value}
        onChange={event => onChange(event.target.value)}
        disabled={disabled}
        dir="rtl"
      >
        {options.map(option => (
          <option key={String(option.value)} value={option.value}>{option.label}</option>
        ))}
      </select>
    </label>
  )
}

export default function TeacherAssignmentManagerModal({
  mode,
  profileTeacher,
  offeringId = null,
  onClose,
  onSaved,
  onUnauthorized,
}) {
  const [teachers, setTeachers] = useState([])
  const [teachersError, setTeachersError] = useState('')
  const [offerings, setOfferings] = useState([])
  const [offeringsLoading, setOfferingsLoading] = useState(mode === 'add')
  const [offeringsError, setOfferingsError] = useState('')
  const [offeringPage, setOfferingPage] = useState(1)
  const [offeringTotalPages, setOfferingTotalPages] = useState(1)
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('open')
  const [selected, setSelected] = useState(null)
  const [selectedLoading, setSelectedLoading] = useState(mode === 'manage')
  const [assignTheoretical, setAssignTheoretical] = useState(false)
  const [assignPractical, setAssignPractical] = useState(false)
  const [theoreticalId, setTheoreticalId] = useState('')
  const [practicalId, setPracticalId] = useState('')
  const [step, setStep] = useState('form')
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const savingRef = useRef(false)
  const profileId = Number(profileTeacher?.faculty_member_id)

  useEffect(() => {
    let active = true

    async function loadTeachers() {
      try {
        const first = await apiRequest(`/v1/teaching-staff/assignment-instructors?per_page=${TEACHER_PAGE_SIZE}&page=1`)
        const rows = [...paginatedRows(first)]
        const lastPage = first?.data?.meta?.last_page ?? 1
        if (lastPage > 1) {
          const rest = await Promise.all(
            Array.from({ length: lastPage - 1 }, (_, index) => (
              apiRequest(`/v1/teaching-staff/assignment-instructors?per_page=${TEACHER_PAGE_SIZE}&page=${index + 2}`)
            )),
          )
          rest.forEach(response => rows.push(...paginatedRows(response)))
        }
        if (active) setTeachers(rows)
      } catch (error) {
        if (!active) return
        if (error.status === 401) {
          onUnauthorized()
          return
        }
        setTeachersError('تعذّر تحميل قائمة المدرسين.')
      }
    }

    loadTeachers()
    return () => { active = false }
  }, [onUnauthorized])

  useEffect(() => {
    if (mode !== 'add') return undefined
    let active = true

    async function loadOfferings() {
      setOfferingsLoading(true)
      setOfferingsError('')
      const params = new URLSearchParams({
        per_page: String(OFFERING_PAGE_SIZE),
        page: String(offeringPage),
      })
      if (appliedSearch) params.set('search', appliedSearch)
      if (statusFilter) params.set('status', statusFilter)

      try {
        const response = await apiRequest(`/v1/teaching-staff/assignment-offerings?${params.toString()}`)
        if (!active) return
        setOfferings(paginatedRows(response))
        setOfferingTotalPages(Math.max(1, Number(response?.data?.meta?.last_page) || 1))
      } catch (error) {
        if (!active) return
        if (error.status === 401) {
          onUnauthorized()
          return
        }
        setOfferings([])
        setOfferingsError('تعذّر تحميل قائمة المقررات المطروحة.')
      } finally {
        if (active) setOfferingsLoading(false)
      }
    }

    loadOfferings()
    return () => { active = false }
  }, [appliedSearch, mode, offeringPage, onUnauthorized, statusFilter])

  useEffect(() => {
    if (mode !== 'manage' || offeringId == null) return undefined
    let active = true

    async function loadOffering() {
      setSelectedLoading(true)
      setSaveError('')
      try {
        const response = await apiRequest(`/v1/teaching-staff/assignment-offerings/${offeringId}`)
        if (!active) return
        const payload = offeringPayload(response)
        setSelected(payload)
        setTheoreticalId(initialComponentFacultyId(payload?.components?.theoretical) ?? '')
        setPracticalId(initialComponentFacultyId(payload?.components?.practical) ?? '')
      } catch (error) {
        if (!active) return
        if (error.status === 401) {
          onUnauthorized()
          return
        }
        setSaveError(error.status === 403
          ? 'ليس لديك صلاحية لإدارة هذا التكليف.'
          : 'تعذّر تحميل بيانات الطرح.')
      } finally {
        if (active) setSelectedLoading(false)
      }
    }

    loadOffering()
    return () => { active = false }
  }, [mode, offeringId, onUnauthorized])

  useEffect(() => {
    function onKey(event) {
      if (event.key === 'Escape' && !saving) onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose, saving])

  const offering = selected?.course_offering
  const components = selected?.components
  const theoreticalAvailable = Boolean(components?.theoretical?.available)
  const practicalAvailable = Boolean(components?.practical?.available)
  const currentTheoreticalId = currentFacultyId(components?.theoretical)
  const currentPracticalId = currentFacultyId(components?.practical)

  const teacherOptions = useMemo(() => {
    const options = [{ value: '', label: 'اختر مدرسًا' }]
    const seen = new Set()
    teachers.forEach(teacher => {
      const id = Number(teacher.faculty_member_id)
      if (!Number.isInteger(id) || id <= 0) return
      seen.add(id)
      options.push({ value: String(id), label: teacherChoiceLabel(teacher) })
    })
    return { options, seen }
  }, [teachers])

  function selectOptionsFor(role) {
    const current = role === 'theoretical' ? currentTheoreticalId : currentPracticalId
    const faculty = role === 'theoretical'
      ? components?.theoretical?.faculty_member
      : components?.practical?.faculty_member
    const options = [...teacherOptions.options]
    if (current != null && !teacherOptions.seen.has(Number(current))) {
      options.push({
        value: String(current),
        label: `${facultySlotName(faculty)} — ${academicRankLabel(faculty?.academic_rank)} (غير نشط)`,
      })
    }
    return options
  }

  function selectOffering(row) {
    setSelected(row)
    setAssignTheoretical(
      Boolean(row.components?.theoretical?.available)
      && (currentFacultyId(row.components?.theoretical) == null
        || Number(currentFacultyId(row.components?.theoretical)) === profileId),
    )
    setAssignPractical(
      Boolean(row.components?.practical?.available)
      && (currentFacultyId(row.components?.practical) == null
        || Number(currentFacultyId(row.components?.practical)) === profileId),
    )
    setStep('form')
    setSaveError('')
  }

  const desiredTheoreticalId = useMemo(() => {
    if (!theoreticalAvailable) return null
    if (mode === 'add') return assignTheoretical ? profileId : currentTheoreticalId
    return theoreticalId === '' ? null : Number(theoreticalId)
  }, [assignTheoretical, currentTheoreticalId, mode, profileId, theoreticalAvailable, theoreticalId])

  const desiredPracticalId = useMemo(() => {
    if (!practicalAvailable) return null
    if (mode === 'add') return assignPractical ? profileId : currentPracticalId
    return practicalId === '' ? null : Number(practicalId)
  }, [assignPractical, currentPracticalId, mode, practicalAvailable, practicalId, profileId])

  function slotNeedsSubmit(component, desiredId) {
    if (!component?.available || desiredId == null) return false
    const workflow = component.workflow
    const proposedId = proposedFacultyId(component)
    const effectiveId = activeComponentFacultyId(component)
    if (workflow?.status === 'returned' && sameId(desiredId, proposedId)) return true
    if (workflow && sameId(desiredId, proposedId) && workflow.status !== 'returned') return false
    if (!workflow && sameId(desiredId, effectiveId)) return false
    return !sameId(desiredId, proposedId ?? effectiveId)
  }

  const theoreticalNeedsSubmit = slotNeedsSubmit(components?.theoretical, desiredTheoreticalId)
  const practicalNeedsSubmit = slotNeedsSubmit(components?.practical, desiredPracticalId)
  const changed = selected != null && (theoreticalNeedsSubmit || practicalNeedsSubmit)
  const unchangedResubmit = (
    (theoreticalNeedsSubmit && components?.theoretical?.workflow?.status === 'returned'
      && sameId(desiredTheoreticalId, proposedFacultyId(components?.theoretical)))
    || (practicalNeedsSubmit && components?.practical?.workflow?.status === 'returned'
      && sameId(desiredPracticalId, proposedFacultyId(components?.practical)))
  )
  const materialCycle = (
    (theoreticalNeedsSubmit && components?.theoretical?.workflow
      && !sameId(desiredTheoreticalId, proposedFacultyId(components?.theoretical)))
    || (practicalNeedsSubmit && components?.practical?.workflow
      && !sameId(desiredPracticalId, proposedFacultyId(components?.practical)))
  )

  const replacingTheoretical = theoreticalAvailable
    && desiredTheoreticalId != null
    && currentTheoreticalId != null
    && Number(desiredTheoreticalId) !== Number(currentTheoreticalId)
  const replacingPractical = practicalAvailable
    && desiredPracticalId != null
    && currentPracticalId != null
    && Number(desiredPracticalId) !== Number(currentPracticalId)

  function teacherNameById(id) {
    if (id == null) return 'بدون مدرس'
    if (Number(id) === Number(components?.theoretical?.faculty_member?.faculty_member_id)) {
      return facultySlotName(components.theoretical.faculty_member)
    }
    if (Number(id) === Number(components?.practical?.faculty_member?.faculty_member_id)) {
      return facultySlotName(components.practical.faculty_member)
    }
    const teacher = teachers.find(item => Number(item.faculty_member_id) === Number(id))
    return teacher ? fullSafeName(teacher) : `مدرس #${id}`
  }

  function fullSafeName(teacher) {
    const composed = `${teacher.employee?.first_name ?? ''} ${teacher.employee?.last_name ?? ''}`.trim()
    return composed || teacherChoiceLabel(teacher)
  }

  function changeSummary() {
    const lines = []
    if (theoreticalAvailable && !sameId(desiredTheoreticalId, currentTheoreticalId)) {
      lines.push({
        role: 'النظري',
        from: teacherNameById(currentTheoreticalId),
        to: teacherNameById(desiredTheoreticalId),
      })
    }
    if (practicalAvailable && !sameId(desiredPracticalId, currentPracticalId)) {
      lines.push({
        role: 'العملي',
        from: teacherNameById(currentPracticalId),
        to: teacherNameById(desiredPracticalId),
      })
    }
    return lines
  }

  async function save() {
    if (savingRef.current || !changed || !selected) return
    savingRef.current = true
    setSaving(true)
    setSaveError('')
    try {
      await apiRequest(`/v1/teaching-staff/assignment-offerings/${selected.course_offering_id}/slots`, {
        method: 'PUT',
        body: JSON.stringify({
          theoretical_faculty_member_id: desiredTheoreticalId,
          practical_faculty_member_id: desiredPracticalId,
        }),
      })
      onSaved()
    } catch (error) {
      if (error.status === 401) {
        onUnauthorized()
        return
      }
      if (error.status === 403) {
        setSaveError('ليس لديك صلاحية لإدارة هذا التكليف.')
      } else if (error.status === 409) {
        setSaveError('تم تعديل التكليف من جلسة أخرى. أعد تحميل البيانات وحاول مجددًا.')
      } else if (error.status === 422) {
        setSaveError(firstApiErrorMessage(error, 'تعذّر حفظ التكليف بسبب بيانات غير صالحة.'))
      } else {
        setSaveError('تعذّر حفظ التكليف. لم يتم اعتماد التعديل.')
      }
      setStep('form')
    } finally {
      savingRef.current = false
      setSaving(false)
    }
  }

  const title = mode === 'add' ? 'إضافة تكليف تدريسي' : 'إدارة التكليف'
  const addNeedsSelection = mode === 'add' && selected == null
  const addNeedsComponent = mode === 'add' && selected != null && !assignTheoretical && !assignPractical

  return (
    <div
      className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45 p-0 sm:p-4"
      dir="rtl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="assignment-manager-title"
      onClick={event => {
        if (event.target === event.currentTarget && !saving) onClose()
      }}
    >
      <div className="w-full sm:max-w-[640px] max-h-[96vh] overflow-y-auto bg-white rounded-t-[18px] sm:rounded-[18px] shadow-2xl">
        <div className="flex items-center justify-between border-b border-primary/10 px-5 py-4 sticky top-0 bg-white z-10">
          <h3 id="assignment-manager-title" className="text-[16px] font-black text-text-dark">{title}</h3>
          <button
            type="button"
            className="p-2 text-text-light hover:text-text-dark disabled:opacity-40"
            onClick={onClose}
            disabled={saving}
            aria-label="إغلاق"
            title="إغلاق"
          >
            <FaTimes aria-hidden="true" />
          </button>
        </div>

        <div className="px-5 py-4 space-y-4">
          {teachersError && <p className="text-[13px] text-red-600">⚠ {teachersError}</p>}
          {saveError && <p className="text-[13px] text-red-600">⚠ {saveError}</p>}

          {mode === 'add' && addNeedsSelection && (
            <>
              <div className="flex flex-col sm:flex-row gap-2">
                <input
                  className="flex-1 py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[13px] outline-none focus:border-primary"
                  value={search}
                  onChange={event => setSearch(event.target.value)}
                  placeholder="ابحث برمز أو اسم المقرر أو البرنامج..."
                  onKeyDown={event => {
                    if (event.key === 'Enter') {
                      setAppliedSearch(search.trim())
                      setOfferingPage(1)
                    }
                  }}
                />
                <select
                  className="py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[13px]"
                  value={statusFilter}
                  onChange={event => {
                    setStatusFilter(event.target.value)
                    setOfferingPage(1)
                  }}
                >
                  <option value="open">المفتوحة</option>
                  <option value="">جميع الحالات</option>
                  <option value="closed">المغلقة</option>
                </select>
                <button
                  type="button"
                  className="px-4 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold"
                  onClick={() => {
                    setAppliedSearch(search.trim())
                    setOfferingPage(1)
                  }}
                >
                  بحث
                </button>
              </div>

              {offeringsError && <p className="text-[13px] text-red-600">⚠ {offeringsError}</p>}
              {offeringsLoading ? (
                <div className="flex items-center justify-center gap-2 py-8 text-primary-light">
                  <FaSpinner className="animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
                  <span className="text-[13px]">جاري تحميل المقررات…</span>
                </div>
              ) : offerings.length === 0 ? (
                <p className="text-[13px] text-text-gray text-center py-8">لا توجد مقررات مطروحة مطابقة.</p>
              ) : (
                <ul className="space-y-2">
                  {offerings.map(row => (
                    <li key={row.course_offering_id}>
                      <button
                        type="button"
                        className="w-full text-right bg-white border border-primary/12 hover:border-primary/40 rounded-[12px] px-3 py-3"
                        onClick={() => selectOffering(row)}
                      >
                        <p className="text-[13.5px] font-bold text-text-dark break-words">{offeringTitle(row.course_offering)}</p>
                        <p className="text-[12px] text-text-gray mt-1">
                          {[
                            row.course_offering?.academic_year?.year_name,
                            row.course_offering?.semester?.semester_name,
                            row.course_offering?.academic_program?.program_name,
                            offeringStatusLabel(row.course_offering?.status),
                          ].filter(Boolean).join(' • ')}
                        </p>
                      </button>
                    </li>
                  ))}
                </ul>
              )}

              {offeringTotalPages > 1 && (
                <div className="flex items-center justify-center gap-3 text-[13px]">
                  <button
                    type="button"
                    className="px-3 py-1.5 border rounded-[8px] disabled:opacity-40"
                    disabled={offeringPage <= 1 || offeringsLoading}
                    onClick={() => setOfferingPage(page => page - 1)}
                  >
                    السابق
                  </button>
                  <span>{offeringPage} / {offeringTotalPages}</span>
                  <button
                    type="button"
                    className="px-3 py-1.5 border rounded-[8px] disabled:opacity-40"
                    disabled={offeringPage >= offeringTotalPages || offeringsLoading}
                    onClick={() => setOfferingPage(page => page + 1)}
                  >
                    التالي
                  </button>
                </div>
              )}
            </>
          )}

          {selectedLoading && (
            <div className="flex items-center justify-center gap-2 py-8 text-primary-light">
              <FaSpinner className="animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
              <span className="text-[13px]">جاري تحميل الطرح…</span>
            </div>
          )}

          {selected && !selectedLoading && step === 'form' && (
            <>
              {mode === 'add' && (
                <button
                  type="button"
                  className="text-[12.5px] text-primary font-semibold"
                  onClick={() => {
                    setSelected(null)
                    setSaveError('')
                  }}
                  disabled={saving}
                >
                  تغيير المقرر
                </button>
              )}
              <OfferingSummary offering={offering} />
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {theoreticalAvailable && <WorkflowStatus title="النظري" component={components?.theoretical} />}
                {practicalAvailable && <WorkflowStatus title="العملي" component={components?.practical} />}
              </div>
              {offering?.status && offering.status !== 'open' && (
                <p className="text-[12.5px] text-amber-700 bg-amber-50 border border-amber-200 rounded-[10px] px-3 py-2">
                  حالة هذا الطرح: {offeringStatusLabel(offering.status)}. راجع الحالة قبل الحفظ.
                </p>
              )}

              {mode === 'add' ? (
                <div className="space-y-3">
                  <p className="text-[13px] font-bold text-text-dark">إسناد لهذا المدرس</p>
                  {theoreticalAvailable ? (
                    <label className="flex items-start gap-2 text-[13px] text-text-dark">
                      <input
                        type="checkbox"
                        className="mt-1"
                        checked={assignTheoretical}
                        onChange={event => setAssignTheoretical(event.target.checked)}
                        disabled={saving}
                      />
                      <span>
                        <span className="font-bold">إسناد النظري لهذا المدرس</span>
                        {currentTheoreticalId != null && Number(currentTheoreticalId) !== profileId && (
                          <span className="block text-[12px] text-amber-700 mt-1">
                            النظري حاليًا: {facultySlotName(components.theoretical.faculty_member)}
                          </span>
                        )}
                      </span>
                    </label>
                  ) : (
                    <p className="text-[12.5px] text-text-light">لا يحتوي المقرر على شق نظري</p>
                  )}
                  {practicalAvailable ? (
                    <label className="flex items-start gap-2 text-[13px] text-text-dark">
                      <input
                        type="checkbox"
                        className="mt-1"
                        checked={assignPractical}
                        onChange={event => setAssignPractical(event.target.checked)}
                        disabled={saving}
                      />
                      <span>
                        <span className="font-bold">إسناد العملي لهذا المدرس</span>
                        {currentPracticalId != null && Number(currentPracticalId) !== profileId && (
                          <span className="block text-[12px] text-amber-700 mt-1">
                            العملي حاليًا: {facultySlotName(components.practical.faculty_member)}
                          </span>
                        )}
                      </span>
                    </label>
                  ) : (
                    <p className="text-[12.5px] text-text-light">لا يحتوي المقرر على شق عملي</p>
                  )}
                </div>
              ) : (
                <div className="space-y-4">
                  <TeacherSelect
                    id="theoretical-teacher"
                    label="النظري"
                    available={theoreticalAvailable}
                    unavailableText="لا يحتوي المقرر على شق نظري"
                    value={theoreticalId === '' || theoreticalId == null ? '' : String(theoreticalId)}
                    onChange={setTheoreticalId}
                    options={selectOptionsFor('theoretical')}
                    disabled={saving}
                  />
                  <TeacherSelect
                    id="practical-teacher"
                    label="العملي"
                    available={practicalAvailable}
                    unavailableText="لا يحتوي المقرر على شق عملي"
                    value={practicalId === '' || practicalId == null ? '' : String(practicalId)}
                    onChange={setPracticalId}
                    options={selectOptionsFor('practical')}
                    disabled={saving}
                  />
                  {theoreticalAvailable && practicalAvailable && theoreticalId && (
                    <button
                      type="button"
                      className="flex items-center gap-2 text-[12.5px] text-primary font-semibold"
                      onClick={() => setPracticalId(theoreticalId)}
                      disabled={saving}
                    >
                      <FaCopy aria-hidden="true" />
                      استخدام نفس مدرس النظري للعملي
                    </button>
                  )}
                </div>
              )}
            </>
          )}

          {selected && !selectedLoading && step === 'confirm' && (
            <div className="space-y-3">
              <p className="text-[14px] font-black text-text-dark">سيتم إرسال طلب التكليف للمراجعة:</p>
              {changeSummary().map(line => (
                <p key={line.role} className="text-[13px] text-text-dark">
                  <span className="font-bold">{line.role}:</span> {line.from} → {line.to}
                </p>
              ))}
              <p className="text-[12.5px] text-text-dark bg-primary/[0.04] border border-primary/15 rounded-[10px] px-3 py-2">
                لا يصبح المدرس المقترح نافذًا إلا بعد موافقة النائب العلمي والنائب الإداري معًا.
              </p>
              {unchangedResubmit && (
                <p className="text-[12.5px] text-text-dark bg-slate-50 border border-slate-200 rounded-[10px] px-3 py-2">
                  إعادة الإرسال بنفس المدرس تحفظ موافقة النائب الذي سبق أن وافق، وتُعيد فقط المراجعة المعادة إلى الانتظار.
                </p>
              )}
              {materialCycle && (
                <p className="text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-3 py-2">
                  تغيير المدرس يبدأ دورة موافقة جديدة ولا تُنقل الموافقات السابقة.
                </p>
              )}
              {(replacingTheoretical || replacingPractical) && (
                <p className="text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-3 py-2">
                  المدرس المعتمد الحالي يبقى نافذًا إلى أن يوافق النائبان على البديل.
                </p>
              )}
            </div>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-primary/10 px-5 py-4 sticky bottom-0 bg-white">
          <button
            type="button"
            className="px-4 py-2 rounded-[10px] text-[13px] font-semibold text-text-gray disabled:opacity-40"
            onClick={step === 'confirm' ? () => setStep('form') : onClose}
            disabled={saving}
          >
            إلغاء
          </button>
          {step === 'form' ? (
            <button
              type="button"
              className="px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40"
              disabled={saving || !changed || addNeedsSelection || addNeedsComponent || selectedLoading}
              onClick={() => {
                if (!changed) return
                setStep('confirm')
              }}
            >
              مراجعة الإرسال
            </button>
          ) : (
            <button
              type="button"
              className="px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40 flex items-center gap-2"
              disabled={saving || !changed}
              onClick={save}
            >
              {saving && <FaSpinner className="animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />}
              {unchangedResubmit && !materialCycle ? 'إعادة الإرسال' : 'إرسال للمراجعة'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
