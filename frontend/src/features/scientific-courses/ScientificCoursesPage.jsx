import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker } from 'react-router-dom'
import { FaBookOpen, FaEdit, FaEye, FaPlus, FaTrash } from 'react-icons/fa'
import DataTable from '../../components/table/DataTable'
import FilterBar from '../../components/table/FilterBar'
import { getIdentity } from '../auth/auth'
import { canViewCatalog, catalogError, queryString, SCOPES, TYPES } from './catalog'
import useCatalogRead from './useCatalogRead'
import { Button, CatalogDialog, CatalogLookup, Field, Notice, Select } from './CatalogControls'
import CourseEditor from './CourseEditor'
import CourseDetails from './CourseDetails'
import { MembershipEditor, RequirementGroupEditor } from './ProgramEditors'

function EditorLoader({ context, onEdit, onMembership, ...props }) {
  const isCourse = ['course', 'view', 'delete'].includes(context.kind)
  const path = isCourse ? `/courses/${context.id}` : `/programs/${context.program.id}`
  const [retry, setRetry] = useState(0)
  const read = useCatalogRead(context.baseline ? null : path, { refresh: retry })
  const courseRead = useCatalogRead(context.kind === 'membership' ? `/courses/${context.course.course_id}` : null, { refresh: retry })
  const baseline = context.baseline || read.data
  if (!baseline) return <div aria-busy={read.loading}><Notice>{read.loading && 'جاري تحميل البيانات…'}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice>{read.error && <Button onClick={() => setRetry(x => x + 1)}>إعادة تحميل التفاصيل</Button>}</div>
  if (context.kind === 'course') return <><Notice>{context.forProgram && 'احفظ بيانات المادة أولًا، ثم اختر إضافتها للبرنامج في خطوة مستقلة.'}</Notice><CourseEditor baseline={baseline} {...props} /></>
  if (isCourse) return <CourseDetails baseline={baseline} onEdit={onEdit} onMembership={onMembership} deleting={context.kind === 'delete'} {...props} />
  if (context.kind === 'groups') return <RequirementGroupEditor baseline={baseline} {...props} />
  if (!courseRead.data || courseRead.data.revision !== baseline.revision) return <div className="space-y-2"><Notice>{courseRead.loading ? 'تحميل بيانات المادة…' : 'تغيرت البيانات أثناء التحميل؛ أعد تحميلها قبل المتابعة.'}</Notice><Notice error>{courseRead.error && catalogError(courseRead.error)}</Notice>{!courseRead.loading && <Button onClick={() => setRetry(x => x + 1)}>إعادة تحميل البيانات</Button>}</div>
  return <MembershipEditor baseline={baseline} course={courseRead.data.data} {...props} />
}

function ProgramCourseChoice({ program, onExisting, onNew, canCreate }) {
  const [course, setCourse] = useState(null)
  return <div className="space-y-4"><p>{program.label}</p>
    <CatalogLookup label="اختيار مادة موجودة" resource="courses" value={course} onChange={setCourse} clearLabel="ابحث عن المادة أو اخترها" />
    <Button primary disabled={!course} onClick={() => onExisting(course)}>متابعة بالمادة المختارة</Button>
    {canCreate && <div className="border-t border-primary/10 pt-4"><p className="mb-2">المادة غير موجودة في الدليل؟</p><Button onClick={onNew}><FaPlus /> إنشاء مادة جديدة</Button><p className="mt-2 text-[12px] text-text-light">ستحفظ المادة أولًا، ثم تؤكد إضافتها للبرنامج بشكل مستقل.</p></div>}
  </div>
}

