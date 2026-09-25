import { Link, useParams } from 'react-router-dom'
import { fetchMinistryDetail } from '../lib/ministryApi'
import { formatDate, viewState } from '../lib/ministryState'
import { Badge, InfoGrid, MiniTable, Notice, PageHeader, Section, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'
import { PositionsTable } from './MinistryFacultyDetail'

export default function MinistryDeanDetail() {
  const { person } = useParams()
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryDetail('deans', person), [person])
  const d = data?.data
  const state = viewState({ loading, error })
  const back = { to: '/ministry/deans', label: 'قائمة العمداء' }
  if (state !== 'ready') return <><PageHeader title="تفاصيل العميد" back={back} /><StatePanel state={state} error={error} onRetry={reload} /></>
  const current = d.dean_assignments.filter(a => a.state === 'current')

  return (
    <>
      <PageHeader title={d.full_name} subtitle={current.length ? `عميد ${current.map(a => a.college.name).join('، ')}` : 'عميد سابق'} back={back}>
        <Badge tone={current.length ? 'success' : 'neutral'}>{current.length ? 'تكليف حالي' : 'تكليف سابق'}</Badge>
      </PageHeader>
      <div className="grid gap-5">
        <Section title="البيانات" id="profile">
          <InfoGrid items={[
            ['الرتبة العلمية', d.academic_rank],
            ['الاختصاص', d.specialization],
            ['ملف تدريسي', d.faculty_member_id ? <Link className="text-primary-dark hover:underline" to={`/ministry/faculty/${d.faculty_member_id}`}>عرض الملف</Link> : 'غير مسجل'],
          ]} />
        </Section>
        <Notice>{d.source_note}</Notice>
        <Section title="تكليفات العمادة" id="assignments">
          <MiniTable rows={d.dean_assignments} rowKey={(r, i) => `${r.college.id}-${i}`} columns={[
            { key: 'college', header: 'الكلية', render: r => <Link className="text-primary-dark hover:underline" to={`/ministry/colleges/${r.college.id}`}>{r.college.name}</Link> },
            { key: 'state', header: 'الحالة', render: r => <Badge tone={r.state === 'current' ? 'success' : 'neutral'}>{r.state_label}</Badge> },
            { key: 'start', header: 'من', render: r => r.start_date ? formatDate(r.start_date) : <span className="text-text-light">غير مسجل</span> },
            { key: 'end', header: 'إلى', render: r => r.end_date ? formatDate(r.end_date) : '—' },
            { key: 'notes', header: 'ملاحظات السجل', render: r => r.notes.join(' ') || '—' },
          ]} />
        </Section>
        <Section title="كل المناصب المسجلة" id="positions">
          <PositionsTable positions={d.positions} />
        </Section>
      </div>
    </>
  )
}
