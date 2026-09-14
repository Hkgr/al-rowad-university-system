import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useBlocker, useNavigate, useParams } from 'react-router-dom'
import { FaClipboardList, FaPlus } from 'react-icons/fa'
import DataTable from '../../components/table/DataTable'
import FilterBar from '../../components/table/FilterBar'
import { getIdentity } from '../auth/auth'
import { Button, CatalogDialog, CatalogLookup, Field, Notice, Select } from '../scientific-courses/CatalogControls'
import { catalogError, queryString } from '../scientific-courses/catalog'
import useCatalogRead from '../scientific-courses/useCatalogRead'
import ProgramForms from './ProgramForms'
import PlanRequirements from './PlanRequirements'
import ProgramDecision from './ProgramDecision'
import ProgramCourseCreation from './ProgramCourseCreation'
import { canViewPrograms, programRead, SETUP_LABELS, SCOPES, TYPES, VERSION_LABELS } from './programs'

export default function ScientificProgramsPage() {
  const [identity, setIdentity] = useState(() => JSON.stringify(getIdentity())), [denied, setDenied] = useState(false)
  const { programId = '' } = useParams()
  useEffect(() => {
    const check = () => { const next = JSON.stringify(getIdentity()); if (next !== identity) { setIdentity(next); setDenied(false) } }
    const reject = () => setDenied(true)
    window.addEventListener('storage', check); window.addEventListener('focus', check); window.addEventListener('scientific-program-denied', reject)
    const timer = setInterval(check, 500)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check); window.removeEventListener('scientific-program-denied', reject) }
  }, [identity])
  if (denied || !canViewPrograms(JSON.parse(identity))) return <Notice error>لم تعد الصلاحية أو هوية الحساب متاحة؛ مُسحت بيانات البرامج.</Notice>
  return <ProgramWorkspace key={`${identity}:${programId}`} programId={programId} onUnauthorized={() => setDenied(true)} />
}

