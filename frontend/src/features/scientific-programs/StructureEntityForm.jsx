import { useState } from 'react'
import { Button, CatalogLookup, Field, Input, Notice, Select } from '../scientific-courses/CatalogControls'
import CatalogConflict from '../scientific-courses/CatalogConflict'
import useCatalogMutation from '../scientific-courses/useCatalogMutation'
import { entityRead, entityWrite } from './entities'
import { programRead } from './programs'

const unitOptions = (path, signal) => entityRead(`/organizational-units?${path.split('?')[1]?.replace(/(?:^|&)resource=[^&]*/g, '').replace(/^&/, '') || ''}`, signal)
export default function StructureEntityForm({ kind, baseline, guard, onSaved, onRestart, onUnauthorized, initialCollege }) {
  const prefix = kind === 'colleges' ? 'college' : 'department', entity = baseline.entity || {}, id = entity[`${prefix}_id`]
  const [draft, setDraft] = useState({ name: entity[`${prefix}_name`] || '', code: entity[`${prefix}_code`] || '', description: entity.description || '', is_active: entity.is_active ?? true,
    college: entity.college ? { id: entity.college_id, label: entity.college.college_name } : initialCollege || null,
    unit: entity.organizational_unit ? { id: entity.organizational_unit_id, label: entity.organizational_unit.unit_name } : null })
  const mutation = useCatalogMutation({ currentPath: id ? `/${kind}/${id}` : `/${kind}`, readCurrent: entityRead, send: entityWrite,
    onSaved, onUnauthorized, onBusy: guard.onBusy, onBlocked: guard.onBlocked })
  const disabled = guard.busy || mutation.blocked, locked = id && !baseline.capabilities.edit_relationships
  const update = (field, value) => { setDraft(d => ({ ...d, [field]: value })); guard.onDirty(true) }
  return <form className="space-y-4" onSubmit={event => { event.preventDefault(); if (disabled) return; mutation.write(id ? `/${kind}/${id}` : `/${kind}`, id ? 'PATCH' : 'POST', {
    revision: baseline.revision, [`${prefix}_name`]: draft.name, [`${prefix}_code`]: draft.code, description: draft.description, is_active: draft.is_active,
    ...(!locked ? { organizational_unit_id: draft.unit?.id ?? null, ...(kind === 'departments' ? { college_id: draft.college?.id } : {}) } : {}),
  }) }}>
    <div className="grid gap-3 sm:grid-cols-2"><Field label={kind === 'colleges' ? 'اسم الكلية أو المعهد' : 'اسم القسم'} error={mutation.fields[`${prefix}_name`]}><Input required maxLength={200} disabled={disabled} value={draft.name} onChange={e => update('name', e.target.value)} /></Field><Field label="الرمز" error={mutation.fields[`${prefix}_code`]}><Input required maxLength={50} dir="ltr" disabled={disabled} value={draft.code} onChange={e => update('code', e.target.value)} /></Field></div>
    <Field label="الوصف" error={mutation.fields.description}><Input disabled={disabled} value={draft.description} onChange={e => update('description', e.target.value)} /></Field>
    {kind === 'departments' && <div><CatalogLookup label="الكلية أو المعهد" resource="colleges" loader={programRead} value={draft.college} onChange={v => update('college', v)} disabled={disabled || locked || !!initialCollege} clearLabel="اختر الكلية" /><p className="text-[11.5px] text-text-light">{baseline.capabilities?.relationship_lock_reason}</p></div>}
    <div><CatalogLookup label="الوحدة التنظيمية المسجلة" resource="organizational-units" loader={unitOptions} value={draft.unit} onChange={v => update('unit', v)} disabled={disabled || locked} clearLabel="دون ربط بوحدة" /><p className="text-[11.5px] text-text-light">{locked ? baseline.capabilities.relationship_lock_reason : 'اختيار من الوحدات الموجودة فقط؛ لا تُنشأ وحدة تلقائيًا.'}</p></div>
    <Field label="الحالة"><Select disabled={disabled} value={draft.is_active ? '1' : '0'} onChange={e => update('is_active', e.target.value === '1')}><option value="1">فعّال</option><option value="0">غير فعّال</option></Select></Field>
    <Notice>{baseline.capabilities?.edit_lock_reason}</Notice><CatalogConflict mutation={mutation} onRestart={onRestart} />
    <Button primary type="submit" disabled={disabled || (kind === 'departments' && !draft.college)}>{guard.busy ? 'جارٍ الحفظ…' : 'حفظ البيانات'}</Button>
  </form>
}
