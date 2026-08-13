import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FaBook, FaCheck, FaChevronDown, FaExclamationTriangle, FaPaperPlane, FaSave, FaSpinner } from 'react-icons/fa'
import useMyOfferings from '../hooks/useMyOfferings'
import { getGradeSheet, getGradeWorkflow, saveRegistrationGrade, submitOfferingGrades } from '../lib/professorApi'

const WORKFLOW = {
  draft: { label: 'مسودة', style: 'bg-blue-50 text-blue-700 border-blue-200' },
  pending: { label: 'بانتظار مراجعة هيئة الامتحانات', style: 'bg-amber-50 text-amber-700 border-amber-200' },
  returned_for_correction: { label: 'معادة للتصحيح', style: 'bg-red-50 text-red-700 border-red-200' },
  approved: { label: 'تم اعتماد العلامات', style: 'bg-green-50 text-green-700 border-green-200' },
}
const ERROR_MESSAGES = {
  grades_locked: 'أُرسلت العلامات ولم تعد قابلة للتعديل.',
  grade_sheet_incomplete: 'بعض الطلاب ما زالت علاماتهم ناقصة أو غير صالحة.',
  no_eligible_students: 'لا يوجد طلاب مؤهلون لإدخال العلامات في هذه الشعبة.',
  not_primary_instructor: 'إدخال العلامات متاح لمدرس المادة الأساسي فقط.',
  grade_approval_status_missing: 'حالة اعتماد العلامات غير مهيأة في النظام. يرجى مراجعة الإدارة.',
}
const rowsFromSheet = sheet => sheet?.students ?? sheet?.data ?? []
const initialEdits = rows => Object.fromEntries(rows.map(row => [row.student_course_registration_id, {
  theoretical_mark: row.theoretical_mark ?? '', practical_mark: row.practical_mark ?? '', notes: row.notes ?? '', dirty: false,
}]))
const validation = edit => ({
  theoretical_mark: edit.theoretical_mark === '' ? 'العلامة النظرية مطلوبة' : Number.isFinite(Number(edit.theoretical_mark)) && Number(edit.theoretical_mark) >= 0 && Number(edit.theoretical_mark) <= 60 ? '' : 'يجب أن تكون بين 0 و60',
  practical_mark: edit.practical_mark === '' ? 'العلامة العملية مطلوبة' : Number.isFinite(Number(edit.practical_mark)) && Number(edit.practical_mark) >= 0 && Number(edit.practical_mark) <= 40 ? '' : 'يجب أن تكون بين 0 و40',
})
function apiMessage(error) { return ERROR_MESSAGES[error?.errorCode] || error?.message || 'تعذّر الاتصال بالخادم' }
function resultLabel(row) { return row.letter_grade || row.result_status?.status_name || row.result_status?.status_code || '—' }

