import { useState } from 'react'
import { Button, CatalogLookup, Field, Input, Notice, Select } from '../scientific-courses/CatalogControls'
import CatalogConflict from '../scientific-courses/CatalogConflict'
import useCatalogMutation from '../scientific-courses/useCatalogMutation'
import { SCOPES, TYPES, programRead, programWrite, requirementsPayload, sixGroups, budgetDraftSummary } from './programs'

export default function ProgramForms({ editor, busy, onDirty, onBusy, onBlocked, onUnauthorized, onSaved, onRestart, onConfigure }) {
  const { baseline, kind, programId, versionId } = editor
  const versionPath = `/${programId}/versions/${versionId}`
  const currentPath = versionId ? versionPath : programId ? `/${programId}` : '/'
  const mutation = useCatalogMutation({ currentPath, onSaved, onBusy, onBlocked, onUnauthorized, readCurrent: programRead, send: programWrite })
  const [draft, setDraft] = useState(() => {
    if (editor.draft) return editor.draft
    if (kind === 'requirements') return { groups: sixGroups(baseline.groups), total_credit_hours: baseline.version.total_credit_hours ?? '' }
    if (kind === 'membership') {
      const pc = editor.membership
      return { course: pc ? { id: pc.course_id, label: `${pc.course?.course_name} (${pc.course?.course_code})` } : null,
        requirement_scope: pc?.requirement_mapping?.requirement_group?.requirement_scope || 'university', course_type: pc?.course_type || 'mandatory',
        level: pc?.academic_level_id ? { id: pc.academic_level_id, label: pc.academic_level?.level_name } : null,
        semester: pc?.recommended_semester_id ? { id: pc.recommended_semester_id, label: pc.recommended_semester?.semester_name } : null,
        is_active: pc?.is_active ?? true }
    }
    const p = baseline.program || {}
    return { program_code: p.program_code || '', program_name: p.program_name || '', degree_level: p.degree_level || '', duration_years: p.duration_years ?? '',
      total_credit_hours: p.total_credit_hours ?? '', description: p.description || '', department: p.department_id ? { id: p.department_id, label: p.department?.department_name } : null }
  })
  const disabled = busy || mutation.blocked
  const budget = kind === 'requirements' ? budgetDraftSummary(draft) : null
  function update(key, value) { setDraft(d => ({ ...d, [key]: value })); onDirty(true) }
  const locked = kind === 'program' && programId && !baseline.capabilities.edit_academic
  const lockReason = locked ? baseline.capabilities.academic_lock_reason : null
  const groupAvailable = kind !== 'membership' || baseline.groups.filter(g => g.is_active && g.requirement_scope === draft.requirement_scope && g.requirement_type === draft.course_type).length === 1
  function save(e) {
    e.preventDefault()
    if (disabled) return
    if (kind === 'requirements') mutation.write(`${versionPath}/requirements`, 'PUT', requirementsPayload(draft, baseline.revision))
    else if (kind === 'membership') {
      if (!draft.course || !groupAvailable) return
      mutation.write(`${versionPath}/courses/${draft.course.id}`, 'PUT', { revision: baseline.revision, requirement_scope: draft.requirement_scope, course_type: draft.course_type,
        academic_level_id: draft.level?.id ?? null, recommended_semester_id: draft.semester?.id ?? null, is_active: draft.is_active })
    } else {
      const fields = locked ? { program_name: draft.program_name, description: draft.description } : { program_name: draft.program_name, description: draft.description,
        program_code: draft.program_code, degree_level: draft.degree_level, duration_years: Number(draft.duration_years), total_credit_hours: Number(draft.total_credit_hours), department_id: draft.department?.id }
      mutation.write(programId ? `/${programId}` : '/', programId ? 'PATCH' : 'POST', { revision: baseline.revision, ...fields })
    }
  }
  return <form onSubmit={save} className="space-y-4">
    {kind === 'program' && <>
      <div className="grid gap-3 sm:grid-cols-2"><Field label="اسم البرنامج"><Input required disabled={disabled} value={draft.program_name} onChange={e => update('program_name', e.target.value)} /></Field><Field label="الوصف"><Input disabled={disabled} value={draft.description} onChange={e => update('description', e.target.value)} /></Field></div>
      <div className="grid gap-3 sm:grid-cols-2">{[['program_code', 'رمز البرنامج', 'text'], ['degree_level', 'الدرجة العلمية', 'text'], ['duration_years', 'المدة بالسنوات', 'number'], ['total_credit_hours', 'إجمالي ساعات البرنامج', 'number']].map(([key, label, type]) => <Field key={key} label={label} note={lockReason}><Input type={type} min={type === 'number' ? 1 : undefined} required disabled={disabled || locked} value={draft[key]} onChange={e => update(key, e.target.value)} /></Field>)}<div><CatalogLookup loader={programRead} resource="departments" label="القسم" clearLabel="اختر القسم" value={draft.department} onChange={o => update('department', o)} disabled={disabled || locked} /><p className="mt-1 text-[11.5px] text-text-light">{lockReason}</p></div></div>
      {!programId && <Notice>سيبدأ البرنامج في مرحلة تهيئة الخطط؛ لا يفتح القبول الجديد حتى اعتماد خطة وتعيينها للطلاب الجدد صراحةً.</Notice>}
    </>}
    {kind === 'requirements' && <>
      <Notice>الحقل الفارغ يعني «لم يحدد». الصفر قيمة صريحة. حفظ المسودة لا يعتمدها، وإضافة مادة لا تعدّل هذه الساعات.</Notice>
      <Field label="إجمالي ساعات التخرج للخطة"><Input type="number" min="1" disabled={disabled} value={draft.total_credit_hours} onChange={e => update('total_credit_hours', e.target.value)} /></Field>
      <Notice>معاينة حقول المسودة — المجموع: {budget.sum ?? 'لم يكتمل'}؛ الفرق عن الإجمالي: {budget.difference ?? 'لم يحدد'}. الاعتماد يخضع للتحقق الرسمي من المواد والمتطلبات.</Notice>
      <div className="grid gap-3 sm:grid-cols-2">{draft.groups.map((g, i) => <Field key={`${g.requirement_scope}:${g.requirement_type}`} label={`${SCOPES[g.requirement_scope]} — ${TYPES[g.requirement_type]}`} note={g.ambiguous ? 'تعريف متكرر؛ يلزم مراجعته.' : undefined}><Input type="number" min="0" placeholder="لم يحدد" disabled={disabled || g.ambiguous} value={g.required_credit_hours} onChange={e => update('groups', draft.groups.map((row, n) => n === i ? { ...row, required_credit_hours: e.target.value } : row))} /></Field>)}</div>
    </>}
    {kind === 'membership' && <>
      <CatalogLookup loader={programRead} resource="courses" label="المادة — الاسم والرمز" clearLabel="اختر المادة" value={draft.course} onChange={o => update('course', o)} disabled={disabled || !!editor.membership} />
      <div className="grid gap-3 sm:grid-cols-2"><Field label="مستوى المتطلب"><Select disabled={disabled} value={draft.requirement_scope} onChange={e => update('requirement_scope', e.target.value)}>{Object.entries(SCOPES).map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select></Field><Field label="نوع المتطلب"><Select disabled={disabled} value={draft.course_type} onChange={e => update('course_type', e.target.value)}>{Object.entries(TYPES).map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select></Field>
        <CatalogLookup loader={programRead} resource="levels" label="المستوى الإرشادي" clearLabel="غير محدد" value={draft.level} onChange={o => update('level', o)} disabled={disabled} /><CatalogLookup loader={programRead} resource="semesters" label="الفصل الإرشادي" clearLabel="غير محدد" value={draft.semester} onChange={o => update('semester', o)} disabled={disabled} />
      </div><label className="flex items-center gap-2"><input type="checkbox" disabled={disabled} checked={draft.is_active} onChange={e => update('is_active', e.target.checked)} /> نشطة في هذه الخطة</label>
      {!groupAvailable && <Notice>لم تُعد مجموعة هذا التصنيف في الخطة. سيبقى اختيار المادة محفوظًا أثناء إعداد المتطلبات.<Button disabled={disabled} onClick={() => onConfigure(draft)}>إعداد متطلبات التخرج</Button></Notice>}
    </>}
    <CatalogConflict mutation={mutation} onRestart={onRestart} />
    <Button primary type="submit" disabled={disabled || !groupAvailable || (kind === 'membership' && !draft.course)}>{busy ? 'جارٍ الحفظ…' : kind === 'requirements' ? 'حفظ متطلبات التخرج' : kind === 'membership' ? 'حفظ ارتباط المادة' : 'حفظ بيانات البرنامج'}</Button>
  </form>
}