function ProgramWorkspace({ programId, onUnauthorized }) {
  const navigate = useNavigate()
  const [filters, setFilters] = useState({ q: '', college: null, department: null, status: '', sort: 'program_code', direction: 'asc', page: 1 })
  const [versionId, setVersionId] = useState(''), [tab, setTab] = useState('program'), [refresh, setRefresh] = useState(0)
  const [editor, setEditor] = useState(null), [epoch, setEpoch] = useState(0), [notice, setNotice] = useState(''), [error, setError] = useState(null)
  const [busy, setBusy] = useState(false), [dirty, setDirty] = useState(false), [blocked, setBlocked] = useState(false), [transition, setTransition] = useState(null)
  const [retained, setRetained] = useState(null)
  const controls = useRef({ busy: false, dirty: false, blocked: false })
  const loadAction = useRef(0)
  const markBusy = value => { controls.current.busy = value; setBusy(value) }
  const markDirty = value => { controls.current.dirty = value; setDirty(value) }
  const markBlocked = value => { controls.current.blocked = value; setBlocked(value) }
  const resetControls = () => { markBusy(false); markDirty(false); markBlocked(false) }
  const blocker = useBlocker(() => canViewPrograms(getIdentity()) && Object.values(controls.current).some(Boolean))
  useEffect(() => {
    const leave = e => { if (Object.values(controls.current).some(Boolean)) { e.preventDefault(); e.returnValue = '' } }
    window.addEventListener('beforeunload', leave); return () => window.removeEventListener('beforeunload', leave)
  }, [])
  const query = queryString({ q: filters.q.trim(), college_id: filters.college?.id, department_id: filters.department?.id, status: filters.status, sort: filters.sort, direction: filters.direction, page: filters.page })
  const read = useCatalogRead(programId ? `/${programId}` : `/?${query}`, { loader: programRead, delay: programId ? 0 : 350, refresh })
  const plan = useCatalogRead(programId && versionId ? `/${programId}/versions/${versionId}` : null, { loader: programRead, refresh })
  const p = read.data?.program, caps = read.data?.capabilities || {}
  function go(action) {
    if (controls.current.busy) { setNotice('انتظر نتيجة العملية قبل الانتقال.'); return }
    const proceed = () => { loadAction.current++; action() }
    if (controls.current.dirty || controls.current.blocked) { setTransition(() => proceed); return }
    proceed()
  }
  function open(context) { go(() => { loadAction.current++; resetControls(); setEditor(context); setEpoch(n => n + 1); setNotice(''); setError(null) }) }
  function close() { go(() => { loadAction.current++; setEditor(null); markDirty(false); markBlocked(false) }) }
  function filter(key, value) { go(() => setFilters(f => ({ ...f, [key]: value, page: 1, ...(key === 'college' ? { department: null } : {}) }))) }
  function version(value) { if (value === versionId) return; go(() => { setVersionId(value); setEditor(null); resetControls(); setRetained(null) }) }
  function saved(result) {
    if (result.deleted) { resetControls(); setEditor(null); navigate('/vp/scientific/programs'); return }
    const newId = !programId && result.program?.academic_program_id
    resetControls(); setEditor(null); setRefresh(n => n + 1); setNotice('تم الحفظ بنجاح.'); setError(null)
    if (retained && editor?.kind === 'requirements') setRetained(s => ({ ...s, current: result }))
    if (newId) navigate(`/vp/scientific/programs/${newId}`)
  }
  function restart(current) {
    go(() => { resetControls(); setEpoch(n => n + 1); setEditor(e => ({ ...e, baseline: current, draft: undefined })) })
  }
  useEffect(() => () => { loadAction.current++ }, [])
  const loadPreview = useCallback(async (path, context) => {
    const token = ++loadAction.current
    try { const baseline = await programRead(path); if (token === loadAction.current) { setEditor({ ...context, baseline }); setEpoch(n => n + 1) } }
    catch (e) { if (token === loadAction.current) setError(catalogError(e)) }
  }, [])
  function decision(kind, message, actionPath, baseline = plan.data, method = 'POST') {
    open({ kind, message, actionPath, method, baseline, programId, versionId })
  }
  const planEditable = plan.data?.version.status === 'draft' && plan.data?.capabilities.plans
  const titles = { program: 'بيانات البرنامج', requirements: 'متطلبات التخرج', membership: 'مواد الخطة', copy: 'إنشاء نسخة للتعديل', approve: 'اعتماد الخطة', default: 'تعيين للطلاب الجدد', transfer: 'نقل الطلاب بين الخطط', begin: 'بدء تهيئة الخطط', fix: 'تثبيت المرجع الحالي', archive: 'أرشفة البرنامج', restore: 'استعادة البرنامج', delete: 'حذف برنامج غير مستخدم' }
  return <main dir="rtl" className="min-w-0 space-y-5 px-2 py-6 text-text-dark">
    <header className="flex flex-wrap items-start justify-between gap-3 rounded-[18px] border border-primary/12 bg-white px-6 py-5"><div><h1 className="text-[22px] font-black">{p?.program_name || 'البرامج الأكاديمية'}</h1><p className="mt-2 text-[13.5px] leading-7 text-text-light">{programId ? 'بيانات البرنامج وخططه ومتطلبات التخرج — كل إجراء ضمن صلاحيتك.' : 'البرامج والخطط المعتمدة وإسنادات الطلاب، مع حفظ التاريخ الأكاديمي.'}</p></div><div className="flex flex-wrap gap-2">{programId ? <Link className="text-[12px] font-bold text-primary" to="/vp/scientific/programs">العودة إلى البرامج</Link> : caps.edit && <Button primary disabled={!read.data} onClick={() => open({ kind: 'program', baseline: read.data })}><FaPlus /> إضافة برنامج</Button>}<Link className="text-[12px] font-bold text-primary" to="/vp/scientific/courses">إدارة المواد</Link></div></header>
    <Notice>{notice}</Notice><Notice error>{error}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice>
    <Button onClick={() => setRefresh(n => n + 1)}>تحديث البيانات</Button>
    {!programId && <>
      <FilterBar search={{ value: filters.q, onChange: value => filter('q', value), placeholder: 'بحث بالاسم أو الرمز' }} />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><CatalogLookup loader={programRead} resource="colleges" label="الكلية" clearLabel="كل الكليات" value={filters.college} onChange={o => filter('college', o)} /><CatalogLookup loader={programRead} key={filters.college?.id || 'all'} resource="departments" label="القسم" clearLabel="كل الأقسام" context={{ college_id: filters.college?.id }} value={filters.department} onChange={o => filter('department', o)} />
        <Field label="الحالة"><Select value={filters.status} onChange={e => filter('status', e.target.value)}><option value="">كل الحالات</option><option value="active">فعّال</option><option value="inactive">غير فعّال</option><option value="archived">مؤرشف</option></Select></Field><Field label="ترتيب حسب"><Select value={filters.sort} onChange={e => filter('sort', e.target.value)}><option value="program_code">الرمز</option><option value="program_name">الاسم</option><option value="duration_years">المدة</option><option value="total_credit_hours">الساعات</option></Select></Field><Field label="الاتجاه"><Select value={filters.direction} onChange={e => filter('direction', e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></Select></Field>
      </div>
      <DataTable rows={read.data?.data || []} rowKey={row => row.academic_program_id} loading={read.loading} emptyIcon={FaClipboardList} emptyTitle="لا توجد برامج مطابقة" columns={[
        { key: 'program_code', header: 'الرمز', render: row => row.program_code || 'غير محدد' }, { key: 'program_name', header: 'البرنامج', render: row => <Link className="font-semibold text-primary" to={`/vp/scientific/programs/${row.academic_program_id}`}>{row.program_name}</Link> },
        { key: 'college', header: 'الكلية', render: row => row.department?.college?.college_name || 'غير محدد' }, { key: 'department', header: 'القسم', render: row => row.department?.department_name || 'غير محدد' }, { key: 'degree_level', header: 'الدرجة العلمية', render: row => row.degree_level || 'غير محدد' }, { key: 'duration_years', header: 'المدة', render: row => row.duration_years ?? 'غير محدد' },
        { key: 'hours', header: 'ساعات التخرج', render: row => <>{row.plan_total_credit_hours ?? row.total_credit_hours ?? 'لم يحدد'}<small className="block text-slate-500">{row.default_plan_label ? `للطلاب الجدد: ${row.default_plan_label}` : 'بيانات البرنامج — لا خطة افتراضية'}</small></> }, { key: 'actions', header: 'الإجراءات', render: row => <Link className="font-semibold text-primary" to={`/vp/scientific/programs/${row.academic_program_id}`}>استعراض وإدارة</Link> }, { key: 'status', header: 'الحالة', render: row => row.archived_at ? 'مؤرشف — مستمر للطلاب الحاليين' : row.is_active ? 'فعّال' : 'غير فعّال' }, { key: 'setup', header: 'تهيئة الخطط', render: row => SETUP_LABELS[row.plan_setup] || 'حالة غير معروفة' },
      ].map(column => ({ ...column, cellClassName: 'text-[12.5px]' }))} page={read.data?.meta.current_page || filters.page} totalPages={read.data?.meta.last_page || 1} onPageChange={page => go(() => setFilters(f => ({ ...f, page })))} />
    </>}
    {programId && p && <>
      <p className="text-[13px] text-slate-600">{p.department?.college?.college_name} / {p.department?.department_name}</p>
      <Notice>{SETUP_LABELS[p.plan_setup] || 'حالة غير معروفة'}{p.archived_at && ' — البرنامج مؤرشف؛ العمليات الأكاديمية للطلاب الحاليين مستمرة.'}</Notice>
      <nav aria-label="أقسام البرنامج" className="flex flex-wrap gap-2">{['program', 'requirements', 'membership', 'versions'].map(name => <Button key={name} primary={tab === name} onClick={() => { if (tab !== name) go(() => setTab(name)) }}>{name === 'versions' ? 'الإصدارات' : titles[name]}</Button>)}</nav>
      {tab === 'program' ? <section className="space-y-4 rounded-[16px] border border-primary/12 bg-white p-5"><dl className="grid gap-3 text-[13px] sm:grid-cols-2">{[['program_code', 'الرمز'], ['degree_level', 'الدرجة العلمية'], ['duration_years', 'المدة'], ['total_credit_hours', 'إجمالي ساعات البرنامج']].map(([key, title]) => <div key={key}><dt className="font-bold">{title}</dt><dd>{p[key] ?? 'لم يحدد'}</dd></div>)}</dl><p>{p.department?.college?.college_name} / {p.department?.department_name}</p><p>{p.description}</p><Notice>{caps.academic_lock_reason}</Notice><div className="flex flex-wrap gap-2">{caps.edit && <Button onClick={() => open({ kind: 'program', programId, baseline: read.data })}>تعديل البيانات</Button>}{caps.archive && <Button onClick={() => decision(p.archived_at ? 'restore' : 'archive', 'الأرشفة تمنع القبول الجديد فقط، ولا تنهي عمليات الطلاب الحاليين.', `/${programId}/${p.archived_at ? 'restore' : 'archive'}`, read.data)}>{p.archived_at ? 'استعادة البرنامج' : 'أرشفة البرنامج'}</Button>}{caps.delete && <Button danger onClick={() => go(() => loadPreview(`/${programId}/deletion-preview`, { kind: 'delete', programId, actionPath: `/${programId}`, method: 'DELETE', message: 'الحذف الحقيقي متاح فقط دون ارتباطات مانعة.' }))}>معاينة الحذف</Button>}</div></section> : <>
        <Field label="الخطة التي تعمل عليها"><Select value={versionId} onChange={e => version(e.target.value)}><option value="">اختر إصدار الخطة صراحةً</option>{read.data.versions.map(v => <option key={v.academic_plan_version_id} value={v.academic_plan_version_id}>{v.label} — {VERSION_LABELS[v.status] || 'غير معروف'}{Number(p.default_academic_plan_version_id) === Number(v.academic_plan_version_id) ? ' — للطلاب الجدد' : ''}</option>)}</Select></Field>
        {caps.plans && p.plan_state === 'legacy' && <Button primary onClick={() => decision('begin', 'بدء التهيئة يوقف القبول وإنشاء الطلاب الجدد حتى اعتماد خطة وتعيينها صراحةً. تبقى عمليات الطلاب الحاليين متاحة.', `/${programId}/initialization`, read.data)}>بدء تهيئة الخطط</Button>}
        {caps.plans && p.plan_state === 'preparing' && !read.data.versions.length && <Button primary onClick={() => go(() => loadPreview(`/${programId}/transition-preview`, { kind: 'fix', programId, actionPath: `/${programId}/transition`, message: 'يحفظ المرجع الحالة الفعلية ولا يُصلح النواقص ولا يمنح اعتمادًا تاريخيًا. سيُسند إليه الطلاب الحاليون مع حفظ سياق عملياتهم.' }))}>معاينة المرجع الحالي وتثبيته</Button>}
        <Notice error>{plan.error && catalogError(plan.error)}</Notice>{plan.loading && <Notice>جاري تحميل الخطة المختارة…</Notice>}
        {plan.data && <section className="space-y-4 rounded-[16px] border border-primary/12 bg-white p-5"><h2 className="text-[16px] font-black">{plan.data.version.label}</h2><Notice>{VERSION_LABELS[plan.data.version.status]}{!planEditable && ' — المواد والمتطلبات ثابتة؛ أنشئ نسخة للتعديل.'}</Notice>
          {tab === 'versions' && <><div className="flex flex-wrap gap-2">{caps.plans && ['approved', 'transitional'].includes(plan.data.version.status) && <Button onClick={() => decision('copy', 'ستُنشأ مسودة مستقلة؛ لا تتغير إسنادات الطلاب.', `/${programId}/versions/${versionId}/copy`)}>إنشاء نسخة للتعديل</Button>}{caps.approve && plan.data.version.status === 'draft' && <Button primary onClick={() => decision('approve', 'الاعتماد يثبت مواد الخطة ومتطلباتها. لا يعيّنها تلقائيًا للطلاب الجدد.', `/${programId}/versions/${versionId}/approve`)}>اعتماد الخطة</Button>}{caps.assign && plan.data.version.status === 'approved' && <><Button onClick={() => decision('default', 'يستخدم إنشاء الطلاب اللاحق هذه الخطة صراحةً؛ لا يُنقل أي طالب سابق.', `/${programId}/versions/${versionId}/default`)}>تعيين للطلاب الجدد</Button><Button onClick={() => decision('transfer', 'راجع أثر النقل قبل التأكيد.', `/${programId}/versions/${versionId}/transfer`)}>نقل الطلاب — إجراء مستقل</Button></>}</div>{plan.data.configuration.issues.map(issue => <Notice key={issue} error>{issue}</Notice>)}</>}
          {tab === 'requirements' && <><PlanRequirements data={plan.data} />{planEditable && <Button primary onClick={() => open({ kind: 'requirements', programId, versionId, baseline: plan.data })}>تعديل متطلبات التخرج</Button>}</>}
          {tab === 'membership' && <>{planEditable && <Button primary onClick={() => open({ kind: 'membership', programId, versionId, baseline: plan.data })}>إضافة مادة موجودة للخطة</Button>}{planEditable && <Button onClick={() => open({ kind: 'create-course', programId, versionId })}>إنشاء مادة في الدليل ثم ربطها</Button>}<DataTable rows={plan.data.courses} rowKey={c => c.program_course_id} columns={[{ key: 'course', header: 'المادة — الاسم والرمز', render: c => <Button onClick={() => open({ kind: 'course-information', course: c.course })}>{c.course?.course_name} ({c.course?.course_code})</Button> }, { key: 'type', header: 'التصنيف', render: c => `${SCOPES[c.requirement_mapping?.requirement_group?.requirement_scope] || 'غير مصنف'} — ${TYPES[c.course_type] || 'غير محدد'}` }, { key: 'advisory', header: 'معلومات إرشادية', render: c => `${c.academic_level?.level_name || 'مستوى غير محدد'} — ${c.recommended_semester?.semester_name || 'فصل غير محدد'}` }, { key: 'action', header: 'الإجراء', render: c => planEditable ? <div className="flex flex-wrap gap-2"><Button onClick={() => open({ kind: 'membership', programId, versionId, baseline: plan.data, membership: c })}>تعديل التصنيف</Button><Button danger onClick={() => decision('remove-course', 'إزالة المادة من هذه المسودة فقط؛ لا يتغير الدليل أو أي خطة ثابتة.', `/${programId}/versions/${versionId}/courses/${c.course_id}`, plan.data, 'DELETE')}>إزالة من المسودة</Button></div> : 'قراءة فقط' }]} emptyIcon={FaClipboardList} emptyTitle="لا توجد مواد في هذه الخطة" /></>}
        </section>}
      </>}
      {retained?.current && <Notice>حُفظت المتطلبات. اختيار المادة ما زال محفوظًا.<Button onClick={() => { const savedDraft = retained; setRetained(null); open({ kind: 'membership', programId, versionId: savedDraft.versionId, baseline: savedDraft.current, draft: savedDraft.draft }) }}>مراجعة اختيار المادة والمتابعة</Button></Notice>}
    </>}
    {editor && <CatalogDialog wide closeButton title={titles[editor.kind] || (editor.kind === 'create-course' ? 'إنشاء مادة في الدليل' : editor.kind === 'course-information' ? 'بيانات المادة' : 'إزالة المادة من المسودة')} onClose={close}>{editor.kind === 'course-information' ? <><h3>{editor.course.course_name} ({editor.course.course_code})</h3><dl className="grid gap-3 text-[12.5px] sm:grid-cols-3">{[['credit_hours', 'الساعات المعتمدة'], ['theoretical_hours', 'الساعات النظرية'], ['practical_hours', 'الساعات العملية']].map(([key, title]) => <div key={key}><dt>{title}</dt><dd>{editor.course[key] ?? 'غير محدد'}</dd></div>)}</dl><p>{editor.course.description}</p><Notice>قراءة بيانات المادة من الخطة المختارة؛ لا يعدّل هذا العرض الدليل أو النتائج.</Notice></> : editor.kind === 'create-course' ? <ProgramCourseCreation key={epoch} editor={editor} busy={busy} onBusy={markBusy} onDirty={markDirty} onBlocked={markBlocked} onUnauthorized={onUnauthorized} onContinue={(baseline, course) => open({ kind: 'membership', programId, versionId: editor.versionId, baseline, draft: { course: { id: course.course_id, label: `${course.course_name} (${course.course_code})` }, requirement_scope: 'university', course_type: 'mandatory', level: null, semester: null, is_active: true } })} /> : ['program', 'requirements', 'membership'].includes(editor.kind) ? <ProgramForms key={epoch} editor={editor} busy={busy} onBusy={markBusy} onDirty={markDirty} onBlocked={markBlocked} onUnauthorized={onUnauthorized} onSaved={saved} onRestart={restart} onConfigure={draft => { setRetained({ draft, versionId: editor.versionId }); resetControls(); setEditor(e => ({ ...e, kind: 'requirements', draft: undefined })); setEpoch(n => n + 1) }} /> : <ProgramDecision key={epoch} editor={editor} busy={busy} onBusy={markBusy} onDirty={markDirty} onBlocked={markBlocked} onUnauthorized={onUnauthorized} onSaved={saved} onRestart={restart} />}</CatalogDialog>}
    {(transition || blocker.state === 'blocked') && <CatalogDialog title={busy ? 'العملية قيد التنفيذ' : 'تغييرات غير محفوظة'} onClose={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}><p>{busy ? 'انتظر نتيجة العملية قبل الانتقال.' : blocked ? 'قد تكون العملية نجحت رغم انقطاع الاتصال. راجع الحالة قبل أي إعادة محاولة.' : 'هل تريد تجاهل تعديلاتك والانتقال؟'}</p><div className="flex flex-wrap gap-2"><Button onClick={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}>البقاء في المحرر</Button><Button danger disabled={busy} onClick={() => { const action = transition; setTransition(null); resetControls(); if (blocker.state === 'blocked') blocker.proceed(); else action?.() }}>تجاهل التعديلات والمتابعة</Button></div></CatalogDialog>}
    <span className="sr-only" aria-live="polite">{dirty && 'توجد تعديلات غير محفوظة'}</span>
  </main>
}