export default function ProfessorGradesPage() {
  const { facultyMember, offerings, loading: offeringsLoading, error: offeringsError } = useMyOfferings()
  const [selectedId, setSelectedId] = useState(null)
  const [sheet, setSheet] = useState(null)
  const [workflow, setWorkflow] = useState(null)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [edits, setEdits] = useState({})
  const [saving, setSaving] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [notice, setNotice] = useState(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [incompleteIds, setIncompleteIds] = useState([])
  const selectedIdRef = useRef(null)
  const requestSequence = useRef(0)
  const rows = useMemo(() => rowsFromSheet(sheet), [sheet])
  const dirtyIds = useMemo(() => Object.keys(edits).filter(id => edits[id].dirty), [edits])
  const knownWorkflow = workflow && WORKFLOW[workflow.status]
  const globallyEditable = Boolean(knownWorkflow && workflow.editable)

  const loadGrades = useCallback(async (offeringId, resetEdits = true) => {
    const sequence = ++requestSequence.current
    setLoading(true); setLoadError(''); setIncompleteIds([])
    try {
      const [nextSheet, nextWorkflow] = await Promise.all([getGradeSheet(offeringId), getGradeWorkflow(offeringId)])
      if (sequence !== requestSequence.current || Number(selectedIdRef.current) !== Number(offeringId)) return
      setSheet(nextSheet); setWorkflow(nextWorkflow)
      if (resetEdits) setEdits(initialEdits(rowsFromSheet(nextSheet)))
    } catch (error) {
      if (sequence !== requestSequence.current || Number(selectedIdRef.current) !== Number(offeringId)) return
      setSheet(null); setWorkflow(null); setEdits({}); setLoadError(apiMessage(error))
    } finally {
      if (sequence === requestSequence.current && Number(selectedIdRef.current) === Number(offeringId)) setLoading(false)
    }
  }, [])

  useEffect(() => {
    selectedIdRef.current = selectedId
    if (!selectedId) { requestSequence.current += 1; setLoading(false); setSheet(null); setWorkflow(null); setEdits({}); setLoadError(''); return }
    loadGrades(selectedId)
  }, [selectedId, loadGrades])

  function selectOffering(offeringId) {
    if (saving || submitting) return
    selectedIdRef.current = offeringId
    requestSequence.current += 1
    setSheet(null); setWorkflow(null); setEdits({}); setLoadError(''); setNotice(null); setIncompleteIds([])
    setLoading(Boolean(offeringId))
    setSelectedId(offeringId)
  }

  function updateField(id, field, value) {
    setEdits(current => ({ ...current, [id]: { ...current[id], [field]: value, dirty: true } }))
    setNotice(null); setIncompleteIds(list => list.filter(item => Number(item) !== Number(id)))
  }

  async function refreshAfterSave(offeringId, successfulIds) {
    const sequence = ++requestSequence.current
    try {
      const [nextSheet, nextWorkflow] = await Promise.all([getGradeSheet(offeringId), getGradeWorkflow(offeringId)])
      if (sequence !== requestSequence.current || Number(selectedIdRef.current) !== Number(offeringId)) return
      const fresh = initialEdits(rowsFromSheet(nextSheet))
      setSheet(nextSheet); setWorkflow(nextWorkflow)
      setEdits(current => {
        const retainedDirtyIds = Object.keys(current).filter(id => current[id]?.dirty && !successfulIds.includes(id))
        const ids = new Set([...Object.keys(fresh), ...retainedDirtyIds])
        return Object.fromEntries([...ids].map(id => [
          id,
          successfulIds.includes(id) ? (fresh[id] ?? current[id]) : (current[id]?.dirty ? current[id] : fresh[id]),
        ]))
      })
    } catch (error) {
      if (sequence !== requestSequence.current || Number(selectedIdRef.current) !== Number(offeringId)) return
      setLoadError(apiMessage(error)); setWorkflow(null)
    }
  }

  async function handleSave() {
    const offeringId = selectedId
    const dirtyRows = rows.filter(row => edits[String(row.student_course_registration_id)]?.dirty && !row.is_deprived && row.grade_entry_allowed === true)
    const validRows = dirtyRows.filter(row => {
      const id = String(row.student_course_registration_id)
      return !validation(edits[id]).theoretical_mark && !validation(edits[id]).practical_mark
    })
    const invalidRows = dirtyRows.filter(row => !validRows.includes(row))
    if (!validRows.length) {
      setNotice({ type: 'error', text: `تم حفظ 0 سجل، وبقي ${invalidRows.length} سجل غير محفوظ.` })
      return
    }
    setSaving(true); setNotice(null)
    const results = await Promise.all(validRows.map(async row => {
      const id = String(row.student_course_registration_id); const edit = edits[id]
      try {
        await saveRegistrationGrade(id, { theoretical_mark: Number(edit.theoretical_mark), practical_mark: Number(edit.practical_mark), ...(edit.notes ? { notes: edit.notes } : {}) }, row.has_existing_grade === true)
        return { id, ok: true }
      } catch (error) { return { id, ok: false, error } }
    }))
    const failed = results.filter(result => !result.ok)
    const successfulIds = results.filter(result => result.ok).map(result => result.id)
    const locked = failed.find(result => result.error?.errorCode === 'grades_locked')
    await refreshAfterSave(offeringId, successfulIds)
    setSaving(false)
    if (Number(selectedIdRef.current) !== Number(offeringId)) return
    const unsavedCount = invalidRows.length + failed.length
    setNotice({ type: unsavedCount ? 'error' : 'success', text: `تم حفظ ${successfulIds.length} سجل، وبقي ${unsavedCount} سجل غير محفوظ.${failed[0] ? ` ${apiMessage(failed[0].error)}` : ''}` })
    if (locked) setLoadError(ERROR_MESSAGES.grades_locked)
  }

  async function handleSubmit() {
    const offeringId = selectedId
    setSubmitting(true); setNotice(null)
    try {
      await submitOfferingGrades(offeringId)
      setConfirmOpen(false); await loadGrades(offeringId)
      if (Number(selectedIdRef.current) !== Number(offeringId)) return
      setNotice({ type: 'success', text: 'تم إرسال العلامات إلى هيئة الامتحانات.' })
    } catch (error) {
      if (Number(selectedIdRef.current) !== Number(offeringId)) return
      if (error.errorCode === 'grade_sheet_incomplete') setIncompleteIds(error.details?.registration_ids ?? [])
      if (error.errorCode === 'grades_locked') await loadGrades(offeringId)
      setConfirmOpen(false); setNotice({ type: 'error', text: apiMessage(error) })
    } finally { setSubmitting(false) }
  }

  const canSubmit = globallyEditable && workflow?.can_submit === true && dirtyIds.length === 0 && !saving && !submitting
  const canSave = globallyEditable && dirtyIds.length > 0 && !saving && !submitting

  return <>
    <div className="mb-5" dir="rtl"><h2 className="text-[20px] font-black text-text-dark mb-[3px]">إدخال العلامات</h2><p className="text-[12.5px] text-text-light">Grades</p></div>
    {offeringsLoading && <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>}
    {!offeringsLoading && offeringsError && <Alert text={offeringsError} />}
    {!offeringsLoading && !offeringsError && !facultyMember && <Empty text="لم يتم العثور على سجل عضو هيئة تدريس مرتبط بحسابك" />}
    {!offeringsLoading && facultyMember && offerings.length === 0 && <Empty text="لا توجد مواد مسندة إليك حالياً" />}
    {!offeringsLoading && offerings.length > 0 && <div className="grid grid-cols-3 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1 gap-4 mb-6">
      {offerings.map(o => { const active = Number(selectedId) === Number(o.course_offering_id); return <button key={o.course_offering_id} disabled={saving || submitting} onClick={() => selectOffering(active ? null : o.course_offering_id)} className={`text-right bg-white border rounded-[16px] px-5 py-4 flex items-center gap-3 shadow-[0_2px_10px_rgba(26,46,16,0.05)] transition-all disabled:opacity-60 ${active ? 'border-primary' : 'border-primary/12 hover:enabled:-translate-y-[2px]'}`} dir="rtl">
        <span className="w-11 h-11 rounded-[11px] bg-primary/10 flex items-center justify-center text-primary flex-shrink-0"><FaBook /></span><span className="flex-1 min-w-0"><span className="block font-bold text-[13.5px] text-text-dark truncate">{o.course?.course_name || o.course_name || `مادة #${o.course_offering_id}`}</span><span className="block text-[11px] text-text-light font-mono">{o.course?.course_code || o.course_code}{o.section_number ? ` — شعبة ${o.section_number}` : ''}</span><span className="block text-[10.5px] text-text-light truncate">{o.academic_year?.year_name} {o.semester?.semester_name ? `— ${o.semester.semester_name}` : ''}</span></span><FaChevronDown className={`text-primary/50 text-[12px] ${active ? 'rotate-180' : ''}`} />
      </button> })}
    </div>}
    {selectedId && loading && <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>}
    {selectedId && !loading && loadError && <Alert text={`${loadError} تم قفل التحرير احترازياً.`} />}
    {selectedId && !loading && sheet && workflow && <>
      <div className={`border rounded-[14px] p-4 mb-4 ${knownWorkflow?.style || 'bg-gray-50 text-gray-700 border-gray-200'}`} dir="rtl">
        <div className="font-extrabold text-[14px]">{knownWorkflow?.label || 'حالة سير العلامات غير معروفة — التحرير مقفل'}</div>
        {workflow.status === 'pending' && <div className="text-[12px] mt-1 font-bold">مرسلة إلى هيئة الامتحانات</div>}
        {workflow.approval_notes && <div className="mt-2 bg-white/70 rounded-[9px] p-3 text-[12.5px]"><strong>ملاحظات هيئة الامتحانات:</strong> {workflow.approval_notes}</div>}
        {workflow.submitted_at && <div className="text-[11px] mt-2">وقت الإرسال: <span dir="ltr">{new Date(workflow.submitted_at).toLocaleString('ar-SY')}</span></div>}
        <div className="text-[11px] mt-2">المكتمل: {workflow.completed_students_count ?? '—'} / {workflow.eligible_students_count ?? '—'}</div>
      </div>
      {notice && <div className={`border rounded-[12px] px-5 py-3 mb-4 text-[13px] ${notice.type === 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-600'}`} dir="rtl">{notice.text}</div>}
      {rows.length === 0 ? <Empty text="لا يوجد طلاب مسجلون في هذه المادة" /> : <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)] mb-4"><div className="overflow-x-auto"><table className="w-full min-w-[850px] border-collapse text-[13px]" dir="rtl"><thead><tr className="bg-[#fafaf8]">{['الرقم الجامعي','اسم الطالب','النظري /60','العملي /40','المجموع','الحالة / التقدير','حالة الحفظ'].map(h => <th key={h} className="px-3 py-3 text-center text-[11px] font-bold text-text-light">{h}</th>)}</tr></thead><tbody>
        {rows.map(row => { const id = String(row.student_course_registration_id); const edit = edits[id] || {}; const errors = validation(edit); const editable = globallyEditable && row.grade_entry_allowed === true && !row.is_deprived; const total = edit.theoretical_mark !== '' && edit.practical_mark !== '' ? Number(edit.theoretical_mark) + Number(edit.practical_mark) : null; const incomplete = incompleteIds.some(item => Number(item) === Number(id)); return <tr key={id} className={`border-t border-primary/6 ${incomplete ? 'bg-red-50' : ''}`}>
          <td className="px-3 py-3 text-center font-mono text-[11px]">{row.student_number || '—'}</td><td className="px-3 py-3 text-right"><div className="font-semibold text-text-dark">{row.full_name || '—'}</div>{row.is_deprived && <Badge text="محروم" />}{!row.is_deprived && !row.grade_entry_allowed && <div className="text-[10px] text-amber-700 mt-1">{row.grade_entry_blocked_reason || 'غير متاح للإدخال'}</div>}</td>
          <MarkInput value={edit.theoretical_mark ?? ''} max={60} error={errors.theoretical_mark} showError={edit.dirty} disabled={!editable || saving || submitting} onChange={value => updateField(id, 'theoretical_mark', value)} />
          <MarkInput value={edit.practical_mark ?? ''} max={40} error={errors.practical_mark} showError={edit.dirty} disabled={!editable || saving || submitting} onChange={value => updateField(id, 'practical_mark', value)} />
          <td className="px-3 py-3 text-center font-bold">{total ?? '—'}</td><td className="px-3 py-3 text-center font-bold">{resultLabel(row)}</td><td className="px-3 py-3 text-center">{edit.dirty ? <span className="text-amber-700 font-bold text-[11px]">غير محفوظ</span> : <span className="text-green-700 font-bold text-[11px]"><FaCheck className="inline ml-1" />محفوظ</span>}</td>
        </tr> })}
      </tbody></table></div></div>}
      <div className="flex gap-3 flex-wrap" dir="rtl"><button onClick={handleSave} disabled={!canSave} className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40">{saving ? <FaSpinner className="animate-spin" /> : <FaSave />}حفظ المسودة</button><button onClick={() => setConfirmOpen(true)} disabled={!canSubmit} className="inline-flex items-center gap-2 px-5 py-2.5 bg-amber-600 text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40"><FaPaperPlane />تأكيد وإرسال لهيئة الامتحانات</button></div>
    </>}
    {confirmOpen && <div className="fixed inset-0 z-50 bg-black/45 flex items-center justify-center p-4" dir="rtl" role="dialog" aria-modal="true"><div className="bg-white rounded-[18px] max-w-[520px] w-full p-6 shadow-2xl"><div className="flex gap-3"><FaExclamationTriangle className="text-amber-500 text-[22px] flex-shrink-0" /><div><h3 className="font-black text-text-dark mb-2">تأكيد إرسال العلامات</h3><p className="text-[13px] leading-7 text-text-dark">سيتم إرسال علامات هذه المادة إلى هيئة الامتحانات لاعتمادها. بعد التأكيد لن تتمكن من تعديل العلامات، إلا إذا أعادتها هيئة الامتحانات للتصحيح. هل أنت متأكد من المتابعة؟</p></div></div><div className="flex gap-3 mt-6"><button onClick={() => setConfirmOpen(false)} disabled={submitting} className="px-5 py-2.5 border border-primary/20 rounded-[10px] text-[13px] font-bold">إلغاء</button><button onClick={handleSubmit} disabled={submitting} className="px-5 py-2.5 bg-amber-600 text-white rounded-[10px] text-[13px] font-bold inline-flex items-center gap-2">{submitting && <FaSpinner className="animate-spin" />}تأكيد وإرسال</button></div></div></div>}
  </>
}
function MarkInput({ value, max, error, showError, disabled, onChange }) { return <td className="px-3 py-3 align-top"><input type="number" min="0" max={max} step="0.5" value={value} disabled={disabled} onChange={e => onChange(e.target.value)} className={`block mx-auto w-[88px] px-2 py-2 border rounded-[8px] text-center outline-none disabled:bg-gray-100 ${showError && error ? 'border-red-400 bg-red-50' : 'border-primary/20 focus:border-primary'}`} dir="ltr" />{showError && error && <div className="text-[10px] text-red-500 text-center mt-1">{error}</div>}</td> }
function Badge({ text }) { return <span className="inline-block mt-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 border border-red-200 text-[10px] font-bold">{text}</span> }
function Alert({ text }) { return <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {text}</div> }
function Empty({ text }) { return <p className="text-center text-[13px] text-text-light py-12" dir="rtl">{text}</p> }
