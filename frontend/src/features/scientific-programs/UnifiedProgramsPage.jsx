import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useBlocker, useLocation, useParams } from 'react-router-dom'
import { FaClipboardList, FaPlus, FaSave } from 'react-icons/fa'
import { ACCESS, canAccess, getIdentity } from '../auth/auth'
import { Button, CatalogDialog, CatalogLookup, Field, Input, Notice, Select } from '../scientific-courses/CatalogControls'
import { catalogError, queryString } from '../scientific-courses/catalog'
import useCatalogRead from '../scientific-courses/useCatalogRead'
import ScientificCoursesPage from '../scientific-courses/ScientificCoursesPage'
import ScientificProgramsPage from './ScientificProgramsPage'
import { programRead, programWrite, PROGRAM_ACCESS, SCOPES, TYPES } from './programs'
import { actualChanges, changePayload, courseChanges, courseIdentity, groupActivityLabel, preparation, requirementGroupChanges, upsertPreparedCourse, WORKSPACE_PATH } from './workspace'
import WorkspaceCourseEditor from './WorkspaceCourseEditor'
import WorkspaceCurriculum from './WorkspaceCurriculum'
import WorkspaceHistory from './WorkspaceHistory'
import Requirements from './WorkspaceRequirements'

export default function UnifiedProgramsPage({ catalogOnly = false }) {
  const location = useLocation(), { programId } = useParams()
  const [identity, setIdentity] = useState(() => JSON.stringify(getIdentity())), [denied, setDenied] = useState(false)
  useEffect(() => {
    const check = () => { const next = JSON.stringify(getIdentity()); if (next !== identity) { setIdentity(next); setDenied(false) } }
    const deny = () => setDenied(true)
    window.addEventListener('storage', check); window.addEventListener('focus', check); window.addEventListener('scientific-program-denied', deny)
    const timer = setInterval(check, 500)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check); window.removeEventListener('scientific-program-denied', deny) }
  }, [identity])
  if (denied) return <Notice error>انتهت الصلاحية؛ أُخفيت بيانات الإعداد.</Notice>
  if (catalogOnly || new URLSearchParams(location.search).get('catalog') === '1' || !canAccess(PROGRAM_ACCESS, JSON.parse(identity))) return <ScientificCoursesPage />
  if (new URLSearchParams(location.search).get('advanced') === '1') return <ScientificProgramsPage />
  return <Workspace key={`${identity}:${programId || ''}:${location.search}`} initialProgram={programId || new URLSearchParams(location.search).get('program')} versionId={new URLSearchParams(location.search).get('version')} initialTab={new URLSearchParams(location.search).get('tab')} onDenied={() => setDenied(true)} />
}

