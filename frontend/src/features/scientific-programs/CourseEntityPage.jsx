import { useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { canViewCatalog, catalogError } from '../scientific-courses/catalog'
import { getIdentity } from '../auth/auth'
import useCatalogRead from '../scientific-courses/useCatalogRead'
import { Button, CatalogDialog, CatalogLookup, Notice } from '../scientific-courses/CatalogControls'
import CourseDetails from '../scientific-courses/CourseDetails'
import CourseEditor from '../scientific-courses/CourseEditor'
import { canViewPrograms, programRead } from './programs'
import { EntityBreadcrumbs, EntityEditorNavigationGuard } from './AcademicEntityNavigation'
import useEntityEditorGuard from './useEntityEditorGuard'
import { entityLink, membershipEntityLink, safeEntityReturn } from './entities'

export default function CourseEntityPage({ id, onUnauthorized }) {
  const [params] = useSearchParams(), location = useLocation(), navigate = useNavigate()
  const [refresh, setRefresh] = useState(0), [editor, setEditor] = useState(null), [epoch, setEpoch] = useState(0), [notice, setNotice] = useState(''), [target, setTarget] = useState(null)
  const guard = useEntityEditorGuard(canViewCatalog), read = useCatalogRead(`/courses/${id}`, { refresh })
  const open = kind => guard.go(() => { guard.reset(); setEditor({ kind, baseline: read.data }); setEpoch(n => n + 1); setTarget(null) })
  const close = () => guard.go(() => { guard.reset(); setEditor(null) })
  const saved = result => { guard.reset(); setEditor(null); if (result.deleted) navigate(safeEntityReturn(params.get('return'), entityLink('courses'))); else { setRefresh(n => n + 1); setNotice('تم حفظ بيانات المادة؛ لم يُغيّر هذا الإجراء خطط الطلاب.') } }
  const restart = current => guard.go(() => { guard.reset(); setEditor(e => ({ ...e, baseline: current })); setEpoch(n => n + 1) })
  const returnTo = location.pathname + location.search
  return <main dir="rtl" className="space-y-4"><EntityBreadcrumbs current={read.data?.data?.course_name || 'المادة'} kind="courses" returnTo={params.get('return')} />
    <Notice>{notice || guard.notice}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice>{read.loading && <Notice>تحميل بيانات المادة…</Notice>}{read.error && <Button onClick={() => setRefresh(n => n + 1)}>إعادة التحميل</Button>}
    {read.data && <section className="rounded-[16px] border border-primary/12 bg-white p-5 space-y-4"><CourseDetails page returnTo={returnTo} baseline={read.data} busy={guard.busy} onEdit={() => open('edit')}
      onMembership={pc => guard.go(() => navigate(membershipEntityLink(pc, returnTo)))} onBusy={guard.onBusy} onBlocked={guard.onBlocked} onSaved={saved} onRestart={() => setRefresh(n => n + 1)} onUnauthorized={onUnauthorized} />
      {canViewPrograms(getIdentity()) && <Button primary onClick={() => open('targets')}>ربط ببرامج محددة</Button>}
    </section>}
    {editor && <CatalogDialog wide closeButton title={editor.kind === 'targets' ? 'اختيار برنامج إعداد الربط' : 'تعديل بيانات المادة'} onClose={close}>{editor.kind === 'targets' ? <><Notice>اختر برنامجًا صراحة لبدء إعداد الربط. يمكن إضافة برامج محددة أخرى ومراجعة توزيع المادة لكل برنامج قبل حفظ ذري واحد للطلاب الجدد فقط.</Notice><CatalogLookup label="البرنامج الذي تبدأ منه" resource="programs" loader={programRead} value={target} onChange={setTarget} clearLabel="اختر البرنامج" /><Link className={`inline-flex rounded-[10px] border px-4 py-2.5 text-[12px] font-bold ${target ? 'bg-primary text-white border-primary' : 'pointer-events-none opacity-50'}`} aria-disabled={!target} to={target ? entityLink('programs', target.id, { addCourse: id, returnTo }) : '#'}>فتح البرنامج وإعداد الربط</Link></> : <CourseEditor key={epoch} baseline={editor.baseline} busy={guard.busy} onDirty={guard.onDirty} onBusy={guard.onBusy} onBlocked={guard.onBlocked} onUnauthorized={onUnauthorized} onSaved={saved} onRestart={restart} allowDistribution={false} />}</CatalogDialog>}
    <EntityEditorNavigationGuard guard={guard} />
  </main>
}
