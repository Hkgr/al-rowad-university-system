import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { FaUniversity, FaPlus } from 'react-icons/fa'
import DataTable from '../../components/table/DataTable'
import FilterBar from '../../components/table/FilterBar'
import { canAccess, getIdentity } from '../auth/auth'
import { CATALOG_ACCESS, canViewCatalog, catalogError, queryString } from '../scientific-courses/catalog'
import { Button, CatalogDialog, CatalogLookup, Field, Notice, Select } from '../scientific-courses/CatalogControls'
import useCatalogRead from '../scientific-courses/useCatalogRead'
import ScientificCoursesPage from '../scientific-courses/ScientificCoursesPage'
import UnifiedProgramsPage from './UnifiedProgramsPage'
import CourseEntityPage from './CourseEntityPage'
import ProgramForms from './ProgramForms'
import StructureEntityForm from './StructureEntityForm'
import StructureDeletion from './StructureDeletion'
import { canViewPrograms, programRead } from './programs'
import { WORKSPACE_ACCESS } from './workspace'
import { EntityBreadcrumbs, EntityDirectoryLinks, EntityEditorNavigationGuard } from './AcademicEntityNavigation'
import useEntityEditorGuard from './useEntityEditorGuard'
import { ENTITY_LABELS, entityFilterSearch, entityLink, entityListFilters, entityRead, safeEntityReturn } from './entities'

/** Shared existing office shell is supplied by App; no nested dashboard. */
export default function AcademicEntitiesPage({ kind = 'colleges' }) {
  const location = useLocation(), { entityId } = useParams()
  const [identity, setIdentity] = useState(() => JSON.stringify(getIdentity())), [denied, setDenied] = useState(false)
  useEffect(() => {
    const check = () => { const next = JSON.stringify(getIdentity()); if (next !== identity) { setIdentity(next); setDenied(false) } }
    const reject = () => setDenied(true)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    window.addEventListener('scientific-program-denied', reject); window.addEventListener('scientific-catalog-denied', reject)
    const timer = setInterval(check, 500)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check); window.removeEventListener('scientific-program-denied', reject); window.removeEventListener('scientific-catalog-denied', reject) }
  }, [identity])
  const user = JSON.parse(identity), query = new URLSearchParams(location.search)
  const legacyProgram = query.get('program')
  if (legacyProgram && /^[1-9]\d*$/.test(legacyProgram)) return <Navigate replace to={entityLink('programs', legacyProgram, { version: query.get('version'), tab: query.get('tab') })} />
  const courseOnly = !canViewPrograms(user) && canViewCatalog(user)
  if (denied || !canAccess(WORKSPACE_ACCESS, user) || (kind === 'courses' ? !canAccess(CATALOG_ACCESS, user) : !courseOnly && !canViewPrograms(user))) return <Notice error>انتهت الصلاحية؛ أُخفيت البيانات والنماذج.</Notice>
  if (query.get('catalog') === '1' || (courseOnly && kind !== 'courses')) return <Navigate replace to={entityLink('courses')} />
  const props = { kind, id: entityId, onUnauthorized: () => setDenied(true) }
  // Search/filter changes do not remount a draft. A real entity/identity change does.
  return <div key={`${identity}:${kind}:${entityId || ''}`} className="min-w-0 space-y-4">
    {kind === 'programs' && entityId ? <UnifiedProgramsPage entityProgramId={entityId} /> : <><EntityDirectoryLinks />{kind === 'courses' ? entityId ? <CourseEntityPage {...props} /> : <ScientificCoursesPage entityPages /> : entityId ? <StructureDetail {...props} /> : <EntityList {...props} />}</>}
  </div>
}

export function LegacyAcademicEntityRoute({ kind }) {
  const location = useLocation(), { programId } = useParams(), query = new URLSearchParams(location.search)
  query.delete('advanced')
  return <Navigate replace to={entityLink(kind, programId) + (query.size ? `?${query}` : '')} />
}

