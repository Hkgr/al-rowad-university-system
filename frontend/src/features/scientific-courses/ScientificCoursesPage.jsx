import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker } from 'react-router-dom'
import { FaBookOpen } from 'react-icons/fa'
import DataTable from '../../components/table/DataTable'
import { getIdentity } from '../auth/auth'
import { canViewCatalog, catalogError, groupedCourses, queryString, SCOPES, TYPES } from './catalog'
import useCatalogRead from './useCatalogRead'
import { Button, CatalogDialog, CatalogLookup, Field, Input, Notice, Pager, Select } from './CatalogControls'
import CourseEditor from './CourseEditor'
import { MembershipEditor, RequirementGroupEditor } from './ProgramEditors'

function EditorLoader({ context, ...props }) {
  const path = context.kind === 'course' ? `/courses/${context.id}` : `/programs/${context.program.id}`
  const [retry, setRetry] = useState(0), read = useCatalogRead(context.baseline ? null : path, { refresh: retry })
  const courseRead = useCatalogRead(context.kind === 'membership' ? `/courses/${context.course.course_id}` : null, { refresh: retry })
  const baseline = context.baseline || read.data
  if (!baseline) return <div aria-busy={read.loading}><Notice>{read.loading && 'تحميل النسخة الرسمية وأسباب القفل…'}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice>{read.error && <Button onClick={() => setRetry(x => x + 1)}>إعادة تحميل التفاصيل</Button>}</div>
  if (context.kind === 'course') return <CourseEditor baseline={baseline} {...props} />
  if (context.kind === 'groups') return <RequirementGroupEditor baseline={baseline} {...props} />
  if (!courseRead.data || courseRead.data.revision !== baseline.revision) return <div className="space-y-2"><Notice>{courseRead.loading ? 'تحميل ارتباط المادة المطابق لإصدار البرنامج…' : 'تغير إصدار الدليل أثناء تحميل سياق الارتباط؛ أعد تحميل النسختين قبل التحرير.'}</Notice><Notice error>{courseRead.error && catalogError(courseRead.error)}</Notice>{!courseRead.loading && <Button onClick={() => setRetry(x => x + 1)}>إعادة تحميل سياق الارتباط</Button>}</div>
  return <MembershipEditor baseline={baseline} course={courseRead.data.data} {...props} />
}

