import { useState } from 'react'
import CourseEditor from '../scientific-courses/CourseEditor'
import useCatalogRead from '../scientific-courses/useCatalogRead'
import { Button, Notice } from '../scientific-courses/CatalogControls'
import { catalogError } from '../scientific-courses/catalog'
import { programRead } from './programs'

/** Creating the catalog origin and linking it to an explicit draft are separate confirmed writes. */
export default function ProgramCourseCreation({ editor, onContinue, onDirty, onBusy, onBlocked, ...props }) {
  const [created, setCreated] = useState(null), [baseline, setBaseline] = useState(null), [epoch, setEpoch] = useState(0)
  const read = useCatalogRead(created ? null : '/courses', { refresh: epoch })
  const plan = useCatalogRead(created ? `/${editor.programId}/versions/${editor.versionId}` : null, { loader: programRead, refresh: epoch })
  if (created) return <div className="space-y-3"><Notice>حُفظت {created.course_name} ({created.course_code}) في الدليل. لم تُربط بالخطة بعد، ولم تتغير ميزانية المتطلبات.</Notice>
    <Notice error>{plan.error && catalogError(plan.error)}</Notice><Button onClick={() => setEpoch(n => n + 1)}>تحديث الخطة قبل الربط</Button>
    <Button primary disabled={!plan.data || plan.data.version.status !== 'draft'} onClick={() => onContinue(plan.data, created)}>مراجعة تصنيف المادة في الخطة المختارة</Button></div>
  if (!read.data && !baseline) return <div><Notice>{read.loading ? 'تحميل صلاحية إنشاء المادة…' : ''}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice><Button onClick={() => setEpoch(n => n + 1)}>إعادة التحميل</Button></div>
  if (!(baseline?.can_create ?? read.data?.can_create)) return <Notice error>لا تتوفر صلاحية إنشاء مادة في الدليل. يمكنك اختيار مادة موجودة ضمن نطاقك.</Notice>
  const initial = baseline || read.data
  return <><Notice>تُحفظ المادة في الدليل أولًا. ربطها بإصدار الخطة يتطلب تأكيدًا منفصلًا بعد نجاح الإنشاء.</Notice><CourseEditor key={epoch}
    {...props} allowDistribution={false} baseline={{ revision: initial.revision, data: {} }} onDirty={onDirty} onBusy={onBusy} onBlocked={onBlocked}
    onRestart={current => { setBaseline(current); setEpoch(n => n + 1); onDirty(false); onBlocked(false) }}
    onSaved={result => { onBusy(false); onDirty(false); onBlocked(false); setCreated(result.data) }} /></>
}
