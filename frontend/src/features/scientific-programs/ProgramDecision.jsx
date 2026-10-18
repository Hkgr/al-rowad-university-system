import { useEffect, useRef, useState } from 'react'
import { apiRequest } from '../../services/apiClient'
import { Button, CatalogLookup, Field, Input, Notice } from '../scientific-courses/CatalogControls'
import CatalogConflict from '../scientific-courses/CatalogConflict'
import { catalogError } from '../scientific-courses/catalog'
import useCatalogMutation from '../scientific-courses/useCatalogMutation'
import { PROGRAM_API, programRead, programWrite, VERSION_LABELS } from './programs'

export default function ProgramDecision({ editor, busy, onDirty, onBusy, onBlocked, onUnauthorized, onSaved, onRestart }) {
  const [label, setLabel] = useState(''), [reason, setReason] = useState(''), [students, setStudents] = useState([])
  const [preview, setPreview] = useState(null), [previewError, setPreviewError] = useState(null), [checking, setChecking] = useState(false)
  const sequence = useRef(0), controller = useRef(null)
  useEffect(() => () => { sequence.current++; controller.current?.abort() }, [])
  const { kind, programId, versionId, baseline, actionPath, method = 'POST' } = editor
  const versionPath = `/${programId}/versions/${versionId}`
  const currentPath = kind === 'fix' ? `/${programId}/transition-preview` : versionId ? versionPath : `/${programId}`
  const mutation = useCatalogMutation({ currentPath, onSaved, onBusy, onBlocked, onUnauthorized, readCurrent: programRead, send: programWrite })
  const disabled = busy || mutation.blocked
  function select(next) { sequence.current++; controller.current?.abort(); setStudents(next); setPreview(null); setPreviewError(null); setChecking(false); onDirty(true) }
  async function inspectTransfer() {
    if (!students.length || disabled || checking) return
    const token = ++sequence.current, abort = new AbortController(); controller.current = abort
    setChecking(true); setPreviewError(null)
    try {
      const r = await apiRequest(PROGRAM_API + versionPath + '/transfer-preview', { method: 'POST', signal: abort.signal, body: JSON.stringify({ student_ids: students.map(s => s.id) }) })
      if (sequence.current === token) {
        if (!r?.success || !Array.isArray(r.data?.students)) throw new Error('معاينة النقل غير مكتملة.')
        setPreview(r.data)
      }
    } catch (e) {
      if (sequence.current === token && !abort.signal.aborted) {
        if ([401, 403].includes(e.status)) onUnauthorized()
        else setPreviewError(catalogError(e))
      }
    } finally { if (sequence.current === token) setChecking(false) }
  }
  function confirm() {
    if (disabled || (kind === 'transfer' && (!preview?.can_transfer || !reason.trim()))) return
    mutation.write(actionPath, method, kind === 'copy' ? { revision: baseline.revision, label: label.trim() }
      : kind === 'transfer' ? { revision: preview.revision, confirmed: true, reason: reason.trim(), student_ids: students.map(s => s.id) }
        : { revision: baseline.revision, confirmed: true })
  }
  return <div className="space-y-4">
    <p>{editor.message}</p>
    {baseline.version && <Notice>{baseline.version.label} — {VERSION_LABELS[baseline.version.status] || 'حالة غير معروفة'}</Notice>}
    {kind === 'fix' && <dl className="grid gap-2 sm:grid-cols-3">{[['student_count', 'الطلاب'], ['course_count', 'المواد'], ['group_count', 'المجموعات']].map(([key, title]) => <div key={key}><dt>{title}</dt><dd>{baseline[key]}</dd></div>)}</dl>}
    {kind === 'fix' && <details><summary>المواد التي ستُثبت دون تغيير</summary><ul>{baseline.courses?.map(c => <li key={c.program_course_id}>{c.course?.course_name || 'مادة غير متاحة'} ({c.course?.course_code || 'غير محدد'}) — {SCOPE_LABEL[c.requirement_mapping?.requirement_group?.requirement_scope] || 'تصنيف غير محدد'} / {TYPES_LABEL[c.course_type] || 'غير محدد'}</li>)}</ul></details>}
    {kind === 'fix' && <><Notice>سيُحفظ الوضع الحالي بمعرّفاته ودلالاته، لا بوصفه اعتمادًا تاريخيًا. نواقص المتطلبات لا تُملأ تلقائيًا. يبقى القبول الجديد موقوفًا حتى تعيين خطة معتمدة صراحةً.</Notice><p>إجمالي ساعات التخرج: {baseline.total_credit_hours ?? 'لم يحدد'}</p><ul>{baseline.groups?.map(g => <li key={g.requirement_group_id}>{SCOPE_LABEL[g.requirement_scope]} — {TYPES_LABEL[g.requirement_type]}: {g.required_credit_hours ?? 'لم يحدد'}{!g.is_active && ' — غير فعال'}</li>)}</ul><details><summary>سياق العمليات الذي سيُحفظ</summary><ul>{Object.entries(baseline.operation_reference_counts || {}).map(([key, count]) => <li key={key}>{REFERENCE_LABEL[key]}: {count}</li>)}</ul></details>{baseline.blockers?.map(text => <Notice key={text} error>{text}</Notice>)}</>}
    {kind === 'delete' && <ul>{Object.entries(baseline.counts || {}).filter(([, n]) => n > 0).map(([k, n]) => <li key={k}>ارتباطات مانعة: {n}</li>)}</ul>}
    {kind === 'copy' && <Field label="اسم النسخة الجديدة"><Input disabled={disabled} value={label} maxLength={150} onChange={e => { setLabel(e.target.value); onDirty(true) }} /></Field>}
    {kind === 'transfer' && <>
      <Notice>النقل إجراء مستقل. اختَر الطلاب صراحةً؛ لا تُستنتج دفعة من أرقامهم. يتطلب تسوية العمليات الجارية ولا يغير التسجيلات أو القرارات السابقة.</Notice>
      <CatalogLookup loader={programRead} label="إضافة طالب إلى المعاينة" resource="students" context={{ academic_program_id: programId }} disabled={disabled || students.length >= 50} clearLabel="اختر طالبًا" onChange={s => { if (s && !students.some(row => row.id === s.id)) select([...students, s]) }} />
      <ul>{students.map(s => <li key={s.id} className="flex items-center justify-between gap-3"><span>{s.label}</span><Button disabled={disabled} onClick={() => select(students.filter(row => row.id !== s.id))}>إزالة من المعاينة</Button></li>)}</ul>
      <Button disabled={disabled || checking || !students.length} onClick={inspectTransfer}>{checking ? 'حساب الأثر من السجل الرسمي…' : 'معاينة أثر النقل'}</Button>
      <Notice error>{previewError}</Notice>
      {preview?.students.map(row => <section key={row.student_id} className="space-y-2 rounded-[12px] border border-primary/15 p-3"><h3 className="font-bold">{students.find(s => Number(s.id) === Number(row.student_id))?.label || 'الطالب المختار'}</h3>
        <div className="overflow-x-auto"><table className="w-full text-right text-[12px]"><thead><tr><th>الأثر</th><th>الخطة الحالية</th><th>الخطة المستهدفة</th></tr></thead><tbody>{[['counted_hours', 'الساعات المحتسبة'], ['required_hours', 'المطلوب للتخرج'], ['remaining_hours', 'المتبقي للتخرج']].map(([key, title]) => <tr key={key}><th>{title}</th><td>{row.before?.available ? row.before[key] : 'غير متاح'}</td><td>{row.after?.available ? row.after[key] : 'غير متاح'}</td></tr>)}</tbody></table></div>
        {['before', 'after'].map(side => <details key={side}><summary>{side === 'before' ? 'تفاصيل الخطة الحالية' : 'تفاصيل الخطة المستهدفة'}</summary><ul>{row[side]?.counted_courses?.map(c => <li key={c.course_id}>{c.course_label || `مادة ${c.course_id}`} — {c.credit_hours} ساعات — {TYPES_LABEL[c.requirement_type] || c.requirement_type}</li>)}</ul><p>خارج المتطلبات: {row[side]?.outside_courses?.map(c => c.course_label || `مادة ${c.course_id}`).join('، ') || 'لا يوجد'}</p></details>)}
        {row.blockers?.map(text => <Notice error key={text}>{text}</Notice>)}
      </section>)}
      <Field label="سبب النقل"><Input disabled={disabled} value={reason} onChange={e => { setReason(e.target.value); onDirty(true) }} /></Field>
    </>}
    <CatalogConflict mutation={mutation} onRestart={onRestart} />
    <Button primary disabled={disabled || (kind === 'copy' && !label.trim()) || (kind === 'transfer' && (!preview?.can_transfer || !reason.trim())) || (kind === 'fix' && !baseline.can_fix) || (kind === 'delete' && !baseline.can_delete)} onClick={confirm}>تأكيد الإجراء</Button>
  </div>
}
const TYPES_LABEL = { mandatory: 'إجباري', elective: 'اختياري' }
const SCOPE_LABEL = { university: 'متطلبات الجامعة', college: 'متطلبات الكلية', department: 'متطلبات القسم' }
const REFERENCE_LABEL = { student_course_registrations: 'التسجيلات الرسمية', student_registration_requests: 'طلبات التسجيل', student_registration_modification_requests: 'طلبات التعديل', student_registration_replacement_requests: 'طلبات الاستبدال', student_progression_decisions: 'قرارات الترفيع', student_graduation_decisions: 'قرارات التخرج' }
