import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, Navigate, useBlocker, useLocation, useNavigate, useParams } from 'react-router-dom'
import { FaClipboardList, FaPlus, FaSave } from 'react-icons/fa'
import { canAccess, getIdentity } from '../auth/auth'
import { Button, CatalogDialog, CatalogLookup, Field, Input, Notice, Select } from '../scientific-courses/CatalogControls'
import { canViewCatalog, catalogRead, catalogError, queryString } from '../scientific-courses/catalog'
import useCatalogRead from '../scientific-courses/useCatalogRead'
import ProgramForms from './ProgramForms'
import ProgramDecision from './ProgramDecision'
import ProgramEntityActions from './ProgramEntityActions'
import { EntityBreadcrumbs } from './AcademicEntityNavigation'
import { entityLink, safeEntityReturn } from './entities'
import { programRead, programWrite, PROGRAM_ACCESS, SCOPES, TYPES } from './programs'
import { actualChanges, changePayload, courseChanges, courseIdentity, groupActivityLabel, preparation, requirementGroupChanges, upsertPreparedCourse, WORKSPACE_PATH } from './workspace'
import WorkspaceCourseEditor from './WorkspaceCourseEditor'
import WorkspaceCurriculum from './WorkspaceCurriculum'
import WorkspaceHistory from './WorkspaceHistory'
import Requirements from './WorkspaceRequirements'

export default function UnifiedProgramsPage({ catalogOnly = false, entityProgramId }) {
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
  const query = new URLSearchParams(location.search), id = entityProgramId || programId || query.get('program')
  if (catalogOnly || query.get('catalog') === '1') return <Navigate replace to={entityLink('courses')} />
  if (!canAccess(PROGRAM_ACCESS, JSON.parse(identity))) return <Notice error>لا تملك صلاحية إدارة هذا البرنامج.</Notice>
  if (!id) return <Navigate replace to={entityLink('programs')} />
  return <Workspace key={`${identity}:${id}:${query.get('version') || ''}`} initialProgram={id} versionId={query.get('version')} initialTab={query.get('tab')} returnTo={query.get('return')} addCourseId={query.get('addCourse')} onDenied={() => setDenied(true)} />
}