const titles = { course: 'بيانات المادة', view: 'استعراض المادة', delete: 'حذف المادة', groups: 'متطلبات التخرج', membership: 'تصنيف المادة في البرنامج', choose: 'إضافة مادة للبرنامج', created: 'تم حفظ المادة' }
export default function ScientificCoursesPage() {
  const [identity, setIdentity] = useState(() => JSON.stringify(getIdentity())), [denied, setDenied] = useState(false)
  const authorized = !denied && canViewCatalog(JSON.parse(identity))
  const [filters, setFilters] = useState({ q: '', college: null, department: null, program: null, requirement_scope: '', course_type: '', is_active: '', sort: 'course_code', direction: 'asc', page: 1 })
  const [refresh, setRefresh] = useState(0), [editor, setEditor] = useState(null), [editorEpoch, setEditorEpoch] = useState(0)
  const [dirty, setDirty] = useState(false), [busy, setBusy] = useState(false), [blocked, setBlocked] = useState(false), [notice, setNotice] = useState(''), [transition, setTransition] = useState(null)
  const controls = useRef({ dirty: false, busy: false, blocked: false })
  const markDirty = value => { controls.current.dirty = value; setDirty(value) }
  const markBusy = value => { controls.current.busy = value; setBusy(value) }
  const markBlocked = value => { controls.current.blocked = value; setBlocked(value) }
  const reset = useCallback(() => { controls.current = { dirty: false, busy: false, blocked: false }; setDirty(false); setBusy(false); setBlocked(false); setEditor(null); setTransition(null); setNotice('') }, [])
  const unauthorized = useCallback(() => { reset(); setDenied(true) }, [reset])
  useEffect(() => { window.addEventListener('scientific-catalog-denied', unauthorized); return () => window.removeEventListener('scientific-catalog-denied', unauthorized) }, [unauthorized])
  useEffect(() => {
    const check = () => { const next = JSON.stringify(getIdentity()); if (next !== identity) { reset(); setIdentity(next); setDenied(false) } }
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    const interval = setInterval(check, 500)
    return () => { window.removeEventListener('storage', check); window.removeEventListener('focus', check); clearInterval(interval) }
  }, [identity, reset])
  useEffect(() => { const leave = e => { if (authorized && Object.values(controls.current).some(Boolean)) { e.preventDefault(); e.returnValue = '' } }; window.addEventListener('beforeunload', leave); return () => window.removeEventListener('beforeunload', leave) }, [authorized])
  const blocker = useBlocker(() => canViewCatalog(getIdentity()) && !denied && Object.values(controls.current).some(Boolean))
  function go(action) {
    if (controls.current.busy) { setNotice('انتظر نتيجة الحفظ قبل الانتقال.'); return }
    if (controls.current.dirty || controls.current.blocked) { setTransition(() => action); return }
    action()
  }
  function edit(context) { go(() => { markDirty(false); markBlocked(false); setEditor(context); setEditorEpoch(x => x + 1); setNotice('') }) }
  function close() { go(() => { setEditor(null); markDirty(false); markBlocked(false) }) }
  function filter(key, value) { go(() => { setEditor(null); setFilters(f => ({ ...f, [key]: value, page: 1, ...(key === 'college' ? { department: null, program: null } : key === 'department' ? { program: null } : {}) })) }) }
  const query = queryString({ q: filters.q.trim(), college_id: filters.college?.id, department_id: filters.department?.id, academic_program_id: filters.program?.id, requirement_scope: filters.requirement_scope, course_type: filters.course_type, is_active: filters.is_active, sort: filters.sort, direction: filters.direction, page: filters.page })
  const read = useCatalogRead(authorized ? `/courses?${query}` : null, { delay: 350, refresh })
  const programRead = useCatalogRead(authorized && filters.program ? `/programs/${filters.program.id}` : null, { refresh })
  function create(forProgram = null) {
    edit({ kind: 'course', forProgram, baseline: { revision: read.data.revision, data: { course_departments: filters.department ? [{ department_id: filters.department.id, is_primary: true, department: { department_name: filters.department.label } }] : [] } } })
  }
  function saved(result) {
    const next = editor?.forProgram && result?.data?.course_id ? { kind: 'created', course: result.data, program: editor.forProgram } : null
    reset(); setRefresh(n => n + 1); setNotice(next ? 'تم حفظ المادة. لم تُضف للبرنامج بعد.' : 'تم الحفظ بنجاح.')
    if (next) { setEditor(next); setEditorEpoch(n => n + 1) }
  }
  function restart(current) { go(() => { markDirty(false); markBlocked(false); setEditorEpoch(x => x + 1); setEditor(e => ({ ...e, baseline: e.kind === 'membership' ? null : e.kind === 'course' && !e.id ? { revision: current.revision, data: {} } : current })) }) }
  const rowAction = 'inline-flex items-center gap-1 rounded-[7px] border px-2.5 py-1.5 text-[11px] font-bold transition-colors'
  const columns = [
    { key: 'code', header: 'الرمز', dir: 'ltr', render: c => <span className="text-[12px] font-mono">{c.course_code}</span> },
    { key: 'name', header: 'اسم المادة', render: c => <span className="text-[13px] font-semibold">{c.course_name}</span> },
    { key: 'ownership', header: 'الكلية / القسم', render: c => <div className="text-[12px]">{c.course_departments?.map(d => <p key={d.department_id}>{[d.department?.college?.college_name, d.department?.department_name].filter(Boolean).join(' / ')}</p>)}</div> },
    { key: 'hours', header: 'الساعات المعتمدة', align: 'center', render: c => <b className="text-[13px]">{c.credit_hours}</b> },
    ...(filters.program ? [{ key: 'classification', header: 'التصنيف في البرنامج', render: c => { const pc = c.program_courses?.find(p => String(p.academic_program_id) === String(filters.program.id)); return <div className="text-[12px]"><p>{SCOPES[pc?.requirement_classification?.requirement_scope] || 'غير مصنف'} / {TYPES[pc?.course_type] || 'غير محدد'}</p><p className="text-[11px] text-text-light">{pc?.academic_level?.level_name || 'المستوى غير محدد'} · {pc?.recommended_semester?.semester_name || 'الفصل غير محدد'} (إرشادي)</p></div> } }] : []),
    { key: 'active', header: 'الحالة', align: 'center', render: c => <span className={`inline-block rounded-full px-2 py-0.5 text-[10.5px] font-bold ${c.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-text-light'}`}>{c.is_active ? 'فعّالة' : 'غير فعّالة'}</span> },
    { key: 'actions', header: 'الإجراءات', align: 'center', render: c => <div className="flex flex-wrap justify-center gap-2">
      <button type="button" aria-label={`استعراض ${c.course_code}`} className={`${rowAction} border-primary/25 text-primary hover:bg-primary/[0.05]`} onClick={() => edit({ kind: 'view', id: c.course_id })}><FaEye /> استعراض</button>
      {read.data?.can_manage && <><button type="button" aria-label={`تعديل ${c.course_code}`} className={`${rowAction} border-primary/25 text-primary hover:bg-primary/[0.05]`} onClick={() => edit({ kind: 'course', id: c.course_id })}><FaEdit /> تعديل</button><button type="button" aria-label={`حذف ${c.course_code}`} className={`${rowAction} border-red-300 text-red-600 hover:bg-red-50`} onClick={() => edit({ kind: 'delete', id: c.course_id })}><FaTrash /> حذف</button></>}
    </div> },
  ]
  if (!authorized) return <Notice error>تم مسح البيانات؛ لم تعد الصلاحية أو هوية الحساب متاحة.</Notice>
  return <main dir="rtl" className="min-w-0 space-y-5 px-2 py-6 text-text-dark">
    <header className="flex flex-wrap items-start justify-between gap-3 rounded-[18px] border border-primary/12 bg-white px-6 py-5">
      <div><h1 className="text-[22px] font-black">إدارة المواد</h1><p className="mt-2 text-[13.5px] leading-7 text-text-light">استعراض المواد وتحديث بياناتها ضمن صلاحيتك.</p></div>
      {read.data?.can_create && <Button primary onClick={() => create()}><FaPlus /> إضافة مادة</Button>}
    </header>
    <Notice>{notice}</Notice>
    <section aria-label="فلاتر المواد">
      <FilterBar search={{ value: filters.q, onChange: value => filter('q', value), placeholder: 'بحث بالاسم أو الرمز' }} />
      <div className="grid gap-3 sm:grid-cols-3"><CatalogLookup label="الكلية" resource="colleges" clearLabel="كل الكليات" value={filters.college} onChange={o => filter('college', o)} /><CatalogLookup key={`dep-${filters.college?.id || ''}`} label="القسم" resource="departments" clearLabel="كل الأقسام" context={{ college_id: filters.college?.id }} value={filters.department} onChange={o => filter('department', o)} /><CatalogLookup key={`prog-${filters.college?.id || ''}-${filters.department?.id || ''}`} label="البرنامج" resource="programs" clearLabel="كل البرامج" context={{ college_id: filters.college?.id, department_id: filters.department?.id }} value={filters.program} onChange={o => filter('program', o)} /></div>
      <details className="mt-3 rounded-[12px] border border-primary/12 bg-white px-4 py-3"><summary className="cursor-pointer text-[12.5px] font-bold text-primary">فلاتر إضافية</summary><div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Field label="مستوى المتطلب"><Select value={filters.requirement_scope} onChange={e => filter('requirement_scope', e.target.value)}><option value="">كل المستويات</option>{Object.entries(SCOPES).filter(([k]) => k !== 'unclassified').map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select></Field>
        <Field label="نوع المتطلب"><Select value={filters.course_type} onChange={e => filter('course_type', e.target.value)}><option value="">كل الأنواع</option><option value="mandatory">إجباري</option><option value="elective">اختياري</option></Select></Field>
        <Field label="الحالة"><Select value={filters.is_active} onChange={e => filter('is_active', e.target.value)}><option value="">كل الحالات</option><option value="1">فعّالة</option><option value="0">غير فعّالة</option></Select></Field>
        <Field label="الترتيب"><Select value={filters.sort} onChange={e => filter('sort', e.target.value)}><option value="course_code">الرمز</option><option value="course_name">الاسم</option><option value="credit_hours">الساعات المعتمدة</option></Select></Field>
        <Field label="اتجاه الترتيب"><Select value={filters.direction} onChange={e => filter('direction', e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></Select></Field>
      </div></details>
    </section>
    {filters.program && <section aria-label="إجراءات البرنامج" className="space-y-2"><div className="flex flex-wrap items-center gap-3"><b className="text-[13px]">{filters.program.label}</b>{read.data?.can_manage && <Button disabled={!programRead.data?.capabilities.edit_curriculum} onClick={() => edit({ kind: 'choose', program: filters.program })}>إضافة مادة للبرنامج</Button>}<Button onClick={() => edit({ kind: 'groups', program: filters.program })}>متطلبات التخرج</Button></div><p className="text-[12px] text-text-light">{programRead.data?.capabilities.lock_reason}</p><Notice error>{programRead.error && catalogError(programRead.error)}</Notice></section>}
    <div className="flex items-center justify-between gap-3"><span className="text-[13px] font-bold">{read.data?.meta.total ?? '—'} مادة</span><Button onClick={() => setRefresh(n => n + 1)}>تحديث القائمة</Button></div>
    <Notice error>{read.error && catalogError(read.error)}</Notice>
    <DataTable columns={columns} rows={read.data?.data || []} rowKey={c => c.course_id} loading={read.loading} emptyIcon={FaBookOpen} emptyTitle="لا توجد مواد مطابقة" page={read.data?.meta.current_page || filters.page} totalPages={read.data?.meta.last_page || 1} onPageChange={page => go(() => { setEditor(null); setFilters(f => ({ ...f, page })) })} />
    {editor && <CatalogDialog wide closeButton title={titles[editor.kind]} onClose={close}>
      {editor.kind === 'choose' ? <ProgramCourseChoice program={editor.program} canCreate={read.data?.can_create} onExisting={course => edit({ kind: 'membership', course: { course_id: course.id }, program: editor.program })} onNew={() => create(editor.program)} />
        : editor.kind === 'created' ? <><Notice>تم حفظ {editor.course.course_name} في الدليل. لم تُضف للبرنامج بعد.</Notice><Button primary onClick={() => edit({ kind: 'membership', course: editor.course, program: editor.program })}>متابعة إضافة المادة للبرنامج</Button><Button onClick={close}>إنهاء دون إضافة للبرنامج</Button></>
          : <EditorLoader key={editorEpoch} context={editor} busy={busy} onDirty={markDirty} onBusy={markBusy} onBlocked={markBlocked} onSaved={saved} onRestart={restart} onUnauthorized={unauthorized}
            onEdit={() => edit({ kind: 'course', id: editor.id })}
            onMembership={pc => edit({ kind: 'membership', course: { course_id: editor.id }, program: { id: pc.academic_program_id, label: pc.academic_program?.program_name } })}
            onRequirements={() => edit({ kind: 'groups', program: editor.program })} />}
    </CatalogDialog>}
    {(transition || blocker.state === 'blocked') && <CatalogDialog title={busy ? 'الحفظ قيد التنفيذ' : 'تغييرات غير محفوظة'} onClose={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}><p>{busy ? 'انتظر نتيجة الحفظ قبل الانتقال.' : blocked ? 'قد يكون الحفظ نجح رغم انقطاع الاتصال. هل تريد المغادرة دون مراجعة؟' : 'هل تريد تجاهل تعديلاتك والانتقال؟'}</p><div className="flex flex-wrap gap-2"><Button onClick={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}>البقاء في المحرر</Button><Button danger disabled={busy} onClick={() => { const action = transition; setTransition(null); markDirty(false); markBlocked(false); if (blocker.state === 'blocked') blocker.proceed(); else action?.() }}>تجاهل التعديلات والمتابعة</Button></div></CatalogDialog>}
    <span className="sr-only" aria-live="polite">{dirty ? 'توجد تعديلات غير محفوظة' : ''}</span>
  </main>
}