function EntityList({ kind, onUnauthorized }) {
  const [params, setParams] = useSearchParams(), location = useLocation(), navigate = useNavigate()
  const filters = entityListFilters(params.toString()), [refresh, setRefresh] = useState(0), [editor, setEditor] = useState(null), [epoch, setEpoch] = useState(0)
  const guard = useEntityEditorGuard(canViewPrograms), program = kind === 'programs', prefix = kind === 'colleges' ? 'college' : 'department'
  const query = queryString({ q: filters.q.trim(), college_id: filters.college?.id, ...(program ? { department_id: filters.department?.id, sort: filters.sort, direction: filters.direction } : {}), status: filters.status, page: filters.page })
  const read = useCatalogRead(program ? `/?${query}` : `/${kind}?${query}`, { loader: program ? programRead : entityRead, refresh, delay: 350 })
  const create = read.data?.capabilities.create
  const filter = (key, value) => guard.go(() => setParams(entityFilterSearch({ ...filters, [key]: value, page: key === 'page' ? value : 1, ...(key === 'college' ? { department: null } : {}) })))
  const open = () => guard.go(() => { guard.reset(); setEpoch(n => n + 1); setEditor({ kind: 'program', baseline: read.data, draft: undefined }) })
  const saved = result => { guard.reset(); setEditor(null); setRefresh(n => n + 1); const entity = result.program || result.entity; const id = entity?.[program ? 'academic_program_id' : `${prefix}_id`]; if (id) navigate(entityLink(kind, id, { returnTo: location.pathname + location.search, version: result.versions?.find(v => v.status === 'draft')?.academic_plan_version_id })) }
  const rows = read.data?.data || []
  return <main dir="rtl" className="space-y-4">
    <header className="flex flex-wrap items-start justify-between gap-3 rounded-[18px] border border-primary/12 bg-white px-6 py-5"><div><h1 className="text-[22px] font-black text-text-dark">{ENTITY_LABELS[kind]}</h1><p className="mt-1 text-[12.5px] leading-7 text-text-light">البيانات المسجلة والمتاحة ضمن نطاقك؛ افتح الاسم لاستعراض التفاصيل وإدارتها.</p></div>{create && <Button primary disabled={read.loading} onClick={open}><FaPlus />{program ? 'إضافة برنامج' : kind === 'colleges' ? 'إضافة كلية أو معهد' : 'إضافة قسم'}</Button>}</header>
    <FilterBar search={{ value: filters.q, onChange: v => filter('q', v), placeholder: 'بحث بالاسم أو الرمز' }} hasActiveFilters={!!(filters.q || filters.college || filters.department || filters.status)} onClear={() => guard.go(() => setParams({}))} />
    <div className="grid gap-3 sm:grid-cols-3">{kind !== 'colleges' && <CatalogLookup label="الكلية أو المعهد" resource="colleges" loader={programRead} value={filters.college} onChange={v => filter('college', v)} clearLabel="كل الكليات والمعاهد" />}{program && <CatalogLookup key={filters.college?.id || 'all'} label="القسم" resource="departments" context={{ college_id: filters.college?.id }} loader={programRead} value={filters.department} onChange={v => filter('department', v)} clearLabel="كل الأقسام" />}<Field label="الحالة"><Select value={filters.status} onChange={e => filter('status', e.target.value)}><option value="">كل الحالات</option><option value="active">فعّال</option><option value="inactive">غير فعّال</option>{program && <option value="archived">مؤرشف</option>}</Select></Field></div>
    <Notice>{guard.notice}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice>{read.error && <Button onClick={() => setRefresh(n => n + 1)}>إعادة التحميل</Button>}
    <DataTable rows={rows} loading={read.loading} rowKey={r => r[program ? 'academic_program_id' : `${prefix}_id`]} emptyIcon={FaUniversity} emptyTitle="لا توجد نتائج مطابقة" columns={[
      { key: 'name', header: program ? 'البرنامج' : kind === 'colleges' ? 'الكلية أو المعهد' : 'القسم', render: r => <Link className="text-primary font-semibold text-[13px]" to={entityLink(kind, r[program ? 'academic_program_id' : `${prefix}_id`], { returnTo: location.pathname + location.search })}>{r[program ? 'program_name' : `${prefix}_name`]}</Link> },
      { key: 'code', header: 'الرمز', dir: 'ltr', render: r => <span className="text-[12.5px]">{r[program ? 'program_code' : `${prefix}_code`] || 'غير محدد'}</span> },
      ...(kind !== 'colleges' ? [{ key: 'college', header: 'الكلية أو المعهد', render: r => { const c = program ? r.department?.college : r.college; return c ? <Link className="text-[12px] text-primary" to={entityLink('colleges', c.college_id, { returnTo: location.pathname + location.search })}>{c.college_name}</Link> : 'غير محدد' } }] : []),
      ...(program ? [{ key: 'department', header: 'القسم', render: r => r.department ? <Link className="text-[12px] text-primary" to={entityLink('departments', r.department_id, { returnTo: location.pathname + location.search })}>{r.department.department_name}</Link> : 'غير محدد' }, { key: 'degree', header: 'الدرجة والمدة', render: r => <span className="text-[12px]">{r.degree_level || 'غير محدد'} · {r.duration_years ?? 'غير محددة'} سنوات</span> }, { key: 'hours', header: 'ساعات التخرج', render: r => r.plan_total_credit_hours ?? r.total_credit_hours ?? 'غير محدد' }] : []),
      { key: 'active', header: 'الحالة', render: r => <span className="text-[12px]">{r.archived_at ? 'مؤرشف — مستمر للطلاب الحاليين' : r.is_active ? 'فعّال' : 'غير فعّال'}</span> },
    ]} page={read.data?.meta.current_page || filters.page} totalPages={read.data?.meta.last_page || 1} onPageChange={v => filter('page', v)} />
    {editor && <CatalogDialog wide closeButton title={program ? 'إضافة برنامج' : kind === 'colleges' ? 'إضافة كلية أو معهد' : 'إضافة قسم'} onClose={() => guard.go(() => { guard.reset(); setEditor(null) })}>{program ? <ProgramForms key={epoch} editor={editor} busy={guard.busy} {...guard} onUnauthorized={onUnauthorized} onSaved={saved} onRestart={current => guard.go(() => { guard.reset(); setEditor(e => ({ ...e, baseline: current })); setEpoch(n => n + 1) })} /> : <StructureEntityForm key={epoch} kind={kind} baseline={{ revision: editor.baseline.revision }} guard={guard} onUnauthorized={onUnauthorized} onSaved={saved} onRestart={current => guard.go(() => { guard.reset(); setEditor({ baseline: current }); setEpoch(n => n + 1) })} />}</CatalogDialog>}
    <EntityEditorNavigationGuard guard={guard} />
  </main>
}

