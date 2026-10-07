import { useState } from 'react'
import { Link } from 'react-router-dom'
import { getIdentity } from '../auth/auth'
import { canViewPrograms, programPlanLink } from '../scientific-programs/programs'
import { Button, CatalogDialog, Notice } from './CatalogControls'
import CatalogConflict from './CatalogConflict'
import useCatalogMutation from './useCatalogMutation'
import { SCOPES, TYPES } from './catalog'
import { entityLink, membershipEntityLink } from '../scientific-programs/entities'

export default function CourseDetails({ baseline, onEdit, onMembership, onBusy, onBlocked, onSaved, onRestart, onUnauthorized, busy, deleting = false, page = false, returnTo }) {
  const c = baseline.data, caps = baseline.capabilities
  const [confirm, setConfirm] = useState(deleting && caps.delete)
  const mutation = useCatalogMutation({ currentPath: `/courses/${c.course_id}`, onSaved, onBusy, onBlocked, onUnauthorized })
  const Heading = page ? 'h1' : 'h3'
  return <section className="space-y-4" aria-label="استعراض المادة">
    <Heading className={page ? 'text-[22px] font-black text-text-dark' : 'text-[16px] font-bold text-text-dark'}>{c.course_name} <span dir="ltr">({c.course_code})</span></Heading>
    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">{[['الساعات المعتمدة', c.credit_hours], ['النظري', c.theoretical_hours], ['العملي', c.practical_hours], ['الحالة', c.is_active ? 'نشطة' : 'غير نشطة']].map(([label, value]) => <div key={label}><dt className="text-text-light">{label}</dt><dd className="font-bold">{value ?? '—'}</dd></div>)}</dl>
    {c.description && <p>{c.description}</p>}
    <div className="flex flex-wrap gap-3 text-[12px]">{c.course_departments.map(d => <span key={d.course_department_id}>{page && canViewPrograms(getIdentity()) ? <><Link className="text-primary" to={entityLink('colleges', d.department?.college_id, { returnTo })}>{d.department?.college?.college_name}</Link> / <Link className="text-primary" to={entityLink('departments', d.department_id, { returnTo })}>{d.department?.department_name}</Link></> : [d.department?.college?.college_name, d.department?.department_name].filter(Boolean).join(' / ')}</span>)}{!c.course_departments.length && 'غير مرتبطة بقسم'}</div>
    <h4 className="font-bold text-text-dark">البرامج المرتبطة</h4>
    {c.program_courses.length ? <ul className="divide-y divide-primary/10">{c.program_courses.map(p => <li key={p.program_course_id} className="flex flex-wrap justify-between gap-2 py-2"><div className="text-[12.5px] leading-7">{canViewPrograms(getIdentity()) ? <Link className="font-semibold text-primary" to={page ? membershipEntityLink(p, returnTo) : programPlanLink(p)}>{p.academic_program?.program_name} ({p.academic_program?.program_code || 'رمز غير محدد'})</Link> : p.academic_program?.program_name}<p>{SCOPES[p.requirement_classification?.requirement_scope] || 'غير مصنف'} / {TYPES[p.course_type]} — {p.academic_level?.level_name || 'السنة غير محددة'} / {p.recommended_semester?.semester_name || 'الفصل غير محدد'}{p.academic_plan_version_id != null && ' — سياق الخطة المحفوظة'}</p></div>{!page && <Button onClick={() => onMembership(p)}>تصنيف البرنامج</Button>}</li>)}</ul> : <p>لم تضف هذه المادة إلى برنامج بعد.</p>}
    {!!c.course_prerequisites?.length && <div className="text-[12.5px] leading-7">المتطلبات السابقة: {c.course_prerequisites.map(p => <span key={p.course_prerequisite_id} className="inline-block ml-3">{page ? <Link className="text-primary" to={entityLink('courses', p.prerequisite_course_id, { returnTo })}>{p.prerequisite_course?.course_name} ({p.prerequisite_course?.course_code})</Link> : `${p.prerequisite_course?.course_name} (${p.prerequisite_course?.course_code})`}</span>)}</div>}
    <Notice>{caps.origin_lock_reason || caps.academic_lock_reason}</Notice>
    <div className="flex flex-wrap gap-2">{caps.edit_text && <Button primary onClick={onEdit} disabled={busy || mutation.blocked}>تعديل بيانات المادة</Button>}<Button danger disabled={!caps.delete || busy || mutation.blocked} onClick={() => setConfirm(true)}>حذف المادة</Button></div>
    {caps.delete_reasons?.length > 0 && <p className="text-[12px] text-text-light">لا يمكن الحذف: {caps.delete_reasons.join(' ')}</p>}
    <CatalogConflict mutation={mutation} onRestart={onRestart} />
    {confirm && <CatalogDialog title="حذف المادة" onClose={() => setConfirm(false)}><p>حذف {c.course_name} من الدليل؟ هذا يختلف عن إزالتها من برنامج واحد.</p><div className="flex gap-2"><Button danger onClick={() => { setConfirm(false); mutation.write(`/courses/${c.course_id}`, 'DELETE', { revision: baseline.revision, confirmed: true }) }}>تأكيد الحذف</Button><Button onClick={() => setConfirm(false)}>إلغاء</Button></div></CatalogDialog>}
  </section>
}
