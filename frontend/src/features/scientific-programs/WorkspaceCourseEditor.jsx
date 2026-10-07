import { useState } from 'react'
import { Button, CatalogLookup, Field, Input, Notice, Select } from '../scientific-courses/CatalogControls'
import { programRead, SCOPES, TYPES } from './programs'

export default function WorkspaceCourseEditor({ existing, initialChoice, onApply, onClose, canCreate, onDirty, departmentId, preparedCourses = [] }) {
  const preparedOrigin = preparedCourses.find(c => c.key === existing?.membership?.new_course_key)
  const [create, setCreate] = useState(!!preparedOrigin), [selected, setSelected] = useState(existing?.choice || initialChoice || null), [preparedKey, setPreparedKey] = useState('')
  const [row, setRow] = useState(existing?.membership || { course_id: null, requirement_scope: 'department', course_type: 'mandatory', academic_level_id: null, recommended_semester_id: null, is_active: true })
  const [origin, setOrigin] = useState(preparedOrigin || { course_code: '', course_name: '', credit_hours: '', theoretical_hours: '', practical_hours: '', is_active: true })
  const update = (field, value) => { setRow(r => ({ ...r, [field]: value })); onDirty() }
  const apply = () => {
    const key = existing?.membership.new_course_key || crypto.randomUUID()
    onApply({ ...row, display_credit_hours: selected?.credit_hours ?? row.display_credit_hours, course_id: create || preparedKey ? null : existing?.membership.course_id || selected?.id,
      ...(create ? { new_course_key: key } : preparedKey ? { new_course_key: preparedKey } : existing?.membership.new_course_key ? { new_course_key: existing.membership.new_course_key } : {}) },
    create ? { ...origin, key, credit_hours: Number(origin.credit_hours), theoretical_hours: Number(origin.theoretical_hours || 0), practical_hours: Number(origin.practical_hours || 0),
      departments: departmentId ? [{ department_id: Number(departmentId), is_primary: true }] : [] } : null,
    create ? `${origin.course_name} (${origin.course_code})` : preparedKey ? `${preparedCourses.find(c => c.key === preparedKey).course_name} (${preparedCourses.find(c => c.key === preparedKey).course_code})` : existing?.choice?.label || selected?.label)
  }
  return <div className="space-y-4">
    {!existing && <div className="flex flex-wrap gap-2"><Button disabled={!create} onClick={() => { setCreate(false); onDirty() }}>مادة موجودة</Button>{canCreate && <Button disabled={create} onClick={() => { setCreate(true); onDirty() }}>مادة جديدة</Button>}</div>}
    {existing && !create ? <p className="font-bold">{existing.choice.label}</p> : create ? <div className="grid gap-3 sm:grid-cols-2">{[['course_name', 'اسم المادة'], ['course_code', 'رمز المادة'], ['credit_hours', 'الساعات المعتمدة'], ['theoretical_hours', 'الساعات النظرية'], ['practical_hours', 'الساعات العملية']].map(([k, label]) => <Field key={k} label={label}><Input type={k.endsWith('hours') ? 'number' : 'text'} min="0" dir={k === 'course_code' || k.endsWith('hours') ? 'ltr' : 'rtl'} value={origin[k]} onChange={e => { setOrigin(o => ({ ...o, [k]: e.target.value })); onDirty() }} /></Field>)}</div>
      : <CatalogLookup label="المادة — الاسم والرمز" resource="courses" loader={programRead} value={selected} onChange={value => { setSelected(value); setPreparedKey(''); onDirty() }} clearLabel="اختر مادة" />}
    {!existing && !create && preparedCourses.length > 0 && <Field label="أو مادة جديدة موجودة في الإعداد"><Select value={preparedKey} onChange={e => { setPreparedKey(e.target.value); setSelected(null); onDirty() }}><option value="">اختر من المواد الجديدة المعدّة</option>{preparedCourses.map(c => <option key={c.key} value={c.key}>{c.course_name} ({c.course_code})</option>)}</Select></Field>}
    <div className="grid gap-3 sm:grid-cols-2"><Field label="تصنيف المتطلب"><Select value={row.requirement_scope || ''} onChange={e => update('requirement_scope', e.target.value)}><option value="">اختر التصنيف</option>{Object.entries(SCOPES).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</Select></Field>
      <Field label="نوع المتطلب"><Select value={row.course_type} onChange={e => update('course_type', e.target.value)}>{Object.entries(TYPES).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</Select></Field>
      <CatalogLookup label="السنة الدراسية الإرشادية" resource="levels" loader={programRead} value={row.academic_level_id ? { id: row.academic_level_id, label: existing?.levelLabel || `السنة ${row.academic_level_id}` } : null} onChange={v => update('academic_level_id', v?.id ?? null)} clearLabel="حدد السنة" />
      <CatalogLookup label="الفصل الإرشادي" resource="semesters" loader={programRead} value={row.recommended_semester_id ? { id: row.recommended_semester_id, label: existing?.semesterLabel || `الفصل ${row.recommended_semester_id}` } : null} onChange={v => update('recommended_semester_id', v?.id ?? null)} clearLabel="حدد الفصل" />
      <Field label="ارتباط المادة بالخطة"><Select value={row.is_active ? '1' : '0'} onChange={e => update('is_active', e.target.value === '1')}><option value="1">فعال</option><option value="0">غير فعال</option></Select></Field></div>
    <Notice>هذا توزيع إرشادي للبرنامج المحدد، وليس طرحًا أو جدول محاضرات. ستضاف المادة إلى الإعداد فقط؛ الحفظ النهائي يطبق الخطة المكتملة.</Notice>
    <div className="flex flex-wrap gap-2"><Button primary disabled={!row.academic_level_id || !row.recommended_semester_id || !row.requirement_scope || (!existing && !create && !selected && !preparedKey) || (create && (!origin.course_name.trim() || !origin.course_code.trim() || Number(origin.credit_hours) < 1))} onClick={apply}>إضافة إلى الإعداد</Button><Button onClick={onClose}>إلغاء</Button></div>
  </div>
}
