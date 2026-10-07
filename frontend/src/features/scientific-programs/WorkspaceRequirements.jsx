import { Field, Input, Notice, Select } from '../scientific-courses/CatalogControls'
import { SCOPES, TYPES } from './programs'

/** Form presentation only. Server preserves activity and owns complete-plan approval. */
export default function WorkspaceRequirements({ draft, editable, onChange }) {
  const updateGroup = (index, field, value) => onChange({ ...draft, requirements: { ...draft.requirements,
    groups: draft.requirements.groups.map((row, i) => i === index ? { ...row, [field]: value } : row) } })
  return <div className="space-y-3"><h3 className="font-bold">متطلبات التخرج — {draft.program.program_name}</h3>
    <Notice>المطلوب للتخرج ليس مجموع كل المواد الاختيارية المتاحة. الفراغ غير محدد، والصفر قيمة صريحة؛ لا تُعدّل الميزانية تلقائيًا. يلزم للاعتماد إعداد المجموعات الست وتفعيلها صراحة؛ تعديل مادة لا يفعّل مجموعة غير فعالة.</Notice>
    <Field label="إجمالي ساعات التخرج"><Input type="number" min="1" value={draft.requirements.total_credit_hours ?? ''} disabled={!editable} onChange={e => onChange({ ...draft, requirements: { ...draft.requirements, total_credit_hours: e.target.value === '' ? null : Number(e.target.value) } })} /></Field>
    <div className="grid gap-3 sm:grid-cols-2">{draft.requirements.groups.map((g, i) => <div key={`${g.requirement_scope}:${g.requirement_type}`} className="space-y-2">
      <Field label={`${SCOPES[g.requirement_scope]} — ${TYPES[g.requirement_type]}`} note={g.required_credit_hours === null ? 'غير محدد؛ يلزم إدخال قيمة صريحة قبل الاعتماد.' : undefined}><Input type="number" min="0" value={g.required_credit_hours ?? ''} disabled={!editable} onChange={e => updateGroup(i, 'required_credit_hours', e.target.value === '' ? null : Number(e.target.value))} /></Field>
      {g.group_exists === false && !editable ? <Notice>المجموعة غير موجودة؛ لا تنشئها القراءة.</Notice> : <Field label={`حالة ${SCOPES[g.requirement_scope]} — ${TYPES[g.requirement_type]}`} note={g.is_active === false ? 'المجموعة غير فعالة. تفعيلها اختيار صريح يظهر في تأكيد الحفظ، ولا يغيّر مرجع الطلاب الحاليين.' : g.group_exists === false ? 'ستُنشأ مجموعة فعالة في الخطة الجديدة عند الحفظ؛ المرجع الحالي يبقى كما هو.' : undefined}><Select value={g.is_active ? '1' : '0'} disabled={!editable} onChange={e => updateGroup(i, 'is_active', e.target.value === '1')}><option value="1">فعالة</option><option value="0">غير فعالة</option></Select></Field>}
    </div>)}</div>
  </div>
}
