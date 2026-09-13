import { useState } from 'react'
import { Button, CatalogDialog, Notice } from './CatalogControls'
import CatalogConflict from './CatalogConflict'
import useCatalogMutation from './useCatalogMutation'
import { SCOPES, TYPES } from './catalog'

export default function CourseDetails({ baseline, onEdit, onMembership, onBusy, onBlocked, onSaved, onRestart, onUnauthorized, busy, deleting = false }) {
  const c = baseline.data, caps = baseline.capabilities
  const [confirm, setConfirm] = useState(deleting && caps.delete)
  const mutation = useCatalogMutation({ currentPath: `/courses/${c.course_id}`, onSaved, onBusy, onBlocked, onUnauthorized })
  return <section className="space-y-4" aria-label="استعراض المادة">
    <h3 className="text-[16px] font-bold text-text-dark">{c.course_name} <span dir="ltr">({c.course_code})</span></h3>
    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">{[['الساعات المعتمدة', c.credit_hours], ['النظري', c.theoretical_hours], ['العملي', c.practical_hours], ['الحالة', c.is_active ? 'نشطة' : 'غير نشطة']].map(([label, value]) => <div key={label}><dt className="text-text-light">{label}</dt><dd className="font-bold">{value ?? '—'}</dd></div>)}</dl>
    {c.description && <p>{c.description}</p>}
    <p>{c.course_departments.map(d => [d.department?.college?.college_name, d.department?.department_name].filter(Boolean).join(' / ')).join('، ') || 'غير مرتبطة بقسم'}</p>
    <h4 className="font-bold text-text-dark">البرامج المرتبطة</h4>
    {c.program_courses.length ? <ul className="divide-y divide-primary/10">{c.program_courses.map(p => <li key={p.program_course_id} className="flex flex-wrap justify-between gap-2 py-2"><span>{p.academic_program?.program_name} — {SCOPES[p.requirement_classification?.requirement_scope] || 'غير مصنف'} / {TYPES[p.course_type]}</span><Button onClick={() => onMembership(p)}>تصنيف البرنامج</Button></li>)}</ul> : <p>لم تضف هذه المادة إلى برنامج بعد.</p>}
    {!!c.course_prerequisites?.length && <p>المتطلبات السابقة: {c.course_prerequisites.map(p => p.prerequisite_course?.course_name).join('، ')}</p>}
    <Notice>{caps.origin_lock_reason || caps.academic_lock_reason}</Notice>
    <div className="flex flex-wrap gap-2">{caps.edit_text && <Button primary onClick={onEdit} disabled={busy || mutation.blocked}>تعديل بيانات المادة</Button>}<Button danger disabled={!caps.delete || busy || mutation.blocked} onClick={() => setConfirm(true)}>حذف المادة</Button></div>
    {caps.delete_reasons?.length > 0 && <p className="text-[12px] text-text-light">لا يمكن الحذف: {caps.delete_reasons.join(' ')}</p>}
    <CatalogConflict mutation={mutation} onRestart={onRestart} />
    {confirm && <CatalogDialog title="حذف المادة" onClose={() => setConfirm(false)}><p>حذف {c.course_name} من الدليل؟ هذا يختلف عن إزالتها من برنامج واحد.</p><div className="flex gap-2"><Button danger onClick={() => { setConfirm(false); mutation.write(`/courses/${c.course_id}`, 'DELETE', { revision: baseline.revision, confirmed: true }) }}>تأكيد الحذف</Button><Button onClick={() => setConfirm(false)}>إلغاء</Button></div></CatalogDialog>}
  </section>
}
