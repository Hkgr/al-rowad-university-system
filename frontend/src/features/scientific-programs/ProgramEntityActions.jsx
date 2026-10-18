import { Button, Field, Select } from '../scientific-courses/CatalogControls'
import { VERSION_LABELS } from './programs'

export default function ProgramEntityActions({ detail, plan, busy, onEdit, onDecision, onPreview, onChooseVersion }) {
  if (!detail) return null
  const p = detail.program, caps = detail.capabilities, id = p.academic_program_id, versionId = plan?.version.academic_plan_version_id
  const decide = (kind, message, suffix) => onDecision({ kind, message, programId: id, versionId, baseline: plan, actionPath: `/${id}/versions/${versionId}/${suffix}` })
  return <div className="flex flex-wrap gap-2">{caps.edit && <Button disabled={busy} onClick={onEdit}>تعديل البيانات</Button>}
    <details className="text-[12px]"><summary className="cursor-pointer rounded-[10px] border border-primary/15 bg-white px-4 py-2.5 font-bold text-text-gray">إجراءات أخرى</summary><div className="mt-3 flex flex-col items-start gap-3 rounded-[12px] border border-primary/12 bg-white p-3">
      {!plan?.persisted && plan && caps.plans && <Button disabled={busy} onClick={() => onPreview(`/${id}/transition-preview`, { kind: 'fix', actionPath: `/${id}/transition`, message: 'تثبيت الخطة الحالية يحفظ البيانات والطلاب بمعرّفاتهم ودلالاتهم، دون اعتماد تاريخي أو تعيين تلقائي للطلاب الجدد.' })}>تثبيت الخطة الحالية</Button>}
      {plan?.persisted && caps.plans && ['approved', 'transitional'].includes(plan.version.status) && <Button disabled={busy} onClick={() => decide('copy', 'تُنشأ نسخة مستقلة للتعديل؛ لا تتغير إسنادات الطلاب.', 'copy')}>إنشاء نسخة للتعديل</Button>}
      {plan?.persisted && caps.approve && plan.version.status === 'draft' && <Button disabled={busy} onClick={() => decide('approve', 'اعتماد هذه الخطة يخضع للتحقق الرسمي، ولا يعيّنها تلقائيًا للطلاب الجدد.', 'approve')}>اعتماد الخطة</Button>}
      {plan?.persisted && caps.assign && plan.version.status === 'approved' && <><Button disabled={busy} onClick={() => decide('default', 'يستخدم الطلاب الجدد هذه الخطة صراحة؛ لا يُنقل أي طالب سابق.', 'default')}>تعيين للطلاب الجدد</Button><Button disabled={busy} onClick={() => decide('transfer', 'نقل الطلاب إجراء مستقل يتطلب معاينة الأثر ومراجعة الموانع.', 'transfer')}>معاينة نقل الطلاب</Button></>}
      {caps.archive && <Button disabled={busy} onClick={() => onDecision({ kind: p.archived_at ? 'restore' : 'archive', programId: id, baseline: detail, actionPath: `/${id}/${p.archived_at ? 'restore' : 'archive'}`, message: 'الأرشفة تمنع القبول الجديد فقط؛ تبقى عمليات الطلاب الحاليين متاحة.' })}>{p.archived_at ? 'استعادة البرنامج' : 'أرشفة البرنامج'}</Button>}
      {caps.delete && <Button danger disabled={busy} onClick={() => onPreview(`/${id}/deletion-preview`, { kind: 'delete', method: 'DELETE', actionPath: `/${id}`, message: 'لا يُحذف البرنامج إلا عند عدم وجود ارتباطات مانعة.' })}>معاينة حذف برنامج غير مستخدم</Button>}
      {detail.versions.length > 0 && <Field label="استعراض خطة محفوظة"><Select disabled={busy} value={versionId || ''} onChange={event => { if (event.target.value && Number(event.target.value) !== Number(versionId)) onChooseVersion(event.target.value) }}><option value="">الخطة الحالية</option>{detail.versions.map(v => <option key={v.academic_plan_version_id} value={v.academic_plan_version_id}>{v.label} — {VERSION_LABELS[v.status] || 'حالة غير معروفة'}</option>)}</Select></Field>}
    </div></details>
  </div>
}
