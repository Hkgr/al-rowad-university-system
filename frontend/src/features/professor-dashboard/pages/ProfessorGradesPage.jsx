import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  FaBook, FaCheck, FaChalkboardTeacher, FaCheckCircle, FaChevronDown, FaClipboardList,
  FaExclamationTriangle, FaFlask, FaLock, FaPaperPlane, FaSave, FaSpinner, FaUserGraduate,
} from 'react-icons/fa'
import useMyOfferings from '../hooks/useMyOfferings'
import { getGradePartsWorkflow, saveRegistrationGradePart, submitMyGradeParts } from '../lib/professorApi'
import CourseRequirementBadges, { pickRequirementClassification } from '../../../components/academic/CourseRequirementBadges'

const PARTS = {
  theoretical: { label: 'النظري', full: 'العلامة النظرية', other: 'practical' },
  practical: { label: 'العملي', full: 'العلامة العملية', other: 'theoretical' },
}
const WORKFLOW_STEPS = [
  { key: 'entry', label: 'إدخال العلامات' },
  { key: 'ready', label: 'جاهز للإرسال' },
  { key: 'submitted', label: 'مرسل للامتحانات' },
  { key: 'approved', label: 'معتمد' },
]
const ERROR_MESSAGES = {
  invalid_grade_part: 'بيانات جزء العلامة غير صالحة.',
  grade_part_not_required: 'هذا الجزء غير مطلوب لهذه المادة.',
  grade_part_locked: 'هذا الجزء مقفل ولم يعد قابلاً للتعديل.',
  grade_part_already_approved: 'هذا الجزء معتمد بالفعل.',
  grade_part_already_submitted: 'هذا الجزء مرسل بالفعل إلى هيئة الامتحانات.',
  grade_part_incomplete: 'علامات هذا الجزء غير مكتملة.',
  grade_parts_must_be_submitted_together: 'يجب إرسال الجزأين النظري والعملي معًا في إجراء واحد.',
  deprived_student_grade_locked: 'لا يمكن تعديل علامة الطالب المحروم.',
  grade_entry_not_allowed: 'إدخال العلامة غير مسموح لهذا التسجيل.',
  not_primary_instructor: 'إدخال العلامات متاح حسب التكليف التدريسي فقط.',
  unauthorized_grade_part: 'غير مصرح لك بالوصول إلى هذا الجزء.',
  grade_part_workflow_required: 'يجب استخدام مسار أجزاء العلامة لإدخال العلامات أو إرسالها.',
}
const CONFLICT_CODES = new Set([
  'grade_part_locked', 'grade_part_already_approved', 'grade_part_already_submitted',
  'grade_part_not_required', 'unauthorized_grade_part', 'grade_parts_must_be_submitted_together',
])
const HTTP_MESSAGES = {
  401: 'انتهت جلسة الدخول. يرجى تسجيل الدخول من جديد.',
  403: 'لا تملك صلاحية إدارة علامات هذه الشعبة.',
  404: 'لم يعد هذا السجل متاحًا. يرجى إعادة تحميل الحالة.',
  409: 'تغيّرت حالة الجزء على الخادم. راجع الحالة المحدّثة قبل المتابعة.',
  422: 'تحقق من القيم المدخلة والحدود العليا للمكونات.',
}
const apiMessage = error => ERROR_MESSAGES[error?.errorCode] || HTTP_MESSAGES[error?.status] || error?.message || 'تعذّر الاتصال بالخادم'
const studentName = student => [student?.first_name, student?.last_name].filter(Boolean).join(' ') || '—'
const editKey = (registrationId, componentId) => `${registrationId}:${componentId}`

function assignmentCopy(mode) {
  if (mode === 'both') return { text: 'تكليفك: النظري والعملي', Icon: FaClipboardList }
  if (mode === 'practical_only') return { text: 'تكليفك: العملي', Icon: FaFlask }
  return { text: 'تكليفك: النظري', Icon: FaChalkboardTeacher }
}

function stepForPart(state) {
  if (!state?.required) return null
  if (state.status === 'approved') return 'approved'
  if (state.status === 'submitted') return 'submitted'
  if (state.status === 'returned') return 'returned'
  if (state.can_submit) return 'ready'
  return 'entry'
}

function buildEdits(data) {
  const result = {}
  for (const row of data?.students ?? []) {
    for (const part of Object.keys(PARTS)) {
      if (data?.parts?.[part]?.assigned_to_me !== true) continue
      for (const component of data?.components?.[part] ?? []) {
        const saved = (row.marks?.[part] ?? []).find(mark => Number(mark.grade_component_id) === Number(component.grade_component_id))
        result[editKey(row.registration_id, component.grade_component_id)] = { value: saved?.mark ?? '', dirty: false }
      }
    }
  }
  return result
}

