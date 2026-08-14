import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FaBook, FaCheck, FaChevronDown, FaExclamationTriangle, FaPaperPlane, FaSave, FaSpinner } from 'react-icons/fa'
import useMyOfferings from '../hooks/useMyOfferings'
import { getGradePartsWorkflow, saveRegistrationGradePart, submitOfferingGradePart } from '../lib/professorApi'

const PARTS = {
  practical: { label: 'العلامة العملية', short: 'العملي' },
  theoretical: { label: 'العلامة النظرية', short: 'النظري' },
}
const STATUSES = {
  draft: { label: 'مسودة', style: 'bg-blue-50 text-blue-700 border-blue-200' },
  submitted: { label: 'مرسل إلى هيئة الامتحانات', style: 'bg-amber-50 text-amber-700 border-amber-200' },
  returned: { label: 'معاد للتصحيح', style: 'bg-red-50 text-red-700 border-red-200' },
  approved: { label: 'معتمد', style: 'bg-green-50 text-green-700 border-green-200' },
}
const ERROR_MESSAGES = {
  invalid_grade_part: 'بيانات جزء العلامة غير صالحة.',
  grade_part_not_required: 'هذا الجزء غير مطلوب لهذه المادة.',
  grade_part_locked: 'هذا الجزء مقفل ولم يعد قابلاً للتعديل.',
  grade_part_already_approved: 'هذا الجزء معتمد بالفعل.',
  grade_part_already_submitted: 'هذا الجزء مرسل بالفعل إلى هيئة الامتحانات.',
  grade_part_incomplete: 'علامات هذا الجزء غير مكتملة.',
  deprived_student_grade_locked: 'لا يمكن تعديل علامة الطالب المحروم.',
  grade_entry_not_allowed: 'إدخال العلامة غير مسموح لهذا التسجيل.',
  not_primary_instructor: 'إدخال العلامات متاح لمدرس المادة الأساسي فقط.',
  unauthorized_grade_part: 'غير مصرح لك بالوصول إلى هذا الجزء.',
}
const CONFLICT_CODES = new Set(['grade_part_locked', 'grade_part_already_approved', 'grade_part_already_submitted', 'grade_part_not_required', 'unauthorized_grade_part'])
const apiMessage = error => ERROR_MESSAGES[error?.errorCode] || error?.message || 'تعذّر الاتصال بالخادم'
const formatDate = value => value ? new Date(value).toLocaleString('ar-SY') : null
const studentName = student => [student?.first_name, student?.last_name].filter(Boolean).join(' ') || '—'
const editKey = (registrationId, componentId) => `${registrationId}:${componentId}`