function Workspace({ initialProgram, versionId, initialTab, onDenied }) {
  const [college, setCollege] = useState(null), [department, setDepartment] = useState(null), [program, setProgram] = useState(initialProgram ? { id: initialProgram, label: 'البرنامج المختار' } : null)
  const [q, setQ] = useState(''), [level, setLevel] = useState(null), [scope, setScope] = useState(''), [refresh, setRefresh] = useState(0)
  const [sourceVersion, setSourceVersion] = useState(versionId || null)
  const [drafts, setDrafts] = useState([]), [originals, setOriginals] = useState([]), [newCourses, setNewCourses] = useState([]), [labels, setLabels] = useState({})
  const [revision, setRevision] = useState(null), [dirty, setDirty] = useState(false), [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(null)
  const [error, setError] = useState(''), [notice, setNotice] = useState(''), [editor, setEditor] = useState(null), [confirm, setConfirm] = useState(false)
  const [historyPage, setHistoryPage] = useState(1), [targetCollege, setTargetCollege] = useState(null), [targetDepartment, setTargetDepartment] = useState(null)
  const [targetSources, setTargetSources] = useState([]), [targetVersion, setTargetVersion] = useState(null)
  const [settings, setSettings] = useState(initialTab === 'requirements' || initialTab === 'program'), [history, setHistory] = useState(initialTab === 'versions'), [targetChoice, setTargetChoice] = useState(null), [transition, setTransition] = useState(null)
  const writing = useRef(false), alive = useRef(true), sequence = useRef(0)
  useEffect(() => { alive.current = true; const counter = sequence; return () => { alive.current = false; counter.current++ } }, [])
  const blocker = useBlocker(useCallback(() => dirty || busy || !!uncertain, [dirty, busy, uncertain]))
  useEffect(() => { const before = event => { if (dirty || busy || uncertain) { event.preventDefault(); event.returnValue = '' } }; window.addEventListener('beforeunload', before); return () => window.removeEventListener('beforeunload', before) }, [dirty, busy, uncertain])
  const deps = useCatalogRead(college ? `/options?${queryString({ resource: 'departments', college_id: college.id, per_page: 100 })}` : null, { loader: programRead })
  const automaticDepartment = deps.data?.unambiguous && deps.data?.meta.total === 1 && deps.data.data[0]?.code?.endsWith('-GEN') ? deps.data.data[0] : null
  const selectedDepartment = department || automaticDepartment
  const programs = useCatalogRead(selectedDepartment ? `/options?${queryString({ resource: 'programs', department_id: selectedDepartment.id, per_page: 100 })}` : null, { loader: programRead })
  const selectedProgram = program || (programs.data?.unambiguous && programs.data?.meta.total === 1 ? programs.data.data[0] : null)
  const read = useCatalogRead(selectedProgram ? `/workspace?${queryString({ academic_program_id: selectedProgram.id, version_id: sourceVersion, per_page: 100 })}` : null, { loader: programRead, refresh })
  const past = useCatalogRead(history && selectedProgram ? `/${selectedProgram.id}/history?page=${historyPage}` : null, { loader: programRead, refresh })
  const activeDraft = drafts.find(d => Number(d.program.academic_program_id) === Number(selectedProgram?.id))
  const shown = activeDraft?.courses || read.data?.values?.courses || []
  const allowSave = read.data?.can_save && !busy && !uncertain
  const clear = () => { setDrafts([]); setOriginals([]); setNewCourses([]); setLabels({}); setTargetChoice(null); setTargetSources([]); setTargetVersion(null); setDirty(false); setUncertain(null); setEditor(null); setError(''); setSourceVersion(versionId || null); sequence.current++ }
  const change = action => { if (busy || uncertain || dirty) setTransition(() => action); else { clear(); action() } }
  const begin = () => {
    if (!read.data || !allowSave) return
    const d = preparation(read.data)
    setDrafts([d]); setOriginals([structuredClone(d)]); setRevision(read.data.revision)
    setLabels(Object.fromEntries(read.data.plan.courses.map(c => [`id:${c.course_id}`, `${c.course?.course_name || 'مادة'} (${c.course?.course_code || 'غير محدد'})`])))
    setNotice('يمكن إعداد عدة مواد والمتطلبات قبل حفظ واحد. لم تتغير قاعدة البيانات.'); setSettings(true)
  }
  const replace = next => { setDrafts(ds => ds.map(d => d.program.academic_program_id === next.program.academic_program_id ? next : d)); setDirty(true) }
  const chooseTarget = value => { setTargetChoice(value); setTargetSources([]); setTargetVersion(null) }
  const addTarget = async () => {
    if (!targetChoice || writing.current || drafts.some(d => Number(d.program.academic_program_id) === Number(targetChoice.id))) return
    const token = ++sequence.current; setBusy(true); setError('')
    try {
      const snapshot = await programRead(`/workspace?${queryString({ academic_program_id: targetChoice.id, version_id: targetVersion })}`)
      if (!alive.current || token !== sequence.current) return
      if (snapshot.revision !== revision) throw new Error('تغيرت البيانات؛ راجع الإعداد الحالي قبل إضافة البرنامج. لم تُبدّل مدخلاتك.')
      if (snapshot.requires_source_selection) { setTargetSources(snapshot.source_choices); setNotice(snapshot.lock_reason); return }
      if (!snapshot.can_save) throw new Error(snapshot.lock_reason)
      const next = preparation(snapshot)
      setDrafts(ds => [...ds, next]); setOriginals(ds => [...ds, structuredClone(next)]); chooseTarget(null)
      setLabels(old => ({ ...old, ...Object.fromEntries(snapshot.plan.courses.map(c => [`id:${c.course_id}`, `${c.course?.course_name} (${c.course?.course_code})`])) }))
    } catch (failure) { if (alive.current) { if ([401, 403].includes(failure.status)) onDenied(); else setError(catalogError(failure)) } }
    finally { if (alive.current && token === sequence.current) setBusy(false) }
  }
  const applyCourse = (membership, origin, label) => {
    if (origin) setNewCourses(cs => [...cs.filter(c => c.key !== origin.key), origin])
    setLabels(old => ({ ...old, [courseIdentity(membership)]: label }))
    replace(upsertPreparedCourse(activeDraft, membership)); setEditor(null)
  }
  const save = async (retry = false) => {
    if (writing.current || busy || (uncertain && !retry) || (!retry && !allowSave)) return
    writing.current = true; setBusy(true); setError('')
    // Explicit user retry only: same immutable request/content, never a new revision or UUID.
    const payload = retry ? uncertain : changePayload(crypto.randomUUID(), revision, drafts, newCourses)
    try {
      const result = await programWrite('/plan-changes', 'POST', payload)
      if (!alive.current) return
      clear(); setConfirm(false); setSettings(false); setRefresh(n => n + 1)
      setNotice(result.changed ? 'حُفظت التغييرات واعتمدت للطلاب الجدد فقط. لم تُنقل خطط الطلاب الحاليين.' : 'لا يوجد تغيير فعلي؛ لم تُنشأ خطة إضافية.')
    } catch (failure) {
      if (!alive.current) return
      if ([401, 403].includes(failure.status)) { onDenied(); return }
      setError(catalogError(failure)); setConfirm(false)
      if (failure.status !== 422) setUncertain(payload)
    } finally { writing.current = false; if (alive.current) setBusy(false) }
  }
  const inspect = async () => {
    if (!uncertain || busy) return
    setBusy(true)
    try {
      const result = await programRead(`/plan-changes/${uncertain.request_id}`)
      if (!alive.current) return
      if (result.status === 'confirmed') { clear(); setRefresh(n => n + 1); setNotice(result.result.changed ? 'تأكد نجاح الحفظ السابق؛ لم تُكرر العملية.' : 'تأكد عدم وجود تغيير فعلي.') }
      else { setError('لم تظهر نتيجة محفوظة بعد. هذا لا يثبت فشل الحفظ؛ لا تعاد العملية تلقائيًا.'); setRefresh(n => n + 1) }
    } catch (failure) { if (alive.current) { if ([401, 403].includes(failure.status)) onDenied(); else setError(catalogError(failure)) } }
    finally { if (alive.current) setBusy(false) }
  }
  const labelFor = c => labels[courseIdentity(c)] || (() => { const course = read.data?.plan.courses.find(pc => Number(pc.course_id) === Number(c.course_id))?.course; return `${course?.course_name || 'مادة'} (${course?.course_code || c.course_id})` })()
  const tableRows = shown.filter(c => (!level || Number(c.academic_level_id) === Number(level.id)) && (!scope || c.requirement_scope === scope)
    && (!q.trim() || labelFor(c).toLocaleLowerCase().includes(q.trim().toLocaleLowerCase())))
    .map(c => ({ ...c, label: labels[courseIdentity(c)] || (() => { const course = read.data?.plan.courses.find(pc => Number(pc.course_id) === Number(c.course_id))?.course; return `${course?.course_name || 'مادة'} (${course?.course_code || c.course_id})` })() }))
  return <main dir="rtl" className="space-y-4">
    <header className="flex flex-wrap items-start justify-between gap-3 rounded-[18px] border border-primary/12 bg-white px-6 py-5"><div><h1 className="text-[22px] font-black">البرامج والمواد</h1><p className="mt-2 text-[13.5px] leading-7 text-text-light">الخطة الإرشادية ومتطلبات التخرج — التعديلات الأكاديمية للطلاب الجدد فقط.</p></div><Link className="text-[12px] font-bold text-primary" to={`${WORKSPACE_PATH}?catalog=1`}>دليل المواد</Link></header>
    <div className="grid gap-3 rounded-[16px] border border-primary/12 bg-white p-5 sm:grid-cols-2"><CatalogLookup label="الكلية أو المعهد" resource="colleges" loader={programRead} value={college} disabled={busy} onChange={v => change(() => { setCollege(v); setDepartment(null); setProgram(null); setSourceVersion(null) })} clearLabel="اختر الكلية أو المعهد" />
      {!automaticDepartment && <CatalogLookup key={college?.id || 'none'} label="القسم" resource="departments" loader={programRead} context={{ college_id: college?.id }} value={department} disabled={!college || busy} onChange={v => change(() => { setDepartment(v); setProgram(null); setSourceVersion(null) })} clearLabel="اختر القسم" />}
      {programs.data?.meta.total > 0 && !programs.data?.unambiguous && <><Notice error>لا يمكن إثبات انفراد البرنامج في هذا القسم؛ اختر البرنامج صراحة.</Notice><CatalogLookup label="البرنامج" resource="programs" loader={programRead} context={{ department_id: selectedDepartment?.id }} value={program} onChange={v => change(() => { setProgram(v); setSourceVersion(null) })} /></>}
    </div>
    {deps.data?.meta.total === 0 && <Notice>لا توجد أقسام فعالة متاحة لهذه الكلية ضمن نطاقك. لم يُنشأ قسم افتراضي.</Notice>}
    {programs.data?.meta.total === 0 && <Notice>لا يوجد برنامج فعال متاح لهذا القسم ضمن نطاقك. لم تُنشأ بيانات افتراضية.</Notice>}
    {canAccess(ACCESS.academicStructure) && <div className="flex gap-3 text-[12px] font-bold text-primary"><Link to="/academic-structure/colleges">إدارة الكليات</Link><Link to="/academic-structure/departments">إدارة الأقسام</Link><Link to="/academic-structure/programs">إدارة البرامج</Link></div>}
    {read.loading && <Notice>تحميل الخطة الحالية…</Notice>}{read.error && <Notice error>{catalogError(read.error)}<Button onClick={() => setRefresh(n => n + 1)}>إعادة التحميل</Button></Notice>}
    <Notice>{notice}</Notice><Notice error>{error}</Notice>
    {uncertain && <Notice error>احتُفظ بإعدادك دون ربطه بإصدار جديد. تحقق من نتيجة الحفظ قبل أي محاولة أخرى.<Button disabled={busy} onClick={inspect}>التحقق من نتيجة الحفظ</Button><Button disabled={busy} onClick={() => save(true)}>إعادة إرسال الطلب نفسه دون تغيير</Button><Button disabled={busy} onClick={() => setTransition(() => () => setRefresh(n => n + 1))}>مراجعة الحالة وتجاهل الإعداد</Button></Notice>}
    {read.data?.requires_source_selection && <section className="space-y-3 rounded-[16px] border border-primary/12 bg-white p-5"><h2 className="font-bold">{read.data.program.program_name}</h2><Notice>{read.data.lock_reason}</Notice><Field label="الخطة التي تريد استكمالها"><Select value="" onChange={e => { if (e.target.value) change(() => setSourceVersion(e.target.value)) }}><option value="">اختر الخطة صراحة</option>{read.data.source_choices.map(v => <option key={v.academic_plan_version_id} value={v.academic_plan_version_id}>{v.label}</option>)}</Select></Field></section>}
    {read.data?.plan && <>
      <section className="space-y-4 rounded-[16px] border border-primary/12 bg-white p-5"><div className="flex flex-wrap items-center justify-between gap-2"><div><h2 className="text-[16px] font-black">{read.data.program.program_name}</h2><p className="text-[12px] text-text-light">{read.data.program.department?.college?.college_name} — {read.data.program.department?.department_name}</p></div><div className="flex flex-wrap gap-2"><Button onClick={() => setSettings(v => !v)}>إعدادات البرنامج</Button><Button onClick={() => setHistory(v => !v)}>سجل التغييرات</Button>{drafts.length === 0 && <Button primary disabled={!allowSave} onClick={begin}><FaClipboardList />إعداد التغييرات</Button>}</div></div>
        <Notice>{read.data.lock_reason}</Notice>{read.data.lock_reason && sourceVersion && <Link className="text-[12px] font-bold text-primary" to={`${WORKSPACE_PATH}?program=${selectedProgram.id}`}>فتح خطة الطلاب الجدد</Link>}{!read.data.plan.persisted && <Notice>هذه الخطة الحالية المسجلة فعلًا. عرضها لا ينشئ خطة أو إسنادًا ولا يوقف القبول.</Notice>}
        {settings && <><div className="flex flex-wrap gap-3 text-[12px]"><span>الدرجة: {read.data.program.degree_level}</span><span>المدة: {read.data.program.duration_years} سنوات</span><Link className="font-bold text-primary" to={`/vp/scientific/programs/${read.data.program.academic_program_id}?advanced=1`}>بيانات البرنامج وإجراءاته</Link></div>
          {(activeDraft ? [activeDraft] : [preparation(read.data)]).map(d => <Requirements key={d.program.academic_program_id} draft={d} editable={!!activeDraft && !busy && !uncertain} onChange={replace} />)}</>}
        <div className="grid gap-3 sm:grid-cols-3"><Field label="بحث بالاسم أو الرمز"><Input value={q} onChange={e => setQ(e.target.value)} /></Field><CatalogLookup label="السنة الدراسية" resource="levels" loader={programRead} value={level} onChange={setLevel} clearLabel="كل السنوات" /><Field label="تصنيف المتطلب"><Select value={scope} onChange={e => setScope(e.target.value)}><option value="">جميع مواد الخطة</option>{Object.entries(SCOPES).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</Select></Field></div>
        {activeDraft && <div className="flex flex-wrap gap-2"><Button disabled={busy || !!uncertain} onClick={() => setEditor({})}><FaPlus />إضافة مادة إلى الإعداد</Button><Button primary disabled={!allowSave} onClick={() => setConfirm(true)}><FaSave />حفظ وتطبيق للطلاب الجدد</Button></div>}
        <WorkspaceCurriculum key={`${level?.id || ''}:${scope}:${q.trim()}`} rows={tableRows} canEdit={!!activeDraft} busy={busy} uncertain={!!uncertain} source={read.data.plan.courses} origins={newCourses} onEdit={c => setEditor({ membership: c, choice: { id: c.course_id, label: c.label } })} onRemove={c => replace({ ...activeDraft, courses: activeDraft.courses.filter(item => courseIdentity(item) !== courseIdentity(c)) })} />
      </section>
      {drafts.length > 0 && <section className="space-y-3 rounded-[16px] border border-primary/12 bg-white p-5"><h3 className="font-bold">البرامج المختارة للعملية</h3>{drafts.map(d => <div key={d.program.academic_program_id} className="space-y-2"><p className="font-bold">{d.program.program_name}</p>{d !== activeDraft && <><Requirements draft={d} editable={!busy && !uncertain} onChange={replace} /><Button disabled={busy || !!uncertain} onClick={() => setEditor({ target: d })}>إضافة مادة مشتركة لهذا البرنامج</Button><WorkspaceCurriculum rows={d.courses.map(c => ({ ...c, label: labelFor(c) }))} canEdit busy={busy} uncertain={!!uncertain} source={d.source_courses} origins={newCourses} onEdit={c => setEditor({ target: d, membership: c, choice: { id: c.course_id, label: labelFor(c) } })} onRemove={c => replace({ ...d, courses: d.courses.filter(item => courseIdentity(item) !== courseIdentity(c)) })} /></>}</div>)}
        <div className="grid gap-3 sm:grid-cols-2"><CatalogLookup label="كلية البرنامج الإضافي" resource="colleges" loader={programRead} value={targetCollege} disabled={busy || !!uncertain} onChange={v => { setTargetCollege(v); setTargetDepartment(null); chooseTarget(null) }} /><CatalogLookup label="قسم البرنامج الإضافي" resource="departments" loader={programRead} context={{ college_id: targetCollege?.id }} value={targetDepartment} disabled={!targetCollege || busy || !!uncertain} onChange={v => { setTargetDepartment(v); chooseTarget(null) }} /><CatalogLookup label="إضافة برنامج محدد للعملية" resource="programs" loader={programRead} value={targetChoice} context={{ college_id: targetCollege?.id, department_id: targetDepartment?.id }} disabled={!targetCollege || busy || !!uncertain} onChange={chooseTarget} />{targetSources.length > 0 && <Field label="خطة البرنامج الإضافي التي تريد استكمالها"><Select value={targetVersion || ''} disabled={busy || !!uncertain} onChange={e => setTargetVersion(e.target.value || null)}><option value="">اختر الخطة صراحة</option>{targetSources.map(v => <option key={v.academic_plan_version_id} value={v.academic_plan_version_id}>{v.label}</option>)}</Select></Field>}<Button disabled={!targetChoice || busy || !!uncertain || (targetSources.length > 0 && !targetVersion)} onClick={addTarget}>إضافة البرنامج المختار</Button></div><Notice>اختيار متطلب جامعة لا يضيف المادة لأي برنامج غير مختار. يراجع كل برنامج وتصنيفه وتوزيعه قبل حفظ واحد.</Notice>
      </section>}
    </>}
    {history && <WorkspaceHistory read={past} page={historyPage} onPage={setHistoryPage} />}
    {editor && <CatalogDialog title="إعداد المادة" wide closeButton onClose={() => setEditor(null)}><WorkspaceCourseEditor existing={editor.membership ? editor : null} departmentId={(editor.target || activeDraft).program.department_id} canCreate={!!read.data?.can_create_course} preparedCourses={newCourses} onDirty={() => setDirty(true)} onClose={() => setEditor(null)} onApply={(membership, origin, label) => {
      if (editor.target) { if (origin) setNewCourses(cs => [...cs.filter(c => c.key !== origin.key), origin]); setLabels(old => ({ ...old, [courseIdentity(membership)]: label })); replace(upsertPreparedCourse(editor.target, membership)); setEditor(null) }
      else applyCourse(membership, origin, label)
    }} /></CatalogDialog>}
    {confirm && <CatalogDialog title="تأكيد التغييرات" onClose={() => { if (!busy) setConfirm(false) }}><Notice>تُطبّق على الطلاب الجدد فقط. تبقى خطط الطلاب الحاليين وحقوقهم محفوظة.</Notice>{drafts.map((d, i) => { const changes = actualChanges(originals[i], d); return <div key={d.program.academic_program_id} className="space-y-2 text-[13px] leading-7"><p><b>{d.program.program_name}</b>: إضافة {changes.added}، إزالة {changes.removed}، تعديل {changes.updated}{changes.requirements ? '، وتعديل متطلبات التخرج' : ''}.</p>{courseChanges(originals[i], d).map(c => <p key={c.identity}>{labelFor(c.after || c.before)}: {!c.before ? 'إضافة' : !c.after ? 'إزالة من الخطة' : 'تعديل التوزيع'} — {courseSummary(c.before)} ← {courseSummary(c.after)}</p>)}{changes.requirements && <><p>ساعات التخرج: {originals[i].requirements.total_credit_hours ?? 'غير محدد'} ← {d.requirements.total_credit_hours ?? 'غير محدد'}</p>{requirementGroupChanges(originals[i].requirements, d.requirements).map(g => <p key={g.identity}>{SCOPES[(g.after || g.before).requirement_scope]} — {TYPES[(g.after || g.before).requirement_type]}: الساعات {g.before?.required_credit_hours ?? 'غير محدد'} ← {g.after?.required_credit_hours ?? 'غير محدد'}؛ الحالة {groupActivityLabel(g.before?.is_active)} ← {groupActivityLabel(g.after?.is_active)}</p>)}</>}</div> })}<div className="flex flex-wrap gap-2"><Button primary disabled={busy} onClick={() => save()}>{busy ? 'جارٍ الحفظ…' : 'تأكيد الحفظ والتطبيق'}</Button><Button disabled={busy} onClick={() => setConfirm(false)}>العودة للإعداد</Button></div></CatalogDialog>}
    {(transition || blocker.state === 'blocked') && <CatalogDialog title="حماية الإعداد" onClose={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}><p>احتُفظ بإعدادك. {busy ? 'انتظر نتيجة الحفظ قبل الانتقال.' : uncertain ? 'قد يكون الحفظ نجح؛ تحقق من نتيجته قبل ترك الإعداد.' : 'هل تريد تجاهل التغييرات والانتقال؟'}</p><div className="flex flex-wrap gap-2"><Button onClick={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}>البقاء في الإعداد</Button><Button danger disabled={busy} onClick={() => { const action = transition; setTransition(null); clear(); if (blocker.state === 'blocked') blocker.proceed(); else action?.() }}>تجاهل الإعداد والمتابعة</Button></div></CatalogDialog>}
  </main>
}

const courseSummary = c => c ? `${SCOPES[c.requirement_scope] || 'غير مصنف'}، ${TYPES[c.course_type]}، السنة ${c.academic_level_id ?? 'غير محددة'}، الفصل ${c.recommended_semester_id ?? 'غير محدد'}، ${c.is_active ? 'فعال' : 'غير فعال'}` : 'خارج الخطة'
