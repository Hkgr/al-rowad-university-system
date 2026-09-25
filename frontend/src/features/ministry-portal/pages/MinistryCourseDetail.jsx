import { Link, useParams } from 'react-router-dom'
import { fetchMinistryDetail } from '../lib/ministryApi'
import { formatNumber, viewState } from '../lib/ministryState'
import { Badge, InfoGrid, MiniTable, PageHeader, Section, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

export default function MinistryCourseDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryDetail('courses', id), [id])
  const c = data?.data
  const state = viewState({ loading, error })
  const back = { to: '/ministry/courses', label: 'قائمة المواد' }
  if (state !== 'ready') return <><PageHeader title="تفاصيل المقرر" back={back} /><StatePanel state={state} error={error} onRetry={reload} /></>

  return (
    <>
      <PageHeader title={c.course_name} en={c.course_code} back={back}>
        <Badge tone={c.is_active ? 'success' : 'neutral'}>{c.is_active ? 'مفعّل' : 'غير مفعّل'}</Badge>
      </PageHeader>
      <div className="grid gap-5">
        <Section title="١. تعريف المقرر" subtitle="البيانات الثابتة للمقرر بصرف النظر عن الخطط والفصول." id="definition">
          <InfoGrid items={[
            ['الرمز', <span dir="ltr">{c.course_code}</span>],
            ['الساعات المعتمدة', formatNumber(c.credit_hours)],
            ['الساعات النظرية', c.theoretical_hours ?? '—'],
            ['الساعات العملية', c.practical_hours ?? '—'],
            ['الأقسام المالكة', c.departments.length ? c.departments.map(d => `${d.department_name}${d.is_primary ? ' (أساسي)' : ''}`).join('، ') : 'غير مسجل'],
            ['الكليات', [...new Map(c.departments.filter(d => d.college).map(d => [d.college.id, d.college])).values()].map(col => col.name).join('، ') || '—'],
          ]} />
          {c.description && <p className="mt-3 text-[12.5px] text-text-gray leading-6">{c.description}</p>}
        </Section>
        <Section title="٢. إدراجه في الخطط الدراسية" subtitle="كل نسخة خطة مسجلة تظهر في سطر؛ «الخطة الفعّالة» هي خطة البرنامج المعتمدة الافتراضية (أو الخطة السابقة لنظام النسخ)." id="plans">
          <MiniTable rows={c.plan_inclusions} rowKey={(r, i) => `${r.program.id}-${r.plan}-${i}`} empty="غير مدرج في أي خطة دراسية." columns={[
            { key: 'program', header: 'البرنامج', render: r => r.program.name },
            { key: 'college', header: 'الكلية', render: r => r.program.college ?? '—' },
            { key: 'plan', header: 'الخطة', render: r => r.plan },
            { key: 'effective', header: 'الخطة الفعّالة', render: r => <Badge tone={r.in_effective_plan ? 'success' : 'neutral'}>{r.in_effective_plan ? 'نعم' : 'لا'}</Badge> },
            { key: 'level', header: 'السنة', render: r => r.level },
            { key: 'semester', header: 'الفصل المقترح', render: r => r.semester },
            { key: 'type', header: 'النوع', render: r => r.course_type },
          ]} />
        </Section>
        <Section title="٣. طروحاته في الفصول" subtitle="عدد الطلاب = طلاب فريدون بتسجيل «مسجل» أو «مكتمل». اعتماد العلامات يوضح هل نُشرت نتائج الطرح رسميًا." id="offerings">
          <MiniTable rows={c.offerings} rowKey={r => r.offering_id} empty="لم يُطرح هذا المقرر في أي فصل." columns={[
            { key: 'term', header: 'الفصل', render: r => r.term },
            { key: 'program', header: 'البرنامج', render: r => r.program ?? '—' },
            { key: 'status', header: 'حالة الطرح', render: r => r.status },
            { key: 'capacity', header: 'السعة', render: r => formatNumber(r.capacity) },
            { key: 'students', header: 'الطلاب', render: r => formatNumber(r.registered_students) },
            { key: 'approved', header: 'اعتماد العلامات', render: r => <Badge tone={r.grades_officially_approved ? 'success' : 'warning'}>{r.grades_officially_approved ? 'معتمدة' : 'غير معتمدة بعد'}</Badge> },
            { key: 'instructors', header: 'المدرسون', render: r => r.instructors.length ? r.instructors.map((i, n) => <span key={i.faculty_member_id}>{n > 0 && '، '}<Link className="text-primary-dark hover:underline" to={`/ministry/faculty/${i.faculty_member_id}`}>{i.full_name}</Link> ({i.role})</span>) : <span className="text-text-light">غير مسجل</span> },
          ]} />
        </Section>
      </div>
    </>
  )
}