function buildEdits(data) {
  const result = {}
  for (const row of data?.students ?? []) for (const part of Object.keys(PARTS)) {
    for (const component of data?.components?.[part] ?? []) {
      const saved = (row.marks?.[part] ?? []).find(mark => Number(mark.grade_component_id) === Number(component.grade_component_id))
      result[editKey(row.registration_id, component.grade_component_id)] = { value: saved?.mark ?? '', dirty: false }
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
  const selectedIdRef = useRef(null)
  const selectedPartRef = useRef(null)
  const requestSequence = useRef(0)

  const requiredParts = useMemo(() => (workflow?.required_parts ?? []).filter(part => PARTS[part]), [workflow])
  const rows = workflow?.students ?? []
  const partState = selectedPart ? workflow?.parts?.[selectedPart] : null
  const componentDefinitions = useMemo(() => workflow?.components?.[selectedPart] ?? [], [workflow, selectedPart])
  const dirtyKeys = useMemo(() => Object.keys(edits).filter(key => edits[key]?.dirty), [edits])
  const selectedDirtyKeys = useMemo(() => {
    if (!selectedPart) return []
    const ids = new Set(rows.flatMap(row => componentDefinitions.map(component => editKey(row.registration_id, component.grade_component_id))))
    return dirtyKeys.filter(key => ids.has(key))
  }, [componentDefinitions, dirtyKeys, rows, selectedPart])

  const loadWorkflow = useCallback(async (offeringId, { preserveDirty = false } = {}) => {
    const sequence = ++requestSequence.current
    setLoading(true); setLoadError('')
    try {
      const next = await getGradePartsWorkflow(offeringId)
      if (sequence !== requestSequence.current || Number(selectedIdRef.current) !== Number(offeringId)) return false
      setWorkflow(next)
      const nextParts = (next.required_parts ?? []).filter(part => PARTS[part])
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
    const offeringId = selectedId; const part = selectedPart
    setSubmitting(true); setNotice(null)
    try {
      await submitOfferingGradePart(offeringId, part)
      if (Number(selectedIdRef.current) !== Number(offeringId) || selectedPartRef.current !== part) return
      setConfirmOpen(false); await loadWorkflow(offeringId, { preserveDirty: true })
      setNotice({ type: 'success', text: `تم إرسال علامات الجزء ${PARTS[part].short} إلى هيئة الامتحانات.` })
    } catch (error) {
      if (Number(selectedIdRef.current) !== Number(offeringId) || selectedPartRef.current !== part) return
      setConfirmOpen(false); setNotice({ type: 'error', text: apiMessage(error) })
      if (CONFLICT_CODES.has(error.errorCode)) await loadWorkflow(offeringId, { preserveDirty: true })
    } finally { if (Number(selectedIdRef.current) === Number(offeringId)) setSubmitting(false) }
  }

  const canSave = !loadError && !loading && partState?.can_edit === true && selectedDirtyKeys.length > 0 && !saving && !submitting
  const canSubmit = !loadError && !loading && partState?.required === true && partState.can_submit === true && !['submitted', 'approved'].includes(partState.status) && selectedDirtyKeys.length === 0 && !saving && !submitting

  return <>
    <div className="mb-5" dir="rtl"><h2 className="text-[20px] font-black text-text-dark mb-[3px]">إدخال العلامات</h2><p className="text-[12.5px] text-text-light">Grades</p></div>
    {offeringsLoading && <Spinner />}
    {!offeringsLoading && offeringsError && <Alert text={offeringsError} />}
    {!offeringsLoading && !offeringsError && !facultyMember && <Empty text="لم يتم العثور على سجل عضو هيئة تدريس مرتبط بحسابك" />}
    {!offeringsLoading && facultyMember && offerings.length === 0 && <Empty text="لا توجد مواد مسندة إليك حالياً" />}
    {!offeringsLoading && offerings.length > 0 && <div className="grid grid-cols-3 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1 gap-4 mb-6">
      {offerings.map(o => { const active = Number(selectedId) === Number(o.course_offering_id); return <button key={o.course_offering_id} disabled={saving || submitting} onClick={() => selectOffering(active ? null : o.course_offering_id)} className={`text-right bg-white border rounded-[16px] px-5 py-4 flex items-center gap-3 shadow-[0_2px_10px_rgba(26,46,16,0.05)] transition-all disabled:opacity-60 ${active ? 'border-primary' : 'border-primary/12 hover:enabled:-translate-y-[2px]'}`} dir="rtl"><span className="w-11 h-11 rounded-[11px] bg-primary/10 flex items-center justify-center text-primary flex-shrink-0"><FaBook /></span><span className="flex-1 min-w-0"><span className="block font-bold text-[13.5px] text-text-dark truncate">{o.course?.course_name || o.course_name || `مادة #${o.course_offering_id}`}</span><span className="block text-[11px] text-text-light font-mono">{o.course?.course_code || o.course_code}{o.section_number ? ` — شعبة ${o.section_number}` : ''}</span></span><FaChevronDown className={`text-primary/50 text-[12px] ${active ? 'rotate-180' : ''}`} /></button> })}
    </div>}
    {selectedId && loading && !workflow && <Spinner />}
    {selectedId && loadError && <RetryAlert text={loadError} disabled={loading || saving || submitting} onRetry={() => loadWorkflow(selectedId, { preserveDirty: true })} />}
    {workflow && <>
      {requiredParts.length > 0 && <div className="inline-flex max-w-full gap-1 p-1 mb-4 bg-primary/5 border border-primary/10 rounded-[12px]" dir="rtl" role="tablist">{requiredParts.map(part => <button key={part} role="tab" aria-selected={selectedPart === part} onClick={() => selectPart(part)} className={`px-5 py-2.5 rounded-[9px] text-[13px] font-extrabold transition-colors ${selectedPart === part ? 'bg-primary text-white shadow-sm' : 'text-text-dark hover:bg-white'}`}>{PARTS[part].label}</button>)}</div>}
      {partState && <PartStatus part={selectedPart} state={partState} />}
      {notice && <Notice notice={notice} />}
      {rows.length === 0 ? <Empty text="لا يوجد طلاب مسجلون في هذه المادة" /> : selectedPart && <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)] mb-4"><div className="overflow-x-auto"><table className="w-full min-w-[720px] border-collapse text-[13px]" dir="rtl"><thead><tr className="bg-[#fafaf8]"><th className="px-3 py-3 text-center text-[11px] text-text-light">الرقم الجامعي</th><th className="px-3 py-3 text-right text-[11px] text-text-light">اسم الطالب</th>{componentDefinitions.map((component, index) => <th key={component.grade_component_id} className="px-3 py-3 text-center text-[11px] text-text-light">{PARTS[selectedPart].short}{componentDefinitions.length > 1 ? ` ${index + 1}` : ''} / {component.max_mark}</th>)}{selectedPart === 'theoretical' && <th className="px-3 py-3 text-center text-[11px] text-text-light">العملي (للقراءة فقط)</th>}<th className="px-3 py-3 text-center text-[11px] text-text-light">حالة الحفظ</th></tr></thead><tbody>
        {rows.map(row => { const errors = rowValidation(row); const dirty = componentDefinitions.some(component => edits[editKey(row.registration_id, component.grade_component_id)]?.dirty); const editable = !loadError && !loading && partState?.can_edit === true && !row.is_deprived; return <tr key={row.registration_id} className="border-t border-primary/6"><td className="px-3 py-3 text-center font-mono text-[11px]">{row.student?.student_number || '—'}</td><td className="px-3 py-3 text-right"><div className="font-semibold text-text-dark">{studentName(row.student)}</div>{row.is_deprived && <Badge text="محروم" />}</td>{componentDefinitions.map((component, index) => <MarkInput key={component.grade_component_id} value={edits[editKey(row.registration_id, component.grade_component_id)]?.value ?? ''} max={component.max_mark} error={errors[index]} dirty={edits[editKey(row.registration_id, component.grade_component_id)]?.dirty} disabled={!editable || saving || submitting} onChange={value => updateMark(row.registration_id, component.grade_component_id, value)} />)}{selectedPart === 'theoretical' && <PracticalReference row={row} definitions={workflow.components?.practical ?? []} status={workflow.parts?.practical?.status} />}<td className="px-3 py-3 text-center">{dirty ? <span className="text-amber-700 font-bold text-[11px]">غير محفوظ</span> : <span className="text-green-700 font-bold text-[11px]"><FaCheck className="inline ml-1" />محفوظ</span>}</td></tr> })}
      </tbody></table></div></div>}
      <div className="flex gap-3 flex-wrap" dir="rtl"><button onClick={handleSave} disabled={!canSave} className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40">{saving ? <FaSpinner className="animate-spin" /> : <FaSave />}حفظ مسودة {PARTS[selectedPart]?.short}</button><button onClick={() => setConfirmOpen(true)} disabled={!canSubmit} className="inline-flex items-center gap-2 px-5 py-2.5 bg-amber-600 text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40"><FaPaperPlane />إرسال {PARTS[selectedPart]?.short} لهيئة الامتحانات</button></div>
    </>}
    {confirmOpen && <ConfirmDialog part={selectedPart} submitting={submitting} onCancel={() => setConfirmOpen(false)} onConfirm={handleSubmit} />}
  </>
}

function PartStatus({ part, state }) { const status = STATUSES[state.status]; return <div className={`border rounded-[14px] p-4 mb-4 ${status?.style || 'bg-gray-50 text-gray-700 border-gray-200'}`} dir="rtl"><div className="flex flex-wrap items-center justify-between gap-2"><div className="font-extrabold text-[14px]">حالة {PARTS[part].short}: {status?.label || state.status || 'غير معروفة'}</div><span className="text-[11px] font-bold">{state.can_edit ? 'قابل للتحرير' : 'مقفل للقراءة فقط'}</span></div>{state.submitted_at && <div className="text-[11px] mt-2">تاريخ الإرسال: <span dir="ltr">{formatDate(state.submitted_at)}</span></div>}{state.reviewed_at && <div className="text-[11px] mt-1">تاريخ المراجعة: <span dir="ltr">{formatDate(state.reviewed_at)}</span></div>}{state.review_notes && <div className="mt-2 bg-white/70 rounded-[9px] p-3 text-[12.5px]"><strong>ملاحظات هيئة الامتحانات:</strong> {state.review_notes}</div>}</div> }
function PracticalReference({ row, definitions, status }) { const saved = row.marks?.practical ?? []; const marks = definitions.map(component => ({ ...component, mark: saved.find(item => Number(item.grade_component_id) === Number(component.grade_component_id))?.mark ?? null })); const entered = marks.filter(item => item.mark !== null && item.mark !== ''); const total = entered.length === marks.length && marks.length ? entered.reduce((sum, item) => sum + Number(item.mark), 0) : null; const max = marks.reduce((sum, item) => sum + Number(item.max_mark), 0); return <td className="px-3 py-3 text-center"><div className="font-bold">{total === null ? '—' : `${total} / ${max}`}</div>{marks.length > 1 && <div className="text-[10px] text-text-light mt-1">{marks.map((item, index) => `${index + 1}: ${item.mark ?? '—'} / ${item.max_mark}`).join('، ')}</div>}<span className={`inline-block mt-1 px-2 py-0.5 rounded-full border text-[10px] font-bold ${STATUSES[status]?.style || 'bg-gray-50 border-gray-200'}`}>{STATUSES[status]?.label || 'غير معروفة'}</span></td> }
function MarkInput({ value, max, error, dirty, disabled, onChange }) { return <td className="px-3 py-3 align-top"><input type="number" min="0" max={max} step="0.5" value={value} disabled={disabled} onChange={event => onChange(event.target.value)} className={`block mx-auto w-[96px] px-2 py-2 border rounded-[8px] text-center outline-none disabled:bg-gray-100 ${dirty && error ? 'border-red-400 bg-red-50' : 'border-primary/20 focus:border-primary'}`} dir="ltr" /><div className="text-[10px] text-text-light text-center mt-1">الحد الأعلى: {max}</div>{dirty && error && <div className="text-[10px] text-red-500 text-center mt-1">{error}</div>}</td> }
function ConfirmDialog({ part, submitting, onCancel, onConfirm }) { const practical = part === 'practical'; return <div className="fixed inset-0 z-50 bg-black/45 flex items-center justify-center p-4" dir="rtl" role="dialog" aria-modal="true"><div className="bg-white rounded-[18px] max-w-[560px] w-full p-6 shadow-2xl"><div className="flex gap-3"><FaExclamationTriangle className="text-amber-500 text-[22px] flex-shrink-0" /><div><h3 className="font-black text-text-dark mb-2">تأكيد إرسال الجزء {PARTS[part]?.short}</h3><p className="text-[13px] leading-7 text-text-dark">{practical ? 'سيتم إرسال علامات الجزء العملي إلى هيئة الامتحانات. بعد التأكيد لن تتمكن من تعديل علامات هذا الجزء إلا إذا أعادته هيئة الامتحانات للتصحيح. لا يؤثر ذلك في الجزء النظري. هل أنت متأكد من المتابعة؟' : 'سيتم إرسال علامات الجزء النظري إلى هيئة الامتحانات. بعد التأكيد لن تتمكن من تعديل علامات هذا الجزء إلا إذا أعادته هيئة الامتحانات للتصحيح. لن تتغير علامات الجزء العملي. هل أنت متأكد من المتابعة؟'}</p></div></div><div className="flex gap-3 mt-6"><button onClick={onCancel} disabled={submitting} className="px-5 py-2.5 border border-primary/20 rounded-[10px] text-[13px] font-bold">إلغاء</button><button onClick={onConfirm} disabled={submitting} className="px-5 py-2.5 bg-amber-600 text-white rounded-[10px] text-[13px] font-bold inline-flex items-center gap-2">{submitting && <FaSpinner className="animate-spin" />}تأكيد وإرسال</button></div></div></div> }
function Spinner() { return <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div> }
function Badge({ text }) { return <span className="inline-block mt-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 border border-red-200 text-[10px] font-bold">{text}</span> }
function Notice({ notice }) { return <div className={`border rounded-[12px] px-5 py-3 mb-4 text-[13px] ${notice.type === 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-600'}`} dir="rtl">{notice.text}</div> }
function Alert({ text }) { return <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {text}</div> }
function RetryAlert({ text, disabled, onRetry }) { return <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 flex flex-wrap items-center justify-between gap-3 text-[13px] text-red-600" dir="rtl"><span>⚠ {text}</span><button type="button" disabled={disabled} onClick={onRetry} className="px-4 py-2 bg-white border border-red-200 rounded-[9px] font-bold disabled:opacity-50">إعادة المحاولة</button></div> }
function Empty({ text }) { return <p className="text-center text-[13px] text-text-light py-12" dir="rtl">{text}</p> }
