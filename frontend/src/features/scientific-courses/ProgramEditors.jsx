import { useState } from 'react'
import { SCOPES, TYPES } from './catalog'
import { Button, CatalogDialog, CatalogLookup, Field, Input, Notice, Select } from './CatalogControls'
import CatalogConflict from './CatalogConflict'
import useCatalogMutation from './useCatalogMutation'

const scopes = Object.entries(SCOPES).filter(([key]) => key !== 'unclassified')
const types = Object.entries(TYPES).filter(([key]) => key !== 'unclassified')
export function RequirementGroupEditor({ baseline, onDirty, onBusy, onBlocked, onSaved, onRestart, onUnauthorized, busy }) {
  const [groups, setGroups] = useState(() => baseline.groups.map(g => ({ ...g })))
  const [total, setTotal] = useState(baseline.data.total_credit_hours), [confirm, setConfirm] = useState(false)
  const mutation = useCatalogMutation({ currentPath: `/programs/${baseline.data.academic_program_id}`, onSaved, onBusy, onBlocked, onUnauthorized })
  const disabled = busy || mutation.blocked || !baseline.capabilities.edit_curriculum
  const update = (i, key, value) => { setGroups(rows => rows.map((r, n) => n === i ? { ...r, [key]: value } : r)); onDirty(true) }
  function save() {
    setConfirm(false)
    mutation.write(`/programs/${baseline.data.academic_program_id}/requirement-groups`, 'PUT', {
      revision: baseline.revision, confirmed: true, total_credit_hours: Number(total),
      groups: groups.map(g => ({ ...(g.requirement_group_id ? { requirement_group_id: g.requirement_group_id } : {}),
        requirement_scope: g.requirement_scope, requirement_type: g.requirement_type,
        required_credit_hours: Number(g.required_credit_hours), is_active: !!g.is_active })),
    })
  }
  return <section aria-label="متطلبات التخرج" className="space-y-4">
    <h3 className="text-[14px] font-bold text-text-dark">{baseline.data.program_name}</h3>
    <Notice>{baseline.capabilities.lock_reason}</Notice>
    {!baseline.configuration.available && <Notice>متطلبات هذا البرنامج غير مكتملة أو متعارضة. راجع التصنيفات والساعات المطلوبة والمواد المرتبطة.</Notice>}
    <form onSubmit={e => { e.preventDefault(); if (!disabled) setConfirm(true) }} className="space-y-4">
      <fieldset disabled={disabled} className="space-y-4">
        <Field label="إجمالي الساعات المطلوبة للتخرج" error={mutation.fields.total_credit_hours}>
          <Input name="total_credit_hours" type="number" dir="ltr" min="1" step="1" required value={total ?? ''} onChange={e => { setTotal(e.target.value); onDirty(true) }} />
        </Field>
        <div className="overflow-x-auto rounded-[12px] border border-primary/12">
          <table className="w-full text-[12.5px] text-right"><thead className="bg-primary/[0.05] text-text-dark"><tr>{['مستوى المتطلب', 'النوع', 'الساعات المطلوبة', 'ساعات المواد المتاحة', 'الحالة'].map(h => <th key={h} className="px-3 py-2.5 font-bold">{h}</th>)}</tr></thead>
            <tbody>{groups.map((g, i) => <tr key={g.requirement_group_id || `new-${i}`} className="border-t border-primary/10">
              <td className="p-2">{g.requirement_group_id ? SCOPES[g.requirement_scope] : <Select aria-label={`مستوى المتطلب ${i + 1}`} value={g.requirement_scope} onChange={e => update(i, 'requirement_scope', e.target.value)}>{scopes.map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select>}</td>
              <td className="p-2">{g.requirement_group_id ? TYPES[g.requirement_type] : <Select aria-label={`النوع ${i + 1}`} value={g.requirement_type} onChange={e => update(i, 'requirement_type', e.target.value)}>{types.map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select>}</td>
              <td className="p-2"><Input aria-label={`الساعات المطلوبة ${i + 1}`} type="number" dir="ltr" min="0" step="1" required value={g.required_credit_hours} onChange={e => update(i, 'required_credit_hours', e.target.value)} /></td>
              <td className="p-2 text-center">{g.available_credit_hours ?? '—'}</td>
              <td className="p-2"><label className="whitespace-nowrap"><input type="checkbox" checked={!!g.is_active} onChange={e => update(i, 'is_active', e.target.checked)} /> نشط</label>{!g.requirement_group_id && <Button onClick={() => { setGroups(rows => rows.filter((_, n) => i !== n)); onDirty(true) }}>إزالة</Button>}</td>
            </tr>)}</tbody></table>
        </div>
        {!groups.length && <p>لم تُعد متطلبات التخرج لهذا البرنامج بعد.</p>}
        {groups.length < 6 && <Button onClick={() => { const pair = scopes.flatMap(([scope]) => types.map(([type]) => ({ scope, type }))).find(p => !groups.some(g => g.requirement_scope === p.scope && g.requirement_type === p.type)); if (pair) { setGroups(rows => [...rows, { requirement_scope: pair.scope, requirement_type: pair.type, required_credit_hours: '', is_active: true }]); onDirty(true) } }}>إضافة تصنيف</Button>}
      </fieldset>
      <p className="text-[12px] text-text-light">الساعات المتاحة هي مجموع ساعات المواد التي يمكن الاختيار منها، وقد تزيد على الساعات المطلوبة للتخرج.</p>
      <CatalogConflict mutation={mutation} onRestart={onRestart} />
      {baseline.capabilities.edit_curriculum && <Button primary type="submit" disabled={disabled || !groups.length}>حفظ متطلبات التخرج</Button>}
    </form>
    {confirm && <CatalogDialog title="تأكيد متطلبات التخرج" onClose={() => setConfirm(false)}><p>حفظ الساعات المحددة للبرنامج {baseline.data.program_name}؟ إضافة المواد لاحقًا لن تغيّر هذه الساعات تلقائيًا.</p><div className="flex gap-2"><Button primary onClick={save}>تأكيد</Button><Button onClick={() => setConfirm(false)}>إلغاء</Button></div></CatalogDialog>}
  </section>
}

export function MembershipEditor({ baseline, course, onDirty, onBusy, onBlocked, onSaved, onRestart, onUnauthorized, onRequirements, busy }) {
  const pc = course.program_courses?.find(p => String(p.academic_program_id) === String(baseline.data.academic_program_id))
  const [draft, setDraft] = useState(() => ({ course_type: pc?.course_type || 'mandatory', requirement_scope: pc?.requirement_classification?.requirement_scope || 'university', academic_level: pc?.academic_level_id ? { id: pc.academic_level_id, label: pc.academic_level?.level_name || 'المستوى الحالي' } : null, semester: pc?.recommended_semester_id ? { id: pc.recommended_semester_id, label: pc.recommended_semester?.semester_name || 'الفصل الحالي' } : null, is_active: pc?.is_active ?? true }))
  const [confirm, setConfirm] = useState(null), path = `/programs/${baseline.data.academic_program_id}`
  const mutation = useCatalogMutation({ currentPath: path, onSaved, onBusy, onBlocked, onUnauthorized })
  const disabled = busy || mutation.blocked || !baseline.capabilities.edit_curriculum
  const matches = baseline.groups.filter(g => g.is_active && g.requirement_scope === draft.requirement_scope && g.requirement_type === draft.course_type)
  function update(key, value) { setDraft(d => ({ ...d, [key]: value })); onDirty(true) }
  function save() {
    const action = confirm; setConfirm(null)
    mutation.write(`${path}/courses/${course.course_id}`, action === 'unlink' ? 'DELETE' : 'PUT', action === 'unlink' ? { revision: baseline.revision, confirmed: true } : { revision: baseline.revision, course_type: draft.course_type, requirement_scope: draft.requirement_scope, academic_level_id: Number(draft.academic_level?.id), recommended_semester_id: Number(draft.semester?.id), is_active: !!draft.is_active })
  }
  return <section className="space-y-4" aria-label="إضافة مادة للبرنامج">
    <div><h3 className="text-[14px] font-bold text-text-dark">{course.course_name} <span dir="ltr">({course.course_code})</span></h3><p>{baseline.data.program_name} · {course.credit_hours} ساعات معتمدة</p></div>
    <Notice>{baseline.capabilities.lock_reason}</Notice>
    <form onSubmit={e => { e.preventDefault(); if (!disabled && matches.length === 1) setConfirm('save') }} className="space-y-4">
      <fieldset disabled={disabled} className="grid gap-3 sm:grid-cols-2">
        <Field label="مستوى المتطلب"><Select value={draft.requirement_scope} onChange={e => update('requirement_scope', e.target.value)}>{scopes.map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select></Field>
        <Field label="نوع المتطلب"><Select value={draft.course_type} onChange={e => update('course_type', e.target.value)}>{types.map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select></Field>
        <CatalogLookup label="المستوى الإرشادي" resource="levels" disabled={disabled} clearLabel="اختر المستوى" value={draft.academic_level} onChange={o => update('academic_level', o)} />
        <CatalogLookup label="الفصل الإرشادي" resource="semesters" disabled={disabled} clearLabel="اختر الفصل" value={draft.semester} onChange={o => update('semester', o)} />
        <label><input type="checkbox" checked={!!draft.is_active} onChange={e => update('is_active', e.target.checked)} /> مادة نشطة في البرنامج</label>
      </fieldset>
      {matches.length !== 1 ? <Notice>إعداد هذا التصنيف غير مكتمل أو متعارض.{baseline.capabilities.edit_curriculum && <Button onClick={onRequirements}>إعداد متطلبات التخرج</Button>}</Notice> : <p className="text-[12px] text-text-light">{SCOPES[draft.requirement_scope]} — {TYPES[draft.course_type]}. يخص هذا التصنيف البرنامج المحدد فقط، ولا يغيّر ساعات التخرج المطلوبة.</p>}
      <CatalogConflict mutation={mutation} onRestart={onRestart} />
      {baseline.capabilities.edit_curriculum && <div className="flex flex-wrap gap-2"><Button primary type="submit" disabled={disabled || !draft.academic_level || !draft.semester || matches.length !== 1}>{pc ? 'حفظ التصنيف' : 'إضافة للبرنامج'}</Button>{pc && <Button danger disabled={disabled} onClick={() => setConfirm('unlink')}>إزالة من البرنامج</Button>}</div>}
    </form>
    {confirm && <CatalogDialog title={confirm === 'unlink' ? 'إزالة من البرنامج' : 'تأكيد التصنيف'} onClose={() => setConfirm(null)}><p>{course.course_name} — {baseline.data.program_name}. لن تُحذف المادة من الدليل أو تتغير في البرامج الأخرى.</p><div className="flex gap-2"><Button primary onClick={save}>تأكيد</Button><Button onClick={() => setConfirm(null)}>إلغاء</Button></div></CatalogDialog>}
  </section>
}
