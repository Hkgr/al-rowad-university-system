import { Link, useParams } from 'react-router-dom'
import { fetchMinistryDetail } from '../lib/ministryApi'
import { formatDate, viewState } from '../lib/ministryState'
import { Badge, InfoGrid, MiniTable, Notice, PageHeader, Section, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

export default function MinistryFacultyDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryDetail('faculty', id), [id])
  const f = data?.data
  const state = viewState({ loading, error })
  const back = { to: '/ministry/faculty', label: 'قائمة المدرسين' }
  if (state !== 'ready') return <><PageHeader title="تفاصيل المدرس" back={back} /><StatePanel state={state} error={error} onRetry={reload} /></>

  return (
    <>
      <PageHeader title={f.full_name} subtitle={[f.academic_rank, f.specialization].filter(Boolean).join(' — ')} back={back}>
        <Badge tone={f.is_active ? 'success' : 'neutral'}>{f.is_active ? 'ملف مفعّل' : 'ملف غير مفعّل'}</Badge>
      </PageHeader>
      <div className="grid gap-5">
        <Section title="البيانات المهنية" id="profile">
          <InfoGrid items={[['الرتبة العلمية', f.academic_rank], ['الاختصاص', f.specialization], ['الحالة الوظيفية', f.employee_status]]} />
        </Section>
        <Notice>{f.source_note}</Notice>
        <Section title="الانتماء للكليات" id="colleges">
          <MiniTable rows={f.colleges} rowKey={r => r.id} empty="لا يوجد انتماء مسجل لأي كلية." columns={[
            { key: 'college', header: 'الكلية', render: r => <Link className="text-primary-dark hover:underline" to={`/ministry/colleges/${r.id}`}>{r.name}</Link> },
            { key: 'source', header: 'مصدر الانتماء', render: r => r.source },
            { key: 'start', header: 'منذ', render: r => r.start_date ? formatDate(r.start_date) : '—' },
          ]} />
        </Section>
        <Section title="التدريس (التكليفات الفعّالة)" id="teaching">
          <MiniTable rows={f.teaching} rowKey={(r, i) => `${r.course_id}-${r.term}-${i}`} empty="لا توجد تكليفات تدريس فعّالة." columns={[
            { key: 'term', header: 'الفصل', render: r => r.term },
            { key: 'course', header: 'المقرر', render: r => <Link className="text-primary-dark hover:underline" to={`/ministry/courses/${r.course_id}`}>{r.course_code} — {r.course_name}</Link> },
            { key: 'program', header: 'البرنامج', render: r => r.program ?? '—' },
            { key: 'role', header: 'الجزء', render: r => `${r.role}${r.is_primary ? ' (أساسي)' : ''}` },
          ]} />
        </Section>
        <Section title="المقررات المؤهل لتدريسها" id="qualified">
          <MiniTable rows={f.qualified_courses} rowKey={r => r.course_id} empty="لا توجد مقررات مسجلة." columns={[
            { key: 'course', header: 'المقرر', render: r => <Link className="text-primary-dark hover:underline" to={`/ministry/courses/${r.course_id}`}>{r.course_code} — {r.course_name}</Link> },
          ]} />
        </Section>
        <Section title="المناصب" id="positions">
          <PositionsTable positions={f.positions} />
        </Section>
      </div>
    </>
  )
}

export function PositionsTable({ positions }) {
  return (
    <MiniTable rows={positions} rowKey={(r, i) => `${r.position}-${r.start_date}-${i}`} empty="لا توجد مناصب مسجلة." columns={[
      { key: 'position', header: 'المنصب', render: r => r.position },
      { key: 'unit', header: 'الوحدة', render: r => r.unit ? <Link className="text-primary-dark hover:underline" to={`/ministry/leadership/units/${r.unit.id}`}>{r.unit.name}</Link> : '—' },
      { key: 'state', header: 'الحالة', render: r => <Badge tone={r.state === 'current' ? 'success' : 'neutral'}>{r.state_label}</Badge> },
      { key: 'start', header: 'من', render: r => formatDate(r.start_date) },
      { key: 'end', header: 'إلى', render: r => r.end_date ? formatDate(r.end_date) : '—' },
    ]} />
  )
}