export default function ScientificCoursesPage() {
  const [identity, setIdentity] = useState(() => JSON.stringify(getIdentity())), [denied, setDenied] = useState(false)
  const authorized = !denied && canViewCatalog(JSON.parse(identity))
  const [filters, setFilters] = useState({ q: '', college: null, department: null, program: null, requirement_scope: '', course_type: '', is_active: '', sort: 'course_code', direction: 'asc', page: 1 })
  const [groupOrder, setGroupOrder] = useState('scope'), [refresh, setRefresh] = useState(0), [editor, setEditor] = useState(null), [editorEpoch, setEditorEpoch] = useState(0)
  const [dirty, setDirty] = useState(false), [busy, setBusy] = useState(false), [blocked, setBlocked] = useState(false), [notice, setNotice] = useState(''), [transition, setTransition] = useState(null)
  const [linkCourse, setLinkCourse] = useState(null)
  const editorRef = useRef(null)
  useEffect(() => { if (editorRef.current) { editorRef.current.scrollIntoView({ block: 'start' }); editorRef.current.querySelector('h2')?.focus({ preventScroll: true }) } }, [editorEpoch])
  const controls = useRef({ dirty: false, busy: false, blocked: false })
  const markDirty = value => { controls.current.dirty = value; setDirty(value) }
  const markBusy = value => { controls.current.busy = value; setBusy(value) }
  const markBlocked = value => { controls.current.blocked = value; setBlocked(value) }
  const reset = useCallback(() => { controls.current = { dirty: false, busy: false, blocked: false }; setDirty(false); setBusy(false); setBlocked(false); setEditor(null); setTransition(null); setLinkCourse(null); setNotice('') }, [])
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
  function filter(key, value) { go(() => { setEditor(null); setFilters(f => ({ ...f, [key]: value, page: 1, ...(key === 'college' ? { department: null, program: null } : key === 'department' ? { program: null } : {}) })); setLinkCourse(null) }) }
  const query = queryString({ q: filters.q.trim(), college_id: filters.college?.id, department_id: filters.department?.id, academic_program_id: filters.program?.id, requirement_scope: filters.requirement_scope, course_type: filters.course_type, is_active: filters.is_active, sort: filters.sort, direction: filters.direction, page: filters.page })
  const read = useCatalogRead(authorized ? `/courses?${query}` : null, { delay: 350, refresh })
  const linkRead = useCatalogRead(authorized && linkCourse ? `/courses/${linkCourse.id}` : null)
  function saved() { reset(); setRefresh(n => n + 1); setNotice('تم تأكيد الحفظ. تحديث القائمة مستقل عن نتيجة الكتابة؛ لا تعِد الحفظ عند فشل التحديث.') }
  function restart(current) { go(() => { markDirty(false); markBlocked(false); setEditorEpoch(x => x + 1); setEditor(e => ({ ...e, baseline: e.kind === 'membership' ? null : e.kind === 'course' && !e.id ? { revision: current.revision, data: {} } : current })) }) }
  const columns = [
    { key: 'course', header: 'المادة', render: r => <div><span className="font-bold">{r.course.course_name}</span><p dir="ltr" className="text-xs text-text-light text-right">{r.course.course_code}</p></div> },
    { key: 'program', header: 'سياق البرنامج', render: r => r.membership?.academic_program?.program_name || 'أصل مستقل' },
    { key: 'ownership', header: 'الكلية / القسم', render: r => <div className="text-xs">{r.course.course_departments?.map(d => <p key={d.department_id}>{d.department?.college?.college_name || '—'} / {d.department?.department_name || '—'}</p>)}</div> },
    { key: 'hours', header: 'معتمدة / نظري / عملي', dir: 'ltr', render: r => `${r.course.credit_hours} / ${r.course.theoretical_hours ?? '—'} / ${r.course.practical_hours ?? '—'}` },
    { key: 'advisory', header: 'بيانات إرشادية فقط', render: r => <div className="text-xs"><p>{r.membership?.academic_level?.level_name || 'المستوى الإرشادي غير محدد'}</p><p>{r.membership?.recommended_semester?.semester_name || 'الفصل الإرشادي غير محدد'}</p></div> },
    { key: 'active', header: 'الحالة', render: r => <span className={`rounded-lg px-2 py-1 text-xs ${r.course.is_active ? 'bg-primary/10 text-primary' : 'bg-gray-100 text-gray-600'}`}>{r.course.is_active ? 'أصل نشط' : 'أصل غير نشط'}{r.membership && (r.membership.is_active ? ' · ارتباط نشط' : ' · ارتباط غير نشط')}</span> },
    { key: 'actions', header: 'التفاصيل والإدارة', render: r => <div className="flex flex-wrap gap-2"><Button onClick={() => edit({ kind: 'course', id: r.course.course_id })}>تفاصيل {r.course.course_code}</Button>{r.membership && <Button onClick={() => edit({ kind: 'membership', program: { id: r.membership.academic_program_id }, course: r.course })}>ارتباط البرنامج</Button>}</div> },
  ]
  if (!authorized) return <Notice error>تم مسح بيانات الدليل؛ لم تعد الصلاحية أو هوية الحساب متاحة.</Notice>
  return <main dir="rtl" className="min-w-0 space-y-5 py-6 text-text-dark"><header className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-bold text-primary">نيابة الشؤون العلمية · الدليل الأكاديمي</p><h1 className="mt-1 text-2xl font-black">إدارة المواد</h1><p className="mt-2 text-sm text-text-light">أصل المادة الجامعي، وارتباطها بالبرنامج، وميزانية متطلباته — موارد مستقلة.</p></div>{read.data?.can_create && <Button primary onClick={() => edit({ kind: 'course', baseline: { revision: read.data.revision, data: {} } })}>إضافة مادة إلى الدليل</Button>}</header>
    <Notice>{notice}</Notice>{read.data && !read.data.can_manage && <Notice>صلاحيتك قراءة فقط؛ لن تظهر إجراءات الحفظ أو الربط.</Notice>}
    <section aria-label="مرشحات دليل المواد" className="grid gap-3 rounded-2xl border border-primary/10 bg-white p-4 sm:grid-cols-2 lg:grid-cols-3"><Field label="بحث بالاسم أو الرمز"><Input aria-label="بحث بالاسم أو الرمز" value={filters.q} onChange={e => filter('q', e.target.value)} /></Field><CatalogLookup label="الكلية" resource="colleges" clearLabel="كل الكليات" value={filters.college} onChange={o => filter('college', o)} /><CatalogLookup key={`dep-${filters.college?.id || ''}`} label="القسم" resource="departments" clearLabel="كل الأقسام" context={{ college_id: filters.college?.id }} value={filters.department} onChange={o => filter('department', o)} /><CatalogLookup key={`prog-${filters.college?.id || ''}-${filters.department?.id || ''}`} label="البرنامج" resource="programs" clearLabel="كل البرامج" context={{ college_id: filters.college?.id, department_id: filters.department?.id }} value={filters.program} onChange={o => filter('program', o)} />
      <Field label="مستوى المتطلب"><Select value={filters.requirement_scope} onChange={e => filter('requirement_scope', e.target.value)}><option value="">كل المستويات</option>{Object.entries(SCOPES).filter(([k]) => k !== 'unclassified').map(([k, label]) => <option key={k} value={k}>{label}</option>)}</Select></Field><Field label="نوع المادة"><Select value={filters.course_type} onChange={e => filter('course_type', e.target.value)}><option value="">كل الأنواع</option><option value="mandatory">إجباري</option><option value="elective">اختياري</option></Select></Field><Field label="نشاط أصل المادة"><Select value={filters.is_active} onChange={e => filter('is_active', e.target.value)}><option value="">كل الحالات</option><option value="1">نشط</option><option value="0">غير نشط</option></Select></Field><Field label="ترتيب المواد"><Select value={filters.sort} onChange={e => filter('sort', e.target.value)}><option value="course_code">الرمز</option><option value="course_name">الاسم</option><option value="credit_hours">الساعات المعتمدة</option></Select></Field><Field label="اتجاه الترتيب"><Select value={filters.direction} onChange={e => filter('direction', e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></Select></Field>
    </section>
    <section aria-label="إدارة سياق البرنامج" className="space-y-3 rounded-2xl border border-primary/15 bg-white p-4"><h2 className="font-black">مجموعات المتطلبات وميزانيات الساعات</h2>{filters.program ? <><p className="text-sm">البرنامج المحدد: {filters.program.label}</p><Button onClick={() => edit({ kind: 'groups', program: filters.program })}>إدارة ميزانيات البرنامج</Button><div className="grid items-start gap-3 md:grid-cols-[1fr_auto]"><CatalogLookup label="مادة من الدليل لربطها بالبرنامج" resource="courses" value={linkCourse} clearLabel="اختر مادة موجودة" onChange={o => go(() => { setEditor(null); setLinkCourse(o) })} /><Button disabled={!linkRead.data || linkRead.loading} onClick={() => edit({ kind: 'membership', program: filters.program, course: linkRead.data.data })}>عرض / إعداد ارتباط البرنامج</Button></div><Notice error>{linkRead.error && catalogError(linkRead.error)}</Notice></> : <p className="text-sm text-text-light">اختر برنامجًا لعرض ميزانياته وربط مواده. يمكنك إضافة مادة مستقلة إلى الدليل دون اختيار برنامج.</p>}</section>
    {editor && <section ref={editorRef} aria-label="لوحة تحرير الدليل" className="scroll-mt-24 space-y-4 rounded-2xl border border-primary/25 bg-white p-5"><div className="flex justify-between gap-3"><h2 tabIndex={-1} className="font-black text-primary">{editor.kind === 'course' ? 'أصل المادة' : editor.kind === 'groups' ? 'مجموعات البرنامج وميزانياته' : 'تصنيف المادة في البرنامج'}</h2><Button disabled={busy} onClick={() => go(() => { setEditor(null); markDirty(false); markBlocked(false) })}>إغلاق المحرر</Button></div><EditorLoader key={editorEpoch} context={editor} busy={busy} onDirty={markDirty} onBusy={markBusy} onBlocked={markBlocked} onSaved={saved} onRestart={restart} onUnauthorized={unauthorized} /></section>}
    <div className="flex flex-wrap items-center justify-between gap-3"><p className="font-bold">مواد الدليل ضمن الاختيار: {read.data?.summary.catalog_count ?? '—'}</p><Field label="تجميع العرض"><Select value={groupOrder} onChange={e => setGroupOrder(e.target.value)}><option value="scope">مستوى المتطلب ثم النوع</option><option value="type">النوع ثم مستوى المتطلب</option></Select></Field><Button onClick={() => setRefresh(x => x + 1)}>تحديث القائمة</Button></div>
    <p className="text-xs text-text-light">التجميع التالي للصفحة الحالية. قد تظهر المادة في سياقات برامج متعددة؛ لا يمثل مجموع الدليل متطلبات تخرج.</p>
    {read.data?.summary.groups.length > 0 && <section aria-label="ملخص كامل الاختيار" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{read.data.summary.groups.map(g => <div key={`${g.requirement_scope}:${g.course_type}`} className="rounded-xl border border-primary/10 bg-white p-3 text-sm"><p className="font-bold">{SCOPES[g.requirement_scope] || SCOPES.unclassified} — {TYPES[g.course_type] || TYPES.unclassified}</p><p>{g.membership_count} ارتباط · {g.available_credit_hours} ساعة في المواد المرتبطة</p><p className="text-xs text-text-light">كامل الاختيار، وليس ميزانية الساعات المطلوبة</p></div>)}</section>}
    <Notice error>{read.error && catalogError(read.error)}</Notice>{read.loading && <Notice>تحميل دليل المواد…</Notice>}
    {read.data && groupedCourses(read.data.data, groupOrder).map(group => <section key={group.key} className="space-y-2"><h2 className="font-bold text-primary">{SCOPES[group.scope]} — {TYPES[group.type]}</h2><DataTable columns={columns} rows={group.rows} rowKey={r => r.key} /></section>)}
    {read.data?.data.length === 0 && <DataTable columns={columns} rows={[]} rowKey={r => r.key} emptyIcon={FaBookOpen} emptyTitle="لا توجد مواد مطابقة" emptySubtitle="عدّل الفلاتر أو أضف أصل مادة جديدًا ضمن صلاحيتك." />}
    <Pager meta={read.data?.meta} disabled={read.loading} onPage={page => go(() => { setEditor(null); setFilters(f => ({ ...f, page })) })} />
    {(transition || blocker.state === 'blocked') && <CatalogDialog title={busy ? 'الحفظ قيد التنفيذ' : 'مراجعة التغييرات قبل الانتقال'} onClose={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}><p>{busy ? 'لا يمكن الانتقال قبل وصول نتيجة الحفظ.' : blocked ? 'نتيجة الحفظ تحتاج مراجعة. مغادرة المحرر لا تلغي كتابة ربما نجحت على الخادم.' : 'لديك تغييرات غير محفوظة. هل تريد تجاهلها والانتقال؟'}</p><div className="mt-4 flex gap-2"><Button onClick={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}>البقاء في المحرر</Button><Button danger disabled={busy} onClick={() => { const action = transition; setTransition(null); markDirty(false); markBlocked(false); if (blocker.state === 'blocked') blocker.proceed(); else action?.() }}>تجاهل المسودة والمتابعة</Button></div></CatalogDialog>}
    <span className="sr-only" aria-live="polite">{dirty ? 'توجد مسودة غير محفوظة' : ''}</span>
  </main>
}