function StructureDetail({ kind, id, onUnauthorized }) {
  const [params, setParams] = useSearchParams(), location = useLocation(), navigate = useNavigate(), prefix = kind === 'colleges' ? 'college' : 'department'
  const [refresh, setRefresh] = useState(0), [editor, setEditor] = useState(null), [epoch, setEpoch] = useState(0), [message, setMessage] = useState('')
  const guard = useEntityEditorGuard(canViewPrograms), read = useCatalogRead(`/${kind}/${id}`, { loader: entityRead, refresh })
  const entity = read.data?.entity, caps = read.data?.capabilities || {}, childKind = kind === 'colleges' ? 'departments' : 'programs'
  const children = useCatalogRead(entity ? `${kind === 'colleges' ? '/departments' : '/'}?${queryString({ [kind === 'colleges' ? 'college_id' : 'department_id']: id, q: params.get('child_q') || '', page: params.get('child_page') || 1 })}` : null, { loader: kind === 'colleges' ? entityRead : programRead, refresh, delay: 350 })
  const goEditor = context => guard.go(() => { guard.reset(); setEditor(context); setEpoch(n => n + 1) })
  const childFilter = (key, value) => guard.go(() => setParams(previous => { const next = new URLSearchParams(previous); if (value) next.set(key, String(value)); else next.delete(key); if (key === 'child_q') next.delete('child_page'); return next }))
  const saved = result => { guard.reset(); setEditor(null); setRefresh(n => n + 1); setMessage('تم حفظ البيانات.'); const e = result.entity || result.program; const newId = e?.[editor?.kind === 'program' ? 'academic_program_id' : 'department_id']; if (editor?.child && newId) navigate(entityLink(childKind, newId, { returnTo: location.pathname + location.search, version: result.versions?.find(v => v.status === 'draft')?.academic_plan_version_id })) }
  const callbacks = { busy: guard.busy, ...guard, onUnauthorized, onSaved: saved, onRestart: current => guard.go(() => { guard.reset(); setEditor(e => ({ ...e, baseline: current, draft: undefined })); setEpoch(n => n + 1) }) }
  return <main dir="rtl" className="space-y-4">
    <EntityBreadcrumbs current={entity?.[`${prefix}_name`] || ENTITY_LABELS[kind]} college={kind === 'departments' ? entity?.college : null} kind={kind} returnTo={params.get('return')} />
    <Notice>{message || guard.notice}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice>{read.error && <Button onClick={() => setRefresh(n => n + 1)}>إعادة التحميل</Button>}{read.loading && <Notice>تحميل بيانات العنصر…</Notice>}
    {entity && <><header className="rounded-[18px] border border-primary/12 bg-white px-6 py-5 space-y-3"><div className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="text-[22px] font-black text-text-dark">{entity[`${prefix}_name`]}</h1><p className="text-[12px] text-text-light">{entity[`${prefix}_code`] || 'الرمز غير محدد'} · {entity.is_active ? 'فعّال' : 'غير فعّال'}</p></div>{caps.edit && <Button onClick={() => goEditor({ baseline: read.data, kind: 'structure' })}>تعديل البيانات</Button>}</div>{entity.description && <p className="text-[12.5px] leading-7">{entity.description}</p>}<p className="text-[12px] text-text-light">الوحدة التنظيمية المسجلة: {entity.organizational_unit?.unit_name || 'غير مرتبطة بوحدة'}</p><Notice>{caps.edit_lock_reason}</Notice></header>
      <section className="space-y-3 rounded-[16px] border border-primary/12 bg-white p-5"><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-[16px] font-black text-text-dark">{ENTITY_LABELS[childKind]} المرتبطة — المتاحة ضمن نطاقك</h2>{caps.create_child && <Button primary onClick={() => goEditor(kind === 'colleges' ? { child: true, kind: 'structure', baseline: { revision: read.data.revision } } : { child: true, kind: 'program', baseline: { revision: read.data.revision }, draft: { program_code: '', program_name: '', degree_level: '', duration_years: '', total_credit_hours: '', description: '', department: { id: Number(id), label: entity.department_name } } })}><FaPlus />{kind === 'colleges' ? 'إضافة قسم' : 'إضافة برنامج'}</Button>}</div>
        <Notice>{caps.create_child_lock_reason}</Notice><FilterBar search={{ value: params.get('child_q') || '', onChange: value => childFilter('child_q', value), placeholder: 'بحث بالاسم أو الرمز' }} />
        <Notice error>{children.error && catalogError(children.error)}</Notice>{children.error && <Button onClick={() => setRefresh(n => n + 1)}>إعادة التحميل</Button>}
        {kind === 'departments' && children.data?.meta.total > 1 && <Notice>لهذا القسم عدة برامج مسجلة. اختر البرنامج المقصود؛ لم يُختَر أحدها تلقائيًا.</Notice>}
        <DataTable rows={children.data?.data || []} loading={children.loading} rowKey={r => r[kind === 'colleges' ? 'department_id' : 'academic_program_id']} emptyTitle={kind === 'colleges' ? 'لا توجد أقسام متاحة' : 'لا توجد برامج متاحة'} columns={[
          { key: 'name', header: kind === 'colleges' ? 'القسم' : 'البرنامج', render: r => <Link className="font-semibold text-primary text-[13px]" to={entityLink(childKind, r[kind === 'colleges' ? 'department_id' : 'academic_program_id'], { returnTo: location.pathname + location.search })}>{r[kind === 'colleges' ? 'department_name' : 'program_name']}</Link> },
          { key: 'code', header: 'الرمز', dir: 'ltr', render: r => r[kind === 'colleges' ? 'department_code' : 'program_code'] || 'غير محدد' },
          ...(kind === 'departments' ? [{ key: 'summary', header: 'الدرجة والمدة وساعات التخرج', render: r => <span className="text-[12px]">{r.degree_level || 'الدرجة غير محددة'} · {r.duration_years ?? 'غير محددة'} سنوات · {r.plan_total_credit_hours ?? r.total_credit_hours ?? 'غير محدد'} ساعة</span> }] : []),
          { key: 'status', header: 'الحالة', render: r => r.archived_at ? 'مؤرشف — مستمر للطلاب الحاليين' : r.is_active ? 'فعّال' : 'غير فعّال' },
        ]} page={children.data?.meta.current_page || 1} totalPages={children.data?.meta.last_page || 1} onPageChange={p => childFilter('child_page', p)} />
      </section><details className="rounded-[16px] border border-primary/12 bg-white p-5"><summary className="cursor-pointer text-[12px] font-bold text-text-light">إجراءات أخرى</summary><p className="py-2 text-[12px] text-text-light">{caps.delete_lock_reason}</p><Button danger disabled={!caps.delete} onClick={() => goEditor({ kind: 'delete', baseline: read.data })}>حذف عنصر غير مستخدم</Button></details>
    </>}
    {editor && <CatalogDialog wide closeButton title={editor.kind === 'program' ? 'إضافة برنامج' : editor.child ? 'إضافة قسم' : editor.kind === 'delete' ? 'حذف العنصر' : 'تعديل البيانات'} onClose={() => guard.go(() => { guard.reset(); setEditor(null) })}>{editor.kind === 'program' ? <ProgramForms key={epoch} editor={editor} {...callbacks} /> : editor.kind === 'delete' ? <StructureDeletion key={epoch} kind={kind} id={id} baseline={editor.baseline} guard={guard} onUnauthorized={onUnauthorized} onRestart={callbacks.onRestart} onDeleted={() => { guard.reset(); navigate(safeEntityReturn(params.get('return'), entityLink(kind))) }} /> : <StructureEntityForm key={epoch} kind={editor.child ? 'departments' : kind} baseline={editor.baseline} initialCollege={editor.child ? { id: Number(id), label: entity.college_name } : null} guard={guard} {...callbacks} />}</CatalogDialog>}
    <EntityEditorNavigationGuard guard={guard} />
  </main>
}
