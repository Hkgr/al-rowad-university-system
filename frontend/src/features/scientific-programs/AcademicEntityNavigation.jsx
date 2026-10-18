import { Link, useLocation } from 'react-router-dom'
import { canAccess } from '../auth/auth'
import { CATALOG_ACCESS } from '../scientific-courses/catalog'
import { Button, CatalogDialog } from '../scientific-courses/CatalogControls'
import { PROGRAM_ACCESS } from './programs'
import { ENTITY_LABELS, ENTITY_ROOT, entityLink, safeEntityReturn } from './entities'

export function EntityDirectoryLinks() {
  const location = useLocation()
  return <nav aria-label="قوائم البرامج والمواد" className="flex flex-wrap gap-2">{Object.entries(ENTITY_LABELS).filter(([kind]) => canAccess(kind === 'courses' ? CATALOG_ACCESS : PROGRAM_ACCESS)).map(([kind, label]) => <Link key={kind} to={entityLink(kind)} className={`inline-flex rounded-[10px] border px-4 py-2.5 text-[12px] font-bold ${location.pathname.startsWith(`${ENTITY_ROOT}/${kind}`) ? 'border-primary text-primary-dark bg-primary/5' : 'border-primary/15 text-text-gray bg-white hover:bg-primary/5'}`}>{label}</Link>)}</nav>
}
export function EntityBreadcrumbs({ college, department, current, returnTo, kind }) {
  return <nav aria-label="مسار التنقل" className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] leading-6 text-text-light"><Link className="text-primary" to={ENTITY_ROOT}>البرامج والمواد</Link>
    {college && <><span aria-hidden>‹</span><Link className="text-primary" to={entityLink('colleges', college.college_id, { returnTo })}>{college.college_name}</Link></>}
    {department && <><span aria-hidden>‹</span><Link className="text-primary" to={entityLink('departments', department.department_id, { returnTo })}>{department.department_name}</Link></>}
    <span aria-hidden>‹</span><span aria-current="page">{current}</span>{kind && <Link className="mr-auto font-bold text-primary" to={safeEntityReturn(returnTo, entityLink(kind))}>العودة إلى {ENTITY_LABELS[kind]}</Link>}
  </nav>
}
export function EntityRowActions({ label, actions }) {
  return <details className="text-[12px] leading-7" aria-label={`إجراءات ${label}`}><summary className="cursor-pointer rounded-[10px] border border-primary/15 bg-white px-3 py-1.5 font-bold text-text-gray">إجراءات</summary><div className="mt-2 flex flex-col items-start gap-2">{actions.map(action => <Button key={action.label} danger={action.danger} disabled={action.disabled} onClick={event => { event.currentTarget.closest('details')?.removeAttribute('open'); action.onClick() }}>{action.label}</Button>)}</div></details>
}

export function EntityEditorNavigationGuard({ guard }) {
  return guard.navigationBlocked ? <CatalogDialog title="حماية التغييرات" onClose={guard.stay}><p>{guard.busy ? 'انتظر نتيجة العملية قبل الانتقال.' : guard.blocked ? 'قد يكون الحفظ نجح؛ راجع الحالة الرسمية قبل ترك النموذج.' : 'هل تريد تجاهل التغييرات والانتقال؟'}</p><div className="flex flex-wrap gap-2"><Button onClick={guard.stay}>البقاء في المحرر</Button><Button danger disabled={guard.busy} onClick={guard.discard}>تجاهل التغييرات والمتابعة</Button></div></CatalogDialog> : null
}