export default function ProfessorGradesPage() {
  const { facultyMember, offerings, loading: offeringsLoading, error: offeringsError } = useMyOfferings()
  const [selectedId, setSelectedId] = useState(null)
  const [selectedPart, setSelectedPart] = useState(null)
  const [workflow, setWorkflow] = useState(null)
  const [edits, setEdits] = useState({})
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [saving, setSaving] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [notice, setNotice] = useState(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [refreshRequired, setRefreshRequired] = useState(false)
  const selectedIdRef = useRef(null)
  const selectedPartRef = useRef(null)
  const requestSequence = useRef(0)

  const assignedParts = useMemo(
    () => (workflow?.assigned_parts ?? []).filter(part => PARTS[part] && workflow?.parts?.[part]?.required !== false),
    [workflow],
  )
  const requiredAssignedParts = useMemo(
    () => assignedParts.filter(part => workflow?.parts?.[part]?.required === true),
    [assignedParts, workflow],
  )
  const rows = workflow?.students ?? []
  const partState = selectedPart ? workflow?.parts?.[selectedPart] : null
  const selectedOffering = useMemo(() => offerings.find(item => Number(item.course_offering_id) === Number(selectedId)), [offerings, selectedId])
  const componentDefinitions = useMemo(() => workflow?.components?.[selectedPart] ?? [], [workflow, selectedPart])
  const dirtyKeys = useMemo(() => Object.keys(edits).filter(key => edits[key]?.dirty), [edits])
  const selectedDirtyKeys = useMemo(() => {
    if (!selectedPart) return []
    const ids = new Set(rows.flatMap(row => componentDefinitions.map(component => editKey(row.registration_id, component.grade_component_id))))
    return dirtyKeys.filter(key => ids.has(key))
  }, [componentDefinitions, dirtyKeys, rows, selectedPart])
  const official = workflow?.finalization?.official_result_available === true
  const ownsBoth = requiredAssignedParts.length === 2
  const actionableAssignedParts = useMemo(
    () => requiredAssignedParts.filter(part => ['draft', 'returned'].includes(workflow?.parts?.[part]?.status || 'draft')),
    [requiredAssignedParts, workflow],
  )
  const unchangedAssignedParts = useMemo(
    () => requiredAssignedParts.filter(part => ['submitted', 'approved'].includes(workflow?.parts?.[part]?.status)),
    [requiredAssignedParts, workflow],
  )
  const busy = saving || submitting || loading

  const loadWorkflow = useCallback(async (offeringId, { preserveDirty = false } = {}) => {
    const sequence = ++requestSequence.current
    setLoading(true); setLoadError('')
    try {
      const next = await getGradePartsWorkflow(offeringId)
      if (sequence !== requestSequence.current || Number(selectedIdRef.current) !== Number(offeringId)) return false
      setWorkflow(next)
      setRefreshRequired(false)
      const nextParts = (next.assigned_parts ?? []).filter(part => PARTS[part] && next.parts?.[part]?.required !== false)
      setSelectedPart(current => nextParts.includes(current) ? current : (nextParts[0] ?? null))
      setEdits(current => {
        const fresh = buildEdits(next)
        if (!preserveDirty) return fresh
        for (const [key, edit] of Object.entries(current)) if (edit.dirty && Object.hasOwn(fresh, key)) fresh[key] = edit
        return fresh
      })
      return true
    } catch (error) {
      if (sequence !== requestSequence.current || Number(selectedIdRef.current) !== Number(offeringId)) return false
      setLoadError(apiMessage(error))
      return false
    } finally {
      if (sequence === requestSequence.current && Number(selectedIdRef.current) === Number(offeringId)) setLoading(false)
    }
  }, [])

  useEffect(() => { selectedPartRef.current = selectedPart }, [selectedPart])
  useEffect(() => {
    selectedIdRef.current = selectedId
    if (!selectedId) { requestSequence.current += 1; setWorkflow(null); setEdits({}); setSelectedPart(null); setLoading(false); return }
    loadWorkflow(selectedId)
  }, [selectedId, loadWorkflow])

  function discardConfirmed() { return dirtyKeys.length === 0 || window.confirm('توجد تعديلات غير محفوظة. هل تريد تجاهلها والمتابعة؟') }
  function selectOffering(offeringId) {
    if (saving || submitting || !discardConfirmed()) return
    requestSequence.current += 1; selectedIdRef.current = offeringId
    setWorkflow(null); setEdits({}); setSelectedPart(null); setNotice(null); setLoadError(''); setSelectedId(offeringId)
  }
  function selectPart(part) {
    if (part === selectedPart || saving || submitting || !discardConfirmed()) return
    setEdits(buildEdits(workflow)); setNotice(null); setSelectedPart(part)
  }
  function updateMark(registrationId, componentId, value) {
    const key = editKey(registrationId, componentId)
    setEdits(current => ({ ...current, [key]: { value, dirty: true } })); setNotice(null)
  }
  function rowValidation(row) {
    return componentDefinitions.map(component => {
      const value = edits[editKey(row.registration_id, component.grade_component_id)]?.value ?? ''
      return value === '' || (Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= Number(component.max_mark)) ? '' : `يجب أن تكون بين 0 و${component.max_mark}`
    })
  }
  function rowComplete(row, part = selectedPart) {
    if (row.is_deprived) return true
    const definitions = workflow?.components?.[part] ?? []
    return definitions.length > 0 && definitions.every(component => {
      const value = edits[editKey(row.registration_id, component.grade_component_id)]?.value
      const fallback = (row.marks?.[part] ?? []).find(mark => Number(mark.grade_component_id) === Number(component.grade_component_id))?.mark
      const current = value === undefined ? fallback : value
      return current !== '' && current !== null && current !== undefined && Number.isFinite(Number(current))
    })
  }

  async function handleSave() {
    const offeringId = selectedId; const part = selectedPart
    const dirtyRows = rows.filter(row => !row.is_deprived && componentDefinitions.some(component => edits[editKey(row.registration_id, component.grade_component_id)]?.dirty))
    const validRows = dirtyRows.filter(row => rowValidation(row).every(error => !error))
    const invalidCount = dirtyRows.length - validRows.length
    setSaving(true); setNotice(null)
    const results = await Promise.all(validRows.map(async row => {
      const components = componentDefinitions.map(component => ({
        grade_component_id: component.grade_component_id,
        mark: edits[editKey(row.registration_id, component.grade_component_id)]?.value === '' ? null : Number(edits[editKey(row.registration_id, component.grade_component_id)]?.value),
      }))
      try { await saveRegistrationGradePart(row.registration_id, part, { components }); return { row, ok: true } }
      catch (error) { return { row, ok: false, error } }
    }))
    if (Number(selectedIdRef.current) !== Number(offeringId) || selectedPartRef.current !== part) { setSaving(false); return }
    const successful = results.filter(result => result.ok)
    setEdits(current => {
      const next = { ...current }
      for (const { row } of successful) for (const component of componentDefinitions) next[editKey(row.registration_id, component.grade_component_id)] = { ...next[editKey(row.registration_id, component.grade_component_id)], dirty: false }
      return next
    })
    const failed = results.filter(result => !result.ok)
    await loadWorkflow(offeringId, { preserveDirty: true })
    setSaving(false)
    if (Number(selectedIdRef.current) !== Number(offeringId)) return
    const unsaved = invalidCount + failed.length
    setNotice({ type: unsaved ? 'error' : 'success', text: `تم حفظ ${successful.length} سجل، وبقي ${unsaved} سجل غير محفوظ.${failed[0] ? ` ${apiMessage(failed[0].error)}` : ''}` })
  }

  async function handleSubmit() {
    const offeringId = selectedId
    setSubmitting(true); setNotice(null)
    try {
      const result = await submitMyGradeParts(offeringId)
      if (Number(selectedIdRef.current) !== Number(offeringId)) return
      setConfirmOpen(false)
      setRefreshRequired(true)
      const refreshed = await loadWorkflow(offeringId)
      const submitted = result?.submitted_parts ?? []
      const submittedLabel = submitted.map(part => PARTS[part]?.label).filter(Boolean).join(' وال')
      setNotice(refreshed
        ? { type: 'success', text: submitted.length ? `تم إرسال علامات ${submittedLabel} إلى هيئة الامتحانات.` : 'لم تتغير حالة الأجزاء المرسلة سابقًا.' }
        : { type: 'warning', text: 'تم الإرسال، لكن تعذّر تحديث الحالة. تبقى الحقول مقفلة حتى إعادة التحميل.' })
    } catch (error) {
      if (Number(selectedIdRef.current) !== Number(offeringId)) return
      setConfirmOpen(false); setNotice({ type: 'error', text: apiMessage(error) })
      if (error.status === 409 || CONFLICT_CODES.has(error.errorCode)) await loadWorkflow(offeringId, { preserveDirty: true })
    } finally { if (Number(selectedIdRef.current) === Number(offeringId)) setSubmitting(false) }
  }

  const partMax = componentDefinitions.reduce((sum, component) => sum + Number(component.max_mark || 0), 0)
  const completeCount = rows.filter(row => rowComplete(row)).length
  const incompleteCount = rows.filter(row => !row.is_deprived && !rowComplete(row)).length
  const actionableReady = actionableAssignedParts.length > 0 && actionableAssignedParts.every(part => workflow?.parts?.[part]?.can_submit === true)
  const canSave = !official && !refreshRequired && !loadError && !loading && partState?.can_edit === true && selectedDirtyKeys.length > 0 && !saving && !submitting
  const canSubmit = !official && !refreshRequired && !loadError && !loading && actionableReady && dirtyKeys.length === 0 && !saving && !submitting
  const submitLabel = submitButtonLabel(actionableAssignedParts, workflow?.parts)

  return <>
    <div className="mb-5" dir="rtl">
      <h2 className="text-[20px] font-black text-text-dark mb-[3px]">إدارة علامات المقرر</h2>
      <p className="text-[12.5px] text-text-light">إدخال وإرسال العلامات حسب التكليف التدريسي</p>
    </div>
    {offeringsLoading && <Spinner />}
    {!offeringsLoading && offeringsError && <Alert text={offeringsError} />}
    {!offeringsLoading && !offeringsError && !facultyMember && <Empty text="لم يتم العثور على سجل عضو هيئة تدريس مرتبط بحسابك" />}
    {!offeringsLoading && facultyMember && offerings.length === 0 && <Empty text="لا توجد مواد مسندة إليك حالياً" />}
    {!offeringsLoading && offerings.length > 0 && <div className="grid grid-cols-3 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1 gap-4 mb-6">
      {offerings.map(o => {
        const active = Number(selectedId) === Number(o.course_offering_id)
        const badge = assignmentCopy(o.assignment_mode)
        const BadgeIcon = badge.Icon
        return <button key={o.course_offering_id} disabled={saving || submitting} onClick={() => selectOffering(active ? null : o.course_offering_id)} className={`text-right bg-white border rounded-[16px] px-5 py-4 flex items-center gap-3 shadow-[0_2px_10px_rgba(26,46,16,0.05)] transition-all disabled:opacity-60 ${active ? 'border-primary' : 'border-primary/12 hover:enabled:-translate-y-[2px]'}`} dir="rtl">
          <span className="w-11 h-11 rounded-[11px] bg-primary/10 flex items-center justify-center text-primary flex-shrink-0"><FaBook /></span>
          <span className="flex-1 min-w-0">
            <span className="block font-bold text-[13.5px] text-text-dark truncate">{o.course?.course_name || `مادة #${o.course_offering_id}`}</span>
            <span className="block text-[11px] text-text-light font-mono">{o.course?.course_code}{o.section?.course_offering_id ? ` — شعبة ${o.section.course_offering_id}` : ''}</span>
            <span className="mt-1 block"><CourseRequirementBadges classification={pickRequirementClassification(o)} compact /></span>
            <span className="mt-1 inline-flex items-center gap-1 text-[10.5px] font-bold text-primary"><BadgeIcon className="text-[10px]" />{badge.text}</span>
          </span>
          <FaChevronDown className={`text-primary/50 text-[12px] ${active ? 'rotate-180' : ''}`} />
        </button>
      })}
    </div>}
    {selectedId && loading && !workflow && <Spinner />}
    {selectedId && loadError && <RetryAlert text={loadError} disabled={busy} onRetry={() => loadWorkflow(selectedId, { preserveDirty: true })} />}
    {workflow && <>
      <OfferingHeader offering={selectedOffering} workflow={workflow} />
      <PartPanels workflow={workflow} />
      {official ? <OfficialResults workflow={workflow} rows={rows} /> : <>
        {ownsBoth && <div className="mb-4 flex flex-wrap items-center gap-2" dir="rtl">
          {requiredAssignedParts.map(part => {
            const complete = workflow.parts?.[part]?.can_submit || workflow.parts?.[part]?.status === 'submitted' || workflow.parts?.[part]?.status === 'approved'
            return <span key={part} className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[12px] font-bold ${complete ? 'border-green-200 bg-green-50 text-green-700' : 'border-amber-200 bg-amber-50 text-amber-700'}`}>{PARTS[part].label}: {complete ? 'مكتمل' : 'ناقص'}</span>
          })}
        </div>}
        {requiredAssignedParts.length > 1 && <div className="inline-flex max-w-full gap-1 p-1 mb-4 bg-primary/5 border border-primary/10 rounded-[12px]" dir="rtl" role="tablist">
          {requiredAssignedParts.map(part => <button key={part} role="tab" aria-selected={selectedPart === part} onClick={() => selectPart(part)} className={`px-5 py-2.5 rounded-[9px] text-[13px] font-extrabold transition-colors ${selectedPart === part ? 'bg-primary text-white shadow-sm' : 'text-text-dark hover:bg-white'}`}>{PARTS[part].label}</button>)}
        </div>}
        {partState?.status === 'returned' && partState?.review_notes && <ReviewNotes part={selectedPart} notes={partState.review_notes} />}
        {notice && <Notice notice={notice} />}
        {refreshRequired && <RetryAlert text="الحقول مقفلة احترازيًا حتى تأكيد الحالة الجديدة من الخادم." disabled={busy} onRetry={() => loadWorkflow(selectedId)} />}
        {selectedPart && partState?.assigned_to_me === true && <div className="mb-3 grid grid-cols-5 max-[900px]:grid-cols-3 max-[560px]:grid-cols-2 gap-2" dir="rtl">
          <SummaryCard label="عدد الطلاب" value={rows.length} />
          <SummaryCard label="المكتمل" value={completeCount} />
          <SummaryCard label="غير المكتمل" value={incompleteCount} />
          <SummaryCard label="التعديلات غير المحفوظة" value={selectedDirtyKeys.length} />
          <SummaryCard label="الحد الأعلى للجزء" value={partMax} />
        </div>}
        {rows.length === 0 ? <Empty text="لا يوجد طلاب مسجلون في هذه المادة" /> : selectedPart && partState?.assigned_to_me === true && <MarksTable
          rows={rows}
          part={selectedPart}
          partState={partState}
          componentDefinitions={componentDefinitions}
          edits={edits}
          rowValidation={rowValidation}
          updateMark={updateMark}
          refreshRequired={refreshRequired}
          loadError={loadError}
          loading={loading}
          saving={saving}
          submitting={submitting}
        />}
        <div className="flex gap-3 flex-wrap mt-4" dir="rtl">
          <button onClick={handleSave} disabled={!canSave} className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40">{saving ? <FaSpinner className="animate-spin" /> : <FaSave />}حفظ مسودة {PARTS[selectedPart]?.label || ''}</button>
          <button onClick={() => setConfirmOpen(true)} disabled={!canSubmit} className="inline-flex items-center gap-2 px-5 py-2.5 bg-amber-700 text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40"><FaPaperPlane />{submitLabel}</button>
        </div>
      </>}
    </>}
    {confirmOpen && <ConfirmDialog
      offering={selectedOffering}
      workflow={workflow}
      partsToSubmit={actionableAssignedParts}
      unchangedParts={unchangedAssignedParts}
      studentCount={rows.length}
      submitting={submitting}
      onCancel={() => setConfirmOpen(false)}
      onConfirm={handleSubmit}
    />}
  </>
}

function OfferingHeader({ offering, workflow }) {
  const year = offering?.academic_year?.year_name || workflow?.academic_year?.year_name
  const semester = offering?.semester?.semester_name || workflow?.semester?.semester_name
  const sectionId = offering?.section?.course_offering_id || offering?.course_offering_id || workflow?.course_offering_id
  const count = offering?.registered_students_count ?? workflow?.students?.length ?? 0
  const badge = assignmentCopy(offering?.assignment_mode || workflow?.assignment_mode)
  const BadgeIcon = badge.Icon
  return <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-4 shadow-[0_2px_10px_rgba(26,46,16,0.04)]" dir="rtl">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div className="font-black text-[18px] text-text-dark">{workflow.course?.course_name || '—'}</div>
        <div className="font-mono text-[12px] text-text-light mt-0.5">{workflow.course?.course_code || '—'}</div>
        <div className="mt-1.5">
          <CourseRequirementBadges classification={pickRequirementClassification(offering) || pickRequirementClassification(workflow)} />
        </div>
        <div className="text-[12px] text-text-light mt-2 flex flex-wrap gap-x-3 gap-y-1">
          {year && <span>العام الدراسي: {year}</span>}
          {semester && <span>الفصل: {semester}</span>}
          {sectionId && <span>الشعبة: {sectionId}</span>}
          <span className="inline-flex items-center gap-1"><FaUserGraduate className="text-[11px]" />الطلاب المسجلون: {count}</span>
        </div>
      </div>
      <span className="inline-flex items-center gap-2 rounded-[12px] border border-primary/20 bg-primary/[0.06] px-4 py-2 text-[13px] font-black text-primary">
        <BadgeIcon /> {badge.text}
      </span>
    </div>
  </div>
}

function PartPanels({ workflow }) {
  const required = workflow?.required_parts ?? []
  return <div className="grid grid-cols-2 max-[700px]:grid-cols-1 gap-3 mb-4" dir="rtl">
    {Object.keys(PARTS).map(part => {
      const state = workflow.parts?.[part]
      if (!state?.required && !required.includes(part)) {
        return <div key={part} className="rounded-[14px] border border-primary/8 bg-[#f7f7f4] p-4 text-text-light">
          <div className="font-extrabold text-[14px] text-text-dark">الجزء {PARTS[part].label}</div>
          <div className="text-[12px] mt-1">غير مطلوب لهذا المقرر</div>
        </div>
      }
      if (state?.assigned_to_me !== true) {
        return <div key={part} className="rounded-[14px] border border-primary/10 bg-[#f4f5f2] p-4 text-text-light">
          <div className="flex items-center gap-2 font-extrabold text-[14px] text-text-dark"><FaLock className="text-[12px]" /> الجزء {PARTS[part].label}</div>
          <div className="text-[12.5px] mt-2">{part === 'practical' ? 'الجزء العملي مسند إلى مدرس آخر' : 'الجزء النظري مسند إلى مدرس آخر'}</div>
          {workflow.part_assignments?.[part]?.instructor_name && <div className="text-[11px] mt-1">المدرّس: {workflow.part_assignments[part].instructor_name}</div>}
          <WorkflowStrip state={state} muted />
        </div>
      }
      return <div key={part} className="rounded-[14px] border border-primary/12 bg-white p-4">
        <div className="font-extrabold text-[14px] text-text-dark mb-2">الجزء {PARTS[part].label}</div>
        <WorkflowStrip state={state} />
        {state?.status === 'returned' && <div className="mt-2 text-[12px] font-bold text-red-700">معاد للتصحيح</div>}
      </div>
    })}
  </div>
}

function WorkflowStrip({ state, muted = false }) {
  const current = stepForPart(state)
  if (current === 'returned') {
    return <div className={`mt-2 rounded-[10px] border px-3 py-2 text-[12px] font-bold ${muted ? 'border-primary/10 text-text-light' : 'border-red-200 bg-red-50 text-red-700'}`}>معاد للتصحيح</div>
  }
  return <ol className={`mt-2 flex flex-wrap items-center gap-1 text-[11px] ${muted ? 'opacity-70' : ''}`}>
    {WORKFLOW_STEPS.map((step, index) => {
      const active = step.key === current
      const reached = WORKFLOW_STEPS.findIndex(item => item.key === current) >= index
      return <li key={step.key} className="flex items-center gap-1">
        <span className={`rounded-full px-2.5 py-1 font-bold ${active ? 'bg-primary text-white' : reached ? 'bg-primary/10 text-primary' : 'bg-gray-100 text-text-light'}`}>{step.label}</span>
        {index < WORKFLOW_STEPS.length - 1 && <span className="text-text-light px-0.5">→</span>}
      </li>
    })}
  </ol>
}

function MarksTable({ rows, part, partState, componentDefinitions, edits, rowValidation, updateMark, refreshRequired, loadError, loading, saving, submitting }) {
  const locked = ['submitted', 'approved'].includes(partState?.status)
  return <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)] mb-4">
    <div className="max-h-[70vh] overflow-auto">
      <table className="w-full min-w-[760px] border-collapse text-[13px]" dir="rtl">
        <thead className="sticky top-0 z-[1] bg-[#fafaf8] shadow-[0_1px_0_rgba(26,46,16,0.08)]">
          <tr>
            <th className="px-3 py-3 text-center text-[11px] text-text-light">الرقم الجامعي</th>
            <th className="px-3 py-3 text-right text-[11px] text-text-light">اسم الطالب</th>
            {componentDefinitions.map((component, index) => <th key={component.grade_component_id} className="px-3 py-3 text-center text-[11px] text-text-light">{PARTS[part].label}{componentDefinitions.length > 1 ? ` ${index + 1}` : ''} / {component.max_mark}</th>)}
            <th className="px-3 py-3 text-center text-[11px] text-text-light">مجموع الجزء</th>
            <th className="px-3 py-3 text-center text-[11px] text-text-light">الحالة</th>
          </tr>
        </thead>
        <tbody>
          {rows.map(row => {
            const errors = rowValidation(row)
            const dirty = componentDefinitions.some(component => edits[editKey(row.registration_id, component.grade_component_id)]?.dirty)
            const editable = !refreshRequired && !loadError && !loading && partState?.can_edit === true && !row.is_deprived && !locked
            const subtotal = componentDefinitions.reduce((sum, component) => {
              const value = edits[editKey(row.registration_id, component.grade_component_id)]?.value
              return value === '' || value === null || value === undefined || !Number.isFinite(Number(value)) ? sum : sum + Number(value)
            }, 0)
            const filled = componentDefinitions.every(component => {
              const value = edits[editKey(row.registration_id, component.grade_component_id)]?.value
              return value !== '' && value !== null && value !== undefined
            })
            return <tr key={row.registration_id} className={`border-t border-primary/6 ${row.is_deprived ? 'bg-gray-50 text-text-light' : dirty ? 'bg-amber-50/40' : ''}`}>
              <td className="px-3 py-3 text-center font-mono text-[11px]">{row.student?.student_number || '—'}</td>
              <td className="px-3 py-3 text-right">
                <div className="font-semibold text-text-dark">{studentName(row.student)}</div>
                {row.is_deprived && <span className="inline-block mt-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 border border-red-200 text-[10px] font-bold">محروم — للقراءة فقط</span>}
                {locked && !row.is_deprived && <span className="inline-flex items-center gap-1 mt-1 text-[10px] font-bold text-text-light"><FaLock />مقفل</span>}
              </td>
              {componentDefinitions.map((component, index) => <MarkInput key={component.grade_component_id} value={edits[editKey(row.registration_id, component.grade_component_id)]?.value ?? ''} max={component.max_mark} error={errors[index]} dirty={edits[editKey(row.registration_id, component.grade_component_id)]?.dirty} disabled={!editable || saving || submitting} onChange={value => updateMark(row.registration_id, component.grade_component_id, value)} />)}
              <td className="px-3 py-3 text-center font-bold">{row.is_deprived ? '—' : filled ? subtotal : '—'}</td>
              <td className="px-3 py-3 text-center">{dirty ? <span className="text-amber-700 font-bold text-[11px]">غير محفوظ</span> : <span className="text-green-700 font-bold text-[11px]"><FaCheck className="inline ml-1" />محفوظ</span>}</td>
            </tr>
          })}
        </tbody>
      </table>
    </div>
  </div>
}

function OfficialResults({ workflow, rows }) {
  const showTheoretical = (workflow.required_parts ?? []).includes('theoretical')
  const showPractical = (workflow.required_parts ?? []).includes('practical')
  return <div className="bg-white border border-green-200 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
    <div className="flex items-center gap-2 border-b border-green-100 bg-green-50 px-5 py-4 text-green-800">
      <FaCheckCircle />
      <div>
        <div className="font-black">تم اعتماد النتائج رسميًا</div>
        <div className="text-[12px]">عرض نهائي للقراءة فقط — لا يمكن تعديل العلامات بعد الاعتماد.</div>
      </div>
    </div>
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] text-[13px]">
        <thead className="sticky top-0 bg-[#fafaf8]">
          <tr>
            <th className="px-3 py-3 text-center text-[11px] text-text-light">الرقم الجامعي</th>
            <th className="px-3 py-3 text-right text-[11px] text-text-light">اسم الطالب</th>
            {showTheoretical && <th className="px-3 py-3 text-center text-[11px] text-text-light">مجموع النظري</th>}
            {showPractical && <th className="px-3 py-3 text-center text-[11px] text-text-light">مجموع العملي</th>}
            <th className="px-3 py-3 text-center text-[11px] text-text-light">العلامة النهائية</th>
            <th className="px-3 py-3 text-center text-[11px] text-text-light">حالة النتيجة</th>
          </tr>
        </thead>
        <tbody>
          {rows.map(row => {
            const result = row.official_result
            return <tr key={row.registration_id} className="border-t border-primary/6">
              <td className="px-3 py-3 text-center font-mono text-[11px]">{row.student?.student_number || '—'}</td>
              <td className="px-3 py-3 text-right font-semibold">{studentName(row.student)}</td>
              {showTheoretical && <td className="px-3 py-3 text-center font-bold">{row.is_deprived || result?.is_deprived ? '—' : (result?.theoretical_total ?? '—')}</td>}
              {showPractical && <td className="px-3 py-3 text-center font-bold">{row.is_deprived || result?.is_deprived ? '—' : (result?.practical_total ?? '—')}</td>}
              <td className="px-3 py-3 text-center font-black">{result?.final_mark ?? '—'}</td>
              <td className="px-3 py-3 text-center font-bold">{result?.is_deprived || row.is_deprived ? 'محروم' : (result?.result_status?.status_name || result?.result_status?.status_code || '—')}</td>
            </tr>
          })}
        </tbody>
      </table>
    </div>
  </div>
}

function submitButtonLabel(actionableParts, parts) {
  if (actionableParts.length === 2) return 'إرسال العلامات إلى هيئة الامتحانات'
  const part = actionableParts[0]
  if (!part) return 'إرسال العلامات إلى هيئة الامتحانات'
  const name = PARTS[part]?.label || ''
  return parts?.[part]?.status === 'returned'
    ? `إعادة إرسال علامات ${name} إلى هيئة الامتحانات`
    : `إرسال علامات ${name} إلى هيئة الامتحانات`
}

function unchangedPartNote(part, status) {
  if (status === 'approved') return `${PARTS[part].label} معتمد مسبقاً ولن يتغير.`
  if (status === 'submitted') return `${PARTS[part].label} مرسل مسبقاً وينتظر المراجعة.`
  return null
}

function ConfirmDialog({ offering, workflow, partsToSubmit, unchangedParts, studentCount, submitting, onCancel, onConfirm }) {
  const course = workflow?.course?.course_name || offering?.course?.course_name || '—'
  const submitLabel = partsToSubmit.map(part => PARTS[part]?.label).join(' وال')
  return <div className="fixed inset-0 z-50 bg-black/45 flex items-center justify-center p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className="bg-white rounded-[18px] max-w-[580px] w-full p-6 shadow-2xl">
      <div className="flex gap-3">
        <FaExclamationTriangle className="text-amber-500 text-[22px] flex-shrink-0" />
        <div>
          <h3 className="font-black text-text-dark mb-2">تأكيد إرسال العلامات</h3>
          <ul className="text-[13px] leading-7 text-text-dark list-disc pr-5">
            <li>المقرر: {course}</li>
            <li>سيتم إرسال: {submitLabel || '—'}</li>
            <li>عدد الطلاب: {studentCount}</li>
            {partsToSubmit.map(part => {
              const complete = workflow?.parts?.[part]?.can_submit === true
              return <li key={part}>{PARTS[part].label}: {complete ? 'مكتمل' : 'ناقص'}</li>
            })}
          </ul>
          {unchangedParts.map(part => {
            const note = unchangedPartNote(part, workflow?.parts?.[part]?.status)
            return note ? <p key={part} className="mt-2 text-[12.5px] text-text-dark">{note}</p> : null
          })}
          <p className="mt-3 text-[12.5px] leading-7 text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] p-3">بعد الإرسال ستُقفل الأجزاء المرسلة حتى تعتمدها هيئة الامتحانات أو تعيدها للتصحيح.</p>
        </div>
      </div>
      <div className="flex gap-3 mt-6">
        <button onClick={onCancel} disabled={submitting} className="px-5 py-2.5 border border-primary/20 rounded-[10px] text-[13px] font-bold">إلغاء</button>
        <button onClick={onConfirm} disabled={submitting} className="px-5 py-2.5 bg-amber-700 text-white rounded-[10px] text-[13px] font-bold inline-flex items-center gap-2">{submitting && <FaSpinner className="animate-spin" />}تأكيد الإرسال</button>
      </div>
    </div>
  </div>
}

function MarkInput({ value, max, error, dirty, disabled, onChange }) {
  return <td className="px-3 py-3 align-top">
    <input type="number" min="0" max={max} step="0.5" value={value} disabled={disabled} onChange={event => onChange(event.target.value)} className={`block mx-auto w-[96px] px-2 py-2 border rounded-[8px] text-center outline-none disabled:bg-gray-100 ${dirty && error ? 'border-red-400 bg-red-50' : 'border-primary/20 focus:border-primary'}`} dir="ltr" />
    <div className="text-[10px] text-text-light text-center mt-1">الحد الأعلى: {max}</div>
    {dirty && error && <div className="text-[10px] text-red-500 text-center mt-1">{error}</div>}
  </td>
}
function ReviewNotes({ part, notes }) {
  return <div className="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-[12px] p-4 text-[13px]" dir="rtl">
    <strong>ملاحظات هيئة الامتحانات لإعادة الجزء {PARTS[part]?.label} للتصحيح:</strong>
    <div className="mt-1 whitespace-pre-wrap">{notes}</div>
  </div>
}
function SummaryCard({ label, value }) {
  return <div className="rounded-[12px] border border-primary/10 bg-white px-3 py-2.5">
    <div className="text-[11px] text-text-light">{label}</div>
    <div className="font-black text-text-dark text-[16px] mt-0.5">{value}</div>
  </div>
}
function Spinner() { return <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div> }
function Notice({ notice }) { const style = notice.type === 'success' ? 'bg-green-50 border-green-200 text-green-700' : notice.type === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-red-50 border-red-200 text-red-600'; return <div className={`border rounded-[12px] px-5 py-3 mb-4 text-[13px] ${style}`} dir="rtl">{notice.text}</div> }
function Alert({ text }) { return <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {text}</div> }
function RetryAlert({ text, disabled, onRetry }) { return <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 flex flex-wrap items-center justify-between gap-3 text-[13px] text-red-600" dir="rtl"><span>⚠ {text}</span><button type="button" disabled={disabled} onClick={onRetry} className="px-4 py-2 bg-white border border-red-200 rounded-[9px] font-bold disabled:opacity-50">إعادة المحاولة</button></div> }
function Empty({ text }) { return <p className="text-center text-[13px] text-text-light py-12" dir="rtl">{text}</p> }