function Workspace({ initialProgram, versionId, initialTab, returnTo, addCourseId, onDenied }) {
  const navigate = useNavigate()
  const selectedProgram = { id: initialProgram }
  const [q, setQ] = useState(''), [level, setLevel] = useState(null), [semester, setSemester] = useState(null), [scope, setScope] = useState(''), [refresh, setRefresh] = useState(0)
  const [sourceVersion, setSourceVersion] = useState(versionId || null)
  const [drafts, setDrafts] = useState([]), [originals, setOriginals] = useState([]), [newCourses, setNewCourses] = useState([]), [labels, setLabels] = useState({})
  const [revision, setRevision] = useState(null), [dirty, setDirty] = useState(false), [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(null)
  const [error, setError] = useState(''), [notice, setNotice] = useState(''), [editor, setEditor] = useState(null), [confirm, setConfirm] = useState(false)
  const [historyPage, setHistoryPage] = useState(1), [targetCollege, setTargetCollege] = useState(null), [targetDepartment, setTargetDepartment] = useState(null)
  const [targetSources, setTargetSources] = useState([]), [targetVersion, setTargetVersion] = useState(null)
  const [settings, setSettings] = useState(initialTab === 'requirements' || initialTab === 'program'), [history, setHistory] = useState(initialTab === 'versions'), [targetChoice, setTargetChoice] = useState(null), [transition, setTransition] = useState(null)
  const [infoEditor, setInfoEditor] = useState(null), [infoEpoch, setInfoEpoch] = useState(0), [checking, setChecking] = useState(false)
  const [infoBlocked, setInfoBlocked] = useState(false), [destination, setDestination] = useState(null)
  const [courseEditorDirty, setCourseEditorDirty] = useState(false), [closeCoursePrompt, setCloseCoursePrompt] = useState(false)
  const writing = useRef(false), alive = useRef(true), sequence = useRef(0)
  useEffect(() => { alive.current = true; const counter = sequence; return () => { alive.current = false; counter.current++ } }, [])
  const blocker = useBlocker(useCallback(() => dirty || courseEditorDirty || busy || checking || infoBlocked || !!uncertain, [dirty, courseEditorDirty, busy, checking, infoBlocked, uncertain]))
  useEffect(() => { const before = event => { if (dirty || courseEditorDirty || busy || checking || infoBlocked || uncertain) { event.preventDefault(); event.returnValue = '' } }; window.addEventListener('beforeunload', before); return () => window.removeEventListener('beforeunload', before) }, [dirty, courseEditorDirty, busy, checking, infoBlocked, uncertain])
  useEffect(() => { if (destination && !dirty && !busy && !checking && !infoBlocked && !uncertain) navigate(destination, { replace: true }) }, [destination, dirty, busy, checking, infoBlocked, uncertain, navigate])
  const read = useCatalogRead(selectedProgram ? `/workspace?${queryString({ academic_program_id: selectedProgram.id, version_id: sourceVersion, per_page: 100 })}` : null, { loader: programRead, refresh })
  const detail = useCatalogRead(`/${initialProgram}`, { loader: programRead, refresh })
  const linkCourse = useCatalogRead(addCourseId && /^[1-9]\d*$/.test(addCourseId) && canViewCatalog(getIdentity()) ? `/courses/${addCourseId}` : null, { loader: catalogRead })
  const past = useCatalogRead(history && selectedProgram ? `/${selectedProgram.id}/history?page=${historyPage}` : null, { loader: programRead, refresh })
  const activeDraft = drafts.find(d => Number(d.program.academic_program_id) === Number(selectedProgram?.id))
  const shown = activeDraft?.courses || read.data?.values?.courses || []
  const p = read.data?.program || detail.data?.program
  const draftStale = !!activeDraft && !!read.data && String(read.data.revision) !== String(revision)
  const metadataChanged = !!read.data && !!detail.data && read.data.revision !== detail.data.revision
  const displayedHours = read.data?.plan ? read.data.plan.version.total_credit_hours : detail.data?.program.total_credit_hours
  const allowSave = read.data?.can_save && !draftStale && !busy && !checking && !uncertain && !infoBlocked && !infoEditor
  const clear = () => { setDrafts([]); setOriginals([]); setNewCourses([]); setLabels({}); setTargetChoice(null); setTargetSources([]); setTargetVersion(null); setDirty(false); setUncertain(null); setEditor(null); setCourseEditorDirty(false); setCloseCoursePrompt(false); setInfoEditor(null); setInfoBlocked(false); setError(''); setSourceVersion(versionId || null); sequence.current++ }
  const change = action => { if (busy || checking || infoBlocked || uncertain || dirty || courseEditorDirty) setTransition(() => action); else { clear(); action() } }
  const closeCourseEditor = () => { if (courseEditorDirty) setCloseCoursePrompt(true); else setEditor(null) }
  const begin = (showRequirements = false) => {
    if (!read.data || !allowSave) return
    const d = preparation(read.data)
    setDrafts([d]); setOriginals([structuredClone(d)]); setRevision(read.data.revision)
    setLabels(Object.fromEntries(read.data.plan.courses.map(c => [`id:${c.course_id}`, `${c.course?.course_name || 'مادة'} (${c.course?.course_code || 'غير محدد'})`])))
    setNotice('يمكن إضافة عدة مواد والمتطلبات قبل حفظ واحد؛ لم تتغير قاعدة البيانات.'); if (showRequirements) setSettings(true)
  }
  const addMaterial = () => { if (!allowSave) return; if (!activeDraft) begin(); setEditor({ initialChoice: linkCourse.data ? { id: linkCourse.data.data.course_id, label: `${linkCourse.data.data.course_name} (${linkCourse.data.data.course_code})`, credit_hours: linkCourse.data.data.credit_hours } : null }) }
  const adoptOutcome = result => { const target = result.targets?.find(t => Number(t.academic_program_id) === Number(initialProgram)); if (target && Number(target.academic_plan_version_id) !== Number(sourceVersion || read.data?.plan?.version.academic_plan_version_id)) setDestination(entityLink('programs', initialProgram, { version: target.academic_plan_version_id, returnTo })) }
  const openInfo = context => change(() => { setInfoEditor(context); setInfoEpoch(n => n + 1) })
  const preview = (path, context) => change(async () => {
    const token = ++sequence.current; setChecking(true)
    try { const baseline = await programRead(path); if (alive.current && token === sequence.current) { setInfoEditor({ ...context, baseline, programId: initialProgram }); setInfoEpoch(n => n + 1) } }
    catch (failure) { if (alive.current && token === sequence.current) { if ([401, 403].includes(failure.status)) onDenied(); else setError(catalogError(failure)) } }
    finally { if (alive.current && token === sequence.current) setChecking(false) }
  })
  const infoSaved = result => { clear(); setRefresh(n => n + 1); setNotice('تم حفظ الإجراء.'); if (result.deleted) setDestination(safeEntityReturn(returnTo, entityLink('programs'))); else if (result.version?.academic_plan_version_id || result.current_version_id) setDestination(entityLink('programs', initialProgram, { version: result.version?.academic_plan_version_id || result.current_version_id, returnTo })) }
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
    replace(upsertPreparedCourse(activeDraft, membership)); setCourseEditorDirty(false); setEditor(null)
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
      adoptOutcome(result)
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
      if (result.status === 'confirmed') { clear(); setRefresh(n => n + 1); setNotice(result.result.changed ? 'تأكد نجاح الحفظ السابق؛ لم تُكرر العملية.' : 'تأكد عدم وجود تغيير فعلي.'); adoptOutcome(result.result) }
      else { setError('لم تظهر نتيجة محفوظة بعد. هذا لا يثبت فشل الحفظ؛ لا تعاد العملية تلقائيًا.'); setRefresh(n => n + 1) }
    } catch (failure) { if (alive.current) { if ([401, 403].includes(failure.status)) onDenied(); else setError(catalogError(failure)) } }
    finally { if (alive.current) setBusy(false) }
  }
  const labelFor = c => labels[courseIdentity(c)] || (() => { const course = read.data?.plan.courses.find(pc => Number(pc.course_id) === Number(c.course_id))?.course; return `${course?.course_name || 'مادة'} (${course?.course_code || c.course_id})` })()
  const tableRows = shown.filter(c => (!level || Number(c.academic_level_id) === Number(level.id)) && (!semester || Number(c.recommended_semester_id) === Number(semester.id)) && (!scope || c.requirement_scope === scope)
    && (!q.trim() || labelFor(c).toLocaleLowerCase().includes(q.trim().toLocaleLowerCase())))
    .map(c => ({ ...c, label: labels[courseIdentity(c)] || (() => { const course = read.data?.plan.courses.find(pc => Number(pc.course_id) === Number(c.course_id))?.course; return `${course?.course_name || 'مادة'} (${course?.course_code || c.course_id})` })() }))
  return <main dir="rtl" className="space-y-4">
    <EntityBreadcrumbs college={read.data?.program.department?.college || detail.data?.program.department?.college} department={read.data?.program.department || detail.data?.program.department} current={read.data?.program.program_name || detail.data?.program.program_name || 'البرنامج'} kind="programs" returnTo={returnTo} />
    {p && <header className="rounded-[18px] border border-primary/12 bg-white px-6 py-5 space-y-3"><div className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="text-[22px] font-black text-text-dark">{p.program_name}</h1><p className="text-[12px] text-text-light"><span dir="ltr">{p.program_code || 'الرمز غير محدد'}</span> · {p.archived_at ? 'مؤرشف — مستمر للطلاب الحاليين' : p.is_active ? 'فعّال' : 'غير فعّال'}</p></div><ProgramEntityActions detail={detail.data} plan={read.data?.plan} busy={busy || checking || metadataChanged || !!uncertain} onEdit={() => openInfo({ kind: 'program', programId: initialProgram, baseline: detail.data })} onDecision={openInfo} onPreview={preview} onChooseVersion={id => change(() => navigate(entityLink('programs', initialProgram, { version: id, returnTo })))} /></div>
      <dl className="grid gap-3 text-[12.5px] leading-7 sm:grid-cols-2 lg:grid-cols-5">{[['الكلية أو المعهد', p.department?.college ? <Link className="text-primary font-semibold" to={entityLink('colleges', p.department.college_id, { returnTo })}>{p.department.college.college_name}</Link> : 'غير محدد'], ['القسم', p.department ? <Link className="text-primary font-semibold" to={entityLink('departments', p.department_id, { returnTo })}>{p.department.department_name}</Link> : 'غير محدد'], ['الدرجة العلمية', p.degree_level || 'غير محدد'], ['المدة بالسنوات', p.duration_years ?? 'غير محدد'], ['ساعات التخرج في الخطة المعروضة', displayedHours ?? 'غير محدد']].map(([label, value]) => <div key={label}><dt className="text-[11.5px] text-text-light">{label}</dt><dd className="font-semibold">{value}</dd></div>)}</dl>{p.description && <p className="text-[12.5px] leading-7">{p.description}</p>}
    </header>}
    {read.loading && <Notice>تحميل الخطة الحالية…</Notice>}{read.error && <Notice error>{catalogError(read.error)}<Button onClick={() => setRefresh(n => n + 1)}>إعادة التحميل</Button></Notice>}
    <Notice>{notice}</Notice><Notice error>{error}</Notice><Notice error>{detail.error && catalogError(detail.error)}</Notice>{detail.error && <Button onClick={() => setRefresh(n => n + 1)}>إعادة تحميل البيانات</Button>}{checking && <Notice>تحميل المعاينة…</Notice>}
    {metadataChanged && <Notice>تغيرت البيانات بين التحميلين؛ أُوقفت إجراءات البيانات حتى مراجعتها.<Button onClick={() => setRefresh(n => n + 1)}>تحديث القراءة</Button></Notice>}
    {draftStale && <Notice error>وصلت نسخة أحدث، لكن بقي إعدادك ومعاينته الأصلية دون تبديل خطة أو مراجعة تلقائيًا. راجع الفرق قبل تجاهل الإعداد.<Button disabled={busy || !!uncertain} onClick={() => change(() => setRefresh(n => n + 1))}>مراجعة النسخة الحالية وتجاهل الإعداد</Button></Notice>}
    {uncertain && <Notice error>احتُفظ بإعدادك دون ربطه بإصدار جديد. تحقق من نتيجة الحفظ قبل أي محاولة أخرى.<Button disabled={busy} onClick={inspect}>التحقق من نتيجة الحفظ</Button><Button disabled={busy} onClick={() => save(true)}>إعادة إرسال الطلب نفسه دون تغيير</Button><Button disabled={busy} onClick={() => setTransition(() => () => setRefresh(n => n + 1))}>مراجعة الحالة وتجاهل الإعداد</Button></Notice>}
    {read.data?.requires_source_selection && <section className="space-y-3 rounded-[16px] border border-primary/12 bg-white p-5"><Notice>{read.data.lock_reason}</Notice><Field label="الخطة التي تريد استكمالها"><Select value="" onChange={e => { if (e.target.value) change(() => navigate(entityLink('programs', initialProgram, { version: e.target.value, returnTo, addCourse: addCourseId }))) }}><option value="">اختر الخطة صراحة</option>{read.data.source_choices.map(v => <option key={v.academic_plan_version_id} value={v.academic_plan_version_id}>{v.label}</option>)}</Select></Field></section>}
    {read.data?.plan && <>
      <section className="space-y-4 rounded-[16px] border border-primary/12 bg-white p-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-[16px] font-black text-text-dark">الخطة الإرشادية</h2><p className="text-[12px] leading-7 text-text-light">التغييرات الأكاديمية تُطبّق على الطلاب الجدد فقط؛ تبقى خطط الطلاب الحاليين محفوظة.</p></div><div className="flex flex-wrap gap-2"><Button primary disabled={!allowSave || (addCourseId && linkCourse.loading)} onClick={addMaterial}><FaPlus />إضافة مادة</Button><Button onClick={() => setSettings(true)}>متطلبات التخرج</Button><Button onClick={() => setHistory(true)}>سجل التغييرات</Button>{drafts.length === 0 && <Button disabled={!allowSave} onClick={() => begin(true)}><FaClipboardList />تعديل المتطلبات والتوزيع</Button>}</div></div>
        <Notice>{read.data.lock_reason}</Notice>{read.data.lock_reason && sourceVersion && <Link className="text-[12px] font-bold text-primary" to={`${WORKSPACE_PATH}?program=${selectedProgram.id}`}>فتح خطة الطلاب الجدد</Link>}{!read.data.plan.persisted && <Notice>هذه الخطة الحالية المسجلة فعلًا. عرضها لا ينشئ خطة أو إسنادًا ولا يوقف القبول.</Notice>}
        <Notice error>{linkCourse.error && catalogError(linkCourse.error)}</Notice>{linkCourse.data && <p className="text-[12px] text-text-light">المادة المختارة للربط: {linkCourse.data.data.course_name} ({linkCourse.data.data.course_code}). راجع توزيعها بالضغط على «إضافة مادة»؛ لم تُربط تلقائيًا.</p>}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><Field label="بحث بالاسم أو الرمز"><Input value={q} onChange={e => setQ(e.target.value)} /></Field><CatalogLookup label="السنة الدراسية" resource="levels" loader={programRead} value={level} onChange={setLevel} clearLabel="كل السنوات" /><CatalogLookup label="الفصل الإرشادي" resource="semesters" loader={programRead} value={semester} onChange={setSemester} clearLabel="كل الفصول" /><Field label="تصنيف المتطلب"><Select value={scope} onChange={e => setScope(e.target.value)}><option value="">جميع مواد الخطة</option>{Object.entries(SCOPES).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</Select></Field></div>
        {(q || level || semester || scope) && <Button onClick={() => { setQ(''); setLevel(null); setSemester(null); setScope('') }}>مسح الفلاتر</Button>}
        {activeDraft && <div className="flex flex-wrap gap-2"><Button primary disabled={!allowSave} onClick={() => setConfirm(true)}><FaSave />حفظ وتطبيق للطلاب الجدد</Button></div>}
        <WorkspaceCurriculum key={`${level?.id || ''}:${semester?.id || ''}:${scope}:${q.trim()}`} rows={tableRows} canEdit={!!activeDraft} busy={busy} uncertain={!!uncertain} source={activeDraft?.source_courses || read.data.plan.courses} origins={newCourses} returnTo={entityLink('programs', initialProgram, { version: activeDraft?.source_version_id || sourceVersion || read.data.plan.version.academic_plan_version_id, returnTo })} onEdit={c => { if (!activeDraft) return; setEditor({ membership: c, choice: { id: c.course_id, label: c.label } }) }} onRemove={c => replace({ ...activeDraft, courses: activeDraft.courses.filter(item => courseIdentity(item) !== courseIdentity(c)) })} />
      </section>
      {drafts.length > 0 && <section className="space-y-3 rounded-[16px] border border-primary/12 bg-white p-5"><h3 className="font-bold">البرامج المختارة للعملية</h3>{drafts.map(d => <div key={d.program.academic_program_id} className="space-y-2"><p className="font-bold">{d.program.program_name}</p>{d !== activeDraft && <><Requirements draft={d} editable={!busy && !uncertain} onChange={replace} /><Button disabled={busy || !!uncertain} onClick={() => setEditor({ target: d })}>إضافة مادة مشتركة لهذا البرنامج</Button><WorkspaceCurriculum rows={d.courses.map(c => ({ ...c, label: labelFor(c) }))} canEdit busy={busy} uncertain={!!uncertain} source={d.source_courses} origins={newCourses} onEdit={c => setEditor({ target: d, membership: c, choice: { id: c.course_id, label: labelFor(c) } })} onRemove={c => replace({ ...d, courses: d.courses.filter(item => courseIdentity(item) !== courseIdentity(c)) })} /></>}</div>)}
        <div className="grid gap-3 sm:grid-cols-2"><CatalogLookup label="كلية البرنامج الإضافي" resource="colleges" loader={programRead} value={targetCollege} disabled={busy || !!uncertain} onChange={v => { setTargetCollege(v); setTargetDepartment(null); chooseTarget(null) }} /><CatalogLookup label="قسم البرنامج الإضافي" resource="departments" loader={programRead} context={{ college_id: targetCollege?.id }} value={targetDepartment} disabled={!targetCollege || busy || !!uncertain} onChange={v => { setTargetDepartment(v); chooseTarget(null) }} /><CatalogLookup label="إضافة برنامج محدد للعملية" resource="programs" loader={programRead} value={targetChoice} context={{ college_id: targetCollege?.id, department_id: targetDepartment?.id }} disabled={!targetCollege || busy || !!uncertain} onChange={chooseTarget} />{targetSources.length > 0 && <Field label="خطة البرنامج الإضافي التي تريد استكمالها"><Select value={targetVersion || ''} disabled={busy || !!uncertain} onChange={e => setTargetVersion(e.target.value || null)}><option value="">اختر الخطة صراحة</option>{targetSources.map(v => <option key={v.academic_plan_version_id} value={v.academic_plan_version_id}>{v.label}</option>)}</Select></Field>}<Button disabled={!targetChoice || busy || !!uncertain || (targetSources.length > 0 && !targetVersion)} onClick={addTarget}>إضافة البرنامج المختار</Button></div><Notice>اختيار متطلب جامعة لا يضيف المادة لأي برنامج غير مختار. يراجع كل برنامج وتصنيفه وتوزيعه قبل حفظ واحد.</Notice>
      </section>}
    </>}
    {settings && read.data?.plan && <CatalogDialog title="متطلبات التخرج" wide closeButton onClose={() => setSettings(false)}><Requirements draft={activeDraft || preparation(read.data)} editable={!!activeDraft && !busy && !uncertain && !infoBlocked} onChange={replace} />{!activeDraft && <Button primary disabled={!allowSave} onClick={() => begin()}>تعديل المتطلبات ضمن الإعداد</Button>}<Button onClick={() => setSettings(false)}>العودة إلى الخطة</Button></CatalogDialog>}
    {history && <CatalogDialog title="سجل التغييرات" wide closeButton onClose={() => setHistory(false)}><WorkspaceHistory read={past} page={historyPage} onPage={setHistoryPage} /></CatalogDialog>}
    {infoEditor && <CatalogDialog title={infoEditor.kind === 'program' ? 'تعديل بيانات البرنامج' : 'مراجعة الإجراء'} wide closeButton onClose={() => change(() => setInfoEditor(null))}>{infoEditor.kind === 'program' ? <ProgramForms key={infoEpoch} editor={infoEditor} busy={busy} onBusy={setBusy} onDirty={setDirty} onBlocked={setInfoBlocked} onUnauthorized={onDenied} onSaved={infoSaved} onRestart={current => change(() => { setInfoEditor({ ...infoEditor, baseline: current }); setInfoEpoch(n => n + 1) })} /> : <ProgramDecision key={infoEpoch} editor={infoEditor} busy={busy} onBusy={setBusy} onDirty={setDirty} onBlocked={setInfoBlocked} onUnauthorized={onDenied} onSaved={infoSaved} onRestart={current => change(() => { setInfoEditor({ ...infoEditor, baseline: current }); setInfoEpoch(n => n + 1) })} />}</CatalogDialog>}
    {editor && <CatalogDialog title="إعداد المادة" wide closeButton onClose={closeCourseEditor}><WorkspaceCourseEditor existing={editor.membership ? editor : null} initialChoice={editor.initialChoice} departmentId={(editor.target || activeDraft).program.department_id} canCreate={!!read.data?.can_create_course} preparedCourses={newCourses} onDirty={() => setCourseEditorDirty(true)} onClose={closeCourseEditor} onApply={(membership, origin, label) => {
      if (editor.target) { if (origin) setNewCourses(cs => [...cs.filter(c => c.key !== origin.key), origin]); setLabels(old => ({ ...old, [courseIdentity(membership)]: label })); replace(upsertPreparedCourse(editor.target, membership)); setCourseEditorDirty(false); setEditor(null) }
      else applyCourse(membership, origin, label)
    }} /></CatalogDialog>}
    {closeCoursePrompt && <CatalogDialog title="مدخلات المادة غير المحفوظة" onClose={() => setCloseCoursePrompt(false)}><p>هل تريد تجاهل مدخلات هذه المادة فقط؟ تبقى بقية مواد الإعداد ومتطلباته محفوظة في النموذج.</p><div className="flex flex-wrap gap-2"><Button onClick={() => setCloseCoursePrompt(false)}>البقاء في المادة</Button><Button danger onClick={() => { setCloseCoursePrompt(false); setCourseEditorDirty(false); setEditor(null) }}>تجاهل مدخلات هذه المادة</Button></div></CatalogDialog>}
    {confirm && <CatalogDialog title="تأكيد التغييرات" onClose={() => { if (!busy) setConfirm(false) }}><Notice>تُطبّق على الطلاب الجدد فقط. تبقى خطط الطلاب الحاليين وحقوقهم محفوظة.</Notice>{drafts.map((d, i) => { const changes = actualChanges(originals[i], d); return <div key={d.program.academic_program_id} className="space-y-2 text-[13px] leading-7"><p><b>{d.program.program_name}</b>: إضافة {changes.added}، إزالة {changes.removed}، تعديل {changes.updated}{changes.requirements ? '، وتعديل متطلبات التخرج' : ''}.</p>{courseChanges(originals[i], d).map(c => <p key={c.identity}>{labelFor(c.after || c.before)}: {!c.before ? 'إضافة' : !c.after ? 'إزالة من الخطة' : 'تعديل التوزيع'} — {courseSummary(c.before)} ← {courseSummary(c.after)}</p>)}{changes.requirements && <><p>ساعات التخرج: {originals[i].requirements.total_credit_hours ?? 'غير محدد'} ← {d.requirements.total_credit_hours ?? 'غير محدد'}</p>{requirementGroupChanges(originals[i].requirements, d.requirements).map(g => <p key={g.identity}>{SCOPES[(g.after || g.before).requirement_scope]} — {TYPES[(g.after || g.before).requirement_type]}: الساعات {g.before?.required_credit_hours ?? 'غير محدد'} ← {g.after?.required_credit_hours ?? 'غير محدد'}؛ الحالة {groupActivityLabel(g.before?.is_active)} ← {groupActivityLabel(g.after?.is_active)}</p>)}</>}</div> })}<div className="flex flex-wrap gap-2"><Button primary disabled={busy} onClick={() => save()}>{busy ? 'جارٍ الحفظ…' : 'تأكيد الحفظ والتطبيق'}</Button><Button disabled={busy} onClick={() => setConfirm(false)}>العودة للإعداد</Button></div></CatalogDialog>}
    {(transition || blocker.state === 'blocked') && <CatalogDialog title="حماية الإعداد" onClose={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}><p>احتُفظ بإعدادك. {busy || checking ? 'انتظر نتيجة العملية قبل الانتقال.' : uncertain || infoBlocked ? 'قد يكون الحفظ نجح؛ تحقق من نتيجته قبل ترك الإعداد.' : 'هل تريد تجاهل التغييرات والانتقال؟'}</p><div className="flex flex-wrap gap-2"><Button onClick={() => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }}>البقاء في الإعداد</Button><Button danger disabled={busy || checking} onClick={() => { const action = transition; setTransition(null); clear(); if (blocker.state === 'blocked') blocker.proceed(); else action?.() }}>تجاهل الإعداد والمتابعة</Button></div></CatalogDialog>}
  </main>
}

const courseSummary = c => c ? `${SCOPES[c.requirement_scope] || 'غير مصنف'}، ${TYPES[c.course_type]}، السنة ${c.academic_level_id ?? 'غير محددة'}، الفصل ${c.recommended_semester_id ?? 'غير محدد'}، ${c.is_active ? 'فعال' : 'غير فعال'}` : 'خارج الخطة'
