import { useState } from 'react'
import { coursePayload, editableCourse } from './catalog'
import { Button, CatalogDialog, CatalogLookup, Field, Input, Notice } from './CatalogControls'
import CatalogConflict from './CatalogConflict'
import useCatalogMutation from './useCatalogMutation'
import useCatalogRead from './useCatalogRead'
import { distributionScope, distributionPath, emptyDistribution } from './associations'
import CourseDistributionFields from './CourseDistributionFields'

export default function CourseEditor({ baseline, onDirty, onBusy, onBlocked, onSaved, onRestart, onUnauthorized, busy, allowDistribution = true }) {
  const [draft, setDraft] = useState(() => editableCourse(baseline)), [confirm, setConfirm] = useState(null)
  const id = baseline.data?.course_id, caps = baseline.capabilities || { edit_text: true, edit_academic: true, edit_relationships: true, delete: false }
  const mutation = useCatalogMutation({ currentPath: id ? `/courses/${id}` : '/courses', onSaved, onBusy, onBlocked, onUnauthorized })
  const disabled = busy || mutation.blocked
  const [distribution, setDistribution] = useState(emptyDistribution), [previewEpoch, setPreviewEpoch] = useState(0)
  const preview = useCatalogRead(!id ? distributionPath(distribution) : null, { refresh: previewEpoch })
  const distributing = !id && !!distribution.scope
  const distributionReady = !distributing || (preview.data?.can_apply && distribution.level && distribution.semester)
  function payload() {
    return { ...coursePayload(draft, baseline), ...(distributing ? { distribution: distributionScope(distribution), distribution_confirmed: true,
      academic_level_id: Number(distribution.level.id), recommended_semester_id: Number(distribution.semester.id) } : {}) }
  }
  function change(field, value) { setDraft(d => ({ ...d, [field]: value })); onDirty(true) }
  const lock = caps.origin_lock_reason || caps.academic_lock_reason
  const relationsLock = caps.relationship_lock_reason || lock
  const save = () => { if (!distributionReady) return; if (distributing) setConfirm('distribution'); else if (baseline.impact?.shared) setConfirm('save'); else mutation.write(id ? `/courses/${id}` : '/courses', id ? 'PUT' : 'POST', payload()) }
  return <section className="space-y-4" aria-label="بيانات المادة">
    {baseline.impact?.shared && <Notice>تعديل بيانات هذه المادة يؤثر على البرامج المرتبطة بها ({baseline.impact.linked_program_count}).</Notice>}
    <form onSubmit={e => { e.preventDefault(); if (!disabled) save() }} className="space-y-4">
      {!id && allowDistribution && <CourseDistributionFields value={distribution} onChange={value => { setDistribution(value); onDirty(true) }} read={preview} disabled={disabled} retry={() => setPreviewEpoch(n => n + 1)} />}
      <fieldset disabled={disabled || !caps.edit_text} className="grid gap-3 sm:grid-cols-2"><Field label="اسم المادة" error={mutation.fields.course_name}><Input name="course_name" required maxLength={200} value={draft.course_name} onChange={e => change('course_name', e.target.value)} /></Field><Field label="الوصف" error={mutation.fields.description}><textarea name="description" className="rounded-[10px] border border-primary/20 px-3 py-2.5 text-[13.5px] leading-normal outline-none focus:border-primary" value={draft.description} onChange={e => change('description', e.target.value)} /></Field></fieldset>
      {lock && <Notice>{lock}</Notice>}
      <fieldset disabled={disabled || !caps.edit_academic} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><Field label="رمز المادة" error={mutation.fields.course_code}><Input name="course_code" dir="ltr" required maxLength={50} value={draft.course_code} onChange={e => change('course_code', e.target.value)} /></Field>{[['credit_hours', 'الساعات المعتمدة'], ['theoretical_hours', 'الساعات النظرية'], ['practical_hours', 'الساعات العملية']].map(([field, label]) => <Field key={field} label={label} error={mutation.fields[field]}><Input name={field} dir="ltr" type="number" min={field === 'credit_hours' ? 1 : 0} step="1" required={field === 'credit_hours'} value={draft[field]} onChange={e => change(field, e.target.value)} /></Field>)}<label className="flex items-center gap-2"><input type="checkbox" checked={!!draft.is_active} onChange={e => change('is_active', e.target.checked)} />مادة نشطة</label></fieldset>
      <fieldset disabled={disabled || !caps.edit_relationships} className="space-y-3 rounded-[12px] border border-primary/15 p-3"><legend className="font-bold text-[12.5px]">الأقسام المرتبطة</legend>{relationsLock && relationsLock !== lock && <Notice>{relationsLock}</Notice>}{draft.departments.map((d, i) => <div key={d.department_id} className="flex flex-wrap items-center justify-between gap-2"><span>{d.label}</span><label><input type="checkbox" checked={d.is_primary} onChange={e => change('departments', draft.departments.map((r, n) => ({ ...r, is_primary: n === i && e.target.checked })))} /> قسم أساسي</label><Button onClick={() => change('departments', draft.departments.filter((_, n) => n !== i))}>إزالة القسم</Button></div>)}<CatalogLookup label="إضافة قسم" resource="departments" clearLabel="اختر قسمًا" disabled={disabled || !caps.edit_relationships} onChange={o => { if (o && !draft.departments.some(d => d.department_id === o.id)) change('departments', [...draft.departments, { department_id: o.id, is_primary: false, label: o.label }]) }} /></fieldset>
      <details className="rounded-[12px] border border-primary/15 p-3"><summary className="cursor-pointer font-bold text-[12.5px]">المتطلبات السابقة ({draft.prerequisites.length})</summary><fieldset disabled={disabled || !caps.edit_relationships} className="mt-3 space-y-3">{draft.prerequisites.map((p, i) => <div key={p.prerequisite_course_id} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"><span>{p.label}</span><CatalogLookup label={`حد نتيجة ${p.label}`} resource="result_statuses" disabled={disabled || !caps.edit_relationships} clearLabel="دون حد إضافي" value={p.minimum_result_status_id ? { id: p.minimum_result_status_id, label: p.statusLabel || 'حالة النتيجة الحالية' } : null} onChange={o => change('prerequisites', draft.prerequisites.map((r, n) => n === i ? { ...r, minimum_result_status_id: o?.id ?? '', statusLabel: o?.label } : r))} /><Button onClick={() => change('prerequisites', draft.prerequisites.filter((_, n) => n !== i))}>إزالة المتطلب</Button></div>)}<CatalogLookup label="إضافة متطلب سابق" resource="courses" disabled={disabled || !caps.edit_relationships} clearLabel="اختر مادة" onChange={o => { if (o && String(o.id) !== String(id) && !draft.prerequisites.some(p => p.prerequisite_course_id === o.id)) change('prerequisites', [...draft.prerequisites, { prerequisite_course_id: o.id, minimum_result_status_id: '', label: o.label }]) }} /></fieldset></details>
      <CatalogConflict mutation={mutation} onRestart={onRestart} />
      <div className="flex flex-wrap gap-3">{caps.edit_text && <Button primary type="submit" disabled={disabled || !distributionReady}>{busy ? 'جارٍ الحفظ…' : id ? 'حفظ التعديلات' : 'حفظ المادة'}</Button>}</div>
    </form>
    {confirm && <CatalogDialog title={confirm === 'distribution' ? 'تأكيد إضافة المادة لجميع البرامج المحددة' : 'تأكيد تعديل مادة مشتركة'} onClose={() => setConfirm(null)}><p>{draft.course_name} ({draft.course_code}) — {confirm === 'distribution' ? `ستُحفظ المادة وتُربط بجميع البرامج المعروضة (${preview.data?.program_count}) ${distribution.course_type === 'mandatory' ? 'كمادة إجبارية' : 'كمادة اختيارية'}.` : 'سيظهر التعديل في البرامج المرتبطة.'} دون تغيير ساعات التخرج المطلوبة.</p><div className="flex gap-2"><Button primary disabled={disabled || !distributionReady} onClick={() => { setConfirm(null); mutation.write(id ? `/courses/${id}` : '/courses', id ? 'PUT' : 'POST', { ...payload(), ...(id ? { impact_confirmed: true } : {}) }) }}>تأكيد</Button><Button onClick={() => setConfirm(null)}>إلغاء</Button></div></CatalogDialog>}
  </section>
}
